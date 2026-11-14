<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserModel;
use App\Services\AuditLog;
use App\Services\AuthService;
use App\Services\LdapService;
use App\Services\OidcService;
use App\Services\QrCode;
use App\Services\Totp;
use App\Services\TwoFactorService;
use RuntimeException;

//---------------------------------------------------------------------------
/**
 * REST controller for the two factor authentication (docs: _docs/2FA.md).
 *
 *   POST /auth/2fa/verify            pending token (challenge) + code -> the login token
 *   POST /auth/2fa/setup             pending token (setup) -> new secret, otpauth URI and QR code
 *   POST /auth/2fa/enable            pending token (setup) + code -> 2FA is on (a forced login is completed)
 *   POST /auth/2fa/begin             logged in: pending token (setup) for the profile page
 *   POST /profile/{id}/2fa/disable   own 2FA off, needs the password
 *   POST /users/{id}/2fa/reset       2FA of a user off (administrator)
 *
 * The pending tokens are sent in the request body, never as login token. Error responses carry "reason" for
 * the pages: pending_expired (log in again), locked (with retry_after seconds), invalid_code.
 */
class TwoFactorController
{
  private TwoFactorService $service;
  private AuthService $auth;
  private UserModel $users;
  private Totp $totp;
  private QrCode $qr;
  private AuditLog $audit;
  private ?LdapService $ldap;
  private ?OidcService $oidc;

  /** @var bool False while the license is limited: nobody can set up 2FA (users who have it keep it) */
  private bool $enrolAllowed = true;

  //---------------------------------------------------------------------------
  /**
   * Allows or refuses setting up 2FA (refused in the limited mode of the license).
   *
   * @param bool $allowed False refuses the setup
   *
   * @return void
   */
  public function allowEnrolment(bool $allowed): void
  {
    $this->enrolAllowed = $allowed;
  }

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param TwoFactorService $service The 2FA rules
   * @param AuthService      $auth    The authentication service
   * @param UserModel        $users   The user model
   * @param Totp             $totp    Builds the otpauth URI
   * @param QrCode           $qr      Draws the QR code
   * @param AuditLog         $audit   The audit log
   * @param LdapService|null $ldap    Tells who authenticates against LDAP (for the audit log)
   * @param OidcService|null $oidc    Tells who signs in with SSO (the Identity Provider handles their second factor)
   */
  public function __construct(TwoFactorService $service, AuthService $auth, UserModel $users, Totp $totp, QrCode $qr, AuditLog $audit, ?LdapService $ldap = null, ?OidcService $oidc = null)
  {
    $this->ldap = $ldap;
    $this->oidc = $oidc;
    $this->service = $service;
    $this->auth = $auth;
    $this->users = $users;
    $this->totp = $totp;
    $this->qr = $qr;
    $this->audit = $audit;
  }

