<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\CustomViewModel;
use App\Models\UserModel;
use App\Services\AuditLog;
use App\Services\AuthPolicy;

//---------------------------------------------------------------------------
/**
 * REST controller for user management.
 *
 *   GET    /users            list
 *   POST   /users            create (username, email, names, roles, groups; a password only without LDAP)
 *   GET    /users/{id}       read (including role_ids and group_ids)
 *   PUT    /users/{id}       update
 *   DELETE /users/{id}       delete
 *   POST   /users/delete     delete many ({"ids": [1, 2]})
 */
class UserController
{
  private UserModel $model;
  private array $protectedUsernames;
  private ?int $actorId;
  private AuditLog $audit;
  private ?AuthPolicy $ldap;
  private ?CustomViewModel $customViews;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param UserModel $model              The user model
   * @param AuditLog  $audit              The audit log
   * @param array     $protectedUsernames Usernames that cannot be deleted
   * @param int|null  $actorId            The logged in user who makes the requests (for the history of the issues)
   * @param AuthPolicy|null $ldap         Tells who authenticates against LDAP or OIDC (no password for those users)
   * @param CustomViewModel|null $customViews Removes the private custom views of deleted users
   */
  public function __construct(UserModel $model, AuditLog $audit, array $protectedUsernames = [], ?int $actorId = null, ?AuthPolicy $ldap = null, ?CustomViewModel $customViews = null)
  {
    $this->customViews = $customViews;
    $this->ldap = $ldap;
    $this->audit = $audit;
    $this->model = $model;
    $this->protectedUsernames = $protectedUsernames;
    $this->actorId = $actorId;
  }

  //---------------------------------------------------------------------------
  /**
   * Dispatches the request.
   *
   * @param string      $method HTTP method
   * @param string|null $param  The path segment after /users ("12", "delete" or null)
   *
   * @return void
   */
  public function handle(string $method, ?string $param): void
  {
    if ($param === null) {
      match ($method) {
        'GET' => $this->respond(['status' => 'success', 'data' => array_map($this->withMethod(...), $this->model->getAll())]),
        'POST' => $this->create(),
        default => $this->fail(405, 'Method not allowed'),
      };
      return;
    }

    if ($param === 'delete') {
      $method === 'POST' ? $this->deleteMany() : $this->fail(405, 'Method not allowed');
      return;
    }

    $id = (int)$param;
    match ($method) {
      'GET' => $this->read($id),
      'PUT' => $this->update($id),
      'DELETE' => $this->deleteMany([$id]),
      default => $this->fail(405, 'Method not allowed'),
    };
  }

  //---------------------------------------------------------------------------
  /**
   * Reads one user.
   */
  private function read(int $id): void
  {
    $user = $this->model->get($id);
    $user ? $this->respond(['status' => 'success', 'data' => $this->withMethod($user)]) : $this->fail(404, 'User not found');
  }

  //---------------------------------------------------------------------------
  /**
   * Creates a user (administrator). With LDAP the account only holds the data; nobody can log in with its password,
   * so a random one is stored. Without LDAP a password is required.
   */
  private function create(): void
  {
    $input = $this->input();
    $username = trim((string)($input['username'] ?? ''));

    $error = null;
    if ($username === '') {
      $error = 'Username is required';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
      $error = 'Username must be 3-50 characters (letters, digits, _ . -)';
    } elseif ($this->model->findByUsername($username)) {
      $error = 'Username is already taken';
    }

    $ldapOn = $this->ldap !== null && $this->ldap->isEnabled();
    if ($ldapOn) {
      unset($input['password']);
    }
    $data = $error === null ? $this->validate($input, ['id' => 0]) : $error;
    if (is_array($data) && !$ldapOn && $data['password'] === null) {
      $data = 'Password is required';
    }
    if (is_string($data)) {
      $this->fail(400, $data);
      return;
    }

    $hash = $ldapOn ? password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT) : $data['password'];

