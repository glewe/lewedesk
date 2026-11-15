<?php

declare(strict_types=1);

namespace App\Utils;

//---------------------------------------------------------------------------
/**
 * Typed access to the "Issue Config" system settings (see _docs/SYSTEM_SETTINGS.md).
 *
 * All issue code reads these settings through this class so that defaults and unit conversions
 * live in one place. A setting that is missing or invalid falls back to its default.
 * Keep the defaults in sync with db_init.sql and src/js/defaults.js.
 */
class IssueConfig
{
  public const DEFAULTS = [
    'default_product_id' => 0,
    'support_email' => 'support@lewe.com',
    'attachment_max_size' => 2,
    'attachment_max_count' => 10,
    'attachment_max_total_size' => 10,
    'rate_limit_issues' => 10,
    'rate_limit_comments' => 30,
    'notification_retention_days' => 90,
    'notification_poll_interval' => 60,
    'temp_upload_cleanup_hours' => 24,
    'audit_retention_days' => 365,
  ];

  private const BYTES_PER_MB = 1048576;

  /** @var array<string, mixed> */
  private array $settings;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param array<string, mixed> $settings All system settings as returned by SettingsModel::getAll()
   */
  public function __construct(array $settings)
  {
    $this->settings = $settings;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the id of the default product: the product of issues that are created without one (Email to ticket).
   * The key of an issue is built by App\Utils\IssueKey.
   *
   * @return int 0 if the setting is missing or invalid
   */
  public function defaultProductId(): int
  {
    $value = $this->settings['default_product_id'] ?? null;

    return is_string($value) && preg_match('/^[1-9]\d{0,8}$/', $value) === 1 ? (int)$value : self::DEFAULTS['default_product_id'];
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the email address of the support team.
   *
   * @return string
   */
  public function supportEmail(): string
  {
    $value = trim((string)($this->settings['support_email'] ?? ''));

    return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : self::DEFAULTS['support_email'];
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the maximum size of one attachment.
   *
   * @return int Bytes
   */
  public function attachmentMaxSize(): int
  {
    return $this->integer('attachment_max_size', 1, 50) * self::BYTES_PER_MB;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the maximum number of attachments per issue.
   *
   * @return int
   */
  public function attachmentMaxCount(): int
  {
    return $this->integer('attachment_max_count', 1, 100);
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the maximum total size of all attachments of an issue.
   *
   * @return int Bytes
   */
  public function attachmentMaxTotalSize(): int
  {
    return $this->integer('attachment_max_total_size', 1, 500) * self::BYTES_PER_MB;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the number of issues a user may create per hour.
   *
   * @return int 0 = no limit
   */
  public function rateLimitIssues(): int
  {
    return $this->integer('rate_limit_issues', 0, 1000);
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the number of comments a user may add per hour.
   *
   * @return int 0 = no limit
   */
  public function rateLimitComments(): int
  {
    return $this->integer('rate_limit_comments', 0, 1000);
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the number of days after which in-app notifications are deleted.
   *
   * @return int
   */
  public function notificationRetentionDays(): int
  {
    return $this->integer('notification_retention_days', 1, 3650);
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the number of days after which audit log entries are deleted.
   *
   * @return int Days; 0 keeps the entries forever
   */
  public function auditRetentionDays(): int
  {
    return $this->integer('audit_retention_days', 0, 3650);
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the interval in which the browser checks for new notifications.
   *
   * @return int Seconds
   */
  public function notificationPollInterval(): int
  {
    return $this->integer('notification_poll_interval', 10, 3600);
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the number of hours after which unused temporary uploads are deleted.
   *
   * @return int
   */
  public function tempUploadCleanupHours(): int
  {
    return $this->integer('temp_upload_cleanup_hours', 1, 168);
  }

  //---------------------------------------------------------------------------
  /**
   * Reads a whole number setting within a range, or its default.
   *
   * @param string $key The setting key
   * @param int    $min Smallest valid value
   * @param int    $max Largest valid value
   *
   * @return int
   */
  private function integer(string $key, int $min, int $max): int
  {
    $value = $this->settings[$key] ?? null;
    if (is_string($value) && preg_match('/^\d{1,9}$/', $value)) {
      $number = (int)$value;
      if ($number >= $min && $number <= $max) {
        return $number;
      }
    }

    return self::DEFAULTS[$key];
  }
}
