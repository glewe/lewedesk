<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

//---------------------------------------------------------------------------
/**
 * Model for the notification settings of the users (table notification_preferences): per event whether the user
 * gets an email and whether the user gets an in-app notification. Both are on for every event until the user
 * switches them off, so a user without rows gets everything.
 */
class NotificationPreferenceModel
{
  /** The events a user can choose for (see App\Services\NotificationService) */
  public const EVENTS = ['issue_created', 'issue_assigned', 'status_changed', 'comment_added', 'sla_at_risk', 'sla_breached'];

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
   * Gets the settings of one user.
   *
   * @param int $userId The user id
   *
   * @return array<string, array{email: bool, in_app: bool}> The settings of every event
   */
  public function all(int $userId): array
  {
    return $this->forUsers([$userId])[$userId];
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the settings of several users at once.
   *
   * @param int[] $userIds The user ids
   *
   * @return array<int, array<string, array{email: bool, in_app: bool}>> The settings of every event by user
   */
  public function forUsers(array $userIds): array
  {
    $result = [];
    foreach ($userIds as $userId) {
      $result[(int)$userId] = array_fill_keys(self::EVENTS, ['email' => true, 'in_app' => true]);
    }
    if ($result === []) {
      return $result;
    }

    $marks = implode(', ', array_fill(0, count($result), '?'));
    $stmt = $this->db->prepare("SELECT user_id, event, email_enabled, inapp_enabled FROM notification_preferences WHERE user_id IN ($marks)");
    $stmt->execute(array_keys($result));
    foreach ($stmt->fetchAll() as $row) {
      if (in_array($row['event'], self::EVENTS, true)) {
        $result[(int)$row['user_id']][$row['event']] = ['email' => (bool)$row['email_enabled'], 'in_app' => (bool)$row['inapp_enabled']];
      }
    }

    return $result;
  }

  //---------------------------------------------------------------------------
  /**
   * Stores settings of a user. The values must have been validated with validate().
   *
   * @param int                                               $userId   The user id
   * @param array<string, array{email?: bool, in_app?: bool}> $settings The events to change
   *
   * @return void
   */
  public function set(int $userId, array $settings): void
  {
    $current = $this->all($userId);
    $stmt = $this->db->prepare(
      'INSERT INTO notification_preferences (user_id, event, email_enabled, inapp_enabled) VALUES (:user, :event, :email, :inapp)
       ON DUPLICATE KEY UPDATE email_enabled = VALUES(email_enabled), inapp_enabled = VALUES(inapp_enabled)'
    );

    $this->db->beginTransaction();
    try {
      foreach ($settings as $event => $values) {
        $merged = $values + $current[$event];
        $stmt->execute(['user' => $userId, 'event' => $event, 'email' => $merged['email'] ? 1 : 0, 'inapp' => $merged['in_app'] ? 1 : 0]);
      }
      $this->db->commit();
    } catch (\Throwable $e) {
      $this->db->rollBack();
      throw $e;
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Checks settings sent by a client: an object of events, each an object with email and/or in_app as true or false.
   *
   * @param mixed $input The decoded request body
   *
   * @return string|null The error message, or null if the settings are valid
   */
  public static function validate(mixed $input): ?string
  {
    if (!is_array($input) || $input === [] || array_is_list($input)) {
      return 'Notification settings must be given as an object of events';
    }

    foreach ($input as $event => $values) {
      if (!in_array($event, self::EVENTS, true)) {
        return 'Unknown event';
      }
      if (!is_array($values) || $values === [] || array_is_list($values)) {
        return "The settings of the event $event must be an object";
      }
      foreach ($values as $channel => $value) {
        if (!in_array($channel, ['email', 'in_app'], true)) {
          return "Unknown channel for the event $event";
        }
        if (!is_bool($value)) {
          return "The $channel setting of the event $event must be true or false";
        }
      }
    }

    return null;
  }
}
