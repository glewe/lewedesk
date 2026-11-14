<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\SlaService;
use PDO;

//---------------------------------------------------------------------------
/**
 * Data access for the custom views of the issue list (table custom_views, see _docs/VIEWS.md): a saved combination of
 * the filters of the issue list, private to its owner or shared with all logged in users.
 *
 * The filters are stored as JSON: an object with the keys of FILTER_KEYS, each an array of ids as strings
 * (assignee_id may also hold "none"; sla_state holds the SLA states of the issue list instead of ids). The ids are not foreign keys; whoever applies a view ignores ids that do not
 * exist anymore.
 *
 * Who may do what is decided by the controller; this class only reads and writes.
 */
class CustomViewModel
{
  /** The filters of the issue list a view can hold */
  public const FILTER_KEYS = ['status_id', 'status_category_id', 'type_id', 'priority_id', 'product_id', 'assignee_id', 'reporter_id', 'tag_id', 'sla_state'];

  /** The longest name of a view, in characters */
  public const MAX_NAME = 100;

  /** The most values of one filter */
  public const MAX_VALUES = 200;

  private const COLUMNS = 'v.id, v.owner_id, v.name, v.shared, v.filters, v.created_at, v.updated_at, u.username, u.first_name, u.last_name';

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
   * Checks a name and returns it trimmed.
   *
   * @param mixed $name The input
   *
   * @return string|null The name; null when it is empty, not a string or too long
   */
  public static function cleanName(mixed $name): ?string
  {
    if (!is_string($name)) {
      return null;
    }
    $name = trim($name);

    return $name !== '' && mb_strlen($name) <= self::MAX_NAME ? $name : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Checks the filters of a view and returns the cleaned version: only the known keys, each an array of unique digit
   * strings ("none" is also allowed for assignee_id), empty filters left out.
   *
   * @param mixed $filters The input
   *
   * @return array|null The cleaned filters (field => string[]); null when the input is not valid
   */
  public static function cleanFilters(mixed $filters): ?array
  {
    if (!is_array($filters) || (array_is_list($filters) && $filters !== [])) {
      return null;
    }

    $clean = [];
    foreach ($filters as $key => $values) {
      if (!in_array($key, self::FILTER_KEYS, true)) {
        continue;
      }
      if (!is_array($values) || !array_is_list($values) || count($values) > self::MAX_VALUES) {
        return null;
      }

      $list = [];
      foreach ($values as $value) {
        $value = is_int($value) ? (string)$value : $value;
        if ($key === 'sla_state') {
          if (!is_string($value) || !in_array($value, SlaService::LIST_STATES, true)) {
            return null;
          }
        } elseif (!is_string($value) || !(ctype_digit($value) && strlen($value) <= 10 || ($key === 'assignee_id' && $value === 'none'))) {
          return null;
        }
        $list[$value] = $value;
      }
      if ($list !== []) {
        $clean[$key] = array_values($list);
      }
    }

    return $clean;
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the views a user sees in the select of the issue list: the own views sorted by name, then the shared views of
   * others sorted by name.
   *
   * @param int $userId The logged in user
   *
   * @return array[] The views (see format())
   */
  public function listVisible(int $userId): array
  {
    $stmt = $this->db->prepare(
      'SELECT ' . self::COLUMNS . ' FROM custom_views v LEFT JOIN users u ON u.id = v.owner_id
        WHERE v.owner_id = :own OR v.shared = 1
        ORDER BY COALESCE(v.owner_id = :first, 0) DESC, v.name, v.id'
    );
    $stmt->execute(['own' => $userId, 'first' => $userId]);

    return array_map(fn(array $row): array => $this->format($row), $stmt->fetchAll());
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the views of a user.
   *
   * @param int $userId The owner
   *
   * @return array[] The views sorted by name (see format())
   */
  public function listOwn(int $userId): array
  {
    $stmt = $this->db->prepare(
      'SELECT ' . self::COLUMNS . ' FROM custom_views v LEFT JOIN users u ON u.id = v.owner_id WHERE v.owner_id = :own ORDER BY v.name, v.id'
    );
    $stmt->execute(['own' => $userId]);

    return array_map(fn(array $row): array => $this->format($row), $stmt->fetchAll());
  }

  //---------------------------------------------------------------------------
  /**
   * Lists all views (for the administration).
   *
   * @return array[] The views sorted by name (see format())
   */
  public function listAll(): array
  {
    $stmt = $this->db->query(
      'SELECT ' . self::COLUMNS . ' FROM custom_views v LEFT JOIN users u ON u.id = v.owner_id ORDER BY v.name, v.id'
    );

    return array_map(fn(array $row): array => $this->format($row), $stmt->fetchAll());
  }

  //---------------------------------------------------------------------------
  /**
   * Gets one view.
   *
   * @param int $id The view id
   *
   * @return array|null The view (see format()); null when it does not exist
   */
  public function find(int $id): ?array
  {
    $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM custom_views v LEFT JOIN users u ON u.id = v.owner_id WHERE v.id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row ? $this->format($row) : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Finds a view of an owner by its name, ignoring the case.
   *
   * @param int      $ownerId   The owner
   * @param string   $name      The name
   * @param int|null $exceptId  A view to leave out (the one that is being renamed)
   *
   * @return array|null The view (see format()); null when the owner has none with that name
   */
  public function findByName(int $ownerId, string $name, ?int $exceptId = null): ?array
  {
    $stmt = $this->db->prepare(
      'SELECT ' . self::COLUMNS . ' FROM custom_views v LEFT JOIN users u ON u.id = v.owner_id
        WHERE v.owner_id = :owner AND v.name = :name AND v.id <> :except LIMIT 1'
    );
    $stmt->execute(['owner' => $ownerId, 'name' => $name, 'except' => $exceptId ?? 0]);
    $row = $stmt->fetch();

    return $row ? $this->format($row) : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Creates a view.
   *
   * @param int    $ownerId The owner
   * @param string $name    The cleaned name
   * @param bool   $shared  Whether all logged in users see it
   * @param array  $filters The cleaned filters
   *
   * @return int The id of the new view
   */
  public function create(int $ownerId, string $name, bool $shared, array $filters): int
  {
    $this->db->prepare('INSERT INTO custom_views (owner_id, name, shared, filters) VALUES (:owner, :name, :shared, :filters)')->execute([
      'owner' => $ownerId,
      'name' => $name,
      'shared' => $shared ? 1 : 0,
      'filters' => self::encode($filters),
    ]);

    return (int)$this->db->lastInsertId();
  }

  //---------------------------------------------------------------------------
  /**
   * Changes a view.
   *
   * @param int    $id      The view id
   * @param string $name    The cleaned name
   * @param bool   $shared  Whether all logged in users see it
   * @param array  $filters The cleaned filters
   *
   * @return void
   */
  public function update(int $id, string $name, bool $shared, array $filters): void
  {
    $this->db->prepare('UPDATE custom_views SET name = :name, shared = :shared, filters = :filters WHERE id = :id')->execute([
      'id' => $id,
      'name' => $name,
      'shared' => $shared ? 1 : 0,
      'filters' => self::encode($filters),
    ]);
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes a view.
   *
   * @param int $id The view id
   *
   * @return bool Whether it existed
   */
  public function delete(int $id): bool
  {
    $stmt = $this->db->prepare('DELETE FROM custom_views WHERE id = :id');
    $stmt->execute(['id' => $id]);

    return $stmt->rowCount() > 0;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the private views of users. The shared views of a deleted user stay (without an owner).
   *
   * @param int[] $userIds The users
   *
   * @return array[] The views (see format())
   */
  public function privateOf(array $userIds): array
  {
    if (empty($userIds)) {
      return [];
    }
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $this->db->prepare(
      'SELECT ' . self::COLUMNS . " FROM custom_views v LEFT JOIN users u ON u.id = v.owner_id WHERE v.shared = 0 AND v.owner_id IN ($placeholders) ORDER BY v.id"
    );
    $stmt->execute(array_values($userIds));

    return array_map(fn(array $row): array => $this->format($row), $stmt->fetchAll());
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes the private views of users (called before the users are deleted).
   *
   * @param int[] $userIds The users
   *
   * @return int The number of deleted views
   */
  public function deletePrivateOf(array $userIds): int
  {
    if (empty($userIds)) {
      return 0;
    }
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $this->db->prepare("DELETE FROM custom_views WHERE shared = 0 AND owner_id IN ($placeholders)");
    $stmt->execute(array_values($userIds));

    return $stmt->rowCount();
  }

  //---------------------------------------------------------------------------
  /**
   * Turns a database row into the public form of a view. The owner is null for a view whose owner was deleted. The
   * filters are always an object, also when empty.
   *
   * @param array $row The row (see COLUMNS)
   *
   * @return array{id: int, name: string, shared: bool, filters: object|array, owner: array|null, created_at: string, updated_at: string}
   */
  private function format(array $row): array
  {
    $filters = json_decode((string)$row['filters'], true);
    $filters = is_array($filters) ? (self::cleanFilters($filters) ?? []) : [];
    $name = trim(trim((string)$row['first_name']) . ' ' . trim((string)$row['last_name']));

    return [
      'id' => (int)$row['id'],
      'name' => (string)$row['name'],
      'shared' => (bool)$row['shared'],
      'filters' => $filters === [] ? new \stdClass() : $filters,
      'owner' => $row['owner_id'] === null ? null : [
        'id' => (int)$row['owner_id'],
        'username' => (string)$row['username'],
        'display_name' => $name !== '' ? $name : (string)$row['username'],
      ],
      'created_at' => (string)$row['created_at'],
      'updated_at' => (string)$row['updated_at'],
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * Encodes filters for the database ("{}" when empty).
   *
   * @param array $filters The cleaned filters
   *
   * @return string
   */
  private static function encode(array $filters): string
  {
    return $filters === [] ? '{}' : json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
}
