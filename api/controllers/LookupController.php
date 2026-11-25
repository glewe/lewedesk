<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\LookupModel;
use App\Services\AuditLog;
use App\Utils\GitHubRepo;

//---------------------------------------------------------------------------
/**
 * REST controller for the issue lookup tables (issue types, status categories, status, resolutions,
 * priorities, products and tags). The structure of a lookup is described by a definition of App\Config\Lookups.
 *
 *   GET    /{lookup}            list (?q=term&limit=10 searches the names, for smart fill)
 *   POST   /{lookup}            create
 *   GET    /{lookup}/{id}       read
 *   PUT    /{lookup}/{id}       update (only the fields that are sent are changed)
 *   DELETE /{lookup}/{id}       delete
 *   POST   /{lookup}/delete     delete many ({"ids": [1, 2]})
 *
 * Deleting a type, status, resolution, priority or product that issues use needs "replacement_id" in the body: the issues
 * get that record instead (409 with replacement_required otherwise). The issues of a deleted product move to the
 * replacement product: they get new numbers there, their old keys stay valid, their versions are cleared. The default
 * product (setting default_product_id) can only be deleted with a replacement; it becomes the default product.
 *
 * Records with a key are protected system records: they cannot be deleted, deactivated or renamed.
 * Their description, icon and colors can be edited.
 *
 * Authorization is done by the caller (index.php).
 */
class LookupController
{
  private const DEFAULT_COLOR = '#6b7785';
  private const MAX_NAME = 50;

  /** The target type of the audit entries by table */
  private const TYPES = [
    'issue_types' => 'issue_type',
    'status_categories' => 'status_category',
    'status' => 'status',
    'resolutions' => 'resolution',
    'priorities' => 'priority',
    'products' => 'product',
    'tags' => 'tag',
  ];

