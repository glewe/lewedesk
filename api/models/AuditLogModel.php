<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

//---------------------------------------------------------------------------
/**
 * Model for reading the audit log (table audit_log). Entries are only written by App\Services\AuditLog and never
 * changed; this model has no write method.
 *
 * Times: created_at is stored in the time zone of the database session. The filters "from" and "to" are calendar days
 * in the time zone of the application (setting timezone) and are converted to the time zone of the database before
 * they are compared, so the index on created_at stays usable. The entries are handed out as ISO 8601 times in the
 * time zone of the application.
 */
class AuditLogModel
{
  /** The categories of the events (see App\Services\AuditLog::category()) */
  public const CATEGORIES = ['auth', 'user', 'config', 'data', 'security', 'audit', 'mail', 'other'];

  /** The columns of the list that can be sorted by (key = parameter, value = SQL column) */
  private const SORT_COLUMNS = [
    'id' => 'id',
    'created_at' => 'created_at',
    'category' => 'category',
    'event' => 'event',
    'outcome' => 'outcome',
    'actor' => 'actor_username',
    'target' => 'target_label',
    'ip' => 'ip',
  ];

  /** The columns that are read, in the order of the export */
  private const COLUMNS = 'id, created_at, actor_id, actor_username, event, category, outcome, target_type, target_id,
                           target_label, old_value, new_value, ip, user_agent, request_id';

