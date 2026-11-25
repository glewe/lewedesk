<?php

declare(strict_types=1);

namespace App\Services;

require_once __DIR__ . '/../utils/GitHubRepo.php';

use App\Models\GitHubCommitCacheModel;
use App\Utils\GitHubRepo;

//---------------------------------------------------------------------------
/**
 * The commits of a repository that mention an issue key (see docs/GITHUB.md).
 *
 * GitHub's commit search is a fuzzy text search, so the hits are checked again here: the key must stand in the commit
 * message as a whole word and with exactly these letters (LD-12 does not match LD-123, ld-12 or XLD-12). The newest
 * ten are kept. The result is cached for ten minutes per repository and key; a failed search is not repeated for a
 * minute, and the last good list is shown meanwhile.
 */
class IssueCommits
{
  /** Seconds a good result is used without asking GitHub again */
  public const FRESH_SECONDS = 600;

  /** Seconds before a failed search is tried again */
  public const RETRY_SECONDS = 60;

  /** The most commits shown */
  public const SHOW = 10;

  /** The longest first line of a message that is kept (characters) */
  private const MAX_MESSAGE = 200;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param GitHubCommitCacheModel $cache  The cache
   * @param GitHubClient           $client The GitHub client
   */
  public function __construct(private readonly GitHubCommitCacheModel $cache, private readonly GitHubClient $client)
  {
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the commits that mention an issue key.
   *
   * @param string $repo     The normalized repository address (https://github.com/owner/repo)
   * @param string $issueKey The issue key, e.g. LD-127
   *
   * @return array{status: string, commits: list<array{sha: string, short_sha: string, message: string, date: string, url: string}>} status is ok, or unavailable when GitHub could not be asked (the commits are then the last known ones, if any)
   */
  public function forIssue(string $repo, string $issueKey): array
  {
    $parts = GitHubRepo::parts($repo);
    if ($parts === null) {
      return ['status' => 'unavailable', 'commits' => []];
    }
    $repo = GitHubRepo::BASE_URL . $parts['owner'] . '/' . $parts['repo'];

    $entry = $this->cache->get($repo, $issueKey);
    if ($entry !== null) {
      $fresh = $entry['status'] === 'ok' ? $entry['age'] < self::FRESH_SECONDS : $entry['age'] < self::RETRY_SECONDS;
      if ($fresh) {
        return $this->present($entry['status'] === 'ok' ? 'ok' : 'unavailable', $entry['commits'], $repo);
      }
    }

    $result = $this->client->searchCommits($repo, $issueKey);
    if (!$result['ok']) {
      // Keep what was known, and wait before the next try
      $known = $entry['commits'] ?? [];
      $this->cache->put($repo, $issueKey, $known, 'error');

      return $this->present('unavailable', $known, $repo);
    }

    $commits = $this->select($result['commits'], $issueKey);
    $this->cache->put($repo, $issueKey, $commits, 'ok');

    return $this->present('ok', $commits, $repo);
  }

  //---------------------------------------------------------------------------
  /**
   * Keeps the hits that really mention the key, newest first, and cuts them to what is stored.
   *
   * @param list<array{sha: string, message: string, date: string}> $hits     What GitHub found
   * @param string                                                  $issueKey The issue key
   *
   * @return list<array{sha: string, message: string, date: string}> At most SHOW commits; the message is its first line
   */
  public function select(array $hits, string $issueKey): array
  {
    $pattern = '/\b' . preg_quote($issueKey, '/') . '\b/';

    $kept = [];
    foreach ($hits as $hit) {
      $sha = strtolower($hit['sha']);
      if (!preg_match('/^[0-9a-f]{40}$/', $sha) || !mb_check_encoding($hit['message'], 'UTF-8') || !preg_match($pattern, $hit['message'])) {
        continue;
      }
      $first = trim(explode("\n", str_replace("\r", '', $hit['message']), 2)[0]);
      $kept[] = ['sha' => $sha, 'message' => mb_substr($first, 0, self::MAX_MESSAGE), 'date' => $this->isoDate($hit['date'])];
    }

    // The search sorts by date already; this keeps the order when the dates are equal or the search changes
    usort($kept, fn(array $a, array $b): int => strcmp($b['date'], $a['date']));

    return array_slice($kept, 0, self::SHOW);
  }

  //---------------------------------------------------------------------------
  /**
   * Adds what the page shows: the short SHA and the address of the commit, built here (never taken from GitHub's answer).
   *
   * @param string $status  ok or unavailable
   * @param array  $commits The stored commits
   * @param string $repo    The normalized repository address
   *
   * @return array{status: string, commits: list<array{sha: string, short_sha: string, message: string, date: string, url: string}>}
   */
  private function present(string $status, array $commits, string $repo): array
  {
    $list = [];
    foreach ($commits as $commit) {
      $sha = (string)($commit['sha'] ?? '');
      if (!preg_match('/^[0-9a-f]{40}$/', $sha)) {
        continue;
      }
      $list[] = [
        'sha' => $sha,
        'short_sha' => substr($sha, 0, 7),
        'message' => (string)($commit['message'] ?? ''),
        'date' => (string)($commit['date'] ?? ''),
        'url' => $repo . '/commit/' . $sha,
      ];
    }

    return ['status' => $status, 'commits' => $list];
  }

  //---------------------------------------------------------------------------
  /**
   * Writes a date of GitHub as ISO 8601 in UTC (empty if it cannot be read).
   *
   * @param string $date The date, e.g. 2026-11-20T10:15:30.000+01:00
   *
   * @return string
   */
  private function isoDate(string $date): string
  {
    try {
      return (new \DateTimeImmutable($date))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    } catch (\Throwable) {
      return '';
    }
  }
}
