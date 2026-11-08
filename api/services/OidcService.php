<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

//---------------------------------------------------------------------------
/**
 * OpenID Connect (single sign-on) with the authorization code flow.
 *
 * OIDC is used for authentication only: the caller finds the LeweDesk account itself, and nothing is read from the
 * Identity Provider except the claims "sub" and "preferred_username". The settings come from the .env file (OIDC_*,
 * see _docs/OIDC.md). The flow follows the OIDC login of TeamCal Neo, without a PHP session (LeweDesk has none):
 *
 *  1. beginLogin(): random state, nonce and PKCE verifier are stored (hashed state, 10 minutes, single use) and the
 *     browser is sent to the authorization endpoint of the provider.
 *  2. finishLogin(): the provider returns with code and state. The state is used up, the code is exchanged for the
 *     ID token at the token endpoint, and the ID token is verified (RS256 signature against the JWKS of the provider,
 *     iss, aud, azp, exp, iat, nonce).
 *  3. createCode() / redeemCode(): after the account checks the callback hands the login page a one-time code (60
 *     seconds, single use), which the page swaps for the login response. No token ever travels in a URL.
 *
 * The user named "admin" never uses OIDC (see usesOidc()). Only ext-curl and ext-openssl are needed.
 */
class OidcService
{
  /** The login worked. */
  public const OK = 'ok';
  /** OIDC is not switched on. */
  public const DISABLED = 'disabled';
  /** The settings are incomplete or the PHP extensions are missing. */
  public const CONFIG = 'config';
  /** The provider could not be reached or sent an unusable answer. */
  public const UNAVAILABLE = 'unavailable';
  /** The ID token or the answer of the provider is not valid. */
  public const INVALID_TOKEN = 'invalid_token';
  /** The state is unknown, used up or older than ten minutes. */
  public const EXPIRED = 'expired';
  /** The provider reported an error (for example the user cancelled). */
  public const PROVIDER_ERROR = 'provider_error';

  /** The username that always authenticates against the LeweDesk database. */
  private const LOCAL_USER = 'admin';

  private const DEFAULT_TIMEOUT = 5;
  private const STATE_SECONDS = 600;
  private const CODE_SECONDS = 60;
  private const LEEWAY = 60;
  private const SCOPES = 'openid profile';
  private const CALLBACK_PATH = '/api/auth/oidc/callback';

  /** @var array<string, mixed> The settings */
  private array $config;

