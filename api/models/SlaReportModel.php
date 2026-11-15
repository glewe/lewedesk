<?php

declare(strict_types=1);

namespace App\Models;

require_once __DIR__ . '/../services/SlaService.php';

use App\Services\IssueVisibility;
use App\Services\SlaService;
use App\Utils\IssueConfig;
use App\Utils\IssueKey;
use PDO;

require_once __DIR__ . "/../utils/IssueKey.php";

//---------------------------------------------------------------------------
/**
 * Model for the SLA numbers of the dashboard and of the report page (compliance, average and median times, the list of
 * the breached timers).
 *
 * One rule decides which timer belongs to a period, for the dashboard and the report alike. A timer has an outcome:
 *
 *   - it was met: the outcome is the moment it was met (`met_at`), in time when the time it needed
 *     (`achieved_seconds`, the working time) is not above its target, otherwise late;
 *   - it is still open and its due time has passed: it is breached, and the outcome is the due time (`due_at`; a breached
 *     timer that is stopped afterwards keeps the instant it was breached).
 *
 * A timer belongs to the period in which its outcome lies. Timers that are not met and not yet due, timers that wait
 * (no due time), and timers of deleted issues are not counted. The compliance is the share of the timers that were met
 * in time. A breached timer that is still open counts against it, so a team that never answers its breached issues does
 * not look compliant.
 *
 * The numbers come from what each timer stored (target, result, due time), never from the policy as it is now. The
 * group by policy uses the policy of the timer; product, assignee and priority are the current values of the issue.
 * Every query is limited by the central visibility check, so the report never shows more than the issue list does.
 */
class SlaReportModel
{
  public const METRICS = ['first_response', 'resolution'];

  /** The groupings of the report */
  public const GROUPS = ['policy', 'product', 'assignee', 'priority'];

  /** Number of breached timers listed by default, and in the export */
  public const BREACHED_LIMIT = 500;
  public const BREACHED_LIMIT_EXPORT = 20000;

  /** The working time a met timer needed (a timer from before the result was stored falls back to the clock time) */
  private const ACHIEVED = 'COALESCE(t.achieved_seconds, TIMESTAMPDIFF(SECOND, t.started_at, t.met_at))';

