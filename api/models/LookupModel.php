<?php

declare(strict_types=1);

namespace App\Models;

require_once __DIR__ . '/../services/SlaService.php';
require_once __DIR__ . '/../services/IssueNumbering.php';

use App\Services\IssueNumbering;
use App\Services\SlaService;
use PDO;

//---------------------------------------------------------------------------
/**
 * Model for the issue lookup tables (types, status categories, status, resolutions, priorities, products, tags).
 * The structure of a table is described by a definition of App\Config\Lookups.
 */
class LookupModel
{
  private const INT_FIELDS = ['id', 'category_id', 'sort_order', 'next_issue_number'];

  private PDO $db;
  private array $definition;
  private string $table;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param PDO   $db         The database connection
   * @param array $definition The definition of the lookup (see App\Config\Lookups)
   */
  public function __construct(PDO $db, array $definition)
  {
    $this->db = $db;
    $this->definition = $definition;
    $this->table = $definition['table'];
  }

  //---------------------------------------------------------------------------
  /**
   * Gets all records.
   *
   * @return array
   */
  public function getAll(): array
  {
    $rows = $this->db->query($this->select() . ' ORDER BY ' . $this->definition['order'])->fetchAll();

    return array_map(fn(array $row): array => $this->normalize($row), $rows);
  }

  //---------------------------------------------------------------------------
  /**
   * Searches the records by name (smart fill while typing). Names that start with the term come first.
   * The term is matched literally: "%" and "_" are no wildcards.
   *
   * @param string $term  The part of the name (not empty)
   * @param int    $limit The maximum number of records
   *
   * @return array
   */
  public function search(string $term, int $limit): array
  {
    $escape = fn(string $value): string => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    $limit = max(1, $limit);

    // A product is also found by its key
    $byKey = $this->table === 'products' ? ' OR t.issue_key LIKE :key_contains' : '';
    $stmt = $this->db->prepare(
      $this->select() . " WHERE (t.name LIKE :contains$byKey) ORDER BY (t.name LIKE :prefix) DESC, t.name LIMIT $limit"
    );
    $params = ['contains' => '%' . $escape($term) . '%', 'prefix' => $escape($term) . '%'];
    if ($byKey !== '') {
      $params['key_contains'] = $params['contains'];
    }
    $stmt->execute($params);

    return array_map(fn(array $row): array => $this->normalize($row), $stmt->fetchAll());
  }

