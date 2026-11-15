<?php

declare(strict_types=1);

namespace App\Models;

require_once __DIR__ . '/SlaReportModel.php';

use App\Services\IssueVisibility;
use App\Utils\IssueConfig;
use App\Utils\IssueKey;
use PDO;

require_once __DIR__ . "/../utils/IssueKey.php";

//---------------------------------------------------------------------------
/**
 * Model for the numbers and lists of the dashboard. Every query is limited by the central visibility check
 * (App\Services\IssueVisibility), so the dashboard never shows more than the issue list does.
 */
class DashboardModel
{
  /** Number of days of the "created vs resolved" chart */
  public const CHART_DAYS = 30;

  /** Number of rows of the issue lists and of the recent activity */
  public const LIST_LIMIT = 5;
  public const ACTIVITY_LIMIT = 10;

  /** Events about comments and attachments that users who may not see internal comments do not learn about when internal */
  private const HIDDEN_FOR_INTERNAL = ['comment_edited', 'comment_deleted', 'attachment_added', 'attachment_deleted'];

  /** Number of days of the SLA compliance tile */
  public const SLA_DAYS = 30;

  /** The tables of an issue row with its type, status and status category */
  private const FROM_SQL = 'FROM issues i
               JOIN issue_types t ON t.id = i.type_id
               JOIN `status` s ON s.id = i.status_id
               JOIN status_categories c ON c.id = s.category_id';

  private PDO $db;
  private IssueVisibility $visibility;
  private IssueConfig $config;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param PDO             $db         The database connection
   * @param IssueVisibility $visibility The visibility rules
   * @param IssueConfig     $config     The issue settings
   */
  public function __construct(PDO $db, IssueVisibility $visibility, IssueConfig $config)
  {
    $this->db = $db;
    $this->visibility = $visibility;
    $this->config = $config;
  }

  //---------------------------------------------------------------------------
  /**
   * Collects everything the dashboard shows for the logged in user.
   *
   * @param int $userId The id of the logged in user
   *
   * @return array tiles, lists (reported, assigned, watching), chart, by_category, by_type, by_product, activity
   */
  public function overview(int $userId): array
  {
    $chart = $this->createdVsResolved();
    $lastWeek = array_slice($chart['resolved'], -7);

    $data = [
      'tiles' => $this->tiles($userId) + ['resolved_7d' => array_sum($lastWeek)],
      'lists' => [
        'reported' => $this->issueList('i.reporter_id = :me', $userId),
        'assigned' => $this->issueList('i.assignee_id = :me', $userId),
        // Issues the user reports or works on are in the other lists already
        'watching' => $this->issueList(
          'EXISTS (SELECT 1 FROM issue_watcher w WHERE w.issue_id = i.id AND w.user_id = :me)
           AND COALESCE(i.reporter_id, 0) <> :me_reporter AND COALESCE(i.assignee_id, 0) <> :me_assignee',
          $userId,
          ['me_reporter' => $userId, 'me_assignee' => $userId]
        ),
      ],
      'chart' => $chart,
      'by_category' => $this->byCategory(),
      'by_type' => $this->byType(),
      'by_product' => $this->byProduct(),
      'activity' => $this->activity($userId),
    ];

    // The SLA numbers are for the holders of view_sla (and not in the limited mode of the license): nobody else gets the key
    $sla = $this->sla();
    if ($sla !== null) {
      $data['sla'] = $sla;
    }

    return $data;
  }

