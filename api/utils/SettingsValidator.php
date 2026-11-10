<?php

declare(strict_types=1);

namespace App\Utils;

use DateTimeZone;

//---------------------------------------------------------------------------
/**
 * Validates and normalizes system settings (see _docs/SYSTEM_SETTINGS.md).
 *
 * Only the known keys are accepted. All values are normalized to the strings stored in the database.
 */
class SettingsValidator
{
  private const LANGUAGES = ['en', 'de', 'fr', 'es'];
  private const COLOR_MODES = ['light', 'dark', 'auto'];
  private const FONTS = ['Default', 'Poppins', 'Roboto', 'Lato', 'Montserrat', 'Open Sans'];

  private const BOOLEAN_KEYS = [
    'alert_show_info', 'alert_show_success', 'alert_show_warning', 'alert_show_error',
    'alert_autoclose_info', 'alert_autoclose_success', 'alert_autoclose_warning', 'alert_autoclose_error',
    'matomo_analytics', 'disable_search_engines', 'disable_caching', 'debug_mode', 'under_maintenance',
    'force_2fa',
  ];

  //---------------------------------------------------------------------------
  /**
   * Validates a set of settings.
   *
   * @param array $input Key/value pairs from the request
   *
   * @return array{0: array<string, string>, 1: string[]} The normalized settings and a list of error messages
   */
  public static function validate(array $input): array
  {
    $clean = [];
    $errors = [];

    foreach ($input as $key => $raw) {
      $key = (string)$key;

      if (!self::isKnown($key)) {
        $errors[] = "Unknown setting: $key";
        continue;
      }
      if (is_array($raw) || is_object($raw) || $raw === null) {
        $errors[] = "Invalid value for $key";
        continue;
      }

      $value = is_bool($raw) ? ($raw ? 'true' : 'false') : trim((string)$raw);
      $error = self::check($key, $value);
      if ($error !== null) {
        $errors[] = "$key: $error";
        continue;
      }
      $clean[$key] = self::normalize($key, $value);
    }

    // Cross-field rules (only when both values are part of the request, e.g. from the settings page)
    if (isset($clean['attachment_max_size'], $clean['attachment_max_total_size']) && (int)$clean['attachment_max_size'] > (int)$clean['attachment_max_total_size']) {
      $errors[] = 'attachment_max_size: must not be larger than attachment_max_total_size';
    }

    return [$clean, $errors];
  }

