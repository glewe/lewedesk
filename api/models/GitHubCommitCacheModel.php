<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

//---------------------------------------------------------------------------
/**
 * The cache of the commits GitHub found for an issue key (see docs/GITHUB.md). One row per repository and issue key;
 * `commits` holds the list as JSON, `status` is 'error' for a search that failed. The age of a row is judged by the
 * caller; rows older than a day are deleted by purge() (the housekeeping).
 */
class GitHubCommitCacheModel
{
  private PDO $db;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param PDO $db The database connection
   */
  public function __construct(PDO $db)
  {
    $this->db = $db;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets a cached entry.
   *
   * @param string $repo     The normalized repository address
   * @param string $issueKey The issue key, e.g. LD-127
   *
   * @return array{commits: array, status: string, age: int}|null The commits, the status and the age in seconds; null if there is no entry
   */
  public function get(string $repo, string $issueKey): ?array
  {
    $stmt = $this->db->prepare(
      'SELECT commits, status, TIMESTAMPDIFF(SECOND, fetched_at, NOW()) AS age FROM github_commit_cache WHERE repo = :repo AND issue_key = :issue_key'
    );
    $stmt->execute(['repo' => $repo, 'issue_key' => $issueKey]);
    $row = $stmt->fetch();
    if (!$row) {
      return null;
    }

    $commits = json_decode((string)$row['commits'], true);

    return ['commits' => is_array($commits) ? $commits : [], 'status' => (string)$row['status'], 'age' => max(0, (int)$row['age'])];
  }

  //---------------------------------------------------------------------------
  /**
   * Stores an entry (replaces the one of the same repository and issue key) and sets its age to zero.
   *
   * @param string $repo     The normalized repository address
   * @param string $issueKey The issue key
   * @param array  $commits  The commits to keep (for a failed search: the last good list, or an empty one)
   * @param string $status   ok or error
   *
   * @return void
   */
  public function put(string $repo, string $issueKey, array $commits, string $status = 'ok'): void
  {
    $stmt = $this->db->prepare(
      'INSERT INTO github_commit_cache (repo, issue_key, commits, status, fetched_at) VALUES (:repo, :issue_key, :commits, :status, NOW())
       ON DUPLICATE KEY UPDATE commits = VALUES(commits), status = VALUES(status), fetched_at = VALUES(fetched_at)'
    );
    $stmt->execute([
      'repo' => $repo,
      'issue_key' => $issueKey,
      'commits' => json_encode(array_values($commits), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
      'status' => $status === 'error' ? 'error' : 'ok',
    ]);
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes the entries that are older than a day.
   *
   * @return int The number of deleted rows
   */
  public function purge(): int
  {
    return $this->db->exec('DELETE FROM github_commit_cache WHERE fetched_at < NOW() - INTERVAL 1 DAY') ?: 0;
  }
}