  //---------------------------------------------------------------------------
  /**
   * Collects the SLA tiles: the issues that are breached or at risk now, and the compliance of the last days (the rule of
   * the report: SlaReportModel).
   *
   * @return array|null breached, at_risk, days, compliance (percent, null without timers), by_metric; null if the viewer may not see SLA data
   */
  private function sla(): ?array
  {
    $report = new SlaReportModel($this->db, $this->visibility, $this->config);
    if (!$report->allowed()) {
      return null;
    }

    // The period ends one second after now, so a timer that was met this second counts
    $now = (int)$this->db->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
    $totals = $report->report($now - self::SLA_DAYS * 86400, $now + 1)['totals'];
    $all = ['total' => 0, 'met_in_time' => 0];
    foreach ($totals as $stats) {
      $all['total'] += $stats['total'];
      $all['met_in_time'] += $stats['met_in_time'];
    }

    return $report->counts() + [
      'days' => self::SLA_DAYS,
      'compliance' => $all['total'] === 0 ? null : round($all['met_in_time'] * 100 / $all['total'], 1),
      'timers' => $all['total'],
      'by_metric' => array_map(static fn(array $stats): array => ['total' => $stats['total'], 'met_in_time' => $stats['met_in_time'], 'compliance' => $stats['compliance']], $totals),
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * Counts the issues of the stat tiles: open issues (not done), the open issues assigned to the user and the
   * open issues nobody works on.
   *
   * @param int $userId The id of the logged in user
   *
   * @return array{open: int, assigned: int, unassigned: int}
   */
  private function tiles(int $userId): array
  {
    [$scope, $params] = $this->visibility->scope('i');
    $stmt = $this->db->prepare(
      "SELECT COALESCE(SUM(c.`key` <> 'done'), 0) AS open,
              COALESCE(SUM(c.`key` <> 'done' AND i.assignee_id = :me), 0) AS assigned,
              COALESCE(SUM(c.`key` <> 'done' AND i.assignee_id IS NULL), 0) AS unassigned
         " . self::FROM_SQL . "
        WHERE $scope AND i.deleted_at IS NULL"
    );
    $stmt->execute($params + ['me' => $userId]);

    return array_map('intval', $stmt->fetch());
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the newest open issues of a relation to the user, with the number of all of them.
   *
   * @param string $relation The SQL condition of the relation (uses :me)
   * @param int    $userId   The id of the logged in user
   * @param array  $extra    More parameters of the condition
   *
   * @return array{total: int, issues: array[]}
   */
  private function issueList(string $relation, int $userId, array $extra = []): array
  {
    [$scope, $params] = $this->visibility->scope('i');
    $where = "$scope AND i.deleted_at IS NULL AND c.`key` <> 'done' AND $relation";
    $params += ['me' => $userId] + $extra;

    $stmt = $this->db->prepare('SELECT COUNT(*) ' . self::FROM_SQL . " WHERE $where");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $limit = self::LIST_LIMIT;
    $stmt = $this->db->prepare(
      "SELECT i.id, i.summary, i.updated_at,
              t.`key` AS type_key, t.name AS type_name, t.icon AS type_icon, t.color AS type_color, t.background_color AS type_bg,
              s.`key` AS status_key, s.name AS status_name, s.icon AS status_icon, s.color AS status_color, s.background_color AS status_bg
         " . self::FROM_SQL . "
        WHERE $where
        ORDER BY i.updated_at DESC, i.id DESC
        LIMIT $limit"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $keys = (new IssueKey($this->db))->forIds(array_column($rows, 'id'));

    return [
      'total' => $total,
      'issues' => array_map(fn(array $row): array => [
        'id' => (int)$row['id'],
        'key' => $keys[(int)$row['id']] ?? '#' . $row['id'],
        'summary' => $row['summary'],
        'updated_at' => $row['updated_at'],
        'type' => $this->lookup($row, 'type'),
        'status' => $this->lookup($row, 'status'),
      ], $rows),
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * Counts the issues created and resolved per day over the last days (today included). Resolved means the
   * status moved from a status outside the category "done" into a status of it, as the history records.
   *
   * @return array{labels: string[], created: int[], resolved: int[]} One value per day, oldest day first
   */
  private function createdVsResolved(): array
  {
    $from = date('Y-m-d', strtotime('-' . (self::CHART_DAYS - 1) . ' days'));
    [$scope, $params] = $this->visibility->scope('i');

    $stmt = $this->db->prepare("SELECT DATE(i.created_at) AS day, COUNT(*) AS n FROM issues i WHERE $scope AND i.deleted_at IS NULL AND i.created_at >= :from GROUP BY day");
    $stmt->execute($params + ['from' => $from]);
    $created = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $stmt = $this->db->prepare(
      "SELECT DATE(h.created_at) AS day, COUNT(*) AS n
         FROM issue_history h
         JOIN issues i ON i.id = h.issue_id
         JOIN `status` sn ON sn.`key` = h.new_value
         JOIN status_categories cn ON cn.id = sn.category_id AND cn.`key` = 'done'
    LEFT JOIN `status` so ON so.`key` = h.old_value
    LEFT JOIN status_categories co ON co.id = so.category_id
        WHERE $scope AND i.deleted_at IS NULL AND h.event = 'field_changed' AND h.field = 'status'
          AND (co.`key` IS NULL OR co.`key` <> 'done') AND h.created_at >= :from
        GROUP BY day"
    );
    $stmt->execute($params + ['from' => $from]);
    $resolved = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $series = ['labels' => [], 'created' => [], 'resolved' => []];
    for ($offset = self::CHART_DAYS - 1; $offset >= 0; $offset--) {
      $day = date('Y-m-d', strtotime("-$offset days"));
      $series['labels'][] = $day;
      $series['created'][] = (int)($created[$day] ?? 0);
      $series['resolved'][] = (int)($resolved[$day] ?? 0);
    }

    return $series;
  }

  //---------------------------------------------------------------------------
  /**
   * Counts the issues per status category. Categories without issues are included.
   *
   * @return array[] key, name, color, background_color, count
   */
  private function byCategory(): array
  {
    [$scope, $params] = $this->visibility->scope('i');
    $stmt = $this->db->prepare(
      "SELECT c.`key`, c.name, c.color, c.background_color, COUNT(i.id) AS n
         FROM status_categories c
    LEFT JOIN `status` s ON s.category_id = c.id
    LEFT JOIN issues i ON i.status_id = s.id AND i.deleted_at IS NULL AND $scope
        GROUP BY c.id, c.`key`, c.name, c.color, c.background_color
        ORDER BY c.id"
    );
    $stmt->execute($params);

    return array_map(fn(array $row): array => [
      'key' => $row['key'],
      'name' => $row['name'],
      'color' => $row['color'],
      'background_color' => $row['background_color'],
      'count' => (int)$row['n'],
    ], $stmt->fetchAll());
  }

  //---------------------------------------------------------------------------
  /**
   * Counts the issues per issue type. Inactive types without issues are left out.
   *
   * @return array[] key, name, icon, color, background_color, count
   */
  private function byType(): array
  {
    [$scope, $params] = $this->visibility->scope('i');
    $stmt = $this->db->prepare(
      "SELECT t.`key`, t.name, t.icon, t.color, t.background_color, COUNT(i.id) AS n
         FROM issue_types t
    LEFT JOIN issues i ON i.type_id = t.id AND i.deleted_at IS NULL AND $scope
        GROUP BY t.id, t.`key`, t.name, t.icon, t.color, t.background_color, t.sort_order, t.active
       HAVING n > 0 OR t.active = 1
        ORDER BY t.sort_order, t.id"
    );
    $stmt->execute($params);

    return array_map(fn(array $row): array => [
      'key' => $row['key'],
      'name' => $row['name'],
      'icon' => $row['icon'],
      'color' => $row['color'],
      'background_color' => $row['background_color'],
      'count' => (int)$row['n'],
    ], $stmt->fetchAll());
  }

  //---------------------------------------------------------------------------
  /**
   * Counts the issues per product; issues without a product are counted as one entry without id.
   *
   * @return array[] id (null for no product), name, color, count; the biggest first
   */
  private function byProduct(): array
  {
    [$scope, $params] = $this->visibility->scope('i');
    $stmt = $this->db->prepare(
      "SELECT p.id, p.name, p.background_color, COUNT(*) AS n
         FROM issues i LEFT JOIN products p ON p.id = i.product_id
        WHERE $scope AND i.deleted_at IS NULL
        GROUP BY p.id, p.name, p.background_color
        ORDER BY n DESC, p.name"
    );
    $stmt->execute($params);

    return array_map(fn(array $row): array => [
      'id' => $row['id'] === null ? null : (int)$row['id'],
      'name' => $row['name'],
      'background_color' => $row['background_color'],
      'count' => (int)$row['n'],
    ], $stmt->fetchAll());
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the latest history entries of the issues the user reports, works on or watches. Entries about
   * internal comments are left out for users who may not see internal comments.
   *
   * @param int $userId The id of the logged in user
   *
   * @return array[] The entries, newest first
   */
  private function activity(int $userId): array
  {
    [$scope, $params] = $this->visibility->scope('i');
    $hidden = '';
    if (!$this->visibility->canViewInternalComments()) {
      $events = implode(', ', array_map(fn(string $event): string => $this->db->quote($event), self::HIDDEN_FOR_INTERNAL));
      $hidden = " AND NOT (h.is_internal = 1 AND h.event IN ($events))";
    }

    $limit = self::ACTIVITY_LIMIT;
    $stmt = $this->db->prepare(
      "SELECT h.id, h.event, h.field, h.old_value, h.new_value, h.is_internal, h.created_at, h.actor_id,
              i.id AS issue_id, i.summary, u.username, u.first_name, u.last_name, u.avatar
         FROM issue_history h
         JOIN issues i ON i.id = h.issue_id
    LEFT JOIN users u ON u.id = h.actor_id
        WHERE $scope AND i.deleted_at IS NULL$hidden
          AND (i.reporter_id = :me_reporter OR i.assignee_id = :me_assignee
               OR EXISTS (SELECT 1 FROM issue_watcher w WHERE w.issue_id = i.id AND w.user_id = :me_watcher))
        ORDER BY h.created_at DESC, h.id DESC
        LIMIT $limit"
    );
    $stmt->execute($params + ['me_reporter' => $userId, 'me_assignee' => $userId, 'me_watcher' => $userId]);
    $rows = $stmt->fetchAll();
    $keys = (new IssueKey($this->db))->forIds(array_column($rows, 'issue_id'));

    return array_map(fn(array $row): array => [
      'id' => (int)$row['id'],
      'event' => $row['event'],
      'field' => $row['field'],
      'old_value' => $row['old_value'],
      'new_value' => $row['new_value'],
      'is_internal' => (bool)$row['is_internal'],
      'created_at' => $row['created_at'],
      'issue' => ['id' => (int)$row['issue_id'], 'key' => $keys[(int)$row['issue_id']] ?? '#' . $row['issue_id'], 'summary' => $row['summary']],
      // A missing actor means the account was deleted
      'actor' => $row['actor_id'] === null
        ? ['id' => null, 'username' => null, 'display_name' => null, 'avatar' => null, 'deleted' => true]
        : IssueModel::userFields((int)$row['actor_id'], (string)$row['username'], $row['first_name'], $row['last_name'], $row['avatar']),
    ], $rows);
  }

  //---------------------------------------------------------------------------
  /**
   * Shapes the columns of a lookup record (type, status) of a row.
   *
   * @param array  $row    The row
   * @param string $prefix The column prefix
   *
   * @return array
   */
  private function lookup(array $row, string $prefix): array
  {
    return [
      'key' => $row["{$prefix}_key"],
      'name' => $row["{$prefix}_name"],
      'icon' => $row["{$prefix}_icon"],
      'color' => $row["{$prefix}_color"],
      'background_color' => $row["{$prefix}_bg"],
    ];
  }
}