    // The account, its roles and groups and the audit entry stand or fall together
    $id = $this->audit->transaction(function () use ($username, $data, $hash): int {
      $this->model->create(['username' => $username, 'password' => $hash, 'email' => $data['email'], 'first_name' => $data['first_name'], 'last_name' => $data['last_name']]);
      $id = (int)$this->model->findByUsername($username)['id'];
      $this->model->update($id, ['password' => null] + $data, $this->actorId);
      $this->audit->log('user.created', null, 'user', $id, $username, null, ['email' => $data['email']]);
      return $id;
    });
    $this->respond(['status' => 'success', 'message' => 'User created', 'data' => $this->withMethod($this->model->get($id))], 201);
  }

  //---------------------------------------------------------------------------
  /**
   * Whether a user authenticates against LDAP or OIDC (has no password of their own).
   *
   * @param string $username The username
   *
   * @return bool
   */
  private function usesLdap(string $username): bool
  {
    return $this->ldap !== null && $this->ldap->usesExternal($username);
  }

  //---------------------------------------------------------------------------
  /**
   * Adds auth_method ("ldap", "oidc" or "local") to a user for the pages.
   *
   * @param array $user The user
   *
   * @return array
   */
  private function withMethod(array $user): array
  {
    $user['auth_method'] = $this->ldap !== null ? $this->ldap->method((string)$user['username']) : AuthPolicy::LOCAL;

    return $user;
  }

  //---------------------------------------------------------------------------
  /**
   * Updates a user.
   */
  private function update(int $id): void
  {
    $existing = $this->model->get($id);
    if (!$existing) {
      $this->fail(404, 'User not found');
      return;
    }

    // The password of an LDAP user is not used, so it is not changed here either
    $input = $this->input();
    if ($this->usesLdap((string)$existing['username'])) {
      unset($input['password']);
    }

    $data = $this->validate($input, $existing);
    if (is_string($data)) {
      $this->fail(400, $data);
      return;
    }

    // At least one user must keep the Administrator role
    $adminIds = $this->model->administratorUserIds();
    if ($adminIds === [$id] && !in_array($this->model->administratorRoleId(), $data['role_ids'], true)) {
      $this->fail(409, 'At least one user must hold the Administrator role');
      return;
    }

    // The change and its audit entries stand or fall together
    $this->audit->transaction(function () use ($id, $data, $existing): void {
      $this->model->update($id, $data, $this->actorId);
      $this->auditUpdate($id, $existing, $data);
    });
    $this->respond(['status' => 'success', 'message' => 'User updated', 'data' => $this->withMethod($this->model->get($id))]);
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes one or more users.
   *
   * @param int[]|null $ids Ids to delete; read from the request body when null
   */
  private function deleteMany(?array $ids = null): void
  {
    $ids ??= $this->input()['ids'] ?? [];
    $ids = $this->ids($ids);

    if (empty($ids)) {
      $this->fail(400, 'No ids given');
      return;
    }

    foreach ($ids as $id) {
      $user = $this->model->get($id);
      if ($user && in_array($user['username'], $this->protectedUsernames, true)) {
        $this->fail(409, "User \"{$user['username']}\" is a system account and cannot be deleted");
        return;
      }
    }

    // At least one user must keep the Administrator role
    $adminIds = $this->model->administratorUserIds();
    if (!empty($adminIds) && empty(array_diff($adminIds, $ids))) {
      $this->fail(409, 'At least one user must hold the Administrator role');
      return;
    }

    $deleted = $this->audit->transaction(function () use ($ids): int {
      // The entries hold the name and email of the account: afterwards it is gone
      $users = array_filter(array_map(fn(int $id): ?array => $this->model->get($id), $ids));
      // The private custom views go with their owners (the shared ones stay, without an owner)
      foreach ($this->customViews?->privateOf($ids) ?? [] as $view) {
        $this->audit->log('customview.deleted', null, 'customview', $view['id'], $view['name'], ['shared' => false, 'filters' => (array)$view['filters']]);
      }
      $this->customViews?->deletePrivateOf($ids);
      $count = $this->model->deleteMany($ids);
      foreach ($users as $user) {
        $this->audit->log('user.deleted', null, 'user', (int)$user['id'], (string)$user['username'], ['email' => $user['email']]);
      }
      return $count;
    });
    $this->respond(['status' => 'success', 'message' => 'Users deleted', 'deleted' => $deleted]);
  }

  //---------------------------------------------------------------------------
  /**
   * Writes the audit entries of a user update: the changed profile fields, a new password (masked), and the changed
   * roles and groups with their names. Runs inside the transaction of the update.
   *
   * @param int   $id       The user id
   * @param array $existing The user before the update (UserModel::get())
   * @param array $data     The validated input
   *
   * @return void
   */
  private function auditUpdate(int $id, array $existing, array $data): void
  {
    $username = (string)$existing['username'];

    $diff = AuditLog::diff($existing, $data, ['email', 'first_name', 'last_name']);
    if ($diff['new'] !== []) {
      $this->audit->log('user.updated', null, 'user', $id, $username, $diff['old'], $diff['new']);
    }
    if (!empty($data['password'])) {
      $this->audit->log('auth.password_changed', null, 'user', $id, $username, ['password' => AuditLog::MASK], ['password' => AuditLog::MASK]);
    }

    foreach (['roles' => 'role_ids', 'groups' => 'group_ids'] as $table => $field) {
      $before = $this->sorted($existing[$field] ?? []);
      $after = $this->sorted($data[$field] ?? []);
      if ($before !== $after) {
        $this->audit->log("user.{$table}_changed", null, 'user', $id, $username, [$table => $this->model->names($table, $before)], [$table => $this->model->names($table, $after)]);
      }
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Sorts a list of ids, for comparing two lists.
   *
   * @param array $ids The ids
   *
   * @return int[]
   */
  private function sorted(array $ids): array
  {
    $ids = array_map('intval', $ids);
    sort($ids);

    return $ids;
  }

  //---------------------------------------------------------------------------
  /**
   * Validates and normalizes the input.
   *
   * @param array $input    The raw input
   * @param array $existing The existing user
   *
   * @return array|string Normalized data or an error message
   */
  private function validate(array $input, array $existing): array|string
  {
    $email = trim((string)($input['email'] ?? ''));
    $firstName = trim((string)($input['first_name'] ?? ''));
    $lastName = trim((string)($input['last_name'] ?? ''));
    $password = (string)($input['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
      return 'Invalid email address';
    }
    if (mb_strlen($firstName) > 50 || mb_strlen($lastName) > 50) {
      return 'First and last name must not be longer than 50 characters';
    }
    if ($password !== '' && strlen($password) < 8) {
      return 'Password must be at least 8 characters';
    }

    $other = $this->model->findByEmail($email);
    if ($other && (int)$other['id'] !== (int)$existing['id']) {
      return 'Email is already registered';
    }

    return [
      'email' => $email,
      'first_name' => $firstName !== '' ? $firstName : null,
      'last_name' => $lastName !== '' ? $lastName : null,
      'password' => $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null,
      'role_ids' => $this->model->existingIds('roles', $this->ids($input['role_ids'] ?? [])),
      'group_ids' => $this->model->existingIds('groups', $this->ids($input['group_ids'] ?? [])),
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * Normalizes a list of ids.
   *
   * @param mixed $value The raw value
   *
   * @return int[]
   */
  private function ids(mixed $value): array
  {
    return array_values(array_unique(array_filter(array_map('intval', (array)$value), fn($v) => $v > 0)));
  }

  //---------------------------------------------------------------------------
  /**
   * Reads the JSON request body.
   */
  private function input(): array
  {
    $input = json_decode(file_get_contents('php://input'), true);
    return is_array($input) ? $input : [];
  }

  //---------------------------------------------------------------------------
  /**
   * Sends a JSON response.
   */
  private function respond(array $payload, int $status = 200): void
  {
    http_response_code($status);
    echo json_encode($payload);
  }

  //---------------------------------------------------------------------------
  /**
   * Sends a JSON error response.
   */
  private function fail(int $status, string $message): void
  {
    $this->respond(['status' => 'error', 'message' => $message], $status);
  }
}
