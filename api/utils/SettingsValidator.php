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
    'force_2fa', 'allow_registration', 'mailin_send_confirmation', 'mailin_trust_sender',
  ];

  private const ACCESS_VALUES = ['public', 'registered', 'reporter'];
  private const UNKNOWN_SENDER_POLICIES = ['reject', 'create_user', 'anonymous'];

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
      'temp_upload_cleanup_hours', 'audit_retention_days', 'lic_expiry_warning', 'admin_page_size',
      'mailin_poll_interval', 'mailin_max_mails_per_run', 'mailin_max_body_size', 'mailin_hourly_limit' => (string)(int)$value,
      'mailin_default_type', 'mailin_default_product' => $value === '' ? '' : (string)(int)$value,
      'mailin_allowed_domains', 'mailin_blocked_domains' => implode(', ', self::domainList($value)),
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
      'provider_contact', 'provider_phone', 'provider_email', 'provider_website', 'admin_page_size',
      'mailin_poll_interval', 'mailin_default_type', 'mailin_default_product', 'mailin_default_access',
      'mailin_unknown_sender', 'mailin_max_mails_per_run', 'mailin_max_body_size', 'mailin_hourly_limit',
      'mailin_allowed_domains', 'mailin_blocked_domains',
      'index_brand_icon', 'index_brand_title', 'index_hero_title_left', 'index_hero_title_right', 'index_hero_subtitle',
      'index_copyright_company', 'index_copyright_url',
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
      // Rows per page of the administration (system configuration) lists
      'admin_page_size' => self::integer($value, 1, 100),
      // Provider (controller / imprint); all four may be empty
      'provider_contact' => self::maxLength($value, 500),
      'provider_phone' => $value === '' ? null : self::phone($value),
      'provider_email' => $value === '' ? null : (strlen($value) <= 255 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? null : 'must be a valid email address'),
      'provider_website' => $value === '' ? null : self::url($value),
      // Email to ticket (_docs/EMAIL_TO_TICKET.md); the credentials are in .env, not here
      'mailin_poll_interval' => self::integer($value, 60, 86400),
      // Empty = the first active issue type; whether the id exists is checked when a mail is turned into an issue
      'mailin_default_type', 'mailin_default_product' => $value === '' ? null : self::integer($value, 1, null),
      'mailin_default_access' => in_array($value, self::ACCESS_VALUES, true) ? null : 'must be one of ' . implode(', ', self::ACCESS_VALUES),
      'mailin_unknown_sender' => in_array($value, self::UNKNOWN_SENDER_POLICIES, true) ? null : 'must be one of ' . implode(', ', self::UNKNOWN_SENDER_POLICIES),
      'mailin_max_mails_per_run' => self::integer($value, 1, 200),
      // Kilobytes of text taken from one mail; the rest is cut off
      'mailin_max_body_size' => self::integer($value, 1, 4096),
      // Mails per hour that may become issues or comments in total; 0 = no limit
      'mailin_hourly_limit' => self::integer($value, 0, 10000),
      'mailin_allowed_domains', 'mailin_blocked_domains' => self::domains($value),
      // Index page (docs/INDEX_PAGE.md); all of them may be empty = the default look
      'index_brand_icon' => $value === '' || preg_match('/^bi-[a-z0-9-]{1,60}$/', $value) ? null : 'must be a Bootstrap Icons class such as bi-headset',
      'index_brand_title' => self::maxLength($value, 50),
      'index_hero_title_left', 'index_hero_title_right', 'index_copyright_company' => self::maxLength($value, 100),
      'index_hero_subtitle' => self::maxLength($value, 300),
      'index_copyright_url' => $value === '' ? null : self::url($value),
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
   * Splits a list of domains (separated by comma, semicolon, space or line break) and lower-cases it.
   *
   * @param string $value The list
   *
   * @return string[] The domains without duplicates, as typed otherwise
   */
  private static function domainList(string $value): array
  {
    $parts = preg_split('/[\s,;]+/', strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    return array_values(array_unique(array_map(static fn(string $d): string => ltrim($d, '@'), $parts)));
  }

  //---------------------------------------------------------------------------
  /**
   * Checks a list of domains, e.g. "example.com, partner.org".
   *
   * @param string $value The list; empty is valid
   *
   * @return string|null An error message or null if the list is valid
   */
  private static function domains(string $value): ?string
  {
    if (strlen($value) > 2000) {
      return 'must not be longer than 2000 characters';
    }
    foreach (self::domainList($value) as $domain) {
      if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
        return "contains an invalid domain: $domain";
      }
    }

    return null;
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