  //---------------------------------------------------------------------------
  /**
   * Normalizes a valid value to the form stored in the database.
   *
   * @param string $key   The setting key
   * @param string $value The valid, trimmed value
   *
   * @return string
   */
  private static function normalize(string $key, string $value): string
  {
    return match ($key) {
      'issue_key_prefix' => strtoupper($value),
      // Whole numbers are stored without leading zeros ("007" -> "7")
      'attachment_max_size', 'attachment_max_count', 'attachment_max_total_size', 'rate_limit_issues',
      'rate_limit_comments', 'notification_retention_days', 'notification_poll_interval',
      'temp_upload_cleanup_hours', 'audit_retention_days', 'lic_expiry_warning' => (string)(int)$value,
      default => $value,
    };
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether a key is a known setting.
   *
   * @param string $key The setting key
   *
   * @return bool
   */
  private static function isKnown(string $key): bool
  {
    return in_array($key, self::BOOLEAN_KEYS, true) || in_array($key, [
      'application_url', 'application_name', 'application_manual', 'html_description', 'html_keywords',
      'default_language', 'alert_autoclose_delay', 'timezone', 'matomo_url', 'matomo_siteid', 'color_mode', 'font',
      'issue_key_prefix', 'support_email', 'attachment_max_size', 'attachment_max_count', 'attachment_max_total_size',
      'rate_limit_issues', 'rate_limit_comments', 'notification_retention_days', 'notification_poll_interval',
      'temp_upload_cleanup_hours', 'audit_retention_days', 'lic_expiry_warning',
      'provider_contact', 'provider_phone', 'provider_email', 'provider_website',
    ], true);
  }

  //---------------------------------------------------------------------------
  /**
   * Checks one value.
   *
   * @param string $key   The setting key
   * @param string $value The trimmed value
   *
   * @return string|null An error message or null if the value is valid
   */
  private static function check(string $key, string $value): ?string
  {
    if (in_array($key, self::BOOLEAN_KEYS, true)) {
      return in_array($value, ['true', 'false'], true) ? null : 'must be true or false';
    }

    return match ($key) {
      'application_name' => $value === '' ? 'must not be empty' : self::maxLength($value, 100),
      'application_url', 'application_manual' => $value === '' ? null : self::url($value),
      'html_description', 'html_keywords' => self::maxLength($value, 500),
      'default_language' => in_array($value, self::LANGUAGES, true) ? null : 'must be one of ' . implode(', ', self::LANGUAGES),
      'color_mode' => in_array($value, self::COLOR_MODES, true) ? null : 'must be one of ' . implode(', ', self::COLOR_MODES),
      'font' => in_array($value, self::FONTS, true) ? null : 'must be one of ' . implode(', ', self::FONTS),
      'timezone' => in_array($value, DateTimeZone::listIdentifiers(), true) ? null : 'is not a valid timezone',
      'alert_autoclose_delay' => self::integer($value, 0, 60000),
      'matomo_siteid' => self::integer($value, 1, null),
      'matomo_url' => $value === '' ? null : self::matomoUrl($value),
      'issue_key_prefix' => preg_match('/^[A-Za-z][A-Za-z0-9]{0,9}$/', $value) ? null : 'must be 1-10 characters (A-Z, 0-9) and start with a letter',
      'support_email' => strlen($value) <= 255 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? null : 'must be a valid email address',
      'attachment_max_size' => self::integer($value, 1, 50),
      'attachment_max_count' => self::integer($value, 1, 100),
      'attachment_max_total_size' => self::integer($value, 1, 500),
      'rate_limit_issues', 'rate_limit_comments' => self::integer($value, 0, 1000),
      'notification_retention_days' => self::integer($value, 1, 3650),
      'notification_poll_interval' => self::integer($value, 10, 3600),
      'temp_upload_cleanup_hours' => self::integer($value, 1, 168),
      // 0 keeps the audit log forever
      'audit_retention_days' => self::integer($value, 0, 3650),
      // Days before the license expires to warn the administrators; 0 = no warning
      'lic_expiry_warning' => self::integer($value, 0, 365),
      // Provider (controller / imprint); all four may be empty
      'provider_contact' => self::maxLength($value, 500),
      'provider_phone' => $value === '' ? null : self::phone($value),
      'provider_email' => $value === '' ? null : (strlen($value) <= 255 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? null : 'must be a valid email address'),
      'provider_website' => $value === '' ? null : self::url($value),
      default => 'is not supported',
    };
  }

  //---------------------------------------------------------------------------
  /**
   * Checks for an international phone number: a leading + and the country code, then 6 to 14 more digits
   * (E.164 allows 15 digits in total). Spaces, dots, dashes, slashes and parentheses are allowed as separators.
   */
  private static function phone(string $value): ?string
  {
    $digits = preg_replace('/\D/', '', $value);
    if (strlen($value) <= 30 && preg_match('/^\+[1-9][0-9 ().\/-]*$/', $value) && strlen($digits) >= 7 && strlen($digits) <= 15) {
      return null;
    }

    return 'must be an international phone number, e.g. +49 6105 123456';
  }

  //---------------------------------------------------------------------------
  /**
   * Checks the maximum length of a value.
   */
  private static function maxLength(string $value, int $max): ?string
  {
    return mb_strlen($value) <= $max ? null : "must not be longer than $max characters";
  }

  //---------------------------------------------------------------------------
  /**
   * Checks for an absolute http(s) URL.
   */
  private static function url(string $value): ?string
  {
    if (strlen($value) > 255) {
      return 'must not be longer than 255 characters';
    }

    return filter_var($value, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $value) ? null : 'must be a valid http(s) URL';
  }

  //---------------------------------------------------------------------------
  /**
   * Checks the Matomo URL. A scheme is optional ("mysite.com/matomo" is fine).
   */
  private static function matomoUrl(string $value): ?string
  {
    if (strlen($value) > 255) {
      return 'must not be longer than 255 characters';
    }
    $candidate = preg_match('#^https?://#i', $value) ? $value : 'https://' . $value;

    return filter_var($candidate, FILTER_VALIDATE_URL) ? null : 'must be a valid URL';
  }

  //---------------------------------------------------------------------------
  /**
   * Checks for an integer within a range.
   */
  private static function integer(string $value, int $min, ?int $max): ?string
  {
    if (!preg_match('/^\d{1,9}$/', $value)) {
      return 'must be a whole number';
    }
    $number = (int)$value;
    if ($number < $min || ($max !== null && $number > $max)) {
      return $max === null ? "must be at least $min" : "must be between $min and $max";
    }

    return null;
  }
}
