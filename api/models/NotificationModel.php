<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\IssueVisibility;
use App\Utils\IssueConfig;
use App\Utils\IssueKey;
use PDO;

require_once __DIR__ . "/../utils/IssueKey.php";

//---------------------------------------------------------------------------
/**
 * Model for the in-app notifications (table notifications). A user only reaches their own notifications, and only
 * those about issues they may see right now: the central visibility rules (IssueVisibility::scope()) are applied
 * when the notifications are read, so lowering the access of an issue also hides its notifications. Notifications
 * about internal comments are only shown to users holding view_internal_comments.
 */
class NotificationModel
{
  private PDO $db;
  private IssueVisibility $visibility;
  private IssueConfig $config;
  private IssueKey $keys;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param PDO             $db         The database connection
   * @param IssueVisibility $visibility The visibility rules
   * @param IssueConfig     $config     The issue settings (the issue key)
   */
  public function __construct(PDO $db, IssueVisibility $visibility, IssueConfig $config)
  {
    $this->db = $db;
    $this->visibility = $visibility;
    $this->config = $config;
    $this->keys = new IssueKey($db);
  }

  //---------------------------------------------------------------------------
  /**
   * Stores a notification for a user.
   *
   * @param int      $userId     The recipient
   * @param int      $issueId    The issue
   * @param int|null $actorId    The user who did it
   * @param string   $event      The main event
   * @param array    $data       What the message says (see App\Services\NotificationService)
   * @param bool     $isInternal Whether the message is about an internal comment
   *
   * @return int The notification id
   */
  public function insert(int $userId, int $issueId, ?int $actorId, string $event, array $data, bool $isInternal): int
  {
    $this->db->prepare('INSERT INTO notifications (user_id, issue_id, actor_id, event, data, is_internal) VALUES (:user, :issue, :actor, :event, :data, :internal)')
      ->execute([
        'user' => $userId,
        'issue' => $issueId,
        'actor' => $actorId,
        'event' => $event,
        'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
        'internal' => $isInternal ? 1 : 0,
      ]);

    return (int)$this->db->lastInsertId();
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the newest notifications of a user.
   *
   * @param int  $userId     The user
   * @param int  $limit      The maximum number of notifications
   * @param bool $unreadOnly Whether only the unread ones are returned
   *
   * @return array[] The notifications, newest first
   */
  public function list(int $userId, int $limit, bool $unreadOnly): array
  {
    [$where, $params] = $this->conditions($userId);
    if ($unreadOnly) {
      $where .= ' AND n.read_at IS NULL';
    }

    $stmt = $this->db->prepare(
      "SELECT n.id, n.issue_id, n.event, n.data, n.is_internal, n.read_at, n.created_at, i.summary,
              a.id AS actor_id, a.username AS actor_username, a.first_name AS actor_first_name, a.last_name AS actor_last_name, a.avatar AS actor_avatar
         FROM notifications n
         JOIN issues i ON i.id = n.issue_id
         LEFT JOIN users a ON a.id = n.actor_id
        WHERE $where
        ORDER BY n.created_at DESC, n.id DESC
        LIMIT :limit"
    );
    foreach ($params as $name => $value) {
      $stmt->bindValue($name, $value);
    }
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();
    // The keys of all issues in one query (present() reads them from there)
    $this->keys->forIds(array_column($rows, 'issue_id'));

    return array_map(fn(array $row): array => $this->present($row), $rows);
  }

  //---------------------------------------------------------------------------
  /**
   * Counts the unread notifications of a user.
   *
   * @param int $userId The user
   *
   * @return int
   */
  public function unreadCount(int $userId): int
  {
    [$where, $params] = $this->conditions($userId);
    $stmt = $this->db->prepare("SELECT COUNT(*) FROM notifications n JOIN issues i ON i.id = n.issue_id WHERE $where AND n.read_at IS NULL");
    $stmt->execute($params);

    return (int)$stmt->fetchColumn();
  }

  //---------------------------------------------------------------------------
  /**
   * Marks one notification of a user as read.
   *
   * @param int $userId The user
   * @param int $id     The notification id
   *
   * @return bool False if the user has no such notification
   */
  public function markRead(int $userId, int $id): bool
  {
    $stmt = $this->db->prepare('UPDATE notifications SET read_at = COALESCE(read_at, NOW()) WHERE id = :id AND user_id = :user');
    $stmt->execute(['id' => $id, 'user' => $userId]);
    if ($stmt->rowCount() > 0) {
      return true;
    }

    // Nothing changed: the notification was read already, or it is not the user's
    $exists = $this->db->prepare('SELECT COUNT(*) FROM notifications WHERE id = :id AND user_id = :user');
    $exists->execute(['id' => $id, 'user' => $userId]);

    return (int)$exists->fetchColumn() > 0;
  }

  //---------------------------------------------------------------------------
  /**
   * Marks all notifications of a user as read.
   *
   * @param int $userId The user
   *
   * @return int The number of notifications that were unread
   */
  public function markAllRead(int $userId): int
  {
    $stmt = $this->db->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = :user AND read_at IS NULL');
    $stmt->execute(['user' => $userId]);

    return $stmt->rowCount();
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes the notifications that are older than the retention period.
   *
   * @param int $days The retention period (notification_retention_days)
   *
   * @return int The number of deleted notifications
   */
  public function purgeExpired(int $days): int
  {
    $stmt = $this->db->prepare('DELETE FROM notifications WHERE created_at < NOW() - INTERVAL :days DAY');
    $stmt->bindValue('days', $days, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->rowCount();
  }

  //---------------------------------------------------------------------------
  /**
   * Builds the condition that selects the notifications a user may see: their own, about issues the viewer
   * may see, and without internal ones unless internal comments are visible to the viewer. The query needs the
   * aliases n (notifications) and i (issues).
   *
   * @param int $userId The user
   *
   * @return array{0: string, 1: array<string, int>} The condition and its parameters
   */
  private function conditions(int $userId): array
  {
    [$scope, $params] = $this->visibility->scope('i');
    $where = "n.user_id = :owner AND $scope";
    if (!$this->visibility->canViewInternalComments()) {
      $where .= ' AND n.is_internal = 0';
    }

    return [$where, $params + ['owner' => $userId]];
  }

  //---------------------------------------------------------------------------
  /**
   * Shapes a row for the API.
   *
   * @param array $row The database row
   *
   * @return array
   */
  private function present(array $row): array
  {
    $data = json_decode((string)$row['data'], true);
    $data = is_array($data) ? $data : [];
    $issueId = (int)$row['issue_id'];

    return [
      'id' => (int)$row['id'],
      'issue_id' => $issueId,
      'issue_key' => $this->keys->forId($issueId),
      'summary' => $row['summary'],
      'event' => $row['event'],
      'events' => $data['events'] ?? [$row['event']],
      'status_key' => $data['status_key'] ?? null,
      'status_name' => $data['status_name'] ?? null,
      'assignee' => $data['assignee'] ?? null,
      // The SLA events: which timer and when it is (was) due (Unix time)
      'sla_metric' => $data['sla_metric'] ?? null,
      'sla_due' => isset($data['sla_due']) ? gmdate('Y-m-d\TH:i:s\Z', (int)$data['sla_due']) : null,
      'internal' => (bool)$row['is_internal'],
      'actor' => $row['actor_id'] === null
        ? null
        : IssueModel::userFields((int)$row['actor_id'], $row['actor_username'], $row['actor_first_name'], $row['actor_last_name'], $row['actor_avatar']),
      'is_read' => $row['read_at'] !== null,
      'created_at' => $row['created_at'],
    ];
  }
}
