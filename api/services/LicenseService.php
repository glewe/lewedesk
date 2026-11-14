<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SettingsModel;
use DateTimeImmutable;

//---------------------------------------------------------------------------
/**
 * The license of this installation, checked against the LeweLic license server (native API, see
 * lewelic/_docs/API.md and _docs/LICENSE.md).
 *
 * The state is cached in the settings table (lic_*) and checked again every 30 minutes. When the license server cannot
 * be reached, the last known state stays in force (fail-open) and the next try follows after five minutes. Without a
 * license key the installation runs as a trial for 14 days.
 *
 * Statuses: active, pending, expired, blocked, invalid, unregistered, trial, trial_expired, unavailable.
 *
 * Modes (see mode()): "full" (valid license), "grace" (trial, or 14 days after the license went missing, invalid or
 * expired: everything works, administrators see a banner) and "limited" (after that, and at once for a blocked license:
 * see LicenseFeatures). The application is only locked (isLocked(), administrators only) when LICENSE_LOCK=1 is set in
 * the .env file.
 */
class LicenseService
{
  /** Seconds after which the cached state is checked again */
  public const CHECK_INTERVAL = 1800;

  /** Seconds after which a failed check is tried again */
  public const RETRY_INTERVAL = 300;

  /** Length of the trial period without a license key */
  public const TRIAL_DAYS = 14;

  /** Length of the grace period after the license became invalid or expired */
  public const GRACE_DAYS = 14;

  /** Statuses whose grace period starts with the first check that returned them (setting lic_grace_start) */
  private const GRACE_FROM_CHECK = ['invalid', 'unregistered', 'pending'];

  /** Statuses that lock the application for everybody but the administrators */
  private const LOCKED = ['expired', 'blocked', 'trial_expired'];

  /** Statuses a stored (cached) state can have */
  private const STORED = ['active', 'pending', 'expired', 'blocked', 'invalid', 'unregistered'];

  /** Timeout of a call to the license server in seconds */
  private const TIMEOUT = 5;

  /** The longest license key accepted (the server refuses longer ones) */
  public const MAX_KEY_LENGTH = 100;

  /** @var string The last problem talking to the license server ('' = none) */
  public string $lastError = '';

  /** @var array<string, string> The settings (lic_* are kept up to date by this class) */
  private array $cache;

  /** @var callable|null Replaces the HTTP call (tests): fn(string $url, string $json, array $headers): ?array{status: int, body: string} */
  private $transport;

