<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

//---------------------------------------------------------------------------
/**
 * Token based authentication.
 *
 * On login a random token is created and returned to the client. Only its SHA-256 hash is stored,
 * together with the user and an expiry time that is extended on every authenticated request.
 * Clients send the token as "Authorization: Bearer <token>".
 */
class AuthService
{
  private const ADMIN_ROLE = 'Administrator';
  private const PUBLIC_ROLE = 'Public';

  private PDO $db;
  private int $lifetime;
  private ?array $user = null;
  private bool $resolved = false;

  /** @var string[]|null */
  private ?array $publicPermissions = null;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param PDO $db       The database connection
   * @param int $lifetime Token lifetime in seconds (renewed on every request)
   */
  public function __construct(PDO $db, int $lifetime)
  {
    $this->db = $db;
    $this->lifetime = max(60, $lifetime);
  }

  //---------------------------------------------------------------------------
  /**
   * Creates a token for a user.
   *
   * @param int $userId The user id
   *
   * @return string The token (not stored in clear text)
   */
  public function issueToken(int $userId): string
  {
    $this->db->exec("DELETE FROM auth_tokens WHERE expires_at < NOW()");

    $token = bin2hex(random_bytes(32));
    $this->db->prepare(
      "INSERT INTO auth_tokens (user_id, token_hash, expires_at) VALUES (:user_id, :hash, DATE_ADD(NOW(), INTERVAL :ttl SECOND))"
    )->execute(['user_id' => $userId, 'hash' => hash('sha256', $token), 'ttl' => $this->lifetime]);

    return $token;
  }

  //---------------------------------------------------------------------------
  /**
   * Returns the logged-in user of the current request, or null.
   * The result includes the role names and an is_admin flag.
   *
   * @return array|null
   */
  public function currentUser(): ?array
  {
    if ($this->resolved) {
      return $this->user;
    }
    $this->resolved = true;

    $token = $this->bearerToken();
    if ($token === null) {
      return null;
    }

    $hash = hash('sha256', $token);
    $stmt = $this->db->prepare(
      "SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.avatar, (u.twofa_enabled_at IS NOT NULL) AS twofa_enabled
         FROM auth_tokens t JOIN users u ON u.id = t.user_id
        WHERE t.token_hash = :hash AND t.expires_at > NOW()"
    );
    $stmt->execute(['hash' => $hash]);
    $user = $stmt->fetch();
    if (!$user) {
      return null;
    }

    $this->db->prepare("UPDATE auth_tokens SET expires_at = DATE_ADD(NOW(), INTERVAL :ttl SECOND) WHERE token_hash = :hash")
      ->execute(['ttl' => $this->lifetime, 'hash' => $hash]);

    return $this->user = $this->withAccess($user);
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the current requester holds a permission. Administrators hold every permission.
   * Anonymous visitors (not logged in) hold the permissions of the role "Public", never more.
   *
   * Endpoints that need a login must still call requireUser(); holding a permission through the
   * role "Public" does not make an endpoint reachable for anonymous visitors.
   *
   * @param string $permission The permission name
   *
   * @return bool
   */
  public function can(string $permission): bool
  {
    return $this->hasPermission($this->currentUser(), $permission);
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether a user (or an anonymous visitor when null) holds a permission.
   *
   * @param array|null $user       The user as returned by currentUser(), or null for an anonymous visitor
   * @param string     $permission The permission name
   *
   * @return bool
   */
  public function hasPermission(?array $user, string $permission): bool
  {
    if ($user === null) {
      return in_array($permission, $this->publicPermissions(), true);
    }

    return $user['is_admin'] || in_array($permission, $user['permissions'], true);
  }

  //---------------------------------------------------------------------------
  /**
   * Returns the permissions of the role "Public", which anonymous visitors hold.
   *
   * @return string[] The permission names
   */
  public function publicPermissions(): array
  {
    if ($this->publicPermissions === null) {
      $stmt = $this->db->prepare(
        "SELECT p.name FROM permissions p
           JOIN permission_role pr ON pr.permission_id = p.id
           JOIN roles r ON r.id = pr.role_id
          WHERE r.name = :role
          ORDER BY p.name"
      );
      $stmt->execute(['role' => self::PUBLIC_ROLE]);
      $this->publicPermissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    return $this->publicPermissions;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the logged-in user holds at least one of the given permissions.
   *
   * @param string[] $permissions The permission names
   *
   * @return bool
   */
  public function canAny(array $permissions): bool
  {
    foreach ($permissions as $permission) {
      if ($this->can($permission)) {
        return true;
      }
    }

    return false;
  }

  //---------------------------------------------------------------------------
  /**
   * Builds the user data sent to the client after login.
   *
   * @param int $userId The user id
   *
   * @return array|null
   */
  public function userData(int $userId): ?array
  {
    $stmt = $this->db->prepare("SELECT id, username, email, first_name, last_name, avatar, (twofa_enabled_at IS NOT NULL) AS twofa_enabled FROM users WHERE id = :id");
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();
    if (!$user) {
      return null;
    }

    return $this->withAccess($user);
  }

  //---------------------------------------------------------------------------
  /**
   * Adds the role names, the is_admin flag and the effective permissions to a user.
   * The effective permissions are the union of those granted through the user's roles and groups.
   * twofa_enabled tells whether the user has set up two factor authentication (the secret is never returned).
   *
   * @param array $user The user row
   *
   * @return array
   */
  private function withAccess(array $user): array
  {
    $roles = $this->db->prepare("SELECT r.name FROM user_role ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :id ORDER BY r.name");
    $roles->execute(['id' => $user['id']]);
    $user['roles'] = $roles->fetchAll(PDO::FETCH_COLUMN);
    $user['twofa_enabled'] = !empty($user['twofa_enabled']);
    $user['is_admin'] = in_array(self::ADMIN_ROLE, $user['roles'], true);

    $permissions = $this->db->prepare(
      "SELECT p.name FROM permissions p
        WHERE p.id IN (SELECT pr.permission_id FROM permission_role pr JOIN user_role ur ON ur.role_id = pr.role_id WHERE ur.user_id = :role_user)
           OR p.id IN (SELECT pg.permission_id FROM permission_group pg JOIN user_group ug ON ug.group_id = pg.group_id WHERE ug.user_id = :group_user)
        ORDER BY p.name"
    );
    $permissions->execute(['role_user' => $user['id'], 'group_user' => $user['id']]);
    $user['permissions'] = $permissions->fetchAll(PDO::FETCH_COLUMN);

    return $user;
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes the token of the current request (logout).
   *
   * @return void
   */
  public function revokeCurrentToken(): void
  {
    $token = $this->bearerToken();
    if ($token !== null) {
      $this->db->prepare("DELETE FROM auth_tokens WHERE token_hash = :hash")->execute(['hash' => hash('sha256', $token)]);
    }
    $this->user = null;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the lifetime of a token.
   *
   * @return int Seconds
   */
  public function lifetime(): int
  {
    return $this->lifetime;
  }

  //---------------------------------------------------------------------------
  /**
   * Reads the bearer token from the request headers.
   *
   * @return string|null
   */
  private function bearerToken(): ?string
  {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
      foreach (getallheaders() as $name => $value) {
        if (strcasecmp($name, 'Authorization') === 0) {
          $header = $value;
        }
      }
    }

    return preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/', trim($header), $m) ? strtolower($m[1]) : null;
  }
}
