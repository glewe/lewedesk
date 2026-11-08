<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\DashboardModel;

//---------------------------------------------------------------------------
/**
 * REST controller for the dashboard.
 *
 *   GET /dashboard   the numbers, lists and chart data of the logged in user
 *
 * A login is required (checked by the caller, index.php). What the numbers include is decided by the
 * visibility rules inside the model, so a user without view_issue gets empty numbers.
 */
class DashboardController
{
  private DashboardModel $model;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param DashboardModel $model The model
   */
  public function __construct(DashboardModel $model)
  {
    $this->model = $model;
  }

  //---------------------------------------------------------------------------
  /**
   * Dispatches the request.
   *
   * @param string $method HTTP method
   * @param int    $userId The id of the logged in user
   *
   * @return void
   */
  public function handle(string $method, int $userId): void
  {
    if ($method !== 'GET') {
      http_response_code(405);
      echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
      return;
    }

    echo json_encode(['status' => 'success', 'data' => $this->model->overview($userId)]);
  }
}