  //---------------------------------------------------------------------------
  /**
   * Gets one record.
   *
   * @param int $id The record id
   *
   * @return array|null
   */
  public function get(int $id): ?array
  {
    $stmt = $this->db->prepare($this->select() . ' WHERE t.id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row ? $this->normalize($row) : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Finds a record by name.
   *
   * @param string $name The name
   *
   * @return array|null
   */
  public function findByName(string $name): ?array
  {
    $stmt = $this->db->prepare($this->select() . ' WHERE t.name = :name');
    $stmt->execute(['name' => $name]);
    $row = $stmt->fetch();

    return $row ? $this->normalize($row) : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Finds a product by its issue key (upper case letters).
   *
   * @param string $issueKey The issue key of the product
   *
   * @return array|null
   */
  public function findByIssueKey(string $issueKey): ?array
  {
    $stmt = $this->db->prepare($this->select() . ' WHERE t.issue_key = :issue_key');
    $stmt->execute(['issue_key' => $issueKey]);
    $row = $stmt->fetch();

    return $row ? $this->normalize($row) : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the former keys of issues block a product key: an issue of another product once had a key with it
   * (LD-5) and that key has to go on resolving to the issue.
   *
   * @param string   $issueKey  The product key (upper case)
   * @param int|null $productId The product that wants the key (null for a new product)
   *
   * @return bool
   */
  public function keyBlockedByAliases(string $issueKey, ?int $productId): bool
  {
    return (new IssueNumbering($this->db))->blockedByAliases($issueKey, $productId);
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the id of the default product (setting default_product_id).
   *
   * @return int|null Null if the setting is missing
   */
  public function defaultProductId(): ?int
  {
    $stmt = $this->db->query("SELECT `value` FROM settings WHERE `key` = 'default_product_id'");
    $value = $stmt->fetchColumn();

    return $value !== false && preg_match('/^[1-9]\d{0,8}$/', (string)$value) === 1 ? (int)$value : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the default product is one of the products.
   *
   * @param int[] $ids The product ids
   *
   * @return bool
   */
  public function holdsDefaultProduct(array $ids): bool
  {
    $default = $this->table === 'products' ? $this->defaultProductId() : null;

    return $default !== null && in_array($default, $ids, true);
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether a status category exists (used to validate the category of a status).
   *
   * @param int $id The category id
   *
   * @return bool
   */
  public function categoryExists(int $id): bool
  {
    $stmt = $this->db->prepare('SELECT COUNT(*) FROM status_categories WHERE id = :id');
    $stmt->execute(['id' => $id]);

    return (int)$stmt->fetchColumn() > 0;
  }

  //---------------------------------------------------------------------------
  /**
   * Creates a record. Records created through the API never have a key (they are not protected).
   *
   * @param array $data name and the writable fields of the definition
   *
   * @return int The new id
   */
  public function create(array $data): int
  {
    if (in_array('sort_order', $this->definition['fields'], true) && !isset($data['sort_order'])) {
      $data['sort_order'] = $this->nextSortOrder();
    }

    $columns = array_merge(['name'], array_filter($this->definition['fields'], fn(string $f): bool => array_key_exists($f, $data)));
    $quoted = implode(', ', array_map(fn(string $c): string => "`$c`", $columns));
    $params = implode(', ', array_map(fn(string $c): string => ":$c", $columns));

    $stmt = $this->db->prepare("INSERT INTO `{$this->table}` ($quoted) VALUES ($params)");
    $stmt->execute($this->bind($columns, $data));

    return (int)$this->db->lastInsertId();
  }

  //---------------------------------------------------------------------------
  /**
   * Updates the given fields of a record. The key is never changed.
   *
   * @param int   $id   The record id
   * @param array $data name and writable fields; fields that are not given stay unchanged
   *
   * @return bool
   */
  public function update(int $id, array $data): bool
  {
    $columns = array_filter(['name', ...$this->definition['fields']], fn(string $f): bool => array_key_exists($f, $data));
    if (empty($columns)) {
      return true;
    }

    // The key of a product: the old keys of its issues stay valid (the caller runs this in a transaction)
    $oldKey = null;
    if ($this->table === 'products' && array_key_exists('issue_key', $data)) {
      $stmt = $this->db->prepare('SELECT issue_key FROM products WHERE id = :id');
      $stmt->execute(['id' => $id]);
      $oldKey = $stmt->fetchColumn();
    }

    $set = implode(', ', array_map(fn(string $c): string => "`$c` = :$c", $columns));
    $stmt = $this->db->prepare("UPDATE `{$this->table}` SET $set WHERE id = :id");
    $saved = $stmt->execute($this->bind($columns, $data) + ['id' => $id]);

    if ($saved && $oldKey !== null && $oldKey !== false && $oldKey !== $data['issue_key']) {
      (new IssueNumbering($this->db))->keepOldKeys($id, (string)$oldKey);
    }

    return $saved;
  }

  //---------------------------------------------------------------------------
  /**
   * Counts the issues (soft deleted ones included) that hold one of the records. Only meaningful for
   * lookups with a replacement column (types, status, resolutions, priorities); returns 0 for the others.
   *
   * @param int[] $ids The record ids
   *
   * @return int
   */
  public function usageCount(array $ids): int
  {
    $column = $this->definition['replacement'] ?? null;
    if ($column === null || empty($ids)) {
      return 0;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $this->db->prepare("SELECT COUNT(*) FROM issues WHERE `$column` IN ($placeholders)");
    $stmt->execute(array_values($ids));

    return (int)$stmt->fetchColumn();
  }

  //---------------------------------------------------------------------------
  /**
   * Counts the issues that hold one of the status and have no resolution.
   *
   * @param int[] $ids The status ids
   *
   * @return int
   */
  public function usageWithoutResolution(array $ids): int
  {
    if (empty($ids)) {
      return 0;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $this->db->prepare("SELECT COUNT(*) FROM issues WHERE status_id IN ($placeholders) AND resolution_id IS NULL");
    $stmt->execute(array_values($ids));

    return (int)$stmt->fetchColumn();
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the key of the category of a status.
   *
   * @param int $statusId The status id
   *
   * @return string|null
   */
  private function categoryKeyOfStatus(int $statusId): ?string
  {
    $stmt = $this->db->prepare('SELECT c.`key` FROM `status` s JOIN status_categories c ON c.id = s.category_id WHERE s.id = :id');
    $stmt->execute(['id' => $statusId]);
    $key = $stmt->fetchColumn();

    return $key === false ? null : (string)$key;
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes records by id. Issues that hold a record are first moved to the replacement (in the same
   * transaction). Products and tags need no replacement: the foreign keys remove them from the issues.
   *
   * Moving issues to a status outside the category "Done" clears their resolution (the resolution
   * only exists in "Done"). The "Updated" timestamp of the issues is not changed.
   *
   * @param int[]    $ids           The ids
   * @param int|null $replacementId The record the issues get instead (validated by the caller)
   *
   * @return int Number of deleted rows
   */
  public function deleteMany(array $ids, ?int $replacementId = null): int
  {
    if (empty($ids)) {
      return 0;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $own = !$this->db->inTransaction();
    if ($own) {
      $this->db->beginTransaction();
    }
    try {
      $column = $this->definition['replacement'] ?? null;
      if ($this->table === 'products' && $replacementId !== null) {
        $this->moveProductIssues($ids, $replacementId);
      } elseif ($column !== null && $replacementId !== null) {
        // The issues with SLA timers: the new status, type or priority can stop the clock, end the resolution or change the policy
        $timed = $this->db->prepare("SELECT DISTINCT i.id FROM issues i JOIN issue_sla t ON t.issue_id = i.id WHERE i.`$column` IN ($placeholders)");
        $timed->execute(array_values($ids));
        $timedIds = array_map('intval', $timed->fetchAll(PDO::FETCH_COLUMN));

        $set = "`$column` = ?";
        if ($column === 'status_id' && $this->categoryKeyOfStatus($replacementId) !== 'done') {
          $set .= ', resolution_id = NULL';
        }
        $this->db->prepare("UPDATE issues SET $set WHERE `$column` IN ($placeholders)")
          ->execute([$replacementId, ...array_values($ids)]);

        (new SlaService($this->db))->syncMany($timedIds, in_array($column, ['type_id', 'priority_id'], true));
      }

      $stmt = $this->db->prepare("DELETE FROM `{$this->table}` WHERE id IN ($placeholders)");
      $stmt->execute(array_values($ids));
      $deleted = $stmt->rowCount();

      if ($own) {
        $this->db->commit();
      }

      return $deleted;
    } catch (\Throwable $e) {
      if ($own && $this->db->inTransaction()) {
        $this->db->rollBack();
      }
      throw $e;
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the issues of products to the replacement product before the products are deleted: each issue gets the next
   * number of the replacement (a new key), its old key stays valid as an alias, and its versions are cleared. The
   * default product setting follows when it pointed to one of the products. The SLA timers of the issues follow, because the
   * product is an input of the SLA policy. Runs inside the transaction of deleteMany().
   *
   * @param int[] $ids           The products that are about to be deleted
   * @param int   $replacementId The replacement product (validated by the caller)
   *
   * @return void
   */
  private function moveProductIssues(array $ids, int $replacementId): void
  {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $timed = $this->db->prepare("SELECT DISTINCT i.id FROM issues i JOIN issue_sla t ON t.issue_id = i.id WHERE i.product_id IN ($placeholders)");
    $timed->execute(array_values($ids));
    $timedIds = array_map('intval', $timed->fetchAll(PDO::FETCH_COLUMN));

    // In the order of the old numbers, so that the order stays
    $issues = $this->db->prepare("SELECT id FROM issues WHERE product_id IN ($placeholders) ORDER BY product_id, number");
    $issues->execute(array_values($ids));
    $numbering = new IssueNumbering($this->db);
    foreach ($issues->fetchAll(PDO::FETCH_COLUMN) as $issueId) {
      $numbering->move((int)$issueId, $replacementId, false);
    }

    $default = $this->defaultProductId();
    if ($default !== null && in_array($default, $ids, true)) {
      $this->db->prepare("UPDATE settings SET `value` = :value WHERE `key` = 'default_product_id'")->execute(['value' => (string)$replacementId]);
    }

    (new SlaService($this->db))->syncMany($timedIds, true);
  }

  //---------------------------------------------------------------------------
  /**
   * Builds the SELECT of the table (without WHERE and ORDER BY).
   *
   * @return string
   */
  private function select(): string
  {
    $protected = $this->definition['protected'] ? ', (t.`key` IS NOT NULL) AS protected' : '';
    if ($this->table === 'status') {
      return "SELECT t.*, c.`key` AS category_key, c.name AS category_name{$protected}
                FROM `status` t JOIN status_categories c ON c.id = t.category_id";
    }

    return "SELECT t.*{$protected} FROM `{$this->table}` t";
  }

  //---------------------------------------------------------------------------
  /**
   * Casts the database values to their PHP types.
   *
   * @param array $row A database row
   *
   * @return array
   */
  private function normalize(array $row): array
  {
    foreach (self::INT_FIELDS as $field) {
      if (isset($row[$field])) {
        $row[$field] = (int)$row[$field];
      }
    }
    foreach (['active', 'protected'] as $field) {
      if (array_key_exists($field, $row)) {
        $row[$field] = (bool)$row[$field];
      }
    }

    return $row;
  }

  //---------------------------------------------------------------------------
  /**
   * Picks the values for the named parameters.
   *
   * @param string[] $columns The columns
   * @param array    $data    The data
   *
   * @return array
   */
  private function bind(array $columns, array $data): array
  {
    $values = [];
    foreach ($columns as $column) {
      $values[$column] = is_bool($data[$column]) ? (int)$data[$column] : $data[$column];
    }

    return $values;
  }

  //---------------------------------------------------------------------------
  /**
   * Returns the sort order for a new record (behind the last one).
   *
   * @return int
   */
  private function nextSortOrder(): int
  {
    $max = (int)$this->db->query("SELECT COALESCE(MAX(sort_order), 0) FROM `{$this->table}`")->fetchColumn();

    return min(9990, $max + 10);
  }
}
