<?php

declare(strict_types=1);

namespace App\Utils;

use PDO;

//---------------------------------------------------------------------------
/**
 * The key of an issue: <product key>-<number>, e.g. LD-123 (see _docs/PRODUCTS.md).
 *
 * The product key has 1-4 letters (stored upper case), the number counts per product. Former keys (an issue that
 * was moved to another product, a product whose key was changed) are kept in issue_key_aliases and still resolve
 * to the issue. The current key always wins over an alias.
 */
final class IssueKey
{
  /** An issue key as typed: letters, a dash and a number (any letter case) */
  public const KEY_PATTERN = '/^([A-Za-z]{1,4})-([1-9]\d{0,8})$/';

  /** A product key as stored */
  public const PRODUCT_PATTERN = '/^[A-Z]{1,4}$/';

  /** @var array<int, string> The keys found so far by issue id */
  private array $known = [];

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
   * Builds the key of an issue.
   *
   * @param string $productKey The key of the product
   * @param int    $number     The number of the issue within the product
   *
   * @return string
   */
  public static function format(string $productKey, int $number): string
  {
    return $productKey . '-' . $number;
  }

  //---------------------------------------------------------------------------
  /**
   * Reads a text as an issue key.
   *
   * @param string $text The text, e.g. "ld-12"
   *
   * @return array{0: string, 1: int}|null The product key (upper case) and the number, or null if the text is no key
   */
  public static function parse(string $text): ?array
  {
    if (preg_match(self::KEY_PATTERN, trim($text), $match) !== 1) {
      return null;
    }

    return [strtoupper($match[1]), (int)$match[2]];
  }

  //---------------------------------------------------------------------------
  /**
   * Finds the issue of a key: the current key first, then the former keys. No visibility check, the caller decides
   * what the viewer may see.
   *
   * @param string $text The key as typed
   *
   * @return int|null The issue id, or null if there is no such issue
   */
  public function resolve(string $text): ?int
  {
    $parsed = self::parse($text);
    if ($parsed === null) {
      return null;
    }
    [$productKey, $number] = $parsed;

    $stmt = $this->db->prepare('SELECT i.id FROM issues i JOIN products p ON p.id = i.product_id WHERE p.issue_key = :product AND i.number = :number');
    $stmt->execute(['product' => $productKey, 'number' => $number]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
      return (int)$id;
    }

    $stmt = $this->db->prepare('SELECT issue_id FROM issue_key_aliases WHERE alias_key = :alias');
    $stmt->execute(['alias' => self::format($productKey, $number)]);
    $id = $stmt->fetchColumn();

    return $id === false ? null : (int)$id;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the current keys of issues, in one query.
   *
   * @param int[] $ids The issue ids
   *
   * @return array<int, string> The keys by issue id (an issue that does not exist is missing)
   */
  public function forIds(array $ids): array
  {
    $missing = array_values(array_unique(array_filter(array_map('intval', $ids), fn(int $id): bool => !isset($this->known[$id]))));
    if ($missing !== []) {
      $placeholders = implode(', ', array_fill(0, count($missing), '?'));
      $stmt = $this->db->prepare("SELECT i.id, p.issue_key, i.number FROM issues i JOIN products p ON p.id = i.product_id WHERE i.id IN ($placeholders)");
      $stmt->execute($missing);
      foreach ($stmt->fetchAll() as $row) {
        $this->known[(int)$row['id']] = self::format((string)$row['issue_key'], (int)$row['number']);
      }
    }

    $keys = [];
    foreach ($ids as $id) {
      if (isset($this->known[(int)$id])) {
        $keys[(int)$id] = $this->known[(int)$id];
      }
    }

    return $keys;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the current key of one issue.
   *
   * @param int $id The issue id
   *
   * @return string The key; "#id" for an issue that does not exist
   */
  public function forId(int $id): string
  {
    return $this->forIds([$id])[$id] ?? '#' . $id;
  }

  //---------------------------------------------------------------------------
  /**
   * Forgets the keys read so far (after issues were moved or a product key was changed).
   *
   * @return void
   */
  public function forget(): void
  {
    $this->known = [];
  }
}