  private ?PDO $db;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param array<string, mixed> $env The environment (normally $_ENV); keys are the OIDC_* names of the .env file
   * @param PDO|null             $db  The database (state and one-time codes); not needed for isEnabled()/usesOidc()
   */
  public function __construct(array $env, ?PDO $db = null)
  {
    $timeout = (int)($env['OIDC_TIMEOUT'] ?? self::DEFAULT_TIMEOUT);
    $verify = $env['OIDC_VERIFY_TLS'] ?? 1;

    $this->db = $db;
    $this->config = [
      'enabled' => self::flag($env['OIDC_YES'] ?? 0),
      'provider' => rtrim(trim((string)($env['OIDC_PROVIDER_URL'] ?? '')), '/'),
      'clientId' => trim((string)($env['OIDC_CLIENT_ID'] ?? '')),
      'clientSecret' => (string)($env['OIDC_CLIENT_SECRET'] ?? ''),
      'redirectUri' => trim((string)($env['OIDC_REDIRECT_URI'] ?? '')),
      'timeout' => $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT,
      'verifyTls' => self::flag($verify),
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * Whether OIDC authentication is switched on (OIDC_YES=1).
   *
   * @return bool
   */
  public function isEnabled(): bool
  {
    return $this->config['enabled'];
  }

  //---------------------------------------------------------------------------
  /**
   * Whether this user has to authenticate with OIDC: OIDC is on and the user is not "admin".
   *
   * @param string $username The username as entered or stored
   *
   * @return bool
   */
  public function usesOidc(string $username): bool
  {
    return $this->config['enabled'] && strtolower(trim($username)) !== self::LOCAL_USER;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells where the login page is: the redirect URI without the callback path of the API. Falls back to the path
   * of the redirect URI two levels up when it does not end with the usual path.
   *
   * @return string The URL of login.html
   */
  public function loginPageUrl(): string
  {
    $uri = (string)preg_replace('/[?#].*$/', '', $this->config['redirectUri']);
    if (str_ends_with($uri, self::CALLBACK_PATH)) {
      return substr($uri, 0, -strlen(self::CALLBACK_PATH)) . '/login.html';
    }
    // Some other path: assume api/<x>/<y>/<z> below the application
    return preg_replace('#/[^/]+/[^/]+/[^/]+/[^/]+$#', '', $uri) . '/login.html';
  }

  //---------------------------------------------------------------------------
  /**
   * Starts a login: stores state, nonce and PKCE verifier and builds the URL of the provider to send the browser to.
   *
   * @return array{error: ?string, url: ?string} error is one of the class constants (null on success)
   */
  public function beginLogin(): array
  {
    $problem = $this->checkReady();
    if ($problem !== null) {
      return ['error' => $problem, 'url' => null];
    }

    $discovery = $this->discovery();
    if ($discovery === null || empty($discovery['authorization_endpoint'])) {
      return ['error' => self::UNAVAILABLE, 'url' => null];
    }

    $state = bin2hex(random_bytes(32));
    $nonce = bin2hex(random_bytes(16));
    $verifier = self::base64Url(random_bytes(48));

    $this->db->exec('DELETE FROM oidc_states WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . self::STATE_SECONDS . ' SECOND)');
    $this->db->exec('DELETE FROM oidc_codes WHERE expires_at < NOW()');
    $this->db->prepare('INSERT INTO oidc_states (state_hash, nonce, code_verifier) VALUES (:hash, :nonce, :verifier)')
      ->execute(['hash' => hash('sha256', $state), 'nonce' => $nonce, 'verifier' => $verifier]);

    $query = http_build_query([
      'response_type' => 'code',
      'client_id' => $this->config['clientId'],
      'redirect_uri' => $this->config['redirectUri'],
      'scope' => self::SCOPES,
      'state' => $state,
      'nonce' => $nonce,
      'code_challenge' => self::base64Url(hash('sha256', $verifier, true)),
      'code_challenge_method' => 'S256',
    ], '', '&', PHP_QUERY_RFC3986);

    $endpoint = (string)$discovery['authorization_endpoint'];
    return ['error' => null, 'url' => $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . $query];
  }

  //---------------------------------------------------------------------------
  /**
   * Finishes a login at the callback: checks the state, exchanges the code and verifies the ID token.
   *
   * @param string $code  The authorization code from the provider
   * @param string $state The state from the provider
   *
   * @return array{error: ?string, sub: string, username: string} error is one of the class constants (null on success)
   */
  public function finishLogin(string $code, string $state): array
  {
    $fail = static fn (string $error): array => ['error' => $error, 'sub' => '', 'username' => ''];

    $problem = $this->checkReady();
    if ($problem !== null) {
      return $fail($problem);
    }
    if ($code === '' || $state === '') {
      return $fail(self::EXPIRED);
    }

    // The state is used up whatever happens next
    $stored = $this->consumeState($state);
    if ($stored === null) {
      return $fail(self::EXPIRED);
    }

    $discovery = $this->discovery();
    if ($discovery === null || empty($discovery['token_endpoint']) || empty($discovery['jwks_uri'])) {
      return $fail(self::UNAVAILABLE);
    }

    $response = $this->http((string)$discovery['token_endpoint'], [
      'grant_type' => 'authorization_code',
      'code' => $code,
      'redirect_uri' => $this->config['redirectUri'],
      'client_id' => $this->config['clientId'],
      'client_secret' => $this->config['clientSecret'],
      'code_verifier' => $stored['code_verifier'],
    ]);
    if ($response === null) {
      return $fail(self::UNAVAILABLE);
    }
    $tokens = json_decode($response['body'], true);
    if ($response['status'] !== 200 || !is_array($tokens) || empty($tokens['id_token']) || !is_string($tokens['id_token'])) {
      error_log('OIDC: the token endpoint refused the code (HTTP ' . $response['status'] . ': ' . self::shorten($response['body']) . ')');
      return $fail(self::INVALID_TOKEN);
    }

    $jwks = $this->http((string)$discovery['jwks_uri'], null);
    $keys = $jwks !== null && $jwks['status'] === 200 ? (json_decode($jwks['body'], true)['keys'] ?? null) : null;
    if (!is_array($keys)) {
      return $fail(self::UNAVAILABLE);
    }

    $claims = $this->verifyIdToken($tokens['id_token'], $keys, (string)$discovery['issuer'], $stored['nonce']);
    if ($claims === null) {
      return $fail(self::INVALID_TOKEN);
    }

    return ['error' => null, 'sub' => (string)$claims['sub'], 'username' => trim((string)($claims['preferred_username'] ?? ''))];
  }

  //---------------------------------------------------------------------------
  /**
   * Creates a one-time code for a user whose OIDC login was accepted. Valid 60 seconds, once.
   *
   * @param int $userId The LeweDesk user id
   *
   * @return string The code (only its hash is stored)
   */
  public function createCode(int $userId): string
  {
    $code = bin2hex(random_bytes(32));
    $this->db->prepare('INSERT INTO oidc_codes (code_hash, user_id, expires_at) VALUES (:hash, :user, DATE_ADD(NOW(), INTERVAL ' . self::CODE_SECONDS . ' SECOND))')
      ->execute(['hash' => hash('sha256', $code), 'user' => $userId]);

    return $code;
  }

  //---------------------------------------------------------------------------
  /**
   * Uses up a one-time code.
   *
   * @param string $code The code from the login page
   *
   * @return int|null The user id, or null when the code is unknown, used or expired
   */
  public function redeemCode(string $code): ?int
  {
    if (!preg_match('/^[0-9a-f]{64}$/', $code)) {
      return null;
    }
    $hash = hash('sha256', $code);
    $select = $this->db->prepare('SELECT user_id FROM oidc_codes WHERE code_hash = :hash AND expires_at > NOW()');
    $select->execute(['hash' => $hash]);
    $userId = $select->fetchColumn();
    if ($userId === false) {
      return null;
    }
    $delete = $this->db->prepare('DELETE FROM oidc_codes WHERE code_hash = :hash');
    $delete->execute(['hash' => $hash]);

    return $delete->rowCount() === 1 ? (int)$userId : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Verifies an ID token (compact JWS) and returns its claims.
   *
   * @param string                           $jwt    The ID token
   * @param array<int, array<string, mixed>> $keys   The keys of the provider (JWKS)
   * @param string                           $issuer The issuer from the discovery document
   * @param string                           $nonce  The nonce that was sent with the login
   * @param int|null                         $now    The time to check against (tests); null = now
   *
   * @return array<string, mixed>|null The claims, or null when anything is wrong (the reason goes to the error log)
   */
  public function verifyIdToken(string $jwt, array $keys, string $issuer, string $nonce, ?int $now = null): ?array
  {
    $now ??= time();
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
      return $this->reject('not a JWT');
    }
    $header = json_decode((string)self::base64UrlDecode($parts[0]), true);
    $claims = json_decode((string)self::base64UrlDecode($parts[1]), true);
    $signature = self::base64UrlDecode($parts[2]);
    if (!is_array($header) || !is_array($claims) || $signature === null || $signature === '') {
      return $this->reject('malformed JWT');
    }
    // Only RS256: "none" and the HMAC algorithms must never be accepted
    if (($header['alg'] ?? '') !== 'RS256') {
      return $this->reject('unsupported algorithm ' . self::shorten((string)($header['alg'] ?? '')));
    }

    $key = null;
    foreach ($keys as $candidate) {
      if (!is_array($candidate) || ($candidate['kty'] ?? '') !== 'RSA' || (($candidate['use'] ?? 'sig') !== 'sig')) {
        continue;
      }
      if (isset($header['kid']) ? ($candidate['kid'] ?? null) === $header['kid'] : count($keys) === 1) {
        $key = $candidate;
        break;
      }
    }
    $pem = $key !== null ? self::rsaPublicKeyPem((string)($key['n'] ?? ''), (string)($key['e'] ?? '')) : null;
    if ($pem === null) {
      return $this->reject('no matching signing key');
    }
    if (openssl_verify($parts[0] . '.' . $parts[1], $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
      return $this->reject('bad signature');
    }

    if (($claims['iss'] ?? null) !== $issuer) {
      return $this->reject('wrong issuer');
    }
    $audience = $claims['aud'] ?? null;
    $audiences = is_array($audience) ? $audience : [$audience];
    if (!in_array($this->config['clientId'], $audiences, true)) {
      return $this->reject('wrong audience');
    }
    if (count($audiences) > 1 && ($claims['azp'] ?? null) !== $this->config['clientId']) {
      return $this->reject('wrong authorized party');
    }
    if (!isset($claims['exp']) || !is_numeric($claims['exp']) || (int)$claims['exp'] + self::LEEWAY < $now) {
      return $this->reject('expired');
    }
    if (isset($claims['iat']) && is_numeric($claims['iat']) && (int)$claims['iat'] - self::LEEWAY > $now) {
      return $this->reject('issued in the future');
    }
    if (!isset($claims['nonce']) || !is_string($claims['nonce']) || !hash_equals($nonce, $claims['nonce'])) {
      return $this->reject('wrong nonce');
    }
    if (empty($claims['sub']) || !is_string($claims['sub']) || strlen($claims['sub']) > 255) {
      return $this->reject('no usable sub');
    }

    return $claims;
  }

  //---------------------------------------------------------------------------
  /**
   * Builds a PEM public key from the base64url modulus and exponent of a JWK.
   *
   * @param string $n The modulus
   * @param string $e The exponent
   *
   * @return string|null The PEM, or null when the values are not usable
   */
  public static function rsaPublicKeyPem(string $n, string $e): ?string
  {
    $modulus = self::base64UrlDecode($n);
    $exponent = self::base64UrlDecode($e);
    if ($modulus === null || $modulus === '' || $exponent === null || $exponent === '') {
      return null;
    }

    $rsaKey = self::der(0x30, self::derInteger($modulus) . self::derInteger($exponent));
    $algorithm = self::der(0x30, "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
    $info = self::der(0x30, $algorithm . self::der(0x03, "\x00" . $rsaKey));

    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($info), 64, "\n") . "-----END PUBLIC KEY-----\n";
  }

  //---------------------------------------------------------------------------
  /**
   * Checks that OIDC is switched on and complete.
   *
   * @return string|null null when ready, otherwise DISABLED or CONFIG
   */
  private function checkReady(): ?string
  {
    if (!$this->config['enabled']) {
      return self::DISABLED;
    }
    if (!function_exists('curl_init') || !function_exists('openssl_verify')) {
      error_log('OIDC: the PHP extension curl or openssl is missing (OIDC_YES is on)');
      return self::CONFIG;
    }
    if ($this->db === null || $this->config['provider'] === '' || $this->config['clientId'] === '' || $this->config['clientSecret'] === '' || $this->config['redirectUri'] === '') {
      error_log('OIDC: OIDC_PROVIDER_URL, OIDC_CLIENT_ID, OIDC_CLIENT_SECRET and OIDC_REDIRECT_URI must all be set (OIDC_YES is on)');
      return self::CONFIG;
    }

    return null;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the discovery document of the provider; its issuer must be the configured provider URL.
   *
   * @return array<string, mixed>|null
   */
  private function discovery(): ?array
  {
    $response = $this->http($this->config['provider'] . '/.well-known/openid-configuration', null);
    $document = $response !== null && $response['status'] === 200 ? json_decode($response['body'], true) : null;
    if (!is_array($document)) {
      return null;
    }
    if (rtrim((string)($document['issuer'] ?? ''), '/') !== $this->config['provider']) {
      error_log('OIDC: the issuer of the provider (' . self::shorten((string)($document['issuer'] ?? '')) . ') differs from OIDC_PROVIDER_URL');
      return null;
    }
    $document['issuer'] = (string)$document['issuer'];

    return $document;
  }

  //---------------------------------------------------------------------------
  /**
   * Fetches a URL (GET) or posts a form to it.
   *
   * @param string                    $url  The URL
   * @param array<string, string>|null $form The form fields for a POST, or null for a GET
   *
   * @return array{status: int, body: string}|null null when the server could not be reached
   */
  private function http(string $url, ?array $form): ?array
  {
    $curl = curl_init($url);
    $options = [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_TIMEOUT => $this->config['timeout'],
      CURLOPT_CONNECTTIMEOUT => $this->config['timeout'],
      CURLOPT_SSL_VERIFYPEER => $this->config['verifyTls'],
      CURLOPT_SSL_VERIFYHOST => $this->config['verifyTls'] ? 2 : 0,
      CURLOPT_HTTPHEADER => ['Accept: application/json'],
      CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ];
    if ($form !== null) {
      $options[CURLOPT_POST] = true;
      $options[CURLOPT_POSTFIELDS] = http_build_query($form, '', '&');
    }
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($body === false) {
      error_log('OIDC: ' . $url . ' could not be reached: ' . $error);
      return null;
    }

    return ['status' => $status, 'body' => (string)$body];
  }

  //---------------------------------------------------------------------------
  /**
   * Uses up a state: it is deleted whether or not it is still valid, and works once only.
   *
   * @param string $state The state from the callback
   *
   * @return array{nonce: string, code_verifier: string}|null null when unknown, used or older than ten minutes
   */
  private function consumeState(string $state): ?array
  {
    $hash = hash('sha256', $state);
    $select = $this->db->prepare('SELECT nonce, code_verifier, created_at >= DATE_SUB(NOW(), INTERVAL ' . self::STATE_SECONDS . ' SECOND) AS valid FROM oidc_states WHERE state_hash = :hash');
    $select->execute(['hash' => $hash]);
    $row = $select->fetch();
    if (!$row) {
      return null;
    }
    $delete = $this->db->prepare('DELETE FROM oidc_states WHERE state_hash = :hash');
    $delete->execute(['hash' => $hash]);
    if ($delete->rowCount() !== 1 || !(int)$row['valid']) {
      return null;
    }

    return ['nonce' => (string)$row['nonce'], 'code_verifier' => (string)$row['code_verifier']];
  }

  //---------------------------------------------------------------------------
  /**
   * Writes why a token was refused to the error log (never the token itself) and returns null.
   *
   * @param string $reason The reason
   *
   * @return null
   */
  private function reject(string $reason): null
  {
    error_log('OIDC: ID token refused: ' . $reason);

    return null;
  }

  //---------------------------------------------------------------------------
  /**
   * Cuts a text from the provider down before it goes to the log.
   *
   * @param string $text The text
   *
   * @return string
   */
  private static function shorten(string $text): string
  {
    return substr((string)preg_replace('/[^\x20-\x7e]/', '?', $text), 0, 200);
  }

  //---------------------------------------------------------------------------
  /**
   * Reads a .env switch: 1, true, yes and on count as on.
   *
   * @param mixed $value The value
   *
   * @return bool
   */
  private static function flag(mixed $value): bool
  {
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
  }

  //---------------------------------------------------------------------------
  /**
   * Encodes bytes as base64url without padding.
   *
   * @param string $bytes The bytes
   *
   * @return string
   */
  private static function base64Url(string $bytes): string
  {
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
  }

  //---------------------------------------------------------------------------
  /**
   * Decodes base64url.
   *
   * @param string $text The text
   *
   * @return string|null null when it is not valid base64url
   */
  private static function base64UrlDecode(string $text): ?string
  {
    if ($text === '' || preg_match('/[^A-Za-z0-9_-]/', $text)) {
      return null;
    }
    $decoded = base64_decode(strtr($text, '-_', '+/'), true);

    return $decoded === false ? null : $decoded;
  }

  //---------------------------------------------------------------------------
  /**
   * Builds a DER element.
   *
   * @param int    $tag     The tag byte
   * @param string $content The content
   *
   * @return string
   */
  private static function der(int $tag, string $content): string
  {
    $length = strlen($content);
    if ($length < 128) {
      $encoded = chr($length);
    } else {
      $bytes = ltrim(pack('N', $length), "\x00");
      $encoded = chr(0x80 | strlen($bytes)) . $bytes;
    }

    return chr($tag) . $encoded . $content;
  }

  //---------------------------------------------------------------------------
  /**
   * Builds a DER INTEGER from an unsigned big-endian number.
   *
   * @param string $bytes The number
   *
   * @return string
   */
  private static function derInteger(string $bytes): string
  {
    $bytes = ltrim($bytes, "\x00");
    if ($bytes === '' || ord($bytes[0]) >= 0x80) {
      $bytes = "\x00" . $bytes;
    }

    return self::der(0x02, $bytes);
  }
}
