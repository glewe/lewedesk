<?php

declare(strict_types=1);

namespace App\Services;

require_once __DIR__ . '/../utils/GitHubRepo.php';

use App\Utils\GitHubRepo;

//---------------------------------------------------------------------------
/**
 * A small client for the public GitHub API (see docs/GITHUB.md). GET only, no token, host fixed to api.github.com.
 *
 * The owner and the repository are validated by GitHubRepo before they enter a URL, and redirects are not followed.
 * Nothing here throws for a problem of the network or of GitHub: the methods answer with a status the caller can show.
 *
 * The transport is injectable (for the tests): fn(string $url, string[] $headers): ?array{status: int, headers: array<string, string>, body: string},
 * where the header names are lower case and null means that GitHub could not be reached.
 */
class GitHubClient
{
  /** The API host; the only one this client talks to */
  private const API = 'https://api.github.com';

  /** Seconds to wait for GitHub */
  private const TIMEOUT = 5;

  /** The most hits one search asks for (GitHub allows 100) */
  public const SEARCH_LIMIT = 30;

  /** @var callable */
  private $transport;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param callable|null $transport Replaces the real HTTP call (tests)
   */
  public function __construct(?callable $transport = null)
  {
    $this->transport = $transport ?? [$this, 'send'];
  }

  //---------------------------------------------------------------------------
  /**
   * Checks that a public repository exists.
   *
   * @param string $repo The repository, in any form GitHubRepo understands
   *
   * @return string exists, missing (not found, or private), or unavailable (GitHub could not be asked: network, rate limit, error)
   */
  public function repoStatus(string $repo): string
  {
    $parts = GitHubRepo::parts($repo);
    if ($parts === null) {
      return 'missing';
    }

    $answer = $this->get('/repos/' . rawurlencode($parts['owner']) . '/' . rawurlencode($parts['repo']));
    if ($answer === null) {
      return 'unavailable';
    }

    return match (true) {
      $answer['status'] === 200 => 'exists',
      $answer['status'] === 404 || $answer['status'] === 301 => 'missing',
      default => 'unavailable',
    };
  }

  //---------------------------------------------------------------------------
  /**
   * Searches the commits of the default branch of a repository whose message contains a text (newest first). The search
   * is a fuzzy text search: the caller has to check the hits.
   *
   * @param string $repo The repository, in any form GitHubRepo understands
   * @param string $text The text, e.g. an issue key like LD-127
   *
   * @return array{ok: bool, reason: string|null, commits: list<array{sha: string, message: string, date: string}>} reason is rate_limit, unavailable or null
   */
  public function searchCommits(string $repo, string $text): array
  {
    $failed = fn(string $reason): array => ['ok' => false, 'reason' => $reason, 'commits' => []];

    $parts = GitHubRepo::parts($repo);
    if ($parts === null || preg_match('/[\x00-\x1F"\\\\]/', $text)) {
      return $failed('unavailable');
    }

    $query = 'repo:' . $parts['owner'] . '/' . $parts['repo'] . ' "' . $text . '"';
    $answer = $this->get('/search/commits?q=' . rawurlencode($query) . '&sort=committer-date&order=desc&per_page=' . self::SEARCH_LIMIT);
    if ($answer === null) {
      return $failed('unavailable');
    }
    if ($this->rateLimited($answer)) {
      return $failed('rate_limit');
    }

    $data = $answer['status'] === 200 ? json_decode($answer['body'], true) : null;
    if (!is_array($data) || !is_array($data['items'] ?? null)) {
      return $failed('unavailable');
    }

    $commits = [];
    foreach ($data['items'] as $item) {
      $sha = is_array($item) ? ($item['sha'] ?? null) : null;
      $commit = is_array($item) ? ($item['commit'] ?? null) : null;
      if (!is_string($sha) || !is_array($commit) || !is_string($commit['message'] ?? null)) {
        continue;
      }
      $date = $commit['committer']['date'] ?? $commit['author']['date'] ?? '';
      $commits[] = ['sha' => $sha, 'message' => $commit['message'], 'date' => is_string($date) ? $date : ''];
    }

    return ['ok' => true, 'reason' => null, 'commits' => $commits];
  }

  //---------------------------------------------------------------------------
  /**
   * Calls the API.
   *
   * @param string $path The path with the query, starting with a slash
   *
   * @return array{status: int, headers: array<string, string>, body: string}|null Null if GitHub could not be reached
   */
  private function get(string $path): ?array
  {
    $headers = [
      'Accept: application/vnd.github+json',
      'X-GitHub-Api-Version: 2022-11-28',
      // GitHub refuses requests without a User-Agent; no details about the installation are sent
      'User-Agent: LeweDesk',
    ];

    return ($this->transport)(self::API . $path, $headers);
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether GitHub refused because the rate limit is used up.
   *
   * @param array{status: int, headers: array<string, string>, body: string} $answer The answer
   *
   * @return bool
   */
  private function rateLimited(array $answer): bool
  {
    if ($answer['status'] === 429) {
      return true;
    }

    return $answer['status'] === 403
      && (($answer['headers']['x-ratelimit-remaining'] ?? null) === '0' || stripos($answer['body'], 'rate limit') !== false);
  }

  //---------------------------------------------------------------------------
  /**
   * Does the HTTP call: with cURL when it is loaded; when that is missing or fails, with PHP streams.
   *
   * @param string   $url     The URL
   * @param string[] $headers The request headers
   *
   * @return array{status: int, headers: array<string, string>, body: string}|null Null if GitHub could not be reached
   */
  private function send(string $url, array $headers): ?array
  {
    if (function_exists('curl_init')) {
      $received = [];
      $curl = curl_init($url);
      curl_setopt_array($curl, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => self::TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$received): int {
          $pair = explode(':', $line, 2);
          if (count($pair) === 2) {
            $received[strtolower(trim($pair[0]))] = trim($pair[1]);
          }

          return strlen($line);
        },
      ]);
      $body = curl_exec($curl);
      $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
      curl_close($curl);
      if ($body !== false) {
        return ['status' => $status, 'headers' => $received, 'body' => (string)$body];
      }
      // Often a missing CA bundle in php.ini (typical on Windows): the streams below use the OpenSSL defaults
    }

    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
      return null;
    }

    $context = stream_context_create([
      'http' => [
        'method' => 'GET',
        'header' => implode("\r\n", $headers),
        'timeout' => self::TIMEOUT,
        'ignore_errors' => true,
        'follow_location' => 0,
      ],
      'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    $received = [];
    foreach ($http_response_header ?? [] as $line) {
      if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
        $status = (int)$m[1];
      } elseif (str_contains($line, ':')) {
        [$name, $value] = explode(':', $line, 2);
        $received[strtolower(trim($name))] = trim($value);
      }
    }

    return $body === false || $status === 0 ? null : ['status' => $status, 'headers' => $received, 'body' => $body];
  }
}
