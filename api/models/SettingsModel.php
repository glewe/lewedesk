<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Settings Model for Phase 3.1.
 */
class SettingsModel
{
  private PDO $db;

  public function __construct(PDO $db)
  {
    $this->db = $db;
  }

  //---------------------------------------------------------------------------
  /**
   * Get all settings as an associative array.
   */
  public function getAll(): array
  {
    $stmt = $this->db->query("SELECT `key`, `value` FROM settings");
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $settings = [];
    foreach ($results as $row) {
      $settings[$row['key']] = $row['value'];
    }

    return $settings;
  }

  //---------------------------------------------------------------------------
  /**
   * Get a specific setting.
   */
  public function get(string $key, $default = null)
  {
    $stmt = $this->db->prepare("SELECT `value` FROM settings WHERE `key` = :key");
    $stmt->execute(['key' => $key]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    return $result ? $result['value'] : $default;
  }

  //---------------------------------------------------------------------------
  /**
   * Update or create a setting.
   */
  public function set(string $key, string $value): bool
  {
    $sql = "INSERT INTO settings (`key`, `value`) 
            VALUES (:key, :value) 
            ON DUPLICATE KEY UPDATE `value` = :value_update";

    $stmt = $this->db->prepare($sql);
    return $stmt->execute([
      'key' => $key,
      'value' => $value,
      'value_update' => $value
    ]);
  }

  //---------------------------------------------------------------------------
  /**
   * Update multiple settings.
   */
  public function setMultiple(array $settings): bool
  {
    $own = !$this->db->inTransaction();
    if ($own) {
      $this->db->beginTransaction();
    }
    try {
      foreach ($settings as $key => $value) {
        // Ensure everything is treated as string for the DB
        $val = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
        if (!$this->set($key, $val)) {
          throw new \Exception("Failed to update setting: $key");
        }
      }
      if ($own) {
        $this->db->commit();
      }
      return true;
    } catch (\Exception $e) {
      // Inside the transaction of the caller (settings change + audit entries) the caller rolls back
      if (!$own) {
        throw $e;
      }
      $this->db->rollBack();
      error_log("Settings Update Error: " . $e->getMessage());
      return false;
    }
  }
}
