<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\IssueHistory;
use PDO;

//---------------------------------------------------------------------------
/**
 * User model for database interactions.
 */
class UserModel
{
  private PDO $db;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param PDO $db The database connection
   */
  public function __construct(PDO $db)
  {
    $this->db = $db;
  }

  //---------------------------------------------------------------------------
  /**
   * Finds a user by their username.
   *
   * @param string $username The username to search for
   *
   * @return array|null User data or null if not found
   */
  public function findByUsername(string $username): ?array
  {
    $stmt = $this->db->prepare("SELECT * FROM users WHERE username = :username");
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    return $user ?: null;
  }

  //---------------------------------------------------------------------------
  /**
   * Finds a user by the permanent subject identifier (sub) of the OIDC Identity Provider.
   *
   * @param string $sub The subject
   *
   * @return array|null User data or null if not found
   */
  public function findByOidcSub(string $sub): ?array
  {
    if ($sub === '') {
      return null;
    }
    $stmt = $this->db->prepare("SELECT * FROM users WHERE oidc_sub = :sub");
    $stmt->execute(['sub' => $sub]);
    $user = $stmt->fetch();

    return $user ?: null;
  }

  //---------------------------------------------------------------------------
  /**
   * Binds the OIDC subject to a user on the first SSO login. Only an account without a subject is bound, so a bound
   * account is never taken over by somebody else with the same username at the provider.
   *
   * @param int    $id  The user id
   * @param string $sub The subject
   *
   * @return bool False when the account is already bound or the subject belongs to another account
   */
  public function saveOidcSub(int $id, string $sub): bool
  {
    try {
      $stmt = $this->db->prepare("UPDATE users SET oidc_sub = :sub WHERE id = :id AND oidc_sub IS NULL");
      $stmt->execute(['sub' => $sub, 'id' => $id]);
    } catch (\PDOException $e) {
      // The subject is unique: another account holds it already
      if ($e->getCode() === '23000') {
        return false;
      }
      throw $e;
    }

    return $stmt->rowCount() === 1;
  }

