<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\IssueModel;
use App\Services\IssueCommits;
use PDO;

//---------------------------------------------------------------------------
/**
 * REST controller for the commits of an issue (see docs/GITHUB.md).
 *
 *   GET /issues/{id}/commits   the commits of the GitHub repository of the issue's product that mention the issue key
 *
 * The route level (view_issue, the license feature "github") is checked by the caller (index.php). The visibility
 * rules are applied here: an issue the viewer may not see is answered with 404, like for the attachments.
 *
 * Answer: data = the commits (newest first, at most ten); meta.repo_url = the repository of the product, or null when
 * the product has none (the page then hides the panel; GitHub is not asked); meta.status = ok, or unavailable when
 * GitHub could not be asked (data then holds the last known commits, if any).
 */
class IssueCommitController
{
  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param IssueModel   $issues  The issue model (reads issues through the visibility check)
   * @param IssueCommits $commits The commits service
   * @param PDO          $db      The database connection (reads the repository of the product)
   */
  public function __construct(private readonly IssueModel $issues, private readonly IssueCommits $commits, private readonly PDO $db)
  {
  }

  //---------------------------------------------------------------------------
  /**
   * Handles a request.
   *
   * @param string $method  HTTP method
   * @param int    $issueId The issue number
   *
   * @return void
   */
  public function handle(string $method, int $issueId): void
  {
    if ($method !== 'GET') {
      $this->respond(['status' => 'error', 'message' => 'Method not allowed'], 405);
      return;
    }

    $issue = $this->issues->find($issueId);
    if ($issue === null) {
      $this->respond(['status' => 'error', 'message' => 'Issue not found'], 404);
      return;
    }

    $stmt = $this->db->prepare('SELECT github_repo FROM products WHERE id = :id');
    $stmt->execute(['id' => (int)$issue['product']['id']]);
    $repo = $stmt->fetchColumn();

    if (!is_string($repo) || $repo === '') {
      $this->respond(['status' => 'success', 'data' => [], 'meta' => ['repo_url' => null, 'count' => 0, 'status' => 'ok']]);
      return;
    }

    $result = $this->commits->forIssue($repo, (string)$issue['key']);
    $this->respond([
      'status' => 'success',
      'data' => $result['commits'],
      'meta' => ['repo_url' => $repo, 'count' => count($result['commits']), 'status' => $result['status']],
    ]);
  }

  //---------------------------------------------------------------------------
  /**
   * Sends a JSON response.
   *
   * @param array $payload The body
   * @param int   $status  The HTTP status
   *
   * @return void
   */
  private function respond(array $payload, int $status = 200): void
  {
    http_response_code($status);
    echo json_encode($payload);
  }
}
