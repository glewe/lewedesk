<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\HistoryModel;
use App\Services\IssueVisibility;

//---------------------------------------------------------------------------
/**
 * REST controller for the history of an issue.
 *
 *   GET /issues/{id}/history   the entries (?order=asc|desc, default desc)
 *
 * The permission view_issue is checked by the caller (index.php). An issue the viewer may not see is
 * answered with 404, as if it did not exist.
 */
class HistoryController
{
  private HistoryModel $model;
  private IssueVisibility $visibility;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param HistoryModel    $model      The model
   * @param IssueVisibility $visibility The visibility rules
   */
  public function __construct(HistoryModel $model, IssueVisibility $visibility)
  {
    $this->model = $model;
    $this->visibility = $visibility;
  }

  //---------------------------------------------------------------------------
  /**
   * Dispatches the request.
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
    if (!$this->model->issueVisible($issueId)) {
      $this->respond(['status' => 'error', 'message' => 'Issue not found'], 404);
      return;
    }

    $order = $_GET['order'] ?? 'desc';
    if ($order !== 'asc' && $order !== 'desc') {
      $this->respond(['status' => 'error', 'message' => 'Order must be asc or desc'], 400);
      return;
    }

    $this->respond(['status' => 'success', 'data' => $this->model->list($issueId, $this->visibility->canViewInternalComments(), $order)]);
  }

  //---------------------------------------------------------------------------
  /**
   * Sends a JSON response.
   */
  private function respond(array $payload, int $status = 200): void
  {
    http_response_code($status);
    echo json_encode($payload);
  }
}
