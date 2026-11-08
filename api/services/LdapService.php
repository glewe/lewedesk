<?php

declare(strict_types=1);

namespace App\Services;

//---------------------------------------------------------------------------
/**
 * Checks a username and password against an LDAP server.
 *
 * LDAP is used for authentication only: the caller has to find the LeweDesk account itself, and nothing is read
 * from LDAP except the distinguished name (DN) of the user. The settings come from the .env file (LDAP_*, see
 * _docs/LDAP.md). The flow follows the LDAP login of TeamCal Neo:
 *
 *  1. optional anonymous bind check (LDAP_CHECK_ANONYMOUS_BIND)
 *  2. optional search bind with LDAP_DIT / LDAP_PASS (LDAP_SEARCH_BIND)
 *  3. search the user DN below LDAP_SBASE (uid=<name>, or sAMAccountName=<name> if LDAP_ADS is on)
 *  4. bind as that DN with the password of the user
 *
 * The user named "admin" never uses LDAP (see usesLdap()).
 */
class LdapService
{
  /** The username and password are correct. */
  public const OK = 0;
  /** The PHP ldap extension is not loaded. */
  public const EXTENSION_MISSING = 90;
  /** The password is empty (an empty password would count as an anonymous bind and always succeed). */
  public const PASSWORD_MISSING = 91;
  /** The server rejected the password of the user (or the user could not be bound). */
  public const BIND_FAILED = 92;
  /** The server could not be reached. */
  public const CONNECT_FAILED = 93;
  /** STARTTLS failed. */
  public const TLS_FAILED = 94;
  /** There is no such user, or more than one. */
  public const USER_NOT_FOUND = 95;
  /** The bind with LDAP_DIT / LDAP_PASS failed. */
  public const SEARCH_BIND_FAILED = 96;
  /** LDAP_CHECK_ANONYMOUS_BIND is on and the anonymous bind failed. */
  public const ANONYMOUS_BIND_FAILED = 97;
  /** The username is empty. */
  public const USERNAME_MISSING = 98;
  /** The settings are incomplete or invalid (no host, no search base, bad attribute name, ...). */
  public const INVALID_CONFIG = 99;
  /** The search itself was rejected by the server (bad search base, no permission to search, ...). */
  public const SEARCH_FAILED = 100;

  public const MESSAGE_INVALID = 'Invalid credentials';
  public const MESSAGE_UNAVAILABLE = 'Authentication server unavailable';
  public const MESSAGE_CONFIG = 'LDAP configuration error on LeweDesk server.';

  /** The protocol error code for "server down" (LDAP_SERVER_DOWN). */
  private const ERRNO_SERVER_DOWN = -1;

  /** The username that always authenticates against the LeweDesk database. */
  private const LOCAL_USER = 'admin';

  private const DEFAULT_TIMEOUT = 5;