  private PDO $db;
  private DateTimeZone $zone;
  private ?int $offset = null;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param PDO    $db       The database connection
   * @param string $timezone The time zone of the application (setting timezone)
   */
  public function __construct(PDO $db, string $timezone)
  {
    $this->db = $db;
    try {
      $this->zone = new DateTimeZone($timezone !== '' ? $timezone : 'UTC');
    } catch (\Exception) {
      $this->zone = new DateTimeZone('UTC');
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Lists a page of entries.
   *
   * @param array  $filters   The validated filters (see where())
   * @param string $sort      A key of SORT_COLUMNS
   * @param string $direction asc or desc
   * @param int    $page      The page, from 1
   * @param int    $pageSize  Entries per page
   *
   * @return array{rows: array, total: int, filtered: int}
   */
  public function list(array $filters, string $sort, string $direction, int $page, int $pageSize): array
  {
    [$where, $params] = $this->where($filters);

    $total = (int)$this->db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
    $filtered = $where === '' ? $total : $this->countFiltered($where, $params);

    $stmt = $this->db->prepare(
      'SELECT ' . self::COLUMNS . ' FROM audit_log' . $this->clause($where) . ' ORDER BY ' . $this->orderBy($sort, $direction)
      . ' LIMIT ' . $pageSize . ' OFFSET ' . (($page - 1) * $pageSize)
    );
    $stmt->execute($params);

    return ['rows' => array_map(fn(array $row): array => $this->present($row), $stmt->fetchAll()), 'total' => $total, 'filtered' => $filtered];
  }

  //---------------------------------------------------------------------------
  /**
   * Gets one entry.
   *
   * @param int $id The entry id
   *
   * @return array|null
   */
  public function get(int $id): ?array
  {
    $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM audit_log WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row ? $this->present($row) : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Counts the entries that match the filters.
   *
   * @param array $filters The validated filters
   *
   * @return int
   */
  public function count(array $filters): int
  {
    [$where, $params] = $this->where($filters);

    return $where === '' ? (int)$this->db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn() : $this->countFiltered($where, $params);
  }

  //---------------------------------------------------------------------------
  /**
   * Streams all entries that match the filters, one at a time, without loading them into memory. While the generator
   * is open, no other statement can run on the connection (the query is unbuffered).
   *
   * @param array  $filters   The validated filters
   * @param string $sort      A key of SORT_COLUMNS
   * @param string $direction asc or desc
   *
   * @return \Generator<int, array> The presented entries
   */
  public function stream(array $filters, string $sort, string $direction): \Generator
  {
    [$where, $params] = $this->where($filters);
    // Read before the query is unbuffered: no other statement can run while its result is being fetched
    $this->databaseOffset();
    $buffered = $this->buffering(null);
    $this->buffering(false);

    try {
      $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM audit_log' . $this->clause($where) . ' ORDER BY ' . $this->orderBy($sort, $direction));
      $stmt->execute($params);
      while (($row = $stmt->fetch()) !== false) {
        yield $this->present($row);
      }
      $stmt->closeCursor();
    } finally {
      $this->buffering($buffered);
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Reads or sets whether the MySQL driver loads the whole result of a query into memory. A driver without this setting
   * keeps its behavior.
   *
   * @param bool|null $set The new setting, or null to only read it
   *
   * @return bool The setting before the call
   */
  private function buffering(?bool $set): bool
  {
    try {
      $current = (bool)$this->db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
      if ($set !== null) {
        $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $set);
      }

      return $current;
    } catch (\PDOException) {
      return true;
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether a sort key is known.
   *
   * @param string $sort The key
   *
   * @return bool
   */
  public static function isSortKey(string $sort): bool
  {
    return isset(self::SORT_COLUMNS[$sort]);
  }

  //---------------------------------------------------------------------------
  /**
   * Builds the ORDER BY of the list. The id breaks ties, so that paging is stable.
   *
   * @param string $sort      A key of SORT_COLUMNS
   * @param string $direction asc or desc
   *
   * @return string
   */
  private function orderBy(string $sort, string $direction): string
  {
    $column = self::SORT_COLUMNS[$sort] ?? 'created_at';
    $dir = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';

    return $column === 'id' ? "id $dir" : "$column $dir, id $dir";
  }

  //---------------------------------------------------------------------------
  /**
   * Builds the WHERE conditions of the filters.
   *
   * @param array $filters from, to (Y-m-d), actor (id or part of a username), category, event, target_type, target_id,
   *                       ip (start of the address), outcome, q (free text); missing or empty ones are ignored
   *
   * @return array{0: string, 1: array} The conditions joined by AND (empty = no filter) and their parameters
   */
  private function where(array $filters): array
  {
    $conditions = [];
    $params = [];

    if (!empty($filters['from'])) {
      $conditions[] = 'created_at >= :from';
      $params['from'] = $this->databaseTime($filters['from'] . ' 00:00:00');
    }
    if (!empty($filters['to'])) {
      // Inclusive: up to the start of the next day
      $conditions[] = 'created_at < :to';
      $params['to'] = $this->databaseTime($filters['to'] . ' 00:00:00', 1);
    }
    if (isset($filters['actor']) && $filters['actor'] !== '') {
      if (ctype_digit((string)$filters['actor'])) {
        $conditions[] = 'actor_id = :actor_id';
        $params['actor_id'] = (int)$filters['actor'];
      } else {
        $conditions[] = 'actor_username LIKE :actor';
        $params['actor'] = '%' . self::escapeLike((string)$filters['actor']) . '%';
      }
    }
    foreach (['category', 'event', 'target_type', 'outcome'] as $field) {
      if (isset($filters[$field]) && $filters[$field] !== '') {
        $conditions[] = "$field = :$field";
        $params[$field] = (string)$filters[$field];
      }
    }
    if (isset($filters['target_id']) && $filters['target_id'] !== '') {
      $conditions[] = 'target_id = :target_id';
      $params['target_id'] = (int)$filters['target_id'];
    }
    if (isset($filters['ip']) && $filters['ip'] !== '') {
      $conditions[] = 'ip LIKE :ip';
      $params['ip'] = self::escapeLike((string)$filters['ip']) . '%';
    }
    if (isset($filters['q']) && $filters['q'] !== '') {
      $conditions[] = '(target_label LIKE :q1 OR actor_username LIKE :q2 OR event LIKE :q3)';
      $like = '%' . self::escapeLike((string)$filters['q']) . '%';
      $params['q1'] = $params['q2'] = $params['q3'] = $like;
    }

    return [implode(' AND ', $conditions), $params];
  }

  //---------------------------------------------------------------------------
  /**
   * Turns the WHERE conditions into the clause.
   *
   * @param string $where The conditions
   *
   * @return string
   */
  private function clause(string $where): string
  {
    return $where === '' ? '' : ' WHERE ' . $where;
  }

  //---------------------------------------------------------------------------
  /**
   * Counts the entries that match conditions.
   *
   * @param string $where  The conditions
   * @param array  $params Their parameters
   *
   * @return int
   */
  private function countFiltered(string $where, array $params): int
  {
    $stmt = $this->db->prepare('SELECT COUNT(*) FROM audit_log WHERE ' . $where);
    $stmt->execute($params);

    return (int)$stmt->fetchColumn();
  }

  //---------------------------------------------------------------------------
  /**
   * Escapes the characters that have a meaning in LIKE.
   *
   * @param string $text The text
   *
   * @return string
   */
  private static function escapeLike(string $text): string
  {
    return addcslashes($text, '\\%_');
  }

  //---------------------------------------------------------------------------
  /**
   * Converts a local time of the application (optionally some days later) into the time of the database session.
   *
   * @param string $local The time as "Y-m-d H:i:s" in the time zone of the application
   * @param int    $days  Days to add
   *
   * @return string The time as "Y-m-d H:i:s" in the time zone of the database
   */
  private function databaseTime(string $local, int $days = 0): string
  {
    $moment = new DateTimeImmutable($local, $this->zone);
    if ($days !== 0) {
      $moment = $moment->modify(($days > 0 ? '+' : '') . $days . ' day');
    }

    return gmdate('Y-m-d H:i:s', $moment->getTimestamp() + $this->databaseOffset());
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the offset of the database session from UTC in seconds (read once; the change of a daylight saving time
   * within the filtered period is not taken into account).
   *
   * @return int
   */
  private function databaseOffset(): int
  {
    return $this->offset ??= (int)$this->db->query('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())')->fetchColumn();
  }

  //---------------------------------------------------------------------------
  /**
   * Prepares a row for the output: numbers as numbers, the time in the time zone of the application, the old and new
   * values decoded.
   *
   * @param array $row The row of the table
   *
   * @return array
   */
  private function present(array $row): array
  {
    $time = new DateTimeImmutable('@' . (strtotime($row['created_at'] . ' UTC') - $this->databaseOffset()));

    return [
      'id' => (int)$row['id'],
      'created_at' => $time->setTimezone($this->zone)->format('c'),
      'actor_id' => $row['actor_id'] !== null ? (int)$row['actor_id'] : null,
      'actor_username' => $row['actor_username'],
      'event' => $row['event'],
      'category' => $row['category'],
      'outcome' => $row['outcome'],
      'target_type' => $row['target_type'],
      'target_id' => $row['target_id'] !== null ? (int)$row['target_id'] : null,
      'target_label' => $row['target_label'],
      'old_value' => $this->decode($row['old_value']),
      'new_value' => $this->decode($row['new_value']),
      'ip' => $row['ip'],
      'user_agent' => $row['user_agent'],
      'request_id' => $row['request_id'],
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * Decodes a stored JSON value.
   *
   * @param string|null $json The stored text
   *
   * @return mixed The value, or null
   */
  private function decode(?string $json): mixed
  {
    return $json === null || $json === '' ? null : json_decode($json, true);
  }
}
