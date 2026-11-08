<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\NotificationModel;
use App\Models\NotificationPreferenceModel;
use App\Utils\IssueConfig;

//---------------------------------------------------------------------------
/**
 * REST controller for the notifications of the logged in user. Everybody only reaches their own.
 *
 *   GET /notifications                    the newest notifications (?limit=1..50, default 10; ?unread=1 only the unread ones),
 *                                         with the number of unread ones and the poll interval in "meta"
 *   POST /notifications/read              marks all notifications as read
 *   POST /notifications/{id}/read         marks one notification as read
 *   GET /notifications/preferences        the settings per event: {"issue_created": {"email": true, "in_app": true}, ...}
 *   PUT /notifications/preferences        changes some of them ({"status_changed": {"email": false}})
 *
 * The login is required by the caller (index.php). The notifications are deleted by the housekeeping after
 * notification_retention_days (see App\Services\NotificationService::purge()).
 */
class NotificationController
{
  private const DEFAULT_LIMIT = 10;
  private const MAX_LIMIT = 50;

  private NotificationModel $model;
  private NotificationPreferenceModel $preferences;
  private IssueConfig $config;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param NotificationModel           $model       The notification model
   * @param NotificationPreferenceModel $preferences The notification settings model
   * @param IssueConfig                 $config      The issue settings (the poll interval)
   */
  public function __construct(NotificationModel $model, NotificationPreferenceModel $preferences, IssueConfig $config)
  {
    $this->model = $model;
    $this->preferences = $preferences;
    $this->config = $config;
  }

  //---------------------------------------------------------------------------
  /**
   * Dispatches the request.
   *
   * @param string      $method HTTP method
   * @param int         $userId The logged in user
   * @param string|null $id     The path segment after /notifications ("12", "read", "preferences" or null)
   * @param string|null $action The path segment after the id ("read" or null)
   *
   * @return void
   */
  public function handle(string $method, int $userId, ?string $id, ?string $action): void
  {
    $route = match (true) {
      $id === null && $action === null => 'list',
      $id === 'read' && $action === null => 'read_all',
      $id === 'preferences' && $action === null => 'preferences',
      $id !== null && ctype_digit($id) && $action === 'read' => 'read_one',
      default => null,
    };

    match ([$route, $method]) {
      ['list', 'GET'] => $this->list($userId),
      ['read_all', 'POST'] => $this->readAll($userId),
      ['read_one', 'POST'] => $this->readOne($userId, (int)$id),
      ['preferences', 'GET'] => $this->respond(['status' => 'success', 'data' => $this->preferences->all($userId)]),
      ['preferences', 'PUT'] => $this->savePreferences($userId),
      default => $route === null ? $this->fail(404, 'Endpoint not found') : $this->fail(405, 'Method not allowed'),
    };
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the newest notifications.
   *
   * @param int $userId The logged in user
   *
   * @return void
   */
  private function list(int $userId): void
  {
    $limit = $_GET['limit'] ?? self::DEFAULT_LIMIT;
    if (!is_string($limit) && !is_int($limit) || !preg_match('/^[1-9]\d{0,2}$/', (string)$limit) || (int)$limit > self::MAX_LIMIT) {
      $this->fail(400, 'Limit must be a number from 1 to ' . self::MAX_LIMIT);
      return;
    }

    $this->respond([
      'status' => 'success',
      'data' => $this->model->list($userId, (int)$limit, ($_GET['unread'] ?? '') === '1'),
      'meta' => [
        'unread_count' => $this->model->unreadCount($userId),
        'poll_interval' => $this->config->notificationPollInterval(),
      ],
    ]);
  }

  //---------------------------------------------------------------------------
  /**
   * Marks all notifications as read.
   *
   * @param int $userId The logged in user
   *
   * @return void
   */
  private function readAll(int $userId): void
  {
    $this->model->markAllRead($userId);
    $this->respond(['status' => 'success', 'message' => 'Notifications marked as read', 'meta' => ['unread_count' => $this->model->unreadCount($userId)]]);
  }

  //---------------------------------------------------------------------------
  /**
   * Marks one notification as read.
   *
   * @param int $userId The logged in user
   * @param int $id     The notification id
   *
   * @return void
   */
  private function readOne(int $userId, int $id): void
  {
    if (!$this->model->markRead($userId, $id)) {
      $this->fail(404, 'Notification not found');
      return;
    }
    $this->respond(['status' => 'success', 'message' => 'Notification marked as read', 'meta' => ['unread_count' => $this->model->unreadCount($userId)]]);
  }

  //---------------------------------------------------------------------------
  /**
   * Stores notification settings.
   *
   * @param int $userId The logged in user
   *
   * @return void
   */
  private function savePreferences(int $userId): void
  {
    $input = json_decode(file_get_contents('php://input'), true);
    $error = NotificationPreferenceModel::validate($input);
    if ($error !== null) {
      $this->fail(400, $error);
      return;
    }

    $this->preferences->set($userId, $input);
    $this->respond(['status' => 'success', 'message' => 'Notification settings saved', 'data' => $this->preferences->all($userId)]);
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

  //---------------------------------------------------------------------------
  /**
   * Sends a JSON error response.
   */
  private function fail(int $status, string $message): void
  {
    $this->respond(['status' => 'error', 'message' => $message], $status);
  }
}