  /**
   * Constructor.
   *
   * @param SettingsModel         $settings  The settings model (the lic_* rows are written with it)
   * @param AuditLog              $audit     The audit log
   * @param array<string, string> $env       The environment (LICENSE_SERVER, LICENSE_ITEM, LICENSE_SECRET)
   * @param array<string, string> $settingsNow The settings loaded for the request
   * @param callable|null         $transport Replaces the HTTP call (tests only)
   */
  public function __construct(
    private SettingsModel $settings,
    private AuditLog $audit,
    private array $env,
    array $settingsNow,
    ?callable $transport = null
  ) {
    $this->cache = $settingsNow;
    $this->transport = $transport;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the status of the license, checking it with the license server when the cached state is due.
   *
   * @return string One of the statuses in the class description
   */
  public function status(): string
  {
    $key = $this->key();
    if ($key === '') {
      return $this->trialStatus();
    }

    $checked = (int)($this->cache['lic_checked_at'] ?? 0);
    if ($checked === 0 || time() - $checked >= self::CHECK_INTERVAL) {
      $this->check();
    }

    $stored = $this->cache['lic_status'] ?? '';

    return in_array($stored, self::STORED, true) ? $stored : 'unavailable';
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the application is locked (expired or blocked license, or the trial is over). Only when the lock is
   * switched on in the .env file (LICENSE_LOCK=1); otherwise the limited mode applies (see mode()).
   *
   * @return bool
   */
  public function isLocked(): bool
  {
    return $this->lockEnabled() && in_array($this->status(), self::LOCKED, true);
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the lock is switched on (LICENSE_LOCK in the .env file, off by default).
   *
   * @return bool
   */
  public function lockEnabled(): bool
  {
    return in_array(strtolower(trim((string)($this->env['LICENSE_LOCK'] ?? ''))), ['1', 'true', 'yes', 'on'], true);
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the mode the installation runs in.
   *
   * @return string full (valid license), grace (trial or grace period: everything works, a banner warns the
   *                administrators) or limited (see LicenseFeatures)
   */
  public function mode(): string
  {
    return $this->phase()['mode'];
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the reason for the grace period or the limited mode.
   *
   * @return string missing, invalid, unregistered, pending, expired or blocked; '' in the full mode
   */
  public function reason(): string
  {
    return $this->phase()['reason'];
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the days left of the grace period (the trial counts as one).
   *
   * @return int 0 = ends today; also 0 outside of the grace period
   */
  public function graceDaysLeft(): int
  {
    return $this->phase()['days'];
  }

  //---------------------------------------------------------------------------
  /**
   * Works out the mode, the reason and the days left of the grace period from the status.
   *
   * @return array{mode: string, reason: string, days: int}
   */
  private function phase(): array
  {
    $status = $this->status();
    switch ($status) {
      case 'trial':
        return ['mode' => 'grace', 'reason' => 'missing', 'days' => $this->trialDaysLeft()];
      case 'trial_expired':
        return ['mode' => 'limited', 'reason' => 'missing', 'days' => 0];
      case 'blocked':
        // Blocking is done on purpose: no grace period
        return ['mode' => 'limited', 'reason' => 'blocked', 'days' => 0];
      case 'expired':
        // The 14 days run from the day after the expiry date (derived from the license data: nothing to store, cannot
        // be reset). Without an expiry date they run from the first check, like the others.
        $toExpiry = $this->daysToExpiry();
        $left = $toExpiry === null ? $this->daysSinceFirstCheck() : min(self::GRACE_DAYS, self::GRACE_DAYS + $toExpiry);
        break;
      case 'invalid':
      case 'unregistered':
      case 'pending':
        $left = $this->daysSinceFirstCheck();
        break;
      default:
        // active, and unavailable (the license server cannot be reached: fail-open)
        return ['mode' => 'full', 'reason' => '', 'days' => 0];
    }

    return ['mode' => $left >= 0 ? 'grace' : 'limited', 'reason' => $status, 'days' => max(0, $left)];
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the days left of a grace period that started with the first check that returned the status.
   *
   * @return int 1 or more while the grace period runs; -1 when it is over
   */
  private function daysSinceFirstCheck(): int
  {
    $end = $this->graceStart() + self::GRACE_DAYS * 86400;

    return time() < $end ? (int)ceil(($end - time()) / 86400) : -1;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the start of the grace period; the first call sets it (see apply(): it is cleared when the license is active).
   *
   * @return int Unix time
   */
  private function graceStart(): int
  {
    $start = (int)($this->cache['lic_grace_start'] ?? 0);
    if ($start <= 0) {
      $start = time();
      $this->store(['lic_grace_start' => (string)$start]);
    }

    return $start;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the license key.
   *
   * @return string The key, '' when none is entered
   */
  public function key(): string
  {
    return trim($this->cache['lic_key'] ?? '');
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the domain that is registered with the license server.
   *
   * @return string
   */
  public function domain(): string
  {
    return (string)($_SERVER['SERVER_NAME'] ?? 'localhost');
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the days until the license expires.
   *
   * @return int|null Days (0 = today, negative = expired); null when there is no expiry date
   */
  public function daysToExpiry(): ?int
  {
    $expiry = $this->license()['expires_at'] ?? null;
    if (!is_string($expiry) || $expiry === '') {
      return null;
    }
    try {
      $today = new DateTimeImmutable('today');
      $end = new DateTimeImmutable(substr($expiry, 0, 10));
    } catch (\Throwable) {
      return null;
    }

    return (int)$today->diff($end)->format('%r%a');
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the days left of the trial.
   *
   * @return int 0 when the trial is over; also 0 when there is none (a key is entered)
   */
  public function trialDaysLeft(): int
  {
    if ($this->key() !== '') {
      return 0;
    }
    $end = $this->trialStart() + self::TRIAL_DAYS * 86400;

    return max(0, (int)ceil(($end - time()) / 86400));
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the state that anybody may see (no key, no personal data).
   *
   * @return array{state: string, mode: string, reason: string, grace_days_left: int, locked: bool, trial_days_left: int, days_to_expiry: int|null, product: string, expiry: string|null}
   */
  public function publicState(): array
  {
    $state = $this->status();
    $license = $this->license();
    $phase = $this->phase();

    return [
      'state' => $state,
      'mode' => $phase['mode'],
      'reason' => $phase['reason'],
      'grace_days_left' => $phase['mode'] === 'grace' ? $phase['days'] : 0,
      'locked' => $this->isLocked(),
      'trial_days_left' => $state === 'trial' ? $this->trialDaysLeft() : 0,
      'days_to_expiry' => $this->daysToExpiry(),
      'product' => (string)($license['product'] ?? ($this->env['LICENSE_ITEM'] ?? 'LeweDesk')),
      'expiry' => isset($license['expires_at']) && is_string($license['expires_at']) ? $license['expires_at'] : null,
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * Gets all license data for the License tab of the settings page (administrators only).
   *
   * @return array<string, mixed>
   */
  public function details(): array
  {
    $state = $this->publicState();
    $data = $this->data();

    return $state + [
      'key' => $this->key(),
      'domain' => $this->domain(),
      'license' => $this->license() ?: null,
      'code' => (string)($data['code'] ?? ''),
      'message' => (string)($data['message'] ?? ''),
      'server_error' => (string)($data['error'] ?? ''),
      'checked_at' => (int)($this->cache['lic_checked_at'] ?? 0),
      'configured' => $this->isConfigured(),
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * Saves the license key and checks it. An empty key removes it (the trial period continues where it was).
   *
   * @param string $key The license key
   *
   * @return array{ok: bool, code: string, message: string, status: string}
   */
  public function saveKey(string $key): array
  {
    $key = trim($key);
    if (strlen($key) > self::MAX_KEY_LENGTH) {
      return $this->result(false, 'invalid_request', 'The license key is too long');
    }

    $old = $this->key();
    $this->store(['lic_key' => $key, 'lic_status' => '', 'lic_data' => '', 'lic_checked_at' => '0']);
    $this->audit->logQuiet('license.key_saved', null, 'license', null, 'License key', ['key' => self::mask($old)], ['key' => self::mask($key)]);

    if ($key === '') {
      return $this->result(true, 'key_removed', 'The license key was removed');
    }
    $this->check();

    return $this->result(true, 'key_saved', 'The license key was saved');
  }

  //---------------------------------------------------------------------------
  /**
   * Removes this domain from the license (frees the place for another installation).
   *
   * @return array{ok: bool, code: string, message: string, status: string}
   */
  public function deregister(): array
  {
    $key = $this->key();
    if ($key === '') {
      return $this->result(false, 'no_key', 'There is no license key');
    }

    $answer = $this->call('deactivate', $key);
    $body = $answer['body'] ?? null;
    $ok = is_array($body) && ($body['status'] ?? '') === 'success';
    $this->audit->logQuiet('license.deregistered', null, 'license', null, $this->domain(), null, ['key' => self::mask($key)], $ok ? 'success' : 'failure');
    // Whatever the answer: ask again, so the cached state matches the server
    $this->check();

    return $this->result($ok, (string)($body['code'] ?? 'unavailable'), (string)($body['message'] ?? $this->lastError));
  }

  //---------------------------------------------------------------------------
  /**
   * Checks the license with the license server now.
   *
   * @return array{ok: bool, code: string, message: string, status: string}
   */
  public function refresh(): array
  {
    if ($this->key() === '') {
      return $this->result(true, 'no_key', 'There is no license key');
    }
    $this->check();

    return $this->result($this->lastError === '', (string)($this->data()['code'] ?? ''), (string)($this->data()['message'] ?? $this->lastError));
  }

  //---------------------------------------------------------------------------
  /**
   * Maps the answer of the license server to a status.
   *
   * @param int        $http The HTTP status code (0 = no answer)
   * @param array|null $body The decoded JSON body (null when it was none)
   *
   * @return string A status of the license, or "unavailable" when the answer says nothing about the license
   */
  public static function classify(int $http, ?array $body): string
  {
    if ($body === null) {
      return 'unavailable';
    }

    if (($body['status'] ?? '') === 'success') {
      $license = $body['license']['status'] ?? '';
      return match ($license) {
        'active' => 'active',
        'pending' => 'pending',
        'expired' => 'expired',
        'blocked' => 'blocked',
        default => 'invalid',
      };
    }

    return match ($body['code'] ?? '') {
      'license_expired' => 'expired',
      'license_blocked' => 'blocked',
      'license_not_found', 'invalid_request' => 'invalid',
      'domain_not_activated' => 'unregistered',
      // invalid_secret, product_inactive, https_required, rate_limited, invalid_domain, ...: a problem of the
      // installation or the server, not of the license
      default => 'unavailable',
    };
  }

  //---------------------------------------------------------------------------
  /**
   * Masks a license key for the audit log.
   *
   * @param string $key The key
   *
   * @return string
   */
  public static function mask(string $key): string
  {
    if ($key === '') {
      return '';
    }

    return strlen($key) > 8 ? substr($key, 0, 4) . '...' . substr($key, -4) : AuditLog::MASK;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the connection to the license server is set up (.env).
   *
   * @return bool
   */
  private function isConfigured(): bool
  {
    return trim((string)($this->env['LICENSE_SERVER'] ?? '')) !== '' && trim((string)($this->env['LICENSE_SECRET'] ?? '')) !== '';
  }

  //---------------------------------------------------------------------------
  /**
   * Activates the license for this domain: registers the domain with the license server (a pending license becomes
   * active; when the maximum number of installations is reached, the oldest registration is replaced).
   *
   * @return array{ok: bool, code: string, message: string, status: string}
   */
  public function activate(): array
  {
    $key = $this->key();
    if ($key === '') {
      return $this->result(false, 'no_key', 'There is no license key');
    }

    $answer = $this->call('activate', $key, ['item_reference' => (string)($this->env['LICENSE_ITEM'] ?? 'LeweDesk')]);
    $body = $answer['body'] ?? null;
    $ok = is_array($body) && ($body['status'] ?? '') === 'success';
    $this->audit->logQuiet('license.activated', null, 'license', null, $this->domain(), null, ['key' => self::mask($key)], $ok ? 'success' : 'failure');
    $this->apply($answer);

    return $this->result($ok, (string)($body['code'] ?? 'unavailable'), (string)($body['message'] ?? $this->lastError));
  }

  //---------------------------------------------------------------------------
  /**
   * Asks the license server about the license and caches the answer.
   *
   * @return void
   */
  private function check(): void
  {
    $this->apply($this->call('check', $this->key()));
  }

  //---------------------------------------------------------------------------
  /**
   * Stores the answer of the license server as the new cached state. When the server cannot be reached (or the answer
   * says nothing about the license) the last known state stays and the next try is due after the retry interval.
   *
   * @param array{http: int, body: array|null, error: string} $answer The answer of call()
   *
   * @return void
   */
  private function apply(array $answer): void
  {
    $new = self::classify($answer['http'], $answer['body']);
    $data = $this->data();
    $body = $answer['body'] ?? [];

    if ($new === 'unavailable') {
      $reason = $answer['error'] !== '' ? $answer['error'] : trim((string)($body['code'] ?? '') . ' ' . (string)($body['message'] ?? ''));
      $this->lastError = $reason !== '' ? $reason : 'The license server gave no usable answer';
      // Come back after the retry interval, not on every request
      $this->store([
        'lic_checked_at' => (string)(time() - self::CHECK_INTERVAL + self::RETRY_INTERVAL),
        'lic_data' => json_encode(['error' => $this->lastError] + $data, JSON_UNESCAPED_SLASHES),
      ]);
      if (empty($data['error'])) {
        // Once per outage, not on every retry
        $this->audit->logQuiet('license.unavailable', [], 'license', null, $this->domain(), null, ['error' => $this->lastError], 'failure');
      }
      return;
    }

    $this->lastError = '';
    // An error answer carries no license data: keep what is known about the same key
    $license = is_array($body['license'] ?? null) ? $body['license'] : (($data['license']['key'] ?? null) === $this->key() ? ($data['license'] ?? []) : []);
    $old = $this->cache['lic_status'] ?? '';
    if ($new === 'active') {
      // A valid license ends the grace period; the next lapse starts a new one
      if (($this->cache['lic_grace_start'] ?? '') !== '') {
        $this->store(['lic_grace_start' => '']);
      }
    } elseif (in_array($new, self::GRACE_FROM_CHECK, true)) {
      // The grace period starts with the first check that returned the status
      $this->graceStart();
    }
    $this->store([
      'lic_status' => $new,
      'lic_checked_at' => (string)time(),
      'lic_data' => json_encode([
        'license' => $license,
        'code' => (string)($body['code'] ?? ''),
        'message' => (string)($body['message'] ?? ''),
      ], JSON_UNESCAPED_SLASHES),
    ]);

    if ($old !== $new) {
      $this->audit->logQuiet('license.status_changed', [], 'license', null, $this->domain(), ['status' => $old], ['status' => $new]);
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Calls the license server.
   *
   * @param string               $action One of activate, deactivate, check
   * @param string               $key    The license key
   * @param array<string, mixed> $extra  More body fields
   *
   * @return array{http: int, body: array|null, error: string} http is 0 and error is set when there was no answer
   */
  private function call(string $action, string $key, array $extra = []): array
  {
    $server = rtrim(trim((string)($this->env['LICENSE_SERVER'] ?? '')), '/');
    $secret = trim((string)($this->env['LICENSE_SECRET'] ?? ''));
    if ($server === '' || $secret === '') {
      return ['http' => 0, 'body' => null, 'error' => 'LICENSE_SERVER or LICENSE_SECRET is missing in the .env file'];
    }

    $json = json_encode(['license_key' => $key, 'domain' => $this->domain()] + $extra, JSON_UNESCAPED_SLASHES);
    $headers = ['Content-Type: application/json', 'Accept: application/json', 'X-Product-Secret: ' . $secret];
    $url = $server . '/api/v1/' . $action;

    $response = $this->transport !== null ? ($this->transport)($url, (string)$json, $headers) : $this->send($url, (string)$json, $headers);
    if ($response === null) {
      $reason = $this->transportError !== '' ? $this->transportError : 'The license server could not be reached';
      error_log('License: ' . $action . ' failed: ' . $reason);
      return ['http' => 0, 'body' => null, 'error' => $reason];
    }

    $body = json_decode($response['body'], true);
    if (!is_array($body)) {
      return ['http' => $response['status'], 'body' => null, 'error' => 'Unexpected answer of the license server (HTTP ' . $response['status'] . ')'];
    }

    return ['http' => $response['status'], 'body' => $body, 'error' => ''];
  }

  /** @var string The reason the last send() failed */
  private string $transportError = '';

  //---------------------------------------------------------------------------
  /**
   * Posts JSON to the license server: with cURL when it is loaded; when that is missing or fails, with PHP streams.
   *
   * @param string   $url     The URL
   * @param string   $json    The body
   * @param string[] $headers The request headers
   *
   * @return array{status: int, body: string}|null null when the server could not be reached
   */
  private function send(string $url, string $json, array $headers): ?array
  {
    $this->transportError = '';

    if (function_exists('curl_init')) {
      $curl = curl_init($url);
      curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => self::TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
      ]);
      $body = curl_exec($curl);
      $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
      $error = 'cURL error ' . curl_errno($curl) . ': ' . curl_error($curl);
      curl_close($curl);
      if ($body !== false) {
        return ['status' => $status, 'body' => (string)$body];
      }
      // Often a missing CA bundle in php.ini (typical on Windows): the streams below use the OpenSSL defaults
      $this->transportError = $error;
    }

    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
      $this->transportError = $this->transportError !== '' ? $this->transportError : 'Neither the PHP cURL extension nor allow_url_fopen is available';
      return null;
    }

    $context = stream_context_create([
      'http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $json,
        'timeout' => self::TIMEOUT,
        'ignore_errors' => true,
        'follow_location' => 0,
      ],
      'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
      if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
        $status = (int)$m[1];
      }
    }
    if ($body === false || $status === 0) {
      $this->transportError = trim($this->transportError . ' / The license server could not be reached (PHP streams)', ' /');
      return null;
    }
    $this->transportError = '';

    return ['status' => $status, 'body' => $body];
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the status without a license key: the trial period.
   *
   * @return string trial or trial_expired
   */
  private function trialStatus(): string
  {
    return time() < $this->trialStart() + self::TRIAL_DAYS * 86400 ? 'trial' : 'trial_expired';
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the start of the trial; the first call sets it.
   *
   * @return int Unix time
   */
  private function trialStart(): int
  {
    $start = (int)($this->cache['lic_trial_start'] ?? 0);
    if ($start <= 0) {
      $start = time();
      $this->store(['lic_trial_start' => (string)$start]);
    }

    return $start;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the cached answer data (license, code, message, error).
   *
   * @return array<string, mixed>
   */
  private function data(): array
  {
    $data = json_decode($this->cache['lic_data'] ?? '', true);

    return is_array($data) ? $data : [];
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the cached license details of the last answer.
   *
   * @return array<string, mixed> Empty when there are none or the key was changed since
   */
  private function license(): array
  {
    if ($this->key() === '') {
      return [];
    }
    $license = $this->data()['license'] ?? [];

    return is_array($license) ? $license : [];
  }

  //---------------------------------------------------------------------------
  /**
   * Writes lic_* settings (database and the cache of this request).
   *
   * @param array<string, string> $values The values by key
   *
   * @return void
   */
  private function store(array $values): void
  {
    foreach ($values as $name => $value) {
      try {
        $this->settings->set($name, $value);
      } catch (\Throwable $e) {
        error_log('License: ' . $name . ' could not be saved: ' . $e->getMessage());
      }
      $this->cache[$name] = $value;
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Builds the result of an action.
   *
   * @param bool   $ok      Whether it worked
   * @param string $code    A code the pages can translate
   * @param string $message The English message
   *
   * @return array{ok: bool, code: string, message: string, status: string}
   */
  private function result(bool $ok, string $code, string $message): array
  {
    return ['ok' => $ok, 'code' => $code, 'message' => $message, 'status' => $this->status()];
  }
}