  private LookupModel $model;
  private AuditLog $audit;
  private array $definition;
  private $deleteGuard;
  private $repoCheck;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param LookupModel   $model       The model
   * @param AuditLog      $audit       The audit log
   * @param array         $definition  The definition of the lookup (see App\Config\Lookups)
   * @param callable|null $deleteGuard fn(string $table, int[] $ids): ?string, gives the reason why the records cannot be
   *                                   deleted (for example, SLA policies use them), or null
   * @param callable|null $repoCheck   fn(string $repo): string, tells whether a GitHub repository exists (exists, missing,
   *                                   unavailable); used when the GitHub Repo of a product is saved
   */
  public function __construct(LookupModel $model, AuditLog $audit, array $definition, ?callable $deleteGuard = null, ?callable $repoCheck = null)
  {
    $this->model = $model;
    $this->audit = $audit;
    $this->definition = $definition;
    $this->deleteGuard = $deleteGuard;
    $this->repoCheck = $repoCheck;
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
        'GET' => $this->list(),
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
   * Lists the records. With "q" only the records whose name contains the term are listed (smart fill),
   * at most "limit" of them (default 10, at most 50).
   */
  private function list(): void
  {
    $term = $_GET['q'] ?? null;
    if ($term === null) {
      $this->respond(['status' => 'success', 'data' => $this->model->getAll()]);
      return;
    }

    $term = is_string($term) ? trim($term) : '';
    if ($term === '' || mb_strlen($term) > self::MAX_NAME || !mb_check_encoding($term, 'UTF-8')) {
      $this->respond(['status' => 'success', 'data' => []]);
      return;
    }

    $limit = $_GET['limit'] ?? null;
    $limit = is_string($limit) && preg_match('/^\d{1,3}$/', $limit) ? min(50, max(1, (int)$limit)) : 10;
    $this->respond(['status' => 'success', 'data' => $this->model->search($term, $limit)]);
  }

  //---------------------------------------------------------------------------
  /**
   * Reads one record.
   */
  private function read(int $id): void
  {
    $record = $this->model->get($id);
    $record ? $this->respond(['status' => 'success', 'data' => $record]) : $this->fail(404, "{$this->label()} not found");
  }

  //---------------------------------------------------------------------------
  /**
   * Creates a record.
   */
  private function create(): void
  {
    if (!$this->definition['creatable']) {
      $this->fail(405, "{$this->label()} records cannot be created");
      return;
    }

    $data = $this->validate($this->input(), null);
    if (is_string($data)) {
      $this->fail(400, $data);
      return;
    }

    $unverified = $this->checkRepo($data, null);
    if (is_string($unverified) && $unverified !== 'unverified') {
      $this->fail(400, $unverified);
      return;
    }

    $id = $this->audit->transaction(function () use ($data): int {
      $id = $this->model->create($data);
      $this->audit->log($this->event('created'), null, $this->type(), $id, (string)$data['name'], null, $data);
      return $id;
    });
    $this->respond(['status' => 'success', 'message' => "{$this->label()} created", 'data' => $this->model->get($id)] + $this->repoWarning($unverified), 201);
  }

  //---------------------------------------------------------------------------
  /**
   * Updates a record.
   */
  private function update(int $id): void
  {
    $existing = $this->model->get($id);
    if (!$existing) {
      $this->fail(404, "{$this->label()} not found");
      return;
    }

    $data = $this->validate($this->input(), $existing);
    if (is_string($data)) {
      $this->fail($this->isProtected($existing) && str_contains($data, 'system record') ? 409 : 400, $data);
      return;
    }

    $unverified = $this->checkRepo($data, $existing);
    if (is_string($unverified) && $unverified !== 'unverified') {
      $this->fail(400, $unverified);
      return;
    }

    $this->audit->transaction(function () use ($id, $data, $existing): void {
      $this->model->update($id, $data);
      $diff = AuditLog::diff($existing, $data, array_keys($data));
      if ($diff['new'] !== []) {
        $this->audit->log($this->event('updated'), null, $this->type(), $id, (string)($data['name'] ?? $existing['name']), $diff['old'], $diff['new']);
      }
    });
    $this->respond(['status' => 'success', 'message' => "{$this->label()} updated", 'data' => $this->model->get($id)] + $this->repoWarning($unverified));
  }

  //---------------------------------------------------------------------------
  /**
   * Checks the GitHub Repo of a product that is about to be saved (only when it is new or changed): the repository
   * must exist and be public. If GitHub cannot be asked the product is saved anyway, and the answer carries a warning.
   *
   * @param array      $data     The validated data
   * @param array|null $existing The existing record when updating
   *
   * @return string|null An error message; 'unverified' when GitHub could not be asked; null when all is fine or nothing was checked
   */
  private function checkRepo(array $data, ?array $existing): ?string
  {
    $repo = $data['github_repo'] ?? null;
    if ($this->repoCheck === null || !is_string($repo) || $repo === '' || ($existing !== null && ($existing['github_repo'] ?? null) === $repo)) {
      return null;
    }

    return match (($this->repoCheck)($repo)) {
      'exists' => null,
      'missing' => 'The GitHub repository was not found. It must exist and be public (private repositories are not supported).',
      default => 'unverified',
    };
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the warning part of an answer when the GitHub Repo could not be verified.
   *
   * @param string|null $unverified The result of checkRepo()
   *
   * @return array
   */
  private function repoWarning(?string $unverified): array
  {
    return $unverified === 'unverified' ? ['warning' => 'github_repo_unverified'] : [];
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes one or more records.
   *
   * @param int[]|null $ids Ids to delete; read from the request body when null
   */
  private function deleteMany(?array $ids = null): void
  {
    if (!$this->definition['deletable']) {
      $this->fail(409, "{$this->label()} records cannot be deleted");
      return;
    }

    $ids ??= $this->input()['ids'] ?? [];
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids), fn(int $v): bool => $v > 0)));

    if (empty($ids)) {
      $this->fail(400, 'No ids given');
      return;
    }

    foreach ($ids as $id) {
      $record = $this->model->get($id);
      if ($record && $this->isProtected($record)) {
        $this->fail(409, "{$this->label()} \"{$record['name']}\" is a system record and cannot be deleted");
        return;
      }
    }

    $blocked = $this->deleteGuard === null ? null : ($this->deleteGuard)($this->definition['table'], $ids);
    if ($blocked !== null) {
      $this->respond(['status' => 'error', 'code' => 'in_use', 'message' => $blocked], 409);
      return;
    }

    // Issues that hold a type, status or resolution move to the replacement the caller chose
    [$replacementId, $error] = $this->replacementFor($ids);
    if ($error !== null) {
      $this->respond($error['payload'], $error['status']);
      return;
    }

