<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;

//---------------------------------------------------------------------------
/**
 * Limits the anonymous authentication endpoints per client address: wrong passwords at /auth/login and requests
 * to /auth/register and /auth/reset-password (which can trigger mails).
 *
 * One row in auth_attempts is one counted event. When an address has reached the maximum within the window, it is
 * blocked until the newest event is a whole window old. Only REMOTE_ADDR is used; forwarding headers can be forged
 * (behind a reverse proxy, make the web server pass the real client address on as REMOTE_ADDR).
 *
 * The maximum is AUTH_THROTTLE_MAX in .env (default 10 per window, 0 switches the limit off), the window is
 * AUTH_THROTTLE_WINDOW in seconds (default 900). If the table is missing (database not yet upgraded), the
 * throttle lets the request through and writes a line to the error log.
 */
class AuthThrottle
{
  /** The action names stored in auth_attempts */
  public const LOGIN = 'login';
  public const REGISTER = 'register';
  public const RESET = 'reset';

  private const DEFAULT_MAX = 10;
  private const DEFAULT_WINDOW = 900;

  private PDO $db;
  private int $max;
  private int $window;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param PDO   $db  The database connection
   * @param array $env The environment (AUTH_THROTTLE_MAX, AUTH_THROTTLE_WINDOW)
   */
  public function __construct(PDO $db, array $env = [])
  {
    $this->db = $db;
    $this->max = isset($env['AUTH_THROTTLE_MAX']) && is_numeric($env['AUTH_THROTTLE_MAX']) ? max(0, (int)$env['AUTH_THROTTLE_MAX']) : self::DEFAULT_MAX;
    $window = isset($env['AUTH_THROTTLE_WINDOW']) && is_numeric($env['AUTH_THROTTLE_WINDOW']) ? (int)$env['AUTH_THROTTLE_WINDOW'] : self::DEFAULT_WINDOW;
    $this->window = $window > 0 ? $window : self::DEFAULT_WINDOW;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells how long an address is blocked for an action.
   *
   * @param string $action LOGIN, REGISTER or RESET
   * @param string $ip     The client address
   *
   * @return int Seconds until the next attempt is allowed, 0 if it is allowed now
   */
  public function lockedFor(string $action, string $ip): int
  {
    if ($this->max === 0) {
      return 0;
    }
    try {
      $stmt = $this->db->prepare(
        'SELECT COUNT(*) AS hits, MAX(created_at) AS newest,
                TIMESTAMPDIFF(SECOND, NOW(), MAX(created_at) + INTERVAL ' . $this->window . ' SECOND) AS seconds
           FROM auth_attempts
          WHERE action = :action AND ip = :ip
            AND created_at > NOW() - INTERVAL ' . $this->window . ' SECOND'
      );
      $stmt->execute(['action' => $action, 'ip' => $ip]);
      $row = $stmt->fetch();
    } catch (PDOException $e) {
      error_log('AuthThrottle: ' . $e->getMessage() . ' (run the database upgrade: table auth_attempts)');
      return 0;
    }

    if (!$row || $row['newest'] === null || (int)$row['hits'] < $this->max) {
      return 0;
    }

    return max(1, (int)$row['seconds']);
  }

  //---------------------------------------------------------------------------
  /**
   * Counts an event (a wrong password, a registration or a reset request).
   *
   * @param string $action LOGIN, REGISTER or RESET
   * @param string $ip     The client address
   *
   * @return void
   */
  public function record(string $action, string $ip): void
  {
    if ($this->max === 0) {
      return;
    }
    try {
      $this->db->exec('DELETE FROM auth_attempts WHERE created_at < NOW() - INTERVAL ' . $this->window . ' SECOND');
      $this->db->prepare('INSERT INTO auth_attempts (action, ip) VALUES (:action, :ip)')->execute(['action' => $action, 'ip' => $ip]);
    } catch (PDOException $e) {
      error_log('AuthThrottle: ' . $e->getMessage());
    }
  }
}
