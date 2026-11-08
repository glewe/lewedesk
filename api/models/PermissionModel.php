<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

//---------------------------------------------------------------------------
/**
 * Model for permissions and their assignments to roles and groups.
 */
class PermissionModel
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
   * Gets all permissions including the names of the assigned roles and groups.
   *
   * @return array
   */
  public function getAll(): array
  {
    $permissions = $this->db->query("SELECT id, name, description, created_at, updated_at FROM permissions ORDER BY name")->fetchAll();
    $roles = $this->linkedEntities('permission_role', 'roles', 'role_id');
    $groups = $this->linkedEntities('permission_group', 'groups', 'group_id');

    foreach ($permissions as &$permission) {
      $permission['roles'] = $roles[$permission['id']] ?? [];
      $permission['groups'] = $groups[$permission['id']] ?? [];
    }
    unset($permission);

    return $permissions;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets one permission including the ids of the assigned roles and groups.
   *
   * @param int $id The permission id
   *
   * @return array|null
   */
  public function get(int $id): ?array
  {
    $stmt = $this->db->prepare("SELECT * FROM permissions WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $permission = $stmt->fetch();
    if (!$permission) {
      return null;
    }

    $permission['role_ids'] = $this->linkedIds('permission_role', 'role_id', $id);
    $permission['group_ids'] = $this->linkedIds('permission_group', 'group_id', $id);

    return $permission;
  }

  //---------------------------------------------------------------------------
  /**
   * Finds a permission by name.
   *
   * @param string $name The name
   *
   * @return array|null
   */
  public function findByName(string $name): ?array
  {
    $stmt = $this->db->prepare("SELECT * FROM permissions WHERE name = :name");
    $stmt->execute(['name' => $name]);
    return $stmt->fetch() ?: null;
  }

  //---------------------------------------------------------------------------
  /**
   * Creates a permission including the role and group assignments, in one transaction.
   *
   * @param array $data name, description, role_ids, group_ids
   *
   * @return int The new id
   */
  public function create(array $data): int
  {
    $own = !$this->db->inTransaction();
    if ($own) {
      $this->db->beginTransaction();
    }
    try {
      $this->db->prepare("INSERT INTO permissions (name, description) VALUES (:name, :description)")
        ->execute(['name' => $data['name'], 'description' => $data['description']]);
      $id = (int)$this->db->lastInsertId();

      $this->replaceLinks('permission_role', 'role_id', $id, $data['role_ids']);
      $this->replaceLinks('permission_group', 'group_id', $id, $data['group_ids']);

      if ($own) {
        $this->db->commit();
      }
      return $id;
    } catch (\Throwable $e) {
      if ($own && $this->db->inTransaction()) {
        $this->db->rollBack();
      }
      throw $e;
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Updates a permission including the role and group assignments, in one transaction.
   *
   * @param int   $id   The permission id
   * @param array $data name, description, role_ids, group_ids
   *
   * @return bool
   */
  public function update(int $id, array $data): bool
  {
    $own = !$this->db->inTransaction();
    if ($own) {
      $this->db->beginTransaction();
    }
    try {
      $this->db->prepare("UPDATE permissions SET name = :name, description = :description WHERE id = :id")
        ->execute(['id' => $id, 'name' => $data['name'], 'description' => $data['description']]);

      $this->replaceLinks('permission_role', 'role_id', $id, $data['role_ids']);
      $this->replaceLinks('permission_group', 'group_id', $id, $data['group_ids']);

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
   * Deletes permissions by id. Role and group assignments are removed by the foreign keys.
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
    $stmt = $this->db->prepare("DELETE FROM permissions WHERE id IN ($placeholders)");
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
   * Gets the entities linked to each permission as lists of name/color/icon arrays, keyed by permission id.
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
      "SELECT l.permission_id, e.name, e.color, e.icon FROM `$linkTable` l JOIN `$table` e ON e.id = l.`$linkColumn` ORDER BY e.name"
    )->fetchAll();

    $result = [];
    foreach ($rows as $row) {
      $result[$row['permission_id']][] = ['name' => $row['name'], 'color' => $row['color'], 'icon' => $row['icon']];
    }

    return $result;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the entity ids linked to a permission.
   *
   * @param string $linkTable  The link table
   * @param string $linkColumn The entity column in the link table
   * @param int    $id         The permission id
   *
   * @return int[]
   */
  private function linkedIds(string $linkTable, string $linkColumn, int $id): array
  {
    $stmt = $this->db->prepare("SELECT `$linkColumn` FROM `$linkTable` WHERE permission_id = :id");
    $stmt->execute(['id' => $id]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
  }

  //---------------------------------------------------------------------------
  /**
   * Replaces all links of a permission in a link table.
   *
   * @param string $linkTable  The link table
   * @param string $linkColumn The entity column in the link table
   * @param int    $id         The permission id
   * @param int[]  $ids        The entity ids to link
   *
   * @return void
   */
  private function replaceLinks(string $linkTable, string $linkColumn, int $id, array $ids): void
  {
    $this->db->prepare("DELETE FROM `$linkTable` WHERE permission_id = :id")->execute(['id' => $id]);

    $insert = $this->db->prepare("INSERT INTO `$linkTable` (permission_id, `$linkColumn`) VALUES (:permission_id, :entity_id)");
    foreach ($ids as $entityId) {
      $insert->execute(['permission_id' => $id, 'entity_id' => $entityId]);
    }
  }
}
