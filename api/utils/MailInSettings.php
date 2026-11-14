<?php

declare(strict_types=1);

namespace App\Utils;

//---------------------------------------------------------------------------
/**
 * The mailin_* system settings of Email-to-ticket (see _docs/EMAIL_TO_TICKET.md, 4.2). Values that are missing or out
 * of range fall back to the defaults, which are also the defaults in db_init.sql and src/js/defaults.js. The mailbox
 * and its credentials are not settings but MAILIN_* in .env (MailInConfig).
 */
class MailInSettings
{
  private const ACCESS = ['public', 'registered', 'reporter'];
  private const POLICIES = ['reject', 'create_user', 'anonymous'];

  /** @var array<string, mixed> */
  private array $settings;

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
   * Gives the seconds between two reads of the mailbox.
   *
   * @return int 60 to 86400, default 300
   */
  public function pollInterval(): int
  {
    return $this->integer('mailin_poll_interval', 60, 86400, 300);
  }

  /**
   * Gives the issue type of new issues.
   *
   * @return int|null Null for the first active type
   */
  public function defaultTypeId(): ?int
  {
    $id = $this->integer('mailin_default_type', 0, 999999999, 0);

    return $id > 0 ? $id : null;
  }

  /**
   * Gives the product of new issues.
   *
   * @return int|null Null for none
   */
  public function defaultProductId(): ?int
  {
    $id = $this->integer('mailin_default_product', 0, 999999999, 0);

    return $id > 0 ? $id : null;
  }

  /**
   * Gives the access value of new issues.
   *
   * @return string public, registered or reporter; default reporter
   */
  public function defaultAccess(): string
  {
    $value = (string)($this->settings['mailin_default_access'] ?? '');

    return in_array($value, self::ACCESS, true) ? $value : 'reporter';
  }

  /**
   * Gives what happens with mails of senders who are not users.
   *
   * @return string reject, create_user or anonymous; default reject
   */
  public function unknownSenderPolicy(): string
  {
    $value = (string)($this->settings['mailin_unknown_sender'] ?? '');

    return in_array($value, self::POLICIES, true) ? $value : 'reject';
  }

  /**
   * Gives the most mails that one run handles.
   *
   * @return int 1 to 200, default 20
   */
  public function maxMailsPerRun(): int
  {
    return $this->integer('mailin_max_mails_per_run', 1, 200, 20);
  }

  /**
   * Gives the most text that is taken from one mail.
   *
   * @return int Bytes (the setting is in kilobytes), default 256 KB
   */
  public function maxBodyBytes(): int
  {
    return $this->integer('mailin_max_body_size', 1, 4096, 256) * 1024;
  }

  /**
   * Gives the most mails per hour that may become issues or comments in total.
   *
   * @return int 0 for no limit, default 100
   */
  public function hourlyLimit(): int
  {
    return $this->integer('mailin_hourly_limit', 0, 10000, 100);
  }

  /**
   * Gives the sender domains that are the only ones accepted.
   *
   * @return string[] Lower case; empty = every domain
   */
  public function allowedDomains(): array
  {
    return $this->domains('mailin_allowed_domains');
  }

  /**
   * Gives the sender domains that are refused.
   *
   * @return string[] Lower case
   */
  public function blockedDomains(): array
  {
    return $this->domains('mailin_blocked_domains');
  }

  /**
   * Tells whether the sender of a new issue gets a confirmation mail.
   *
   * @return bool Default true
   */
  public function sendConfirmation(): bool
  {
    return ($this->settings['mailin_send_confirmation'] ?? 'true') !== 'false';
  }

  /**
   * Tells whether the From address of a user is trusted without a passing SPF/DKIM/DMARC result.
   *
   * @return bool Default false
   */
  public function trustSender(): bool
  {
    return ($this->settings['mailin_trust_sender'] ?? 'false') === 'true';
  }

  //---------------------------------------------------------------------------
  /**
   * Reads a whole number setting.
   *
   * @param string $key     The setting
   * @param int    $min     The lowest valid value
   * @param int    $max     The highest valid value
   * @param int    $default The value for a missing or invalid setting
   *
   * @return int
   */
  private function integer(string $key, int $min, int $max, int $default): int
  {
    $value = $this->settings[$key] ?? null;
    if (!is_numeric($value)) {
      return $default;
    }
    $number = (int)$value;

    return $number >= $min && $number <= $max ? $number : $default;
  }

  /**
   * Reads a list of domains.
   *
   * @param string $key The setting
   *
   * @return string[]
   */
  private function domains(string $key): array
  {
    $parts = preg_split('/[\s,;]+/', strtolower((string)($this->settings[$key] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    return array_values(array_unique(array_map(static fn(string $d): string => ltrim($d, '@'), $parts)));
  }
}
