<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\PermissionModel;
use App\Services\AuditLog;

//---------------------------------------------------------------------------
/**
 * REST controller for permissions.
 *
 *   GET    /permissions            list
 *   POST   /permissions            create
 *   GET    /permissions/{id}       read (including role_ids and group_ids)
 *   PUT    /permissions/{id}       update
 *   DELETE /permissions/{id}       delete
 *   POST   /permissions/delete     delete many ({"ids": [1, 2]})
 */
class PermissionController
{
  private PermissionModel $model;
  private AuditLog $audit;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param PermissionModel $model The model
   */
  public function __construct(PermissionModel $model, AuditLog $audit)
  {
    $this->model = $model;
    $this->audit = $audit;
  }

  //---------------------------------------------------------------------------
  /**
   * Dispatches the request.
   *
   * @param string      $method HTTP method
   * @param string|null $param  The path segment after the collection ("12", "delete" or null)
   *
   * @return void
   */
  public function handle(string $method, ?string $param): void
  {
    if ($param === null) {
      match ($method) {
        'GET' => $this->respond(['status' => 'success', 'data' => $this->model->getAll()]),
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
   * Reads one permission.
   */
  private function read(int $id): void
  {
    $permission = $this->model->get($id);
    $permission ? $this->respond(['status' => 'success', 'data' => $permission]) : $this->fail(404, 'Permission not found');
  }

  //---------------------------------------------------------------------------
  /**
   * Creates a permission.
   */
  private function create(): void
  {
    $data = $this->validate($this->input(), null);
    if (is_string($data)) {
      $this->fail(400, $data);
      return;
    }

    $id = $this->audit->transaction(function () use ($data): int {
      $id = $this->model->create($data);
      $this->audit->log('permission.created', null, 'permission', $id, $data['name'], null, $this->state($data));
      return $id;
    });
    $this->respond(['status' => 'success', 'message' => 'Permission created', 'data' => $this->model->get($id)], 201);
  }

  //---------------------------------------------------------------------------
  /**
   * Updates a permission.
   */
  private function update(int $id): void
  {
    $existing = $this->model->get($id);
    if (!$existing) {
      $this->fail(404, 'Permission not found');
      return;
    }

    $data = $this->validate($this->input(), $existing);
    if (is_string($data)) {
      $this->fail(400, $data);
      return;
    }

    $this->audit->transaction(function () use ($id, $data, $existing): void {
      $this->model->update($id, $data);

      // The name and description, and the roles and groups that hold the permission (with their names)
      $diff = AuditLog::diff($existing, $data, ['name', 'description']);
      $old = $diff['old'];
      $new = $diff['new'];
      $before = $this->state($existing);
      $after = $this->state($data);
      foreach (['roles', 'groups'] as $field) {
        if ($before[$field] !== $after[$field]) {
          $old[$field] = $before[$field];
          $new[$field] = $after[$field];
        }
      }
      if ($new !== []) {
        $this->audit->log('permission.updated', null, 'permission', $id, $data['name'], $old, $new);
      }
    });
    $this->respond(['status' => 'success', 'message' => 'Permission updated', 'data' => $this->model->get($id)]);
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes one or more permissions.
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

    $deleted = $this->audit->transaction(function () use ($ids): int {
      // The entries hold the data of the permissions: afterwards they are gone
      $permissions = array_filter(array_map(fn(int $id): ?array => $this->model->get($id), $ids));
      $count = $this->model->deleteMany($ids);
      foreach ($permissions as $permission) {
        $this->audit->log('permission.deleted', null, 'permission', (int)$permission['id'], (string)$permission['name'], $this->state($permission));
      }
      return $count;
    });
    $this->respond(['status' => 'success', 'message' => 'Permissions deleted', 'deleted' => $deleted]);
  }

  //---------------------------------------------------------------------------
  /**
   * Describes a permission for the audit log: name, description and the names of the roles and groups that hold it.
   *
   * @param array $permission A permission (PermissionModel::get()) or the validated input (with role_ids and group_ids)
   *
   * @return array
   */
  private function state(array $permission): array
  {
    return [
      'name' => $permission['name'],
      'description' => $permission['description'],
      'roles' => $this->model->names('roles', $permission['role_ids'] ?? []),
      'groups' => $this->model->names('groups', $permission['group_ids'] ?? []),
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * Validates and normalizes the input.
   *
   * @param array      $input    The raw input
   * @param array|null $existing The existing record when updating
   *
   * @return array|string Normalized data or an error message
   */
  private function validate(array $input, ?array $existing): array|string
  {
    $name = trim((string)($input['name'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));

    if ($name === '') {
      return 'Name is required';
    }
    if (mb_strlen($name) > 50) {
      return 'Name must not be longer than 50 characters';
    }
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
      return 'Name may only contain the characters a-z, A-Z, 0-9, _ and -';
    }
    if (mb_strlen($description) > 255) {
      return 'Description must not be longer than 255 characters';
    }

    $other = $this->model->findByName($name);
    if ($other && (!$existing || (int)$other['id'] !== (int)$existing['id'])) {
      return 'A permission with this name already exists';
    }

    $roleIds = $this->model->existingIds('roles', $this->ids($input['role_ids'] ?? []));
    // The Administrator role always holds every permission: Administrators bypass the permission
    // checks, and keeping the assignment complete avoids surprises when permissions are reviewed.
    $administratorRoleId = $this->model->administratorRoleId();
    if ($administratorRoleId !== null && !in_array($administratorRoleId, $roleIds, true)) {
      $roleIds[] = $administratorRoleId;
    }

    return [
      'name' => $name,
      'description' => $description,
      'role_ids' => $roleIds,
      'group_ids' => $this->model->existingIds('groups', $this->ids($input['group_ids'] ?? [])),
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * Normalizes a list of ids to unique positive integers.
   *
   * @param mixed $ids The raw ids
   *
   * @return int[]
   */
  private function ids(mixed $ids): array
  {
    return array_values(array_unique(array_filter(array_map('intval', (array)$ids), fn($v) => $v > 0)));
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
