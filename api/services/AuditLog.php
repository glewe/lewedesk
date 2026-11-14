<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

//---------------------------------------------------------------------------
/**
 * Writes the audit log (table audit_log): who did what, when and from where. See _docs/AUDITLOG.md.
 *
 * Successful changes are logged with log() by the models inside the transaction of the change, so a change and its
 * entry stand or fall together: when the insert fails, the exception goes up and the change is rolled back. Attempts
 * that did not happen (wrong password, denied access) are logged with logFailure(), outside of any transaction, and
 * never throw: a logging problem must not turn a 401 or 403 into a 500.
 *
 * What is stored is limited by design: the IP address is masked (the last octet of an IPv4 address is zeroed, an IPv6
 * address is cut to its first 48 bits), sensitive values (passwords, secrets, tokens) are replaced by "***", and a
 * change holds only the fields that changed. Comment texts and TOTP secrets are never passed in.
 *
 * Event keys are "subject.action", e.g. "auth.login" or "role.updated". The category (auth, user, config, data,
 * security, audit) follows from the subject, see category().
 */
class AuditLog
{
  /** The longest text of a single logged value, in characters */
  private const MAX_VALUE_LENGTH = 2000;

  /** The size the JSON of old_value or new_value may reach (a TEXT column holds 65535 bytes) */
  private const MAX_JSON_BYTES = 60000;

  /** Keys whose values are never stored (case insensitive, matched anywhere in the key) */
  private const SENSITIVE_KEYS = '/password|passwd|secret|token|api[_-]?key|private[_-]?key|smtp_pass|twofa_/i';

  /** The category of the events by their subject (the part of the event key before the dot); anything else is "other" */
  private const CATEGORIES = [
    'auth' => 'auth',
    'user' => 'user',
    'setting' => 'config', 'license' => 'config', 'role' => 'config', 'group' => 'config', 'permission' => 'config', 'issue_type' => 'config',
    'status' => 'config', 'status_category' => 'config', 'resolution' => 'config', 'priority' => 'config', 'product' => 'config', 'tag' => 'config',
    'sla_policy' => 'config', 'sla_calendar' => 'config', 'sla_holiday' => 'config', 'sla_timer' => 'data',
    'issue' => 'data', 'attachment' => 'data', 'customview' => 'data', 'housekeeping' => 'config',
    'security' => 'security',
    'audit' => 'audit',
    'mail' => 'mail',
  ];

  /** The mask that replaces a sensitive value */
  public const MASK = '***';

  /** Rows the purge deletes per statement, and the statements per call (the rest follows with the next housekeeping run) */
  private const PURGE_BATCH = 1000;
  private const PURGE_MAX_BATCHES = 100;

  private PDO $db;
  private ?AuthService $auth;

  /** The id of this request, shared by all its entries */
  private ?string $requestId = null;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param PDO              $db   The database connection
   * @param AuthService|null $auth The authentication service, to find the acting user when none is given
   */
  public function __construct(PDO $db, ?AuthService $auth = null)
  {
    $this->db = $db;
    $this->auth = $auth;
  }

