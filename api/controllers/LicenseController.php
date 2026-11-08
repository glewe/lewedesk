<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\LicenseService;

//---------------------------------------------------------------------------
/**
 * REST controller for the license (see _docs/LICENSE.md).
 *
 *   GET  /license             all license data (key, status, details)        manage_settings
 *   GET  /license/public      the state without the key                      nobody needs to be logged in
 *   POST /license/key         {"key": "..."} saves the key and checks it     manage_settings
 *   POST /license/activate    activates the license and registers this domain manage_settings
 *   POST /license/deregister  removes this domain from the license           manage_settings
 *   POST /license/refresh     checks the license now                         manage_settings
 *
 * The permission is checked by the caller (index.php); this class only knows the routes.
 */
class LicenseController
{
  private LicenseService $license;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param LicenseService $license The license service
   */
  public function __construct(LicenseService $license)
  {
    $this->license = $license;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether a route needs no login.
   *
   * @param string|null $action The part after /license ('' or null for the license itself)
   *
   * @return bool
   */
  public static function isPublic(?string $action): bool
  {
    return $action === 'public';
  }

  //---------------------------------------------------------------------------
  /**
   * Dispatches the request.
   *
   * @param string      $method HTTP method
   * @param string|null $action The part after /license
   *
   * @return void
   */
  public function handle(string $method, ?string $action): void
  {
    if ($action === null || $action === '' || $action === 'public') {
      if ($method !== 'GET') {
        $this->respond(['status' => 'error', 'message' => 'Method not allowed'], 405);
        return;
      }
      $this->respond(['status' => 'success', 'data' => $action === 'public' ? $this->license->publicState() : $this->license->details()]);
      return;
    }

    if ($method !== 'POST') {
      $this->respond(['status' => 'error', 'message' => 'Method not allowed'], 405);
      return;
    }

    $result = match ($action) {
      'key' => $this->saveKey(),
      'activate' => $this->license->activate(),
      'deregister' => $this->license->deregister(),
      'refresh' => $this->license->refresh(),
      default => null,
    };
    if ($result === null) {
      $this->respond(['status' => 'error', 'message' => 'Not found'], 404);
      return;
    }

    // 502: the license server could not be asked; 422: it was asked and said no
    $http = $result['ok'] ? 200 : ($result['code'] === 'unavailable' ? 502 : 422);
    $this->respond([
      'status' => $result['ok'] ? 'success' : 'error',
      'code' => $result['code'],
      'message' => $result['message'],
      'data' => $this->license->details(),
    ], $http);
  }

  //---------------------------------------------------------------------------
  /**
   * Saves the license key from the request body.
   *
   * @return array{ok: bool, code: string, message: string, status: string}
   */
  private function saveKey(): array
  {
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input) || !array_key_exists('key', $input) || !is_string($input['key'])) {
      return ['ok' => false, 'code' => 'invalid_request', 'message' => 'The license key is missing', 'status' => $this->license->status()];
    }

    return $this->license->saveKey($input['key']);
  }

  //---------------------------------------------------------------------------
  /**
   * Sends a JSON answer.
   *
   * @param array<string, mixed> $body   The answer
   * @param int                  $status HTTP status code
   *
   * @return void
   */
  private function respond(array $body, int $status = 200): void
  {
    http_response_code($status);
    echo json_encode($body);
  }
}