  /** How the report is grouped: the join, the id, and the columns that name a group */
  private const GROUP_SQL = [
    'policy' => ['join' => 'JOIN sla_policies g ON g.id = t.policy_id', 'id' => 'g.id', 'cols' => ['g.name' => 'gname']],
    'product' => ['join' => 'LEFT JOIN products g ON g.id = i.product_id', 'id' => 'g.id', 'cols' => ['g.name' => 'gname']],
    'assignee' => ['join' => 'LEFT JOIN users g ON g.id = i.assignee_id', 'id' => 'g.id', 'cols' => ['g.username' => 'gname', 'g.first_name' => 'gfirst', 'g.last_name' => 'glast']],
    'priority' => ['join' => 'LEFT JOIN priorities g ON g.id = i.priority_id', 'id' => 'g.id', 'cols' => ['g.name' => 'gname', 'g.`key`' => 'gkey']],
  ];

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param PDO             $db         The database connection
   * @param IssueVisibility $visibility The visibility rules
   * @param IssueConfig     $config     The issue settings
   */
  public function __construct(private readonly PDO $db, private readonly IssueVisibility $visibility, private readonly IssueConfig $config)
  {
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the viewer may see SLA data (permission view_sla, and the license has SLA switched on).
   *
   * @return bool
   */
  public function allowed(): bool
  {
    return SlaService::$timersEnabled && $this->visibility->hasPermission('view_sla');
  }

  //---------------------------------------------------------------------------
  /**
   * Counts the issues that have an open timer which is breached or at risk right now. The conditions are the ones of the
   * issue list (filter and quick view), so the numbers match the lists they link to.
   *
   * @return array{breached: int, at_risk: int}
   */
  public function counts(): array
  {
    [$scope, $params] = $this->visibility->scope('i');
    $breached = SlaService::stateCondition('breached', 'bt');
    $atRisk = SlaService::stateCondition('at_risk', 'rt');
    $stmt = $this->db->prepare(
      "SELECT COALESCE(SUM(EXISTS (SELECT 1 FROM issue_sla bt WHERE bt.issue_id = i.id AND bt.met_at IS NULL AND $breached)), 0) AS breached,
              COALESCE(SUM(EXISTS (SELECT 1 FROM issue_sla rt WHERE rt.issue_id = i.id AND rt.met_at IS NULL AND $atRisk)), 0) AS at_risk
         FROM issues i
        WHERE $scope AND i.deleted_at IS NULL"
    );
    $stmt->execute($params);

    return array_map('intval', $stmt->fetch());
  }

  //---------------------------------------------------------------------------
  /**
   * Calculates the numbers of a period, per metric and per group.
   *
   * @param int         $from    The start of the period (Unix time, included)
   * @param int         $to      The end of the period (Unix time, not included)
   * @param string|null $groupBy One of GROUPS, or null for the totals only
   *
   * @return array{totals: array, groups: array[]} totals by metric; groups with id, key, name, avatar fields and stats by metric
   */
  public function report(int $from, int $to, ?string $groupBy = null): array
  {
    [$scope, $params] = $this->visibility->scope('i');
    $window = $this->window($from, $to);
    $sql = $groupBy === null ? null : self::GROUP_SQL[$groupBy];

    $achieved = self::ACHIEVED;
    $idColumn = $sql === null ? 'NULL' : $sql['id'];
    $columns = '';
    $groupColumns = '';
    foreach ($sql['cols'] ?? [] as $expression => $alias) {
      $columns .= ", $expression AS $alias";
      $groupColumns .= ", $expression";
    }

    $stmt = $this->db->prepare(
      "SELECT $idColumn AS gid$columns, t.metric,
              COUNT(*) AS total,
              COALESCE(SUM(t.met_at IS NOT NULL AND $achieved <= t.target_minutes * 60), 0) AS in_time,
              COALESCE(SUM(t.met_at IS NOT NULL AND $achieved > t.target_minutes * 60), 0) AS late,
              COALESCE(SUM(t.met_at IS NULL), 0) AS open_breached,
              COALESCE(SUM(IF(t.met_at IS NULL, 0, $achieved)), 0) AS sum_achieved
         FROM issue_sla t
         JOIN issues i ON i.id = t.issue_id " . ($sql['join'] ?? '') . "
        WHERE $scope AND i.deleted_at IS NULL AND {$window[0]}
        GROUP BY " . ($sql === null ? '' : "$idColumn$groupColumns, ") . 't.metric'
    );
    $stmt->execute($params + $window[1]);

    // The rows of one group, then summed up to the totals
    $groups = [];
    foreach ($stmt->fetchAll() as $row) {
      $key = (string)($row['gid'] ?? 'none');
      $groups[$key] ??= ['id' => $row['gid'] === null ? null : (int)$row['gid'], 'row' => $row, 'raw' => []];
      $groups[$key]['raw'][$row['metric']] = [
        'total' => (int)$row['total'],
        'met_in_time' => (int)$row['in_time'],
        'met_late' => (int)$row['late'],
        'breached_open' => (int)$row['open_breached'],
        'sum_achieved' => (int)$row['sum_achieved'],
      ];
    }

    $medians = $this->medians($scope, $params, $window, $sql);

    $totals = [];
    foreach (self::METRICS as $metric) {
      $totals[$metric] = $this->emptyRaw();
    }
    $result = [];
    foreach ($groups as $key => $group) {
      $stats = [];
      foreach (self::METRICS as $metric) {
        $raw = $group['raw'][$metric] ?? $this->emptyRaw();
        foreach ($raw as $name => $value) {
          $totals[$metric][$name] += $value;
        }
        $stats[$metric] = $this->stats($raw, $this->median($medians[$key][$metric] ?? []));
      }
      if ($groupBy !== null) {
        $result[] = ['id' => $group['id']] + $this->describe($groupBy, $group['row']) + ['stats' => $stats];
      }
    }

    // The median of all timers needs all values, not the medians of the groups
    $all = [];
    foreach ($medians as $perMetric) {
      foreach ($perMetric as $metric => $values) {
        $all[$metric] = array_merge($all[$metric] ?? [], $values);
      }
    }
    foreach (self::METRICS as $metric) {
      $totals[$metric] = $this->stats($totals[$metric], $this->median($all[$metric] ?? []));
    }

    // The groups with the most timers first
    usort($result, static function (array $a, array $b): int {
      $count = static fn(array $group): int => array_sum(array_column($group['stats'], 'total'));
      return $count($b) <=> $count($a) ?: strcasecmp((string)$a['name'], (string)$b['name']);
    });

    return ['totals' => $totals, 'groups' => $result];
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the timers of a period that were breached: the ones that were met late and the ones that are open and over their
   * due time, newest outcome first.
   *
   * @param int $from  The start of the period (Unix time, included)
   * @param int $to    The end of the period (Unix time, not included)
   * @param int $limit The maximum number of rows
   *
   * @return array[] issue, metric, policy, assignee, target_minutes, due_at, met_at, overrun_seconds
   */
  public function breached(int $from, int $to, int $limit = self::BREACHED_LIMIT): array
  {
    [$scope, $params] = $this->visibility->scope('i');
    $window = $this->window($from, $to);
    $achieved = self::ACHIEVED;
    $limit = max(1, min($limit, self::BREACHED_LIMIT_EXPORT));

    $stmt = $this->db->prepare(
      "SELECT t.id, t.issue_id, t.metric, t.period, t.target_minutes, t.overridden, $achieved AS achieved,
              UNIX_TIMESTAMP(t.due_at) AS due, UNIX_TIMESTAMP(t.met_at) AS met,
              i.summary, p.name AS policy_name, u.id AS user_id, u.username, u.first_name, u.last_name, u.avatar
         FROM issue_sla t
         JOIN issues i ON i.id = t.issue_id
    LEFT JOIN sla_policies p ON p.id = t.policy_id
    LEFT JOIN users u ON u.id = i.assignee_id
        WHERE $scope AND i.deleted_at IS NULL AND {$window[0]}
          AND (t.met_at IS NULL OR $achieved > t.target_minutes * 60)
        ORDER BY COALESCE(t.met_at, t.due_at) DESC, t.id DESC
        LIMIT $limit"
    );
    $stmt->execute($params + $window[1]);
    $rows = $stmt->fetchAll();
    $keys = (new IssueKey($this->db))->forIds(array_column($rows, 'issue_id'));

    // A timer that is still open has no result yet: its working time up to now comes from the calendar of the timer
    $open = array_column(array_filter($rows, static fn(array $row): bool => $row['met'] === null), 'id');
    $overruns = $open === [] ? [] : (new SlaService($this->db))->overruns($open);

    return array_map(function (array $row) use ($overruns, $keys): array {
      $overrun = $row['met'] === null ? ($overruns[(int)$row['id']] ?? null) : max(0, (int)$row['achieved'] - (int)$row['target_minutes'] * 60);

      return [
        'issue' => ['id' => (int)$row['issue_id'], 'key' => $keys[(int)$row['issue_id']] ?? '#' . $row['issue_id'], 'summary' => $row['summary']],
        'metric' => $row['metric'],
        'period' => (int)$row['period'],
        'policy' => $row['policy_name'],
        'assignee' => $row['user_id'] === null ? null : IssueModel::userFields((int)$row['user_id'], (string)$row['username'], $row['first_name'], $row['last_name'], $row['avatar']),
        'target_minutes' => (int)$row['target_minutes'],
        'overridden' => (bool)$row['overridden'],
        'due_at' => $row['due'] === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int)$row['due']),
        'met_at' => $row['met'] === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int)$row['met']),
        'overrun_seconds' => $overrun,
      ];
    }, $rows);
  }

  //---------------------------------------------------------------------------
  /**
   * The condition that selects the timers of a period (see the rule in the description of the class).
   *
   * @param int $from The start of the period (Unix time, included)
   * @param int $to   The end of the period (Unix time, not included)
   *
   * @return array{0: string, 1: array<string, int>} The condition and its parameters
   */
  private function window(int $from, int $to): array
  {
    return [
      '((t.met_at >= FROM_UNIXTIME(:sla_from_met) AND t.met_at < FROM_UNIXTIME(:sla_to_met))
        OR (t.met_at IS NULL AND t.due_at < NOW() AND t.due_at >= FROM_UNIXTIME(:sla_from_due) AND t.due_at < FROM_UNIXTIME(:sla_to_due)))',
      ['sla_from_met' => $from, 'sla_to_met' => $to, 'sla_from_due' => $from, 'sla_to_due' => $to],
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * Reads the times of the met timers of a period (sorted), for the median.
   *
   * @param string      $scope  The visibility condition
   * @param array       $params The parameters of the condition
   * @param array       $window The condition of the period and its parameters
   * @param array|null  $sql    The grouping (GROUP_SQL), null for none
   *
   * @return array<string, array<string, int[]>> The seconds by group key and metric
   */
  private function medians(string $scope, array $params, array $window, ?array $sql): array
  {
    $achieved = self::ACHIEVED;
    $idColumn = $sql === null ? 'NULL' : $sql['id'];
    $stmt = $this->db->prepare(
      "SELECT $idColumn AS gid, t.metric, $achieved AS achieved
         FROM issue_sla t
         JOIN issues i ON i.id = t.issue_id " . ($sql['join'] ?? '') . "
        WHERE $scope AND i.deleted_at IS NULL AND t.met_at IS NOT NULL AND {$window[0]}
        ORDER BY gid, t.metric, achieved"
    );
    $stmt->execute($params + $window[1]);

    $values = [];
    foreach ($stmt->fetchAll() as $row) {
      $values[(string)($row['gid'] ?? 'none')][$row['metric']][] = (int)$row['achieved'];
    }

    return $values;
  }

  //---------------------------------------------------------------------------
  /**
   * @param int[] $sorted The values, ascending
   *
   * @return int|null The median (the mean of the two in the middle when the count is even, rounded), null without values
   */
  private function median(array $sorted): ?int
  {
    $count = count($sorted);
    if ($count === 0) {
      return null;
    }
    sort($sorted);
    $middle = intdiv($count, 2);

    return $count % 2 === 1 ? $sorted[$middle] : (int)round(($sorted[$middle - 1] + $sorted[$middle]) / 2);
  }

  //---------------------------------------------------------------------------
  /**
   * @return array<string, int> The counters of a metric before they are summed up
   */
  private function emptyRaw(): array
  {
    return ['total' => 0, 'met_in_time' => 0, 'met_late' => 0, 'breached_open' => 0, 'sum_achieved' => 0];
  }

  //---------------------------------------------------------------------------
  /**
   * Shapes the counters of one metric for the answer.
   *
   * @param array    $raw    The counters (total, met_in_time, met_late, breached_open, sum_achieved)
   * @param int|null $median The median of the met timers
   *
   * @return array total, met_in_time, met_late, breached_open, compliance (percent, one decimal, null without timers),
   *               avg_seconds, median_seconds (null without met timers)
   */
  private function stats(array $raw, ?int $median): array
  {
    $met = $raw['met_in_time'] + $raw['met_late'];

    return [
      'total' => $raw['total'],
      'met_in_time' => $raw['met_in_time'],
      'met_late' => $raw['met_late'],
      'breached_open' => $raw['breached_open'],
      'compliance' => $raw['total'] === 0 ? null : round($raw['met_in_time'] * 100 / $raw['total'], 1),
      'avg_seconds' => $met === 0 ? null : (int)round($raw['sum_achieved'] / $met),
      'median_seconds' => $median,
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * Names a group of the report.
   *
   * @param string $groupBy The grouping
   * @param array  $row     A row of the query
   *
   * @return array key (priorities: the key of a protected one), name, avatar (assignees), none (the group of the issues without a value)
   */
  private function describe(string $groupBy, array $row): array
  {
    if ($row['gid'] === null) {
      return ['key' => null, 'name' => null, 'user' => null, 'none' => true];
    }
    if ($groupBy === 'assignee') {
      $user = IssueModel::userFields((int)$row['gid'], (string)$row['gname'], $row['gfirst'], $row['glast'], null);

      return ['key' => null, 'name' => $user['display_name'], 'user' => ['id' => $user['id'], 'username' => $user['username']], 'none' => false];
    }

    return ['key' => $row['gkey'] ?? null, 'name' => $row['gname'], 'user' => null, 'none' => false];
  }
}
