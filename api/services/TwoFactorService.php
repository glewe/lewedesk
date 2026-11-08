<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

//---------------------------------------------------------------------------
/**
 * The rules of the two factor authentication (docs: _docs/2FA.md).
 *
 * After the password step a user does not get a login token. A "pending" token is issued instead (only its SHA-256
 * hash is stored in twofa_pending), valid for 5 minutes, for one purpose: "challenge" (enter a code) or "setup"
 * (enroll first, when 2FA is forced). A valid code is exchanged for the real login token by the caller.
 *
 * Failed codes are counted per user (5 within 5 minutes) and per IP address (20 within 15 minutes); while a
 * limit is reached, no further code is checked. The database clock is used throughout.
 */
class TwoFactorService
{
  public const CHALLENGE = 'challenge';
  public const SETUP = 'setup';

  /** Lifetime of a pending token and the extension a setup call grants (seconds) */
  private const PENDING_TTL = 300;

  /** A pending token never lives longer than this, however often the setup is extended (seconds) */
  private const PENDING_MAX = 900;

  private const USER_MAX_FAILURES = 5;
  private const USER_WINDOW = 300;
  private const IP_MAX_FAILURES = 20;
  private const IP_WINDOW = 900;

  private PDO $db;
  private SecretVault $vault;
  private Totp $totp;
  private bool $forced;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param PDO         $db       The database connection
   * @param SecretVault $vault    Encrypts the secrets
   * @param Totp        $totp     Creates and checks the codes
   * @param array       $settings The system settings (force_2fa)
   */
  public function __construct(PDO $db, SecretVault $vault, Totp $totp, array $settings)
  {
    $this->db = $db;
    $this->vault = $vault;
    $this->totp = $totp;
    $this->forced = ($settings['force_2fa'] ?? 'false') === 'true';
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the administrator forces 2FA for everybody.
   *
   * @return bool
   */
  public function isForced(): bool
  {
    return $this->forced;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether a user has set up 2FA.
   *
   * @param int $userId The user id
   *
   * @return bool
   */
  public function isEnrolled(int $userId): bool
  {
    $stmt = $this->db->prepare('SELECT twofa_enabled_at IS NOT NULL FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);

    return (bool)$stmt->fetchColumn();
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether a user has to pass a second factor at login: 2FA is forced or the user has set it up.
   *
   * @param int $userId The user id
   *
   * @return bool
   */
  public function isRequired(int $userId): bool
  {
    return $this->forced || $this->isEnrolled($userId);
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether a user may skip 2FA: DISABLE_ADMIN_2FA=true in the .env file lets every administrator in
   * without a code (emergency only).
   *
   * @param array $user The user as returned by AuthService::userData() (needs is_admin)
   *
   * @return bool
   */
  public function isBypassed(array $user): bool
  {
    return !empty($user['is_admin']) && strtolower(trim((string)($_ENV['DISABLE_ADMIN_2FA'] ?? 'false'))) === 'true';
  }

  //---------------------------------------------------------------------------
  /**
   * Creates a pending token for a user.
   *
   * @param int    $userId  The user id
   * @param string $purpose TwoFactorService::CHALLENGE or TwoFactorService::SETUP
   *
   * @return string The token (only its hash is stored)
   */
  public function createPending(int $userId, string $purpose): string
  {
    $this->db->exec('DELETE FROM twofa_pending WHERE expires_at < NOW()');

    $token = bin2hex(random_bytes(32));
    $this->db->prepare(
      'INSERT INTO twofa_pending (user_id, token_hash, purpose, expires_at)
       VALUES (:user, :hash, :purpose, DATE_ADD(NOW(), INTERVAL :ttl SECOND))'
    )->execute(['user' => $userId, 'hash' => hash('sha256', $token), 'purpose' => $purpose, 'ttl' => self::PENDING_TTL]);

    return $token;
  }

  //---------------------------------------------------------------------------
  /**
   * Looks up a pending token.
   *
   * @param string $token   The token sent by the client
   * @param string $purpose The purpose the endpoint needs
   *
   * @return array|null The row (id, user_id, purpose, setup_secret, created_at), or null if unknown, expired or of another purpose
   */
  public function resolvePending(string $token, string $purpose): ?array
  {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
      return null;
    }

    $stmt = $this->db->prepare(
      'SELECT id, user_id, purpose, setup_secret, created_at FROM twofa_pending
        WHERE token_hash = :hash AND purpose = :purpose AND expires_at > NOW()'
    );
    $stmt->execute(['hash' => hash('sha256', $token), 'purpose' => $purpose]);
    $row = $stmt->fetch();

    return $row ?: null;
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes a pending token.
   *
   * @param array $pending The row from resolvePending()
   *
   * @return void
   */
  public function consumePending(array $pending): void
  {
    $this->db->prepare('DELETE FROM twofa_pending WHERE id = :id')->execute(['id' => $pending['id']]);
  }

  //---------------------------------------------------------------------------
  /**
   * Starts (or restarts) the enrollment: creates a new secret, keeps it encrypted in the pending row and extends
   * the pending token by five minutes (up to 15 minutes in total), so there is time to scan the code. Nothing
   * is written to the user until the code is confirmed.
   *
   * @param array $pending The row from resolvePending() (purpose setup)
   *
   * @return string The new secret (Base32)
   */
  public function startSetup(array $pending): string
  {
    $secret = $this->totp->generateSecret();

    $this->db->prepare(
      'UPDATE twofa_pending
          SET setup_secret = :secret,
              expires_at = LEAST(DATE_ADD(created_at, INTERVAL :max SECOND), DATE_ADD(NOW(), INTERVAL :ttl SECOND))
        WHERE id = :id'
    )->execute([
      'secret' => $this->vault->encrypt($secret, $this->context((int)$pending['user_id'])),
      'max' => self::PENDING_MAX,
      'ttl' => self::PENDING_TTL,
      'id' => $pending['id'],
    ]);

    return $secret;
  }

  //---------------------------------------------------------------------------
  /**
   * Confirms the enrollment: if the code matches the secret of the pending row, 2FA is switched on for the user and
   * the pending token is used up.
   *
   * @param array  $pending The row from resolvePending() (purpose setup)
   * @param string $code    The code entered by the user
   *
   * @return bool Whether the code was right and 2FA is on now
   */
  public function confirmSetup(array $pending, string $code): bool
  {
    $userId = (int)$pending['user_id'];
    if (empty($pending['setup_secret'])) {
      return false;
    }

    $secret = $this->vault->decrypt((string)$pending['setup_secret'], $this->context($userId));
    if (!$this->totp->verify($secret, $code, $step)) {
      return false;
    }

    $stmt = $this->db->prepare(
      'UPDATE users SET twofa_secret = :secret, twofa_enabled_at = NOW(), twofa_last_step = :step
        WHERE id = :id AND twofa_enabled_at IS NULL'
    );
    $stmt->execute(['secret' => $this->vault->encrypt($secret, $this->context($userId)), 'step' => $step, 'id' => $userId]);
    if ($stmt->rowCount() === 0) {
      return false;
    }

    $this->consumePending($pending);

    return true;
  }

  //---------------------------------------------------------------------------
  /**
   * Checks the code of a login. A code is accepted once: the time step of the last accepted code is stored, and
   * the same or an older step is refused (the update is one statement, so two requests cannot both use it).
   * The pending token is used up on success.
   *
   * @param array  $pending The row from resolvePending() (purpose challenge)
   * @param string $code    The code entered by the user
   *
   * @return bool
   */
  public function verifyLogin(array $pending, string $code): bool
  {
    $userId = (int)$pending['user_id'];
    $stmt = $this->db->prepare('SELECT twofa_secret, twofa_last_step FROM users WHERE id = :id AND twofa_enabled_at IS NOT NULL');
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();
    if (!$row || empty($row['twofa_secret'])) {
      return false;
    }

    $secret = $this->vault->decrypt((string)$row['twofa_secret'], $this->context($userId));
    $last = $row['twofa_last_step'] !== null ? (int)$row['twofa_last_step'] : null;
    if (!$this->totp->verify($secret, $code, $step, $last)) {
      return false;
    }

    $update = $this->db->prepare(
      'UPDATE users SET twofa_last_step = :step WHERE id = :id AND (twofa_last_step IS NULL OR twofa_last_step < :step_check)'
    );
    $update->execute(['step' => $step, 'step_check' => $step, 'id' => $userId]);
    if ($update->rowCount() === 0) {
      return false;
    }

    $this->consumePending($pending);

    return true;
  }

  //---------------------------------------------------------------------------
  /**
   * Switches 2FA off for a user (by the user or by an administrator) and drops the user's pending tokens.
   *
   * @param int $userId The user id
   *
   * @return void
   */
  public function disable(int $userId): void
  {
    $this->db->prepare('UPDATE users SET twofa_secret = NULL, twofa_enabled_at = NULL, twofa_last_step = NULL WHERE id = :id')
      ->execute(['id' => $userId]);
    $this->db->prepare('DELETE FROM twofa_pending WHERE user_id = :id')->execute(['id' => $userId]);
  }

  //---------------------------------------------------------------------------
  /**
   * Tells how long an attempt is blocked. The limits are 5 failed codes of a user within 5 minutes and 20 failed
   * codes from an IP address within 15 minutes; the block lasts as long as the window, counted from the last failure.
   *
   * @param int    $userId The user id
   * @param string $ip     The IP address of the request
   *
   * @return int Seconds until the next attempt is allowed, 0 if it is allowed now
   */
  public function lockedFor(int $userId, string $ip): int
  {
    return max(
      $this->remaining('user_id', $userId, self::USER_MAX_FAILURES, self::USER_WINDOW),
      $this->remaining('ip', $ip, self::IP_MAX_FAILURES, self::IP_WINDOW)
    );
  }

  //---------------------------------------------------------------------------
  /**
   * Counts a failed code.
   *
   * @param int    $userId The user id
   * @param string $ip     The IP address of the request
   *
   * @return void
   */
  public function recordFailure(int $userId, string $ip): void
  {
    // Entries older than the longest window are of no use any more
    $this->db->exec('DELETE FROM twofa_attempts WHERE created_at < NOW() - INTERVAL ' . self::IP_WINDOW . ' SECOND');
    $this->db->prepare('INSERT INTO twofa_attempts (user_id, ip) VALUES (:user, :ip)')->execute(['user' => $userId, 'ip' => $ip]);
  }

  //---------------------------------------------------------------------------
  /**
   * Forgets the failed codes of a user (after a right code).
   *
   * @param int $userId The user id
   *
   * @return void
   */
  public function clearFailures(int $userId): void
  {
    $this->db->prepare('DELETE FROM twofa_attempts WHERE user_id = :id')->execute(['id' => $userId]);
  }

  //---------------------------------------------------------------------------
  /**
   * Tells how long a limit still blocks. It is reached when the newest failure and at least max - 1 more lie within
   * one window; then it blocks until a whole window has passed since the newest failure.
   *
   * @param string     $column  user_id or ip
   * @param int|string $value   The user id or the IP address
   * @param int        $max     The number of failures that blocks
   * @param int        $window  The window in seconds
   *
   * @return int Seconds, 0 if not blocked
   */
  private function remaining(string $column, int|string $value, int $max, int $window): int
  {
    $stmt = $this->db->prepare(
      "SELECT COUNT(*) AS failures, MAX(created_at) AS newest,
              TIMESTAMPDIFF(SECOND, NOW(), MAX(created_at) + INTERVAL $window SECOND) AS seconds
         FROM twofa_attempts
        WHERE $column = :value
          AND created_at > (SELECT MAX(created_at) FROM twofa_attempts WHERE $column = :value_newest) - INTERVAL $window SECOND"
    );
    $stmt->execute(['value' => $value, 'value_newest' => $value]);
    $row = $stmt->fetch();

    if (!$row || $row['newest'] === null || (int)$row['failures'] < $max) {
      return 0;
    }

    return max(0, (int)$row['seconds']);
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the context a user's secret is encrypted for.
   *
   * @param int $userId The user id
   *
   * @return string
   */
  private function context(int $userId): string
  {
    return 'user:' . $userId;
  }
}
