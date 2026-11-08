<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\PreferenceModel;

//---------------------------------------------------------------------------
/**
 * REST controller for the preferences of the logged in user. Everybody only reaches their own.
 *
 *   GET /preferences   all preferences ({"comment_order": "desc"})
 *   PUT /preferences   store some of them ({"comment_order": "asc"}); answers with all of them
 *
 * The login is required by the caller (index.php).
 */
class PreferenceController
{
  private PreferenceModel $model;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param PreferenceModel $model The model
   */
  public function __construct(PreferenceModel $model)
  {
    $this->model = $model;
  }

  //---------------------------------------------------------------------------
  /**
   * Dispatches the request.
   *
   * @param string $method HTTP method
   * @param int    $userId The logged in user
   *
   * @return void
   */
  public function handle(string $method, int $userId): void
  {
    if ($method === 'GET') {
      $this->respond(['status' => 'success', 'data' => $this->model->all($userId)]);
      return;
    }

    if ($method !== 'PUT') {
      $this->respond(['status' => 'error', 'message' => 'Method not allowed'], 405);
      return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $error = PreferenceModel::validate($input);
    if ($error !== null) {
      $this->respond(['status' => 'error', 'message' => $error], 400);
      return;
    }

    $this->model->set($userId, $input);
    $this->respond(['status' => 'success', 'message' => 'Preferences saved', 'data' => $this->model->all($userId)]);
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