  //---------------------------------------------------------------------------
  /**
   * Adds an entry. Call it inside the transaction of the change it records; an error is not caught.
   *
   * @param string      $event       The event key, e.g. "user.updated"
   * @param array|null  $actor       The acting user (id, username); null = the logged in user of the request, if any
   *                                 (no login: anonymous); [] = explicitly nobody (system, anonymous)
   * @param string|null $targetType  What the event is about, e.g. "user" or "setting"
   * @param int|null    $targetId    The id of the target
   * @param string|null $targetLabel The name of the target at the time (kept when the target is deleted)
   * @param mixed       $old         The value(s) before the change (array of field => value, or a scalar)
   * @param mixed       $new         The value(s) after the change
   * @param string      $outcome     success or failure
   *
   * @return void
   */
  public function log(
    string $event,
    ?array $actor = null,
    ?string $targetType = null,
    ?int $targetId = null,
    ?string $targetLabel = null,
    mixed $old = null,
    mixed $new = null,
    string $outcome = 'success'
  ): void {
    $actor ??= $this->auth?->currentUser() ?? [];
    $username = isset($actor['username']) ? mb_substr((string)$actor['username'], 0, 50) : null;
    $label = $targetLabel !== null ? mb_substr($targetLabel, 0, 255) : null;

    $this->db->prepare(
      'INSERT INTO audit_log (actor_id, actor_username, event, category, outcome, target_type, target_id, target_label,
                              old_value, new_value, ip, user_agent, request_id)
       VALUES (:actor, :username, :event, :category, :outcome, :type, :target, :label, :old, :new, :ip, :agent, :request)'
    )->execute([
      'actor' => isset($actor['id']) ? (int)$actor['id'] : null,
      'username' => $username,
      'event' => mb_substr($event, 0, 50),
      'category' => self::category($event),
      'outcome' => $outcome === 'failure' ? 'failure' : 'success',
      'type' => $targetType,
      'target' => $targetId,
      'label' => $label,
      'old' => self::encode($old),
      'new' => self::encode($new),
      'ip' => self::maskIp((string)($_SERVER['REMOTE_ADDR'] ?? '')),
      'agent' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
      'request' => $this->requestId(),
    ]);
  }

  //---------------------------------------------------------------------------
  /**
   * Runs a change and its audit entries in one transaction: when something fails, none of it is kept. When a transaction
   * is active already (the caller's, or one of the model it calls), the code simply runs inside it.
   *
   * @param callable $change The change; it calls log() for its entries
   *
   * @return mixed What the change returns
   */
  public function transaction(callable $change): mixed
  {
    if ($this->db->inTransaction()) {
      return $change();
    }

    $this->db->beginTransaction();
    try {
      $result = $change();
      $this->db->commit();
      return $result;
    } catch (\Throwable $e) {
      if ($this->db->inTransaction()) {
        $this->db->rollBack();
      }
      throw $e;
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Adds an entry that is not part of a database change (a login, a logout, a mail that was sent) and never throws:
   * when the entry cannot be written, the problem goes to the PHP error log and the request carries on.
   *
   * @param string      $event       The event key, e.g. "auth.login"
   * @param array|null  $actor       The acting user (see log())
   * @param string|null $targetType  What the event is about
   * @param int|null    $targetId    The id of the target
   * @param string|null $targetLabel The name of the target
   * @param mixed       $old         Value(s) before
   * @param mixed       $new         Value(s) after
   * @param string      $outcome     success or failure
   *
   * @return void
   */
  public function logQuiet(
    string $event,
    ?array $actor = null,
    ?string $targetType = null,
    ?int $targetId = null,
    ?string $targetLabel = null,
    mixed $old = null,
    mixed $new = null,
    string $outcome = 'success'
  ): void {
    try {
      $this->log($event, $actor, $targetType, $targetId, $targetLabel, $old, $new, $outcome);
    } catch (\Throwable $e) {
      error_log('Audit log entry could not be written (' . $event . '): ' . $e->getMessage());
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Adds an entry for an attempt that did not happen (wrong password, denied access, locked account). Like logQuiet(),
   * it never throws.
   *
   * @param string      $event       The event key, e.g. "auth.login_failed"
   * @param array|null  $actor       The user the attempt was made as or by (see log())
   * @param string|null $targetType  What the attempt was about
   * @param int|null    $targetId    The id of the target
   * @param string|null $targetLabel The name of the target
   * @param mixed       $old         Value(s) before
   * @param mixed       $new         Value(s) of the attempt
   *
   * @return void
   */
  public function logFailure(
    string $event,
    ?array $actor = null,
    ?string $targetType = null,
    ?int $targetId = null,
    ?string $targetLabel = null,
    mixed $old = null,
    mixed $new = null
  ): void {
    $this->logQuiet($event, $actor, $targetType, $targetId, $targetLabel, $old, $new, 'failure');
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the category of an event (auth, user, config, data, security, audit) from the subject of its key.
   *
   * @param string $event The event key, e.g. "role.updated"
   *
   * @return string
   */
  public static function category(string $event): string
  {
    $dot = strpos($event, '.');
    $subject = $dot === false ? $event : substr($event, 0, $dot);

    return self::CATEGORIES[$subject] ?? 'other';
  }

  //---------------------------------------------------------------------------
  /**
   * Masks an IP address so that it no longer identifies a single device: IPv4 a.b.c.d becomes a.b.c.0, IPv6 keeps its
   * first three groups (48 bits) and zeroes the rest. An IPv4 address in IPv6 notation (::ffff:a.b.c.d) is treated as
   * IPv4. Anything that is not an IP address gives null, so nothing unvalidated is stored.
   *
   * @param string $ip The address (REMOTE_ADDR)
   *
   * @return string|null The masked address
   */
  public static function maskIp(string $ip): ?string
  {
    $ip = trim($ip);
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
      return null;
    }

    $packed = inet_pton($ip);
    if ($packed === false) {
      return null;
    }

    if (strlen($packed) === 16) {
      // IPv4 mapped into IPv6: ::ffff:a.b.c.d
      if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
        $packed = substr($packed, 12);
      } else {
        return inet_ntop(substr($packed, 0, 6) . str_repeat("\0", 10)) ?: null;
      }
    }

    return inet_ntop(substr($packed, 0, 3) . "\0") ?: null;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether a key (a setting name or a field name) holds a value that must not be stored.
   *
   * @param string $key The key
   *
   * @return bool
   */
  public static function isSensitive(string $key): bool
  {
    return preg_match(self::SENSITIVE_KEYS, $key) === 1;
  }

  //---------------------------------------------------------------------------
  /**
   * Prepares a value for the log: sensitive keys (at any depth) get the mask, long texts are cut. Scalars stay as they
   * are, so use it with the array of field => value of a change.
   *
   * @param mixed $value The value or the array of field => value
   *
   * @return mixed
   */
  public static function sanitize(mixed $value): mixed
  {
    if (is_array($value)) {
      $clean = [];
      foreach ($value as $key => $item) {
        $clean[$key] = is_string($key) && self::isSensitive($key) ? self::MASK : self::sanitize($item);
      }
      return $clean;
    }

    if (is_string($value)) {
      return mb_strlen($value) > self::MAX_VALUE_LENGTH ? mb_substr($value, 0, self::MAX_VALUE_LENGTH) . '…' : $value;
    }

    // Objects and resources have no place in the log
    return is_scalar($value) || $value === null ? $value : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Prepares the value of one setting: a sensitive setting is masked.
   *
   * @param string $key   The setting name
   * @param mixed  $value The value
   *
   * @return mixed
   */
  public static function sanitizeSetting(string $key, mixed $value): mixed
  {
    return self::isSensitive($key) ? self::MASK : self::sanitize($value);
  }

  //---------------------------------------------------------------------------
  /**
   * Compares two states of a record and returns only what changed, ready to be passed as old and new value of log().
   * Values are compared as text (a number and the same number as a string are equal; lists compare by content).
   *
   * @param array    $old    The record before (field => value)
   * @param array    $new    The record after
   * @param string[] $fields The fields to compare
   *
   * @return array{old: array, new: array} The changed fields; both empty when nothing changed
   */
  public static function diff(array $old, array $new, array $fields): array
  {
    $before = [];
    $after = [];
    foreach ($fields as $field) {
      $a = $old[$field] ?? null;
      $b = $new[$field] ?? null;
      if (self::comparable($a) !== self::comparable($b)) {
        $before[$field] = $a;
        $after[$field] = $b;
      }
    }

    return ['old' => $before, 'new' => $after];
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes the entries older than the retention period, in batches so that the table is not locked for long. At most
   * 100,000 entries go per call; the housekeeping continues with the next run.
   *
   * @param int $days The retention period in days; 0 keeps everything
   *
   * @return int The number of deleted entries
   */
  public function purge(int $days): int
  {
    if ($days <= 0) {
      return 0;
    }

    $deleted = 0;
    for ($i = 0; $i < self::PURGE_MAX_BATCHES; $i++) {
      $statement = $this->db->prepare('DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY) LIMIT ' . self::PURGE_BATCH);
      $statement->execute();
      $count = $statement->rowCount();
      $deleted += $count;
      if ($count < self::PURGE_BATCH) {
        break;
      }
    }

    return $deleted;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the id of this request (16 hex characters), created on first use.
   *
   * @return string
   */
  public function requestId(): string
  {
    return $this->requestId ??= bin2hex(random_bytes(8));
  }

  //---------------------------------------------------------------------------
  /**
   * Turns a value into the JSON that is stored. Empty values are stored as NULL.
   *
   * @param mixed $value The value (see sanitize())
   *
   * @return string|null
   */
  private static function encode(mixed $value): ?string
  {
    if ($value === null || $value === [] || $value === '') {
      return null;
    }

    $json = json_encode(self::sanitize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
      return null;
    }

    // Cutting JSON would break it; a value this large is not a normal change
    return strlen($json) > self::MAX_JSON_BYTES ? '{"truncated":true}' : $json;
  }

  //---------------------------------------------------------------------------
  /**
   * Brings a value into a form that compares equal when the content is equal.
   *
   * @param mixed $value The value
   *
   * @return string
   */
  private static function comparable(mixed $value): string
  {
    if (is_array($value)) {
      return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
    if (is_bool($value)) {
      return $value ? '1' : '0';
    }

    return (string)$value;
  }
}
