<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\IssueVisibility;
use App\Utils\IssueKey;
use PDO;

require_once __DIR__ . '/../utils/IssueKey.php';

//---------------------------------------------------------------------------
/**
 * Model for the versions of a product (table product_versions, see _docs/PRODUCTS.md). A version belongs to one
 * product; issues refer to versions as "affected version" and "fixed version".
 */
class ProductVersionModel
{
  public const STATUSES = ['planned', 'development', 'released', 'archived'];

  /** The columns of a version that the API can write */
  private const WRITABLE = ['name', 'description', 'status', 'release_date', 'link'];

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param PDO             $db         The database connection
   * @param IssueVisibility $visibility The visibility rules (the release notes only list issues the viewer may see)
   */
  public function __construct(private readonly PDO $db, private readonly IssueVisibility $visibility)
  {
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether a product exists.
   *
   * @param int $productId The product id
   *
   * @return bool
   */
  public function productExists(int $productId): bool
  {
    return $this->productName($productId) !== null;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the name of a product.
   *
   * @param int $productId The product id
   *
   * @return string|null Null if the product does not exist
   */
  public function productName(int $productId): ?string
  {
    $stmt = $this->db->prepare('SELECT name FROM products WHERE id = :id');
    $stmt->execute(['id' => $productId]);
    $name = $stmt->fetchColumn();

    return $name === false ? null : (string)$name;
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the versions of a product in natural order of the name, the highest first (1.10 above 1.9).
   *
   * @param int $productId The product id
   *
   * @return array[]
   */
  public function list(int $productId): array
  {
    $stmt = $this->db->prepare($this->select() . ' WHERE v.product_id = :product');
    $stmt->execute(['product' => $productId]);
    $versions = array_map(fn(array $row): array => $this->normalize($row), $stmt->fetchAll());
    usort($versions, static fn(array $a, array $b): int => strnatcasecmp($b['name'], $a['name']) ?: $b['id'] <=> $a['id']);

    return $versions;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets one version of a product.
   *
   * @param int $productId The product id
   * @param int $id        The version id
   *
   * @return array|null Null if the product has no such version
   */
  public function get(int $productId, int $id): ?array
  {
    $stmt = $this->db->prepare($this->select() . ' WHERE v.product_id = :product AND v.id = :id');
    $stmt->execute(['product' => $productId, 'id' => $id]);
    $row = $stmt->fetch();

    return $row ? $this->normalize($row) : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Finds a version of a product by its name (the comparison ignores the letter case).
   *
   * @param int    $productId The product id
   * @param string $name      The name
   *
   * @return array|null
   */
  public function findByName(int $productId, string $name): ?array
  {
    $stmt = $this->db->prepare($this->select() . ' WHERE v.product_id = :product AND v.name = :name');
    $stmt->execute(['product' => $productId, 'name' => $name]);
    $row = $stmt->fetch();

    return $row ? $this->normalize($row) : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Creates a version.
   *
   * @param int   $productId The product id
   * @param array $data      name, description, status, release_date, link
   *
   * @return int The new id
   */
  public function create(int $productId, array $data): int
  {
    $this->db->prepare(
      'INSERT INTO product_versions (product_id, name, description, status, release_date, link)
       VALUES (:product, :name, :description, :status, :release_date, :link)'
    )->execute([
      'product' => $productId,
      'name' => $data['name'],
      'description' => $data['description'],
      'status' => $data['status'],
      'release_date' => $data['release_date'],
      'link' => $data['link'],
    ]);

    return (int)$this->db->lastInsertId();
  }

  //---------------------------------------------------------------------------
  /**
   * Updates the given fields of a version.
   *
   * @param int   $id   The version id
   * @param array $data Any of name, description, status, release_date, link
   *
   * @return void
   */
  public function update(int $id, array $data): void
  {
    $columns = array_values(array_filter(self::WRITABLE, fn(string $column): bool => array_key_exists($column, $data)));
    if ($columns === []) {
      return;
    }

    $set = implode(', ', array_map(fn(string $column): string => "`$column` = :$column", $columns));
    $params = ['id' => $id];
    foreach ($columns as $column) {
      $params[$column] = $data[$column];
    }
    $this->db->prepare("UPDATE product_versions SET $set WHERE id = :id")->execute($params);
  }

  //---------------------------------------------------------------------------
  /**
   * Counts the issues (soft deleted ones included) that refer to a version.
   *
   * @param int $id The version id
   *
   * @return int Issues with the version as affected or as fixed version (an issue counts once)
   */
  public function usageCount(int $id): int
  {
    $stmt = $this->db->prepare('SELECT COUNT(*) FROM issues WHERE affected_version_id = :affected OR fixed_version_id = :fixed');
    $stmt->execute(['affected' => $id, 'fixed' => $id]);

    return (int)$stmt->fetchColumn();
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes a version. The issues that refer to it get the replacement instead, or none.
   *
   * @param int      $id            The version id
   * @param int|null $replacementId The version of the same product the issues get (null: they get none)
   *
   * @return void
   */
  public function delete(int $id, ?int $replacementId): void
  {
    foreach (['affected_version_id', 'fixed_version_id'] as $column) {
      $this->db->prepare("UPDATE issues SET $column = :replacement WHERE $column = :id")->execute(['replacement' => $replacementId, 'id' => $id]);
    }
    $this->db->prepare('DELETE FROM product_versions WHERE id = :id')->execute(['id' => $id]);
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the issues that a version fixes (the release notes), grouped by issue type. Only issues the viewer may see
   * are listed; deleted issues are left out.
   *
   * @param int $id The version id
   *
   * @return array[] One entry per issue type with issues: type (id, key, name, icon, color, background_color) and issues
   *                 (id, key, summary, status), the types in their sort order, the issues by key
   */
  public function releaseNotes(int $id): array
  {
    [$scope, $params] = $this->visibility->scope('i');
    $stmt = $this->db->prepare(
      "SELECT i.id, i.number, p.issue_key, i.summary,
              t.id AS type_id, t.`key` AS type_key, t.name AS type_name, t.icon AS type_icon, t.color AS type_color,
              t.background_color AS type_bg, t.sort_order AS type_order,
              s.id AS status_id, s.`key` AS status_key, s.name AS status_name, s.icon AS status_icon, s.color AS status_color,
              s.background_color AS status_bg
         FROM issues i
         JOIN products p ON p.id = i.product_id
         JOIN issue_types t ON t.id = i.type_id
         JOIN `status` s ON s.id = i.status_id
        WHERE $scope AND i.deleted_at IS NULL AND i.fixed_version_id = :version
        ORDER BY t.sort_order, t.name, i.number"
    );
    $stmt->execute($params + ['version' => $id]);

    $groups = [];
    foreach ($stmt->fetchAll() as $row) {
      $typeId = (int)$row['type_id'];
      $groups[$typeId] ??= [
        'type' => [
          'id' => $typeId,
          'key' => $row['type_key'],
          'name' => $row['type_name'],
          'icon' => $row['type_icon'],
          'color' => $row['type_color'],
          'background_color' => $row['type_bg'],
        ],
        'issues' => [],
      ];
      $groups[$typeId]['issues'][] = [
        'id' => (int)$row['id'],
        'key' => IssueKey::format((string)$row['issue_key'], (int)$row['number']),
        'summary' => $row['summary'],
        'status' => [
          'id' => (int)$row['status_id'],
          'key' => $row['status_key'],
          'name' => $row['status_name'],
          'icon' => $row['status_icon'],
          'color' => $row['status_color'],
          'background_color' => $row['status_bg'],
        ],
      ];
    }

    return array_values($groups);
  }

  //---------------------------------------------------------------------------
  /**
   * Builds the SELECT of a version (without WHERE and ORDER BY): the version and how many issues use it.
   *
   * @return string
   */
  private function select(): string
  {
    return 'SELECT v.id, v.product_id, v.name, v.description, v.status, v.release_date, v.link, v.created_at, v.updated_at,
                   (SELECT COUNT(*) FROM issues a WHERE a.affected_version_id = v.id) AS affected_count,
                   (SELECT COUNT(*) FROM issues f WHERE f.fixed_version_id = v.id) AS fixed_count
              FROM product_versions v';
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
    foreach (['id', 'product_id', 'affected_count', 'fixed_count'] as $field) {
      $row[$field] = (int)$row[$field];
    }

    return $row;
  }
}