  /** @var array<string, mixed> The settings */
  private array $config;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param array<string, mixed> $env The environment (normally $_ENV); keys are the LDAP_* names of the .env file
   */
  public function __construct(array $env)
  {
    $tls = (int)($env['LDAP_TLS'] ?? 0);
    $ads = self::flag($env['LDAP_ADS'] ?? 0);
    $defaultPort = $tls === 2 ? 636 : 389;
    $attribute = trim((string)($env['LDAP_USER_ATTR'] ?? ''));
    $timeout = (int)($env['LDAP_TIMEOUT'] ?? self::DEFAULT_TIMEOUT);

    $this->config = [
      // OIDC wins: with OIDC_YES=1 LDAP is off, whatever LDAP_YES says (see _docs/OIDC.md)
      'enabled' => self::flag($env['LDAP_YES'] ?? 0) && !self::flag($env['OIDC_YES'] ?? 0),
      'ads' => $ads,
      'host' => trim((string)($env['LDAP_HOST'] ?? '')),
      'port' => (int)($env['LDAP_PORT'] ?? 0) ?: $defaultPort,
      'bindDn' => (string)($env['LDAP_DIT'] ?? ''),
      'bindPass' => (string)($env['LDAP_PASS'] ?? ''),
      'searchBase' => trim((string)($env['LDAP_SBASE'] ?? '')),
      'tls' => $tls,
      'checkAnonymous' => self::flag($env['LDAP_CHECK_ANONYMOUS_BIND'] ?? 0),
      'searchBind' => self::flag($env['LDAP_SEARCH_BIND'] ?? 0),
      'attribute' => $attribute !== '' ? $attribute : ($ads ? 'sAMAccountName' : 'uid'),
      'timeout' => $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT,
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * Whether LDAP authentication is switched on (LDAP_YES=1).
   *
   * @return bool
   */
  public function isEnabled(): bool
  {
    return $this->config['enabled'];
  }

  //---------------------------------------------------------------------------
  /**
   * Whether this user has to authenticate against LDAP: LDAP is on and the user is not "admin".
   *
   * @param string $username The username as entered or stored
   *
   * @return bool
   */
  public function usesLdap(string $username): bool
  {
    return $this->config['enabled'] && strtolower(trim($username)) !== self::LOCAL_USER;
  }

  //---------------------------------------------------------------------------
  /**
   * Checks the username and password against the LDAP server.
   *
   * @param string $username The username
   * @param string $password The password
   *
   * @return int One of the result constants; OK means the password is correct
   */
  public function verify(string $username, string $password): int
  {
    if (!function_exists('ldap_connect')) {
      return self::EXTENSION_MISSING;
    }
    if ($username === '') {
      return self::USERNAME_MISSING;
    }
    // An empty password makes ldap_bind() an unauthenticated bind, which most servers accept
    if ($password === '') {
      return self::PASSWORD_MISSING;
    }
    if (!$this->configIsValid()) {
      return self::INVALID_CONFIG;
    }

    $c = $this->config;
    $scheme = $c['tls'] === 2 ? 'ldaps' : 'ldap';
    $ds = @ldap_connect($scheme . '://' . $c['host'] . ':' . $c['port']);
    if ($ds === false) {
      return self::CONNECT_FAILED;
    }

    try {
      ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);
      ldap_set_option($ds, LDAP_OPT_REFERRALS, 0);
      ldap_set_option($ds, LDAP_OPT_NETWORK_TIMEOUT, $c['timeout']);
      ldap_set_option($ds, LDAP_OPT_TIMELIMIT, $c['timeout']);

      // STARTTLS first, so that nothing (not even the search bind password) crosses the wire in plain text
      if ($c['tls'] === 1 && !@ldap_start_tls($ds)) {
        return $this->failed($ds, self::TLS_FAILED);
      }

      if ($c['checkAnonymous'] && !@ldap_bind($ds)) {
        return $this->failed($ds, self::ANONYMOUS_BIND_FAILED);
      }

      if ($c['searchBind'] && !@ldap_bind($ds, $c['bindDn'], $c['bindPass'])) {
        return $this->failed($ds, self::SEARCH_BIND_FAILED);
      }

      $dn = $this->findDn($ds, $username);
      if (is_int($dn)) {
        return $dn;
      }

      if (!@ldap_bind($ds, $dn, $password)) {
        return $this->failed($ds, self::BIND_FAILED);
      }

      return self::OK;
    } finally {
      @ldap_close($ds);
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Describes how a login with a failed result has to be answered.
   *
   * @param int $result A result constant other than OK
   *
   * @return array{status: int, message: string, log: bool} The HTTP status, the message for the user, and
   *                                                         whether it is a server problem for the error log
   */
  public static function failure(int $result): array
  {
    switch ($result) {
      case self::CONNECT_FAILED:
        return ['status' => 503, 'message' => self::MESSAGE_UNAVAILABLE, 'log' => true];
      case self::EXTENSION_MISSING:
      case self::TLS_FAILED:
      case self::SEARCH_BIND_FAILED:
      case self::ANONYMOUS_BIND_FAILED:
      case self::INVALID_CONFIG:
      case self::SEARCH_FAILED:
        return ['status' => 500, 'message' => self::MESSAGE_CONFIG, 'log' => true];
      default:
        // Wrong password, unknown user, empty input: the user is not told which
        return ['status' => 401, 'message' => self::MESSAGE_INVALID, 'log' => false];
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Short name of a result for the error log.
   *
   * @param int $result A result constant
   *
   * @return string
   */
  public static function describe(int $result): string
  {
    $names = [
      self::OK => 'ok',
      self::EXTENSION_MISSING => 'PHP ldap extension missing',
      self::PASSWORD_MISSING => 'password missing',
      self::BIND_FAILED => 'bind of the user failed',
      self::CONNECT_FAILED => 'cannot connect to the server',
      self::TLS_FAILED => 'STARTTLS failed',
      self::USER_NOT_FOUND => 'user not found',
      self::SEARCH_BIND_FAILED => 'search bind failed',
      self::ANONYMOUS_BIND_FAILED => 'anonymous bind failed',
      self::USERNAME_MISSING => 'username missing',
      self::INVALID_CONFIG => 'invalid LDAP settings',
      self::SEARCH_FAILED => 'search rejected by the server',
    ];

    return $names[$result] ?? 'unknown result ' . $result;
  }

  //---------------------------------------------------------------------------
  /**
   * Searches the DN of the user.
   *
   * @param \LDAP\Connection $ds       The connection
   * @param string           $username The username
   *
   * @return string|int The DN, or a result constant if there is not exactly one user
   */
  private function findDn($ds, string $username): string|int
  {
    $filter = '(' . $this->config['attribute'] . '=' . ldap_escape($username, '', LDAP_ESCAPE_FILTER) . ')';

    // Size limit 2: more than one hit is already one too many
    $search = @ldap_search($ds, $this->config['searchBase'], $filter, ['dn'], 1, 2, $this->config['timeout']);
    if ($search === false) {
      return $this->failed($ds, self::SEARCH_FAILED);
    }

    $entries = ldap_count_entries($ds, $search);
    if ($entries !== 1) {
      return self::USER_NOT_FOUND;
    }

    $entry = ldap_first_entry($ds, $search);
    $dn = $entry !== false ? ldap_get_dn($ds, $entry) : false;

    return is_string($dn) && $dn !== '' ? $dn : self::USER_NOT_FOUND;
  }

  //---------------------------------------------------------------------------
  /**
   * Turns a failed LDAP call into a result and writes the server's reason to the error log.
   *
   * An unreachable server shows up as "server down" in any call, so it takes precedence over the result of
   * the call that failed. The password is never logged.
   *
   * @param \LDAP\Connection $ds     The connection
   * @param int              $result The result for the failed call
   *
   * @return int
   */
  private function failed($ds, int $result): int
  {
    $errno = ldap_errno($ds);
    if ($errno === self::ERRNO_SERVER_DOWN) {
      $result = self::CONNECT_FAILED;
    }

    // A wrong password or an unknown user is not a server problem
    if (self::failure($result)['log']) {
      error_log(sprintf(
        'LDAP: %s (%s:%d, LDAP error %d: %s)',
        self::describe($result),
        $this->config['host'],
        $this->config['port'],
        $errno,
        ldap_error($ds)
      ));
    }

    return $result;
  }

  //---------------------------------------------------------------------------
  /**
   * Checks that the settings can work.
   *
   * @return bool
   */
  private function configIsValid(): bool
  {
    $c = $this->config;
    $ok = $c['host'] !== ''
      && $c['searchBase'] !== ''
      && $c['port'] > 0 && $c['port'] <= 65535
      && in_array($c['tls'], [0, 1, 2], true)
      // The attribute goes into the search filter unescaped, so it has to be a plain attribute name
      && preg_match('/^[A-Za-z][A-Za-z0-9-]*$/', $c['attribute']) === 1
      && (!$c['searchBind'] || $c['bindDn'] !== '');

    if (!$ok) {
      error_log('LDAP: ' . self::describe(self::INVALID_CONFIG) . ' (check LDAP_HOST, LDAP_PORT, LDAP_SBASE, LDAP_TLS, LDAP_USER_ATTR, LDAP_DIT)');
    }

    return $ok;
  }

  //---------------------------------------------------------------------------
  /**
   * Reads an on/off setting.
   *
   * @param mixed $value The value from the .env file
   *
   * @return bool
   */
  private static function flag(mixed $value): bool
  {
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
  }
}
