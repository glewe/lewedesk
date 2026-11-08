<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

//---------------------------------------------------------------------------
/**
 * Model for the per-user preferences (table user_preferences). Only the keys and values defined in
 * DEFINITIONS can be stored, so the table never holds anything the application does not understand.
 */
class PreferenceModel
{
  /**
   * The preferences: the default and the allowed values of every key.
   *   comment_order: the order of the comments on the issue page (desc: newest first)
   *   notify_own_actions: whether the user is notified about their own actions (see NotificationService)
   *   status_row_color: whether the rows of issue tables get the background color of the issue's status
   *   language: the interface language of the user (an empty value means the system default language)
   */
  public const DEFINITIONS = [
    'comment_order' => ['default' => 'desc', 'values' => ['asc', 'desc']],
    'notify_own_actions' => ['default' => 'true', 'values' => ['true', 'false']],
    'status_row_color' => ['default' => 'true', 'values' => ['true', 'false']],
    'language' => ['default' => '', 'values' => ['', 'en', 'de', 'fr', 'es']],
  ];

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
   * Gets all preferences of a user: the stored value, or the default if none is stored. A stored value
   * that is no longer allowed counts as not stored.
   *
   * @param int $userId The user id
   *
   * @return array<string, string> The value of every defined key
   */
  public function all(int $userId): array
  {
    $preferences = array_map(fn(array $definition): string => $definition['default'], self::DEFINITIONS);

    $stmt = $this->db->prepare('SELECT pref_key, pref_value FROM user_preferences WHERE user_id = :user');
    $stmt->execute(['user' => $userId]);
    foreach ($stmt->fetchAll() as $row) {
      $definition = self::DEFINITIONS[$row['pref_key']] ?? null;
      if ($definition !== null && in_array($row['pref_value'], $definition['values'], true)) {
        $preferences[$row['pref_key']] = $row['pref_value'];
      }
    }

    return $preferences;
  }

  //---------------------------------------------------------------------------
  /**
   * Stores preferences of a user. The values must have been validated with validate().
   *
   * @param int                   $userId The user id
   * @param array<string, string> $values Key and value of the preferences to store
   *
   * @return void
   */
  public function set(int $userId, array $values): void
  {
    $stmt = $this->db->prepare(
      'INSERT INTO user_preferences (user_id, pref_key, pref_value) VALUES (:user, :key, :value)
       ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value)'
    );

    $this->db->beginTransaction();
    try {
      foreach ($values as $key => $value) {
        $stmt->execute(['user' => $userId, 'key' => $key, 'value' => $value]);
      }
      $this->db->commit();
    } catch (\Throwable $e) {
      $this->db->rollBack();
      throw $e;
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Checks preferences sent by a client.
   *
   * @param mixed $input The decoded request body
   *
   * @return string|null The error message, or null if every key is known and every value allowed
   */
  public static function validate(mixed $input): ?string
  {
    if (!is_array($input) || $input === [] || array_is_list($input)) {
      return 'Preferences must be given as an object of keys and values';
    }

    foreach ($input as $key => $value) {
      $definition = self::DEFINITIONS[$key] ?? null;
      if ($definition === null) {
        return 'Unknown preference';
      }
      if (!is_string($value) || !in_array($value, $definition['values'], true)) {
        return "Invalid value for the preference $key";
      }
    }

    return null;
  }
}