  //---------------------------------------------------------------------------
  /**
   * Finds a user by their email address.
   *
   * @param string $email The email to search for
   *
   * @return array|null User data or null if not found
   */
  public function findByEmail(string $email): ?array
  {
    $stmt = $this->db->prepare("SELECT * FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    return $user ?: null;
  }

  //---------------------------------------------------------------------------
  /**
   * Creates a new user.
   *
   * @param array $data User data (username, password, email, etc.)
   *
   * @return bool Success or failure
   */
  public function create(array $data): bool
  {
    $sql = "INSERT INTO users (username, password, email, first_name, last_name)
            VALUES (:username, :password, :email, :first_name, :last_name)";

    $stmt = $this->db->prepare($sql);
    return $stmt->execute([
      'username' => $data['username'],
      'password' => $data['password'],
      'email' => $data['email'],
      'first_name' => $data['first_name'] ?? null,
      'last_name' => $data['last_name'] ?? null
    ]);
  }

  //---------------------------------------------------------------------------
  /**
   * Gives a user a role by its name (nothing happens if the user has it already or the role does not exist).
   *
   * @param int    $userId   The user id
   * @param string $roleName The role name, e.g. "User"
   *
   * @return void
   */
  public function assignRole(int $userId, string $roleName): void
  {
    $stmt = $this->db->prepare(
      'INSERT IGNORE INTO user_role (user_id, role_id) SELECT :user, id FROM roles WHERE name = :name'
    );
    $stmt->execute(['user' => $userId, 'name' => $roleName]);
  }

  //---------------------------------------------------------------------------
  /**
   * Counts all users except the built-in administrator "admin" (the license limit; inactive users count too).
   *
   * @return int
   */
  public function countExceptAdmin(): int
  {
    return (int)$this->db->query("SELECT COUNT(*) FROM users WHERE username <> 'admin'")->fetchColumn();
  }

  //---------------------------------------------------------------------------
  /**
   * Gets all users (without password hashes) including their roles and groups.
   *
   * @return array
   */
  public function getAll(): array
  {
    $users = $this->db->query(
      "SELECT id, username, email, first_name, last_name, avatar, (twofa_enabled_at IS NOT NULL) AS twofa_enabled, created_at, updated_at
         FROM users ORDER BY username"
    )->fetchAll();

    $roles = $this->linkedEntities('user_role', 'roles', 'role_id');
    $groups = $this->linkedEntities('user_group', 'groups', 'group_id');
    foreach ($users as &$user) {
      $user['twofa_enabled'] = (bool)$user['twofa_enabled'];
      $user['roles'] = $roles[$user['id']] ?? [];
      $user['groups'] = $groups[$user['id']] ?? [];
    }
    unset($user);

    return $users;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets one user (without password hash) including the ids of the assigned roles and groups.
   *
   * @param int $id The user id
   *
   * @return array|null
   */
  public function get(int $id): ?array
  {
    $stmt = $this->db->prepare(
      "SELECT id, username, email, first_name, last_name, avatar, (twofa_enabled_at IS NOT NULL) AS twofa_enabled, created_at, updated_at
         FROM users WHERE id = :id"
    );
    $stmt->execute(['id' => $id]);
    $user = $stmt->fetch();
    if (!$user) {
      return null;
    }
    $user['twofa_enabled'] = (bool)$user['twofa_enabled'];

    $user['role_ids'] = $this->linkedIds('user_role', 'role_id', $id);
    $user['group_ids'] = $this->linkedIds('user_group', 'group_id', $id);

    return $user;
  }

  //---------------------------------------------------------------------------
  /**
   * Updates a user, including the role and group assignments, in one transaction.
   *
   * @param int      $id      The user id
   * @param array    $data    email, first_name, last_name, password (hash or null to keep), role_ids, group_ids
   * @param int|null $actorId The user who makes the change (for the history of the issues that lose their assignee)
   *
   * @return bool
   */
  public function update(int $id, array $data, ?int $actorId = null): bool
  {
    $own = !$this->db->inTransaction();
    if ($own) {
      $this->db->beginTransaction();
    }
    try {
      $sql = "UPDATE users SET email = :email, first_name = :first_name, last_name = :last_name";
      $params = [
        'id' => $id,
        'email' => $data['email'],
        'first_name' => $data['first_name'],
        'last_name' => $data['last_name'],
      ];
      if (!empty($data['password'])) {
        $sql .= ", password = :password";
        $params['password'] = $data['password'];
      }
      $this->db->prepare($sql . " WHERE id = :id")->execute($params);

      $this->replaceLinks('user_role', 'role_id', $id, $data['role_ids']);
      $this->replaceLinks('user_group', 'group_id', $id, $data['group_ids']);
      $this->releaseAssignments($id, $actorId);

      if ($own) {
        $this->db->commit();
      }
      return true;
    } catch (\Throwable $e) {
      if ($own && $this->db->inTransaction()) {
        $this->db->rollBack();
      }
      throw $e;
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Clears the assignment of the issues of a user who is no Agent (any more), with a history entry each.
   * Only users with the role Agent can be assigned. Runs inside the transaction of the role change.
   *
   * @param int      $id      The user id
   * @param int|null $actorId The user who makes the change
   *
   * @return void
   */
  private function releaseAssignments(int $id, ?int $actorId): void
  {
    $isAgent = $this->db->prepare("SELECT COUNT(*) FROM user_role ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :id AND r.name = 'Agent'");
    $isAgent->execute(['id' => $id]);
    if ((int)$isAgent->fetchColumn() > 0) {
      return;
    }

    $issues = $this->db->prepare('SELECT id FROM issues WHERE assignee_id = :id FOR UPDATE');
    $issues->execute(['id' => $id]);
    $issueIds = $issues->fetchAll(PDO::FETCH_COLUMN);
    if (empty($issueIds)) {
      return;
    }

    $user = $this->db->prepare('SELECT username, first_name, last_name FROM users WHERE id = :id');
    $user->execute(['id' => $id]);
    $row = $user->fetch();
    $name = trim(trim((string)$row['first_name']) . ' ' . trim((string)$row['last_name']));
    $label = $name !== '' ? $name : (string)$row['username'];

    $history = new IssueHistory($this->db);
    $clear = $this->db->prepare('UPDATE issues SET assignee_id = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :issue');
    foreach ($issueIds as $issueId) {
      $clear->execute(['issue' => $issueId]);
      $history->log((int)$issueId, $actorId, 'field_changed', 'assignee', $label, null);
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the profile data of one user (without password hash).
   *
   * @param int $id The user id
   *
   * @return array|null
   */
  public function getProfile(int $id): ?array
  {
    $stmt = $this->db->prepare(
      "SELECT id, username, email, first_name, last_name, avatar, (twofa_enabled_at IS NOT NULL) AS twofa_enabled FROM users WHERE id = :id"
    );
    $stmt->execute(['id' => $id]);
    $user = $stmt->fetch();
    if ($user) {
      $user['twofa_enabled'] = (bool)$user['twofa_enabled'];
    }

    return $user ?: null;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the password hash of a user.
   *
   * @param int $id The user id
   *
   * @return string|null
   */
  public function getPasswordHash(int $id): ?string
  {
    $stmt = $this->db->prepare("SELECT password FROM users WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $hash = $stmt->fetchColumn();

    return $hash === false ? null : (string)$hash;
  }

  //---------------------------------------------------------------------------
  /**
   * Updates the profile fields of a user.
   *
   * @param int   $id   The user id
   * @param array $data email, first_name, last_name, password (hash or null to keep)
   *
   * @return bool
   */
  public function updateProfile(int $id, array $data): bool
  {
    $sql = "UPDATE users SET email = :email, first_name = :first_name, last_name = :last_name";
    $params = [
      'id' => $id,
      'email' => $data['email'],
      'first_name' => $data['first_name'],
      'last_name' => $data['last_name'],
    ];
    if (!empty($data['password'])) {
      $sql .= ", password = :password";
      $params['password'] = $data['password'];
    }

    return $this->db->prepare($sql . " WHERE id = :id")->execute($params);
  }

  //---------------------------------------------------------------------------
  /**
   * Sets the avatar of a user.
   *
   * @param int         $id     The user id
   * @param string|null $avatar "sample/<file>", "upload/<file>" or null to remove it
   *
   * @return bool
   */
  public function setAvatar(int $id, ?string $avatar): bool
  {
    return $this->db->prepare("UPDATE users SET avatar = :avatar WHERE id = :id")->execute(['id' => $id, 'avatar' => $avatar]);
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes users by id. Role and group assignments are removed by the foreign keys.
   *
   * @param int[] $ids The ids
   *
   * @return int Number of deleted rows
   */
  public function deleteMany(array $ids): int
  {
    if (empty($ids)) {
      return 0;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $this->db->prepare("DELETE FROM users WHERE id IN ($placeholders)");
    $stmt->execute(array_values($ids));
    return $stmt->rowCount();
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the names of roles or groups (for the audit log).
   *
   * @param string $table roles or groups
   * @param int[]  $ids   The ids
   *
   * @return string[] The names, sorted
   */
  public function names(string $table, array $ids): array
  {
    if (empty($ids) || !in_array($table, ['roles', 'groups'], true)) {
      return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $this->db->prepare("SELECT name FROM `$table` WHERE id IN ($placeholders) ORDER BY name");
    $stmt->execute(array_values($ids));

    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the id of the Administrator role.
   *
   * @return int|null
   */
  public function administratorRoleId(): ?int
  {
    $id = $this->db->query("SELECT id FROM roles WHERE name = 'Administrator'")->fetchColumn();

    return $id === false ? null : (int)$id;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the ids of all users holding the Administrator role.
   *
   * @return int[]
   */
  public function administratorUserIds(): array
  {
    $stmt = $this->db->query(
      "SELECT ur.user_id FROM user_role ur JOIN roles r ON r.id = ur.role_id WHERE r.name = 'Administrator'"
    );

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
  }

  //---------------------------------------------------------------------------
  /**
   * Filters ids down to those that exist in a table.
   *
   * @param string $table The table (roles or groups)
   * @param int[]  $ids   The ids to check
   *
   * @return int[] The existing ids
   */
  public function existingIds(string $table, array $ids): array
  {
    if (empty($ids)) {
      return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $this->db->prepare("SELECT id FROM `$table` WHERE id IN ($placeholders)");
    $stmt->execute(array_values($ids));
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the entities linked to each user as lists of name/color/icon arrays, keyed by user id.
   *
   * @param string $linkTable  The link table
   * @param string $table      The entity table
   * @param string $linkColumn The entity column in the link table
   *
   * @return array
   */
  private function linkedEntities(string $linkTable, string $table, string $linkColumn): array
  {
    $rows = $this->db->query(
      "SELECT l.user_id, e.name, e.color, e.icon FROM `$linkTable` l JOIN `$table` e ON e.id = l.`$linkColumn` ORDER BY e.name"
    )->fetchAll();

    $result = [];
    foreach ($rows as $row) {
      $result[$row['user_id']][] = ['name' => $row['name'], 'color' => $row['color'], 'icon' => $row['icon']];
    }

    return $result;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the entity ids linked to a user.
   *
   * @param string $linkTable  The link table
   * @param string $linkColumn The entity column in the link table
   * @param int    $userId     The user id
   *
   * @return int[]
   */
  private function linkedIds(string $linkTable, string $linkColumn, int $userId): array
  {
    $stmt = $this->db->prepare("SELECT `$linkColumn` FROM `$linkTable` WHERE user_id = :id");
    $stmt->execute(['id' => $userId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
  }

  //---------------------------------------------------------------------------
  /**
   * Replaces all links of a user in a link table.
   *
   * @param string $linkTable  The link table
   * @param string $linkColumn The entity column in the link table
   * @param int    $userId     The user id
   * @param int[]  $ids        The entity ids to link
   *
   * @return void
   */
  private function replaceLinks(string $linkTable, string $linkColumn, int $userId, array $ids): void
  {
    $this->db->prepare("DELETE FROM `$linkTable` WHERE user_id = :id")->execute(['id' => $userId]);

    $insert = $this->db->prepare("INSERT INTO `$linkTable` (user_id, `$linkColumn`) VALUES (:user_id, :entity_id)");
    foreach ($ids as $entityId) {
      $insert->execute(['user_id' => $userId, 'entity_id' => $entityId]);
    }
  }
}