    $oldDefault = $this->model->holdsDefaultProduct($ids) ? $this->model->defaultProductId() : null;
    $deleted = $this->audit->transaction(function () use ($ids, $replacementId, $oldDefault): int {
      // The entries hold the data of the records: afterwards they are gone
      $records = array_filter(array_map(fn(int $id): ?array => $this->model->get($id), $ids));
      $replacement = $replacementId !== null ? ($this->model->get($replacementId)['name'] ?? null) : null;
      $count = $this->model->deleteMany($ids, $replacementId);
      foreach ($records as $record) {
        $this->audit->log(
          $this->event('deleted'),
          null,
          $this->type(),
          (int)$record['id'],
          (string)$record['name'],
          ['name' => $record['name'], 'description' => $record['description'] ?? null],
          $replacement !== null ? ['replacement' => $replacement] : null
        );
      }
      // The default product moved with the issues
      if ($oldDefault !== null && $replacementId !== null) {
        $this->audit->log('setting.changed', null, 'setting', null, 'default_product_id', ['value' => (string)$oldDefault], ['value' => (string)$replacementId]);
      }
      return $count;
    });
    $this->respond(['status' => 'success', 'message' => "{$this->label()} deleted", 'deleted' => $deleted]);
  }

  //---------------------------------------------------------------------------
  /**
   * Determines the replacement for records that issues use (types, status, resolutions, priorities). The request
   * body carries it as "replacement_id". Without a replacement the answer is 409 with
   * replacement_required and the number of issues (in_use), so that the client can ask the user.
   * Tags need none: the issues just lose them. A product needs one when it has issues or is the default product.
   *
   * @param int[] $ids The ids that are about to be deleted
   *
   * @return array{0: int|null, 1: array|null} The replacement id (null if none is needed) and, if the
   *                                           request cannot go on, the error as ['status' => int, 'payload' => array]
   */
  private function replacementFor(array $ids): array
  {
    $inUse = $this->model->usageCount($ids);
    $isDefault = $this->model->holdsDefaultProduct($ids);
    if ($inUse === 0 && !$isDefault) {
      return [null, null];
    }

    $fail = fn(int $status, string $message, array $extra = []): array => [null, ['status' => $status, 'payload' => ['status' => 'error', 'message' => $message] + $extra]];

    $given = $this->input()['replacement_id'] ?? null;
    if ($given === null || $given === '') {
      $message = $inUse > 0
        ? "{$this->label()} is used by $inUse issue(s). Choose a replacement."
        : "{$this->label()} is the default product. Choose a replacement.";

      return $fail(409, $message, ['replacement_required' => true, 'in_use' => $inUse, 'is_default' => $isDefault]);
    }
    if (is_bool($given) || is_array($given) || !preg_match('/^[1-9]\d{0,8}$/', (string)$given)) {
      return $fail(400, 'Replacement must be a record id');
    }

    $replacementId = (int)$given;
    $replacement = $this->model->get($replacementId);
    if (in_array($replacementId, $ids, true)) {
      return $fail(400, 'The replacement must not be one of the records to delete');
    }
    if (!$replacement) {
      return $fail(400, "Replacement {$this->label()} does not exist");
    }
    // Products have no active flag
    if (array_key_exists('active', $replacement) && !$replacement['active']) {
      return $fail(400, "Replacement {$this->label()} must be active");
    }

    // The resolution is mandatory in the category "Done": issues without one cannot move into it
    if ($this->definition['table'] === 'status' && $replacement['category_key'] === 'done') {
      $without = $this->model->usageWithoutResolution($ids);
      if ($without > 0) {
        return $fail(409, "The replacement is in the category \"Done\", but $without affected issue(s) have no resolution. Choose a replacement outside \"Done\".");
      }
    }

    return [$replacementId, null];
  }

  //---------------------------------------------------------------------------
  /**
   * Validates and normalizes the input. Only fields of the definition are accepted, the key is
   * never writable. When creating, missing fields get their defaults; when updating, missing
   * fields stay unchanged.
   *
   * @param array      $input    The raw input
   * @param array|null $existing The existing record when updating
   *
   * @return array|string Normalized data or an error message
   */
  private function validate(array $input, ?array $existing): array|string
  {
    $data = [];

    // Name
    if ($existing !== null && $this->isProtected($existing)) {
      if (array_key_exists('name', $input) && trim((string)$input['name']) !== $existing['name']) {
        return "{$this->label()} \"{$existing['name']}\" is a system record and cannot be renamed";
      }
      $data['name'] = $existing['name'];
    } elseif ($existing === null || array_key_exists('name', $input)) {
      $name = trim((string)($input['name'] ?? ''));
      if ($name === '') {
        return 'Name is required';
      }
      if (mb_strlen($name) > self::MAX_NAME) {
        return 'Name must not be longer than ' . self::MAX_NAME . ' characters';
      }
      if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
        return 'Name must be a single line';
      }
      $other = $this->model->findByName($name);
      if ($other && ($existing === null || (int)$other['id'] !== (int)$existing['id'])) {
        return "A {$this->label()} with this name already exists";
      }
      $data['name'] = $name;
    }

    // Other fields
    foreach ($this->definition['fields'] as $field) {
      $given = array_key_exists($field, $input);
      if (!$given && $existing !== null) {
        continue;
      }

      $result = $this->field($field, $given ? $input[$field] : null, $given, $existing);
      if (is_string($result)) {
        return $result;
      }
      if ($result !== null) {
        $data[$field] = $result['value'];
      }
    }

    // A status category is needed to create a status
    if ($existing === null && in_array('category_id', $this->definition['fields'], true) && !isset($data['category_id'])) {
      return 'Category is required';
    }

    return $data;
  }

  //---------------------------------------------------------------------------
  /**
   * Validates one field.
   *
   * @param string     $field    The field name
   * @param mixed      $value    The given value (null if not given)
   * @param bool       $given    Whether the value was part of the request
   * @param array|null $existing The existing record when updating
   *
   * @return array|string|null ['value' => ...], null (nothing to store) or an error message
   */
  private function field(string $field, mixed $value, bool $given, ?array $existing): array|string|null
  {
    switch ($field) {
      case 'issue_key':
        return $this->issueKey($value, $given, $existing);

      case 'github_repo':
        // Empty clears the field; otherwise a public GitHub repository, stored as https://github.com/owner/repo
        $text = trim((string)($value ?? ''));
        if ($text === '') {
          return ['value' => null];
        }
        $repo = GitHubRepo::normalize($text);

        return $repo === null
          ? 'GitHub Repo must be a GitHub repository like https://github.com/owner/repo or owner/repo'
          : ['value' => $repo];

      case 'description':
        $text = trim((string)($value ?? ''));
        return mb_strlen($text) > 255 ? 'Description must not be longer than 255 characters' : ['value' => $text];

      case 'icon':
        $icon = trim((string)($value ?? ''));
        return $icon !== '' && !preg_match('/^bi-[a-z0-9-]{1,45}$/', $icon)
          ? 'Icon must be a Bootstrap Icons class like bi-person'
          : ['value' => $icon];

      case 'color':
      case 'background_color':
        $color = strtolower(trim((string)($value ?? '')));
        if ($color === '') {
          // Tags show white text on their background color by default
          $color = $field === 'color' && $this->definition['table'] === 'tags' ? '#ffffff' : self::DEFAULT_COLOR;
        }
        $label = $field === 'color' ? 'Color' : 'Background color';
        return preg_match('/^#[0-9a-f]{6}$/', $color) ? ['value' => $color] : "$label must be a hex value like #5856d6";

      case 'sort_order':
        if (!$given) {
          return null;
        }
        if (is_bool($value) || !preg_match('/^\d{1,4}$/', (string)$value)) {
          return 'Sort order must be a whole number between 0 and 9999';
        }
        return ['value' => (int)$value];

      case 'active':
        if (!$given) {
          return null;
        }
        $active = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($active === null) {
          return 'Active must be true or false';
        }
        if (!$active && $existing !== null && $this->isProtected($existing)) {
          return "{$this->label()} \"{$existing['name']}\" is a system record and cannot be deactivated";
        }
        return ['value' => $active];

      case 'category_id':
        if (!$given) {
          return null;
        }
        if (is_bool($value) || !preg_match('/^\d{1,9}$/', (string)$value) || !$this->model->categoryExists((int)$value)) {
          return 'Category does not exist';
        }
        return ['value' => (int)$value];
    }

    return null;
  }

  //---------------------------------------------------------------------------
  /**
   * Validates the key of a product: 1-4 letters, stored upper case, unique, and not the start of a former issue key
   * (an issue that was moved away from a product with that key still has to be found by it). Mandatory when a
   * product is created.
   *
   * @param mixed      $value    The given value (null if not given)
   * @param bool       $given    Whether the value was part of the request
   * @param array|null $existing The existing record when updating
   *
   * @return array|string|null ['value' => key] or an error message
   */
  private function issueKey(mixed $value, bool $given, ?array $existing): array|string|null
  {
    if (!$given) {
      return $existing === null ? 'Key is required' : null;
    }

    $key = is_string($value) ? strtoupper(trim($value)) : '';
    if ($key === '') {
      return 'Key is required';
    }
    if (!preg_match('/^[A-Z]{1,4}$/', $key)) {
      return 'Key must be 1 to 4 letters (A-Z)';
    }

    // A key that is not changed is always fine
    if ($existing !== null && $existing['issue_key'] === $key) {
      return ['value' => $key];
    }
    if ($this->model->findByIssueKey($key) !== null) {
      return "A {$this->label()} with this key already exists";
    }
    if ($this->model->keyBlockedByAliases($key, $existing === null ? null : (int)$existing['id'])) {
      return 'The key is the start of former issue keys of another product and cannot be used';
    }

    return ['value' => $key];
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether a record is a protected system record.
   */
  private function isProtected(array $record): bool
  {
    return !empty($record['protected']);
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the target type of the audit entries (issue_type, status, ...).
   *
   * @return string
   */
  private function type(): string
  {
    return self::TYPES[$this->definition['table']] ?? $this->definition['table'];
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the event key of an audit entry, e.g. product.created.
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
   * Returns the singular label used in messages.
   */
  private function label(): string
  {
    return $this->definition['label'];
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
