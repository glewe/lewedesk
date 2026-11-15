<?php

declare(strict_types=1);

namespace App\Services;

use App\Utils\IssueKey;
use PDO;

//---------------------------------------------------------------------------
/**
 * Hands out the numbers of the issue keys and keeps the former keys (see _docs/PRODUCTS.md).
 *
 * Every method that changes data must run inside a transaction of the caller: the number counter of a product is
 * locked until the transaction ends, so two issues of one product never get the same number.
 */
final class IssueNumbering
{
  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param PDO $db The database connection
   */
  public function __construct(private readonly PDO $db)
  {
  }

  //---------------------------------------------------------------------------
  /**
   * Takes the next number of a product.
   *
   * @param int $productId The product id
   *
   * @return int The number for the new issue
   *
   * @throws \LogicException   If no transaction is open
   * @throws \RuntimeException If the product does not exist
   */
  public function allocate(int $productId): int
  {
    if (!$this->db->inTransaction()) {
      throw new \LogicException('Issue numbers must be taken inside a transaction');
    }

    $stmt = $this->db->prepare('SELECT next_issue_number FROM products WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $productId]);
    $number = $stmt->fetchColumn();
    if ($number === false) {
      throw new \RuntimeException('Product does not exist');
    }

    $this->db->prepare('UPDATE products SET next_issue_number = :next WHERE id = :id')->execute(['next' => (int)$number + 1, 'id' => $productId]);

    return (int)$number;
  }

  //---------------------------------------------------------------------------
  /**
   * Moves an issue to another product: it gets the next number of the target product, its old key becomes an alias,
   * and the versions are cleared (they belong to the old product). The caller writes history, audit and SLA.
   *
   * @param int  $issueId   The issue id
   * @param int  $productId The target product id (not the product the issue has)
   * @param bool $touch     Whether the move counts as an update of the issue ("Updated" moves)
   *
   * @return array{old_key: string, new_key: string, number: int} The old and the new key and the new number
   */
  public function move(int $issueId, int $productId, bool $touch = true): array
  {
    $stmt = $this->db->prepare('SELECT i.number, p.issue_key FROM issues i JOIN products p ON p.id = i.product_id WHERE i.id = :id FOR UPDATE');
    $stmt->execute(['id' => $issueId]);
    $row = $stmt->fetch();
    if ($row === false) {
      throw new \RuntimeException('Issue does not exist');
    }
    $oldKey = IssueKey::format((string)$row['issue_key'], (int)$row['number']);

    $number = $this->allocate($productId);

    $this->db->prepare('INSERT IGNORE INTO issue_key_aliases (alias_key, issue_id) VALUES (:alias, :id)')->execute(['alias' => $oldKey, 'id' => $issueId]);
    $touchSql = $touch ? ', updated_at = CURRENT_TIMESTAMP' : '';
    $this->db->prepare("UPDATE issues SET product_id = :product, number = :number, affected_version_id = NULL, fixed_version_id = NULL$touchSql WHERE id = :id")
      ->execute(['product' => $productId, 'number' => $number, 'id' => $issueId]);

    $stmt = $this->db->prepare('SELECT issue_key FROM products WHERE id = :id');
    $stmt->execute(['id' => $productId]);

    return ['old_key' => $oldKey, 'new_key' => IssueKey::format((string)$stmt->fetchColumn(), $number), 'number' => $number];
  }

  //---------------------------------------------------------------------------
  /**
   * Keeps the old keys of the issues of a product whose key was changed. The new key must already be saved.
   * An alias that has become the current key of the issue again (the key was changed back) is removed.
   *
   * @param int    $productId The product id
   * @param string $oldKey    The product key before the change
   *
   * @return void
   */
  public function keepOldKeys(int $productId, string $oldKey): void
  {
    $this->db->prepare(
      "INSERT IGNORE INTO issue_key_aliases (alias_key, issue_id)
       SELECT CONCAT(:old_key, '-', i.number), i.id FROM issues i WHERE i.product_id = :product"
    )->execute(['old_key' => $oldKey, 'product' => $productId]);

    $this->db->prepare(
      "DELETE a FROM issue_key_aliases a
         JOIN issues i ON i.id = a.issue_id
         JOIN products p ON p.id = i.product_id
        WHERE p.id = :product AND a.alias_key = CONCAT(p.issue_key, '-', i.number)"
    )->execute(['product' => $productId]);
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether a product key is blocked by former keys: an issue of another product once had a key with it
   * (LD-5), and that key still has to resolve to that issue.
   *
   * @param string   $productKey The key to check (upper case)
   * @param int|null $productId  The product that wants the key (its own former keys do not block it)
   *
   * @return bool
   */
  public function blockedByAliases(string $productKey, ?int $productId): bool
  {
    $stmt = $this->db->prepare(
      'SELECT COUNT(*) FROM issue_key_aliases a JOIN issues i ON i.id = a.issue_id WHERE a.alias_key LIKE :prefix AND i.product_id <> :product'
    );
    $stmt->execute(['prefix' => $productKey . '-%', 'product' => $productId ?? 0]);

    return (int)$stmt->fetchColumn() > 0;
  }
}