  //---------------------------------------------------------------------------
  /**
   * Dispatches a /auth/2fa/{action} request.
   *
   * @param string $method HTTP method
   * @param string $action verify, setup, enable or begin
   *
   * @return void
   */
  public function handleAuth(string $method, string $action): void
  {
    if ($method !== 'POST') {
      $this->fail(405, 'Method not allowed');
    }

    if (!$this->enrolAllowed && in_array($action, ['setup', 'enable', 'begin'], true)) {
      $this->fail(402, 'This feature is currently disabled due to an invalid license.', ['code' => 'license_limit']);
    }

    try {
      match ($action) {
        'verify' => $this->verify(),
        'setup' => $this->setup(),
        'enable' => $this->enable(),
        'begin' => $this->begin(),
        default => $this->fail(404, 'Not found'),
      };
    } catch (RuntimeException $e) {
      // Most likely the encryption key is not set up; the message says what to do
      error_log('Two factor authentication: ' . $e->getMessage());
      $this->fail(500, $e->getMessage());
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Checks the code of a login and issues the login token.
   *
   * @return void
   */
  private function verify(): void
  {
    $input = $this->input();
    $pending = $this->service->resolvePending((string)($input['pending_token'] ?? ''), TwoFactorService::CHALLENGE);
    if ($pending === null) {
      $this->expired();
    }

    $userId = (int)$pending['user_id'];
    $this->denyIfLocked($userId);

    if (!$this->service->verifyLogin($pending, (string)($input['code'] ?? ''))) {
      $this->service->recordFailure($userId, $this->ip());
      $this->auditFailure($userId, 'login');
      $this->denyIfLocked($userId);
      $this->fail(401, 'The code is not valid. Please try again.', ['reason' => 'invalid_code']);
    }

    $this->service->clearFailures($userId);
    $this->respondLogin($userId);
  }

  //---------------------------------------------------------------------------
  /**
   * Starts (or restarts) the enrollment: a new secret with its QR code.
   *
   * @return void
   */
  private function setup(): void
  {
    $pending = $this->service->resolvePending((string)($this->input()['pending_token'] ?? ''), TwoFactorService::SETUP);
    if ($pending === null) {
      $this->expired();
    }

    $userId = (int)$pending['user_id'];
    if ($this->service->isEnrolled($userId)) {
      $this->fail(409, '2FA is already set up.');
    }

    $profile = $this->users->getProfile($userId);
    $secret = $this->service->startSetup($pending);
    $uri = $this->totp->uri($secret, (string)($profile['email'] ?? ''));

    $this->respond([
      'status' => 'success',
      'secret' => $secret,
      'otpauth_uri' => $uri,
      'qr_svg' => $this->qr->svg($uri),
    ]);
  }

  //---------------------------------------------------------------------------
  /**
   * Confirms the enrollment with a code. A forced login (no login token sent) is completed with the login token;
   * a logged in user (profile page) only gets 2FA switched on and keeps the session.
   *
   * @return void
   */
  private function enable(): void
  {
    $input = $this->input();
    $pending = $this->service->resolvePending((string)($input['pending_token'] ?? ''), TwoFactorService::SETUP);
    if ($pending === null) {
      $this->expired();
    }

    $userId = (int)$pending['user_id'];
    $this->denyIfLocked($userId);

    // The switch-on and its audit entry stand or fall together
    $confirmed = $this->audit->transaction(function () use ($pending, $input, $userId): bool {
      $ok = $this->service->confirmSetup($pending, (string)($input['code'] ?? ''));
      if ($ok) {
        $this->audit->log('auth.2fa_enabled', $this->actor($userId), 'user', $userId, $this->username($userId));
      }
      return $ok;
    });
    if (!$confirmed) {
      $this->service->recordFailure($userId, $this->ip());
      $this->auditFailure($userId, 'setup');
      $this->denyIfLocked($userId);
      // Not 401: a logged in client would take that for an expired session
      $this->fail(422, 'The code is not valid. Please try again.', ['reason' => 'invalid_code']);
    }

    $this->service->clearFailures($userId);

    $current = $this->auth->currentUser();
    if ($current !== null && (int)$current['id'] === $userId) {
      $this->respond(['status' => 'success', 'enabled' => true]);
      return;
    }

    $this->respondLogin($userId);
  }

  //---------------------------------------------------------------------------
  /**
   * Gives a logged in user a pending token for the setup page (profile path).
   *
   * @return void
   */
  private function begin(): void
  {
    $user = $this->auth->currentUser();
    if ($user === null) {
      $this->fail(401, 'Authentication required');
    }
    if ($this->oidc !== null && $this->oidc->usesOidc((string)$user['username'])) {
      $this->fail(403, 'Two factor authentication is managed by your Identity Provider.');
    }
    if ($this->service->isEnrolled((int)$user['id'])) {
      $this->fail(409, '2FA is already set up.');
    }

    $this->respond(['status' => 'success', 'pending_token' => $this->service->createPending((int)$user['id'], TwoFactorService::SETUP)]);
  }

  //---------------------------------------------------------------------------
  /**
   * Switches 2FA off for the logged in user. Needs the password. Allowed when 2FA is mandatory too; the user then
   * has to set it up again at the next login. Only the own account (an administrator uses reset()).
   *
   * @param string $method HTTP method
   * @param int    $id     The user id from the path
   *
   * @return void
   */
  public function disable(string $method, int $id): void
  {
    if ($method !== 'POST') {
      $this->fail(405, 'Method not allowed');
    }

    $user = $this->auth->currentUser();
    if ($user === null) {
      $this->fail(401, 'Authentication required');
    }
    if ((int)$user['id'] !== $id) {
      $this->fail(403, 'Forbidden');
    }
    if (!$this->service->isEnrolled($id)) {
      $this->fail(409, '2FA is not set up.');
    }

    $password = (string)($this->input()['password'] ?? '');
    $username = $this->username($id);
    // Not 401: the client would take that for an expired session
    if ($this->ldap !== null && $this->ldap->usesLdap($username)) {
      // An LDAP user confirms with the LDAP password
      $result = $password === '' ? LdapService::PASSWORD_MISSING : $this->ldap->verify($username, $password);
      if ($result !== LdapService::OK) {
        $failure = LdapService::failure($result);
        if ($failure['status'] !== 401) {
          $this->fail($failure['status'], $failure['message']);
        }
        $this->fail(403, 'The password is not correct.', ['reason' => 'invalid_password']);
      }
    } else {
      $hash = $this->users->getPasswordHash($id);
      if ($hash === null || $password === '' || !password_verify($password, $hash)) {
        $this->fail(403, 'The password is not correct.', ['reason' => 'invalid_password']);
      }
    }

    $this->audit->transaction(function () use ($id): void {
      $this->service->disable($id);
      $this->audit->log('auth.2fa_disabled', $this->actor($id), 'user', $id, $this->username($id));
    });
    $this->respond(['status' => 'success', 'message' => '2FA has been disabled.']);
  }

  //---------------------------------------------------------------------------
  /**
   * Switches 2FA off for a user (administrator, e.g. after a lost device). The caller has checked manage_users.
   *
   * @param string $method  HTTP method
   * @param int    $id      The user id from the path
   * @param int    $actorId The administrator
   *
   * @return void
   */
  public function reset(string $method, int $id, int $actorId): void
  {
    if ($method !== 'POST') {
      $this->fail(405, 'Method not allowed');
    }
    if ($this->users->getProfile($id) === null) {
      $this->fail(404, 'User not found');
    }
    if (!$this->service->isEnrolled($id)) {
      $this->fail(409, '2FA is not set up for this user.');
    }

    // The actor is the administrator (the logged in user of the request)
    $this->audit->transaction(function () use ($id): void {
      $this->service->disable($id);
      $this->audit->log('auth.2fa_reset', null, 'user', $id, $this->username($id));
    });
    error_log("Two factor authentication of user $id was reset by user $actorId");
    $this->respond(['status' => 'success', 'message' => '2FA has been reset.']);
  }

  //---------------------------------------------------------------------------
  /**
   * Sends the response of a completed login (the same as /auth/login without 2FA).
   *
   * @param int $userId The user id
   *
   * @return void
   */
  private function respondLogin(int $userId): void
  {
    $this->audit->logQuiet('auth.login', $this->actor($userId), 'user', $userId, $this->username($userId), null, ['second_factor' => true, 'method' => $this->loginMethod($userId)]);
    $this->respond([
      'status' => 'success',
      'token' => $this->auth->issueToken($userId),
      'expires_in' => $this->auth->lifetime(),
      'user' => $this->auth->userData($userId),
    ]);
  }

  //---------------------------------------------------------------------------
  /**
   * Writes the audit entries of a wrong code: the failure and, when it starts the lock, the lock.
   *
   * @param int    $userId The user id
   * @param string $step   login or setup
   *
   * @return void
   */
  private function auditFailure(int $userId, string $step): void
  {
    $actor = $this->actor($userId);
    $username = $this->username($userId);
    $this->audit->logFailure('auth.2fa_failed', $actor, 'user', $userId, $username, null, ['step' => $step]);

    // This failure is the one that reached the limit (the check before the code passed, so it was not locked before)
    $seconds = $this->service->lockedFor($userId, $this->ip());
    if ($seconds > 0) {
      $this->audit->logFailure('auth.2fa_locked', $actor, 'user', $userId, $username, null, ['seconds' => $seconds]);
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the user as the actor of an audit entry (the request has no login token at this point of a login).
   *
   * @param int $userId The user id
   *
   * @return array
   */
  private function actor(int $userId): array
  {
    return ['id' => $userId, 'username' => $this->username($userId)];
  }

  //---------------------------------------------------------------------------
  /**
   * Gets how a user logs in, for the audit log.
   *
   * @param int $userId The user id
   *
   * @return string "oidc", "ldap" or "local"
   */
  private function loginMethod(int $userId): string
  {
    $username = $this->username($userId);
    if ($this->oidc !== null && $this->oidc->usesOidc($username)) {
      return 'oidc';
    }

    return $this->ldap !== null && $this->ldap->usesLdap($username) ? 'ldap' : 'local';
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the username of a user.
   *
   * @param int $userId The user id
   *
   * @return string
   */
  private function username(int $userId): string
  {
    return (string)($this->users->getProfile($userId)['username'] ?? '');
  }

  //---------------------------------------------------------------------------
  /**
   * Ends the request with 429 while the user or the IP address is locked.
   *
   * @param int $userId The user id
   *
   * @return void
   */
  private function denyIfLocked(int $userId): void
  {
    $seconds = $this->service->lockedFor($userId, $this->ip());
    if ($seconds > 0) {
      header('Retry-After: ' . $seconds);
      $this->fail(429, 'Too many wrong codes. Please try again later.', ['reason' => 'locked', 'retry_after' => $seconds]);
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Ends the request: the pending token is unknown or has expired.
   *
   * @return never
   */
  private function expired(): never
  {
    $this->audit->logFailure('security.2fa_pending_invalid');
    $this->fail(401, 'Your login has expired. Please log in again.', ['reason' => 'pending_expired']);
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the IP address of the request. Only REMOTE_ADDR is trusted; forwarding headers can be forged.
   *
   * @return string
   */
  private function ip(): string
  {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
  }

  //---------------------------------------------------------------------------
  /**
   * Reads the JSON request body.
   *
   * @return array
   */
  private function input(): array
  {
    $input = json_decode(file_get_contents('php://input'), true);

    return is_array($input) ? $input : [];
  }

  //---------------------------------------------------------------------------
  /**
   * Sends a JSON response.
   *
   * @param array $payload The response
   * @param int   $status  HTTP status code
   *
   * @return void
   */
  private function respond(array $payload, int $status = 200): void
  {
    http_response_code($status);
    echo json_encode($payload);
  }

  //---------------------------------------------------------------------------
  /**
   * Sends a JSON error response and ends the request.
   *
   * @param int    $status HTTP status code
   * @param string $message The message
   * @param array  $extra   More fields (reason, retry_after)
   *
   * @return never
   */
  private function fail(int $status, string $message, array $extra = []): never
  {
    $this->respond(['status' => 'error', 'message' => $message] + $extra, $status);
    exit;
  }
}
