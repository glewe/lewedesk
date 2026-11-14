<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\EntityModel;
use App\Services\AuditLog;

//---------------------------------------------------------------------------
/**
 * REST controller for entities with the structure of roles and groups.
 *
 *   GET    /{entities}            list
 *   POST   /{entities}            create
 *   GET    /{entities}/{id}       read
 *   PUT    /{entities}/{id}       update
 *   DELETE /{entities}/{id}       delete
 *   POST   /{entities}/delete     delete many ({"ids": [1, 2]})
 */
class EntityController
{
  private const FIELDS = ['name', 'description', 'color', 'icon'];

  private EntityModel $model;
  private AuditLog $audit;
  private string $label;
  private array $protectedNames;
  private $deleteGuard;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param EntityModel $model          The model
   * @param AuditLog    $audit          The audit log
   * @param string      $label          Singular label used in messages (e.g. "Role")
   * @param array       $protectedNames Names that can be neither deleted nor renamed
   * @param callable|null $deleteGuard  fn(int[] $ids): ?string, gives the reason why the records cannot be deleted (for
   *                                    example, SLA policies use them), or null
   */
  public function __construct(EntityModel $model, AuditLog $audit, string $label, array $protectedNames = [], ?callable $deleteGuard = null)
  {
    $this->model = $model;
    $this->audit = $audit;
    $this->label = $label;
    $this->protectedNames = $protectedNames;
    $this->deleteGuard = $deleteGuard;
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
   * Reads one entity.
   */
  private function read(int $id): void
  {
    $entity = $this->model->get($id);
    $entity ? $this->respond(['status' => 'success', 'data' => $entity]) : $this->fail(404, "{$this->label} not found");
  }

  //---------------------------------------------------------------------------
  /**
   * Creates an entity.
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
      $this->audit->log($this->event('created'), null, $this->type(), $id, $data['name'], null, array_intersect_key($data, array_flip(self::FIELDS)));
      return $id;
    });
    $this->respond(['status' => 'success', 'message' => "{$this->label} created", 'data' => $this->model->get($id)], 201);
  }

  //---------------------------------------------------------------------------
  /**
   * Updates an entity.
   */
  private function update(int $id): void
  {
    $existing = $this->model->get($id);
    if (!$existing) {
      $this->fail(404, "{$this->label} not found");
      return;
    }

    $data = $this->validate($this->input(), $existing);
    if (is_string($data)) {
      $this->fail(400, $data);
      return;
    }

    $this->audit->transaction(function () use ($id, $data, $existing): void {
      $this->model->update($id, $data);
      $diff = AuditLog::diff($existing, $data, self::FIELDS);
      if ($diff['new'] !== []) {
        $this->audit->log($this->event('updated'), null, $this->type(), $id, $data['name'], $diff['old'], $diff['new']);
      }
    });
    $this->respond(['status' => 'success', 'message' => "{$this->label} updated", 'data' => $this->model->get($id)]);
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes one or more entities.
   *
   * @param int[]|null $ids Ids to delete; read from the request body when null
   */
  private function deleteMany(?array $ids = null): void
  {
    $ids ??= $this->input()['ids'] ?? [];
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids), fn($v) => $v > 0)));

    if (empty($ids)) {
      $this->fail(400, 'No ids given');
      return;
    }

    foreach ($ids as $id) {
      $entity = $this->model->get($id);
      if ($entity && in_array($entity['name'], $this->protectedNames, true)) {
        $this->fail(409, "{$this->label} \"{$entity['name']}\" is a system entry and cannot be deleted");
        return;
      }
    }

    $blocked = $this->deleteGuard === null ? null : ($this->deleteGuard)($ids);
    if ($blocked !== null) {
      $this->respond(['status' => 'error', 'code' => 'in_use', 'message' => $blocked], 409);
      return;
    }

    $deleted = $this->audit->transaction(function () use ($ids): int {
      // The entries hold the data of the records: afterwards they are gone
      $records = array_filter(array_map(fn(int $id): ?array => $this->model->get($id), $ids));
      $count = $this->model->deleteMany($ids);
      foreach ($records as $record) {
        $this->audit->log($this->event('deleted'), null, $this->type(), (int)$record['id'], (string)$record['name'], array_intersect_key($record, array_flip(self::FIELDS)));
      }
      return $count;
    });
    $this->respond(['status' => 'success', 'message' => "{$this->label} deleted", 'deleted' => $deleted]);
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the target type of the audit entries (role, group).
   *
   * @return string
   */
  private function type(): string
  {
    return strtolower($this->label);
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the event key of an audit entry, e.g. role.created.
   *
   * @param string $action created, updated or deleted
   *
   * @return string
   */
  private function event(string $action): string
  {
    return $this->type() . '.' . $action;
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
    $color = strtolower(trim((string)($input['color'] ?? '')));
    $icon = trim((string)($input['icon'] ?? ''));

    if ($name === '') {
      return 'Name is required';
    }
    if (mb_strlen($name) > 50) {
      return 'Name must not be longer than 50 characters';
    }
    if (mb_strlen($description) > 255) {
      return 'Description must not be longer than 255 characters';
    }
    if ($color === '') {
      $color = '#6b7785';
    }
    if (!preg_match('/^#[0-9a-f]{6}$/', $color)) {
      return 'Color must be a hex value like #5856d6';
    }
    if ($icon !== '' && !preg_match('/^bi-[a-z0-9-]{1,45}$/', $icon)) {
      return 'Icon must be a Bootstrap Icons class like bi-person';
    }

    if ($existing && $existing['name'] !== $name && in_array($existing['name'], $this->protectedNames, true)) {
      return "{$this->label} \"{$existing['name']}\" is a system entry and cannot be renamed";
    }

    $other = $this->model->findByName($name);
    if ($other && (!$existing || (int)$other['id'] !== (int)$existing['id'])) {
      return "A {$this->label} with this name already exists";
    }

    return ['name' => $name, 'description' => $description, 'color' => $color, 'icon' => $icon];
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
