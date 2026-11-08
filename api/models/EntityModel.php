<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

//---------------------------------------------------------------------------
/**
 * Generic model for entities that share the same structure (roles and groups):
 * id, name, description, color, icon and timestamps.
 */
class EntityModel
{
  private PDO $db;
  private string $table;
  private string $linkTable;
  private string $linkColumn;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param PDO    $db         The database connection
   * @param string $table      Table name (roles or groups)
   * @param string $linkTable  Table that links users to the entity (user_role, user_group)
   * @param string $linkColumn Column in the link table referencing the entity
   */
  public function __construct(PDO $db, string $table, string $linkTable, string $linkColumn)
  {
    $this->db = $db;
    $this->table = $table;
    $this->linkTable = $linkTable;
    $this->linkColumn = $linkColumn;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets all entities including the number of assigned users.
   *
   * @return array
   */
  public function getAll(): array
  {
    $sql = "SELECT e.id, e.name, e.description, e.color, e.icon, e.created_at, e.updated_at,
                   (SELECT COUNT(*) FROM `{$this->linkTable}` l WHERE l.`{$this->linkColumn}` = e.id) AS user_count
            FROM `{$this->table}` e
            ORDER BY e.name";
    return $this->db->query($sql)->fetchAll();
  }

  //---------------------------------------------------------------------------
  /**
   * Gets one entity by id.
   *
   * @param int $id The entity id
   *
   * @return array|null
   */
  public function get(int $id): ?array
  {
    $stmt = $this->db->prepare("SELECT * FROM `{$this->table}` WHERE id = :id");
    $stmt->execute(['id' => $id]);
    return $stmt->fetch() ?: null;
  }

  //---------------------------------------------------------------------------
  /**
   * Finds an entity by name.
   *
   * @param string $name The name
   *
   * @return array|null
   */
  public function findByName(string $name): ?array
  {
    $stmt = $this->db->prepare("SELECT * FROM `{$this->table}` WHERE name = :name");
    $stmt->execute(['name' => $name]);
    return $stmt->fetch() ?: null;
  }

  //---------------------------------------------------------------------------
  /**
   * Creates an entity.
   *
   * @param array $data name, description, color, icon
   *
   * @return int The new id
   */
  public function create(array $data): int
  {
    $stmt = $this->db->prepare(
      "INSERT INTO `{$this->table}` (name, description, color, icon) VALUES (:name, :description, :color, :icon)"
    );
    $stmt->execute([
      'name' => $data['name'],
      'description' => $data['description'],
      'color' => $data['color'],
      'icon' => $data['icon'],
    ]);
    return (int)$this->db->lastInsertId();
  }

  //---------------------------------------------------------------------------
  /**
   * Updates an entity.
   *
   * @param int   $id   The entity id
   * @param array $data name, description, color, icon
   *
   * @return bool
   */
  public function update(int $id, array $data): bool
  {
    $stmt = $this->db->prepare(
      "UPDATE `{$this->table}` SET name = :name, description = :description, color = :color, icon = :icon WHERE id = :id"
    );
    return $stmt->execute([
      'id' => $id,
      'name' => $data['name'],
      'description' => $data['description'],
      'color' => $data['color'],
      'icon' => $data['icon'],
    ]);
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes entities by id.
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
    $stmt = $this->db->prepare("DELETE FROM `{$this->table}` WHERE id IN ($placeholders)");
    $stmt->execute(array_values($ids));
    return $stmt->rowCount();
  }
}
