<?php

declare(strict_types=1);

namespace App\Services;

require_once __DIR__ . '/SlaCalculator.php';
require_once __DIR__ . '/SlaTimer.php';
require_once __DIR__ . '/SlaOverrideException.php';
require_once __DIR__ . '/SlaDeadlockException.php';

use PDO;
use Throwable;

//---------------------------------------------------------------------------
/**
 * The SLA timers of the issues (see _docs/SLA.md, sections 3, 6 and 13).
 *
 * One timer is a row of issue_sla (one per issue, metric and period); its clock is an App\Services\SlaTimer, and the
 * business hours come from App\Services\SlaCalculator. The timers are not driven by events. After every change of an
 * issue the callers (IssueModel, IssueWorkflowModel, CommentModel, LookupModel) call sync(), which looks at what the
 * issue is now and brings the timers in line:
 *
 *   - the first public comment of an agent (not the reporter) stops "first response" at the time of the comment
 *   - a status of the pause set of the policy stops the clock, any other status starts it again
 *   - a deleted issue cancels the timers, a restored one starts them again
 *   - a status of the category Done stops "resolution" (and a "first response" that is still open); a status outside
 *     Done after that starts a new period of "resolution" (the old period keeps its result)
 *
 * sync() is idempotent and works inside the transaction of the caller: it never starts or commits one, and it locks the
 * row of the issue first, so two requests cannot both start a period. It never fails the action of the user: what goes
 * wrong is logged and the next sync repairs the timers.
 *
 * Timers are started for an issue when it is created (startTimers()) and by the action "apply to open issues"
 * (applyToOpenIssues()), never for the issues that exist without timers otherwise. Changing the type, priority, product
 * or access of an issue looks for the policy again (sync with $reevaluate): that moves a timer that is running or
 * paused to the target of the new policy, but never touches a timer that is met, overridden or breached.
 *
 * All times are Unix timestamps of the database clock (the columns are written with FROM_UNIXTIME() and read with
 * UNIX_TIMESTAMP()), so the time zone of PHP does not matter. The session time zone of the database only matters for the
 * hour that exists twice when the clocks go back (a TIMESTAMP written in that hour is read as the first one).
 */
class SlaService
{
  /**
   * Whether new timers are started. The license switches it off in the limited mode (index.php, housekeeping.php); the
   * timers that exist keep being updated, so what happens in that time is not lost when the license is back.
   */
  public static bool $timersEnabled = true;

  public const METRICS = ['first_response', 'resolution'];

  /** The states of the filter of the issue list (see stateCondition()) */
  public const LIST_STATES = ['breached', 'at_risk', 'due_today', 'on_track', 'paused'];

  /** @var array<int|string, SlaCalculator> The calculators by calendar id ("none" for around the clock) */
  private array $calculators = [];

  /** @var array<int, array>|null The active policies in the order they are tried */
  private ?array $policies = null;

  /** @var array<int, array|null> The policies read one by one (a policy may be inactive) */
  private array $single = [];

  public function __construct(private readonly PDO $db)
  {
  }

  //---------------------------------------------------------------------------
  /**
   * Starts the timers of a new issue: the policy that matches it, one timer per metric the policy has a target for.
   * Nothing happens when SLA is switched off by the license, no policy matches, or the issue has timers already.
   *
   * @param int $issueId The issue (inside the transaction that created it)
   *
   * @return int The number of timers started
   */
  public function startTimers(int $issueId): int
  {
    if (!self::$timersEnabled) {
      return 0;
    }

    try {
      return (int)$this->guarded(function () use ($issueId): int {
        $issue = $this->issue($issueId);
        if ($issue === null || $this->timerRows($issueId) !== []) {
          return 0;
        }
        $policy = $this->selectPolicy($issue);
        if ($policy === null) {
          return 0;
        }

        $started = 0;
        foreach (self::METRICS as $metric) {
          $target = $this->targetFor($policy, $issue['priority_id'], $metric);
          if ($target === null) {
            continue;
          }
          $this->insertTimer($issueId, $metric, 1, $policy['id'], $policy['calendar_id'], $target, $issue['created']);
          $started++;
        }
        if ($started > 0) {
          $this->doSync($issueId, false);
        }

        return $started;
      });
    } catch (SlaDeadlockException $e) {
      throw $e;
    } catch (Throwable $e) {
      error_log(sprintf('SLA: the timers of issue %d could not be started: %s', $issueId, $e->getMessage()));

      return 0;
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Brings the timers of an issue in line with what the issue is now (see the class description).
   *
   * @param int  $issueId    The issue (inside the transaction of the change)
   * @param bool $reevaluate Whether the policy is looked for again (type, priority, product or access changed)
   *
   * @return void
   */
  public function sync(int $issueId, bool $reevaluate = false): void
  {
    try {
      $this->guarded(function () use ($issueId, $reevaluate): void {
        $this->doSync($issueId, $reevaluate);
      });
    } catch (SlaDeadlockException $e) {
      throw $e;
    } catch (Throwable $e) {
      error_log(sprintf('SLA: the timers of issue %d could not be updated: %s', $issueId, $e->getMessage()));
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Runs a piece of work on the timers so that it is all or nothing inside the transaction of the caller: a failure takes
   * back what the work wrote (a savepoint) and is passed on to be logged, so the action of the user goes on. A deadlock
   * is passed on as it is: the database has taken back the whole transaction of the caller, and the action must not
   * report success for a change that is gone.
   *
   * @param callable $work The work
   *
   * @return mixed What the work returned
   *
   * @throws Throwable What went wrong in the work
   */
  private function guarded(callable $work): mixed
  {
    if (!$this->db->inTransaction()) {
      return $work();
    }

    $this->db->exec('SAVEPOINT sla_sync');
    try {
      $result = $work();
      $this->db->exec('RELEASE SAVEPOINT sla_sync');

      return $result;
    } catch (Throwable $e) {
      if ($e instanceof \PDOException && ($e->getCode() === '40001' || (int)($e->errorInfo[1] ?? 0) === 1213)) {
        throw new SlaDeadlockException($e->getMessage(), 0, $e);
      }
      try {
        $this->db->exec('ROLLBACK TO SAVEPOINT sla_sync');
      } catch (Throwable) {
        // The transaction is gone: the caller finds out when it commits
      }
      throw $e;
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Brings the timers of several issues in line (for a change that moves many issues at once).
   *
   * @param int[] $issueIds   The issues
   * @param bool  $reevaluate Whether the policy is looked for again
   *
   * @return void
   */
  public function syncMany(array $issueIds, bool $reevaluate = false): void
  {
    foreach ($issueIds as $issueId) {
      $this->sync((int)$issueId, $reevaluate);
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Starts timers for the issues that are not done and not deleted and have none: the action "apply to open issues".
   * The clock of such a timer starts when the issue was created, so it may be breached at once.
   *
   * @return array{issues: int, timers: int} The issues that got timers and the number of timers
   */
  public function applyToOpenIssues(): array
  {
    $ids = $this->db->query(
      "SELECT i.id FROM issues i
         JOIN `status` s ON s.id = i.status_id
         JOIN status_categories c ON c.id = s.category_id
        WHERE i.deleted_at IS NULL AND c.`key` <> 'done'
          AND NOT EXISTS (SELECT 1 FROM issue_sla t WHERE t.issue_id = i.id)
        ORDER BY i.id"
    )->fetchAll(PDO::FETCH_COLUMN);

    $issues = 0;
    $timers = 0;
    foreach ($ids as $id) {
      $this->db->beginTransaction();
      try {
        $count = $this->startTimers((int)$id);
        $this->db->commit();
      } catch (Throwable $e) {
        if ($this->db->inTransaction()) {
          $this->db->rollBack();
        }
        error_log(sprintf('SLA: issue %d could not get timers: %s', $id, $e->getMessage()));
        continue;
      }
      if ($count > 0) {
        $issues++;
        $timers += $count;
      }
    }

    // Timers from before the stored warning moment existed get it now
    $this->refreshOpenTimers(null, null, true);

    return ['issues' => $issues, 'timers' => $timers];
  }

  //---------------------------------------------------------------------------
  /**
   * Brings the stored due and warning moments of the open timers in line with what their calendar and policy say now.
   * The issue list and the escalation check read the stored moments, and a timer is derived from the current content of
   * its calendar: after the hours or the holidays of a calendar (or the warning threshold of a policy) changed, the
   * stored moments of its running timers are out of date until the issue changes the next time.
   *
   * @param int|null $calendarId   Only the timers that run on this calendar
   * @param int|null $policyId     Only the timers of this policy
   * @param bool     $onlyMissing  Only the timers that have a due time but no warning moment (from before it was stored)
   *
   * @return int The number of issues that were looked at
   */
  public function refreshOpenTimers(?int $calendarId = null, ?int $policyId = null, bool $onlyMissing = false): int
  {
    $where = ['t.met_at IS NULL', 'i.deleted_at IS NULL'];
    $params = [];
    if ($calendarId !== null) {
      $where[] = 't.calendar_id = :calendar';
      $params['calendar'] = $calendarId;
    }
    if ($policyId !== null) {
      $where[] = 't.policy_id = :policy';
      $params['policy'] = $policyId;
    }
    if ($onlyMissing) {
      $where[] = 't.due_at IS NOT NULL AND t.warn_at IS NULL';
    }
    $stmt = $this->db->prepare('SELECT DISTINCT t.issue_id FROM issue_sla t JOIN issues i ON i.id = t.issue_id WHERE ' . implode(' AND ', $where) . ' ORDER BY t.issue_id');
    $stmt->execute($params);

    $count = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
      $this->db->beginTransaction();
      try {
        $this->doSync((int)$id, false);
        $this->db->commit();
        $count++;
      } catch (Throwable $e) {
        if ($this->db->inTransaction()) {
          $this->db->rollBack();
        }
        error_log(sprintf('SLA: the timers of issue %d could not be refreshed: %s', $id, $e->getMessage()));
      }
    }

    return $count;
  }

  //---------------------------------------------------------------------------
  /**
   * Builds the SQL condition of one state of the issue list for an open timer (met_at IS NULL, to be added by the
   * caller). The states are read from the stored moments, so the list needs no calendar:
   *
   *   breached   the due time has passed (also for a breached timer that waits: its due time is the moment it was breached)
   *   at_risk    the clock runs and the warning moment has passed
   *   due_today  the clock runs and the due time is before the end of the day (the caller binds :sla_day_end)
   *   on_track   the clock runs, nothing of the above
   *   paused     the clock is stopped and the target is not breached
   *
   * @param string $state The state (one of LIST_STATES)
   * @param string $timer The alias of issue_sla in the query
   *
   * @return string The condition (a state that does not exist matches nothing)
   */
  public static function stateCondition(string $state, string $timer = 't'): string
  {
    return match ($state) {
      'breached' => "$timer.due_at < NOW()",
      'at_risk' => "($timer.due_at >= NOW() AND $timer.warn_at <= NOW())",
      'due_today' => "($timer.due_at >= NOW() AND $timer.due_at < FROM_UNIXTIME(:sla_day_end))",
      'on_track' => "($timer.due_at >= NOW() AND ($timer.warn_at IS NULL OR $timer.warn_at > NOW()))",
      'paused' => "$timer.due_at IS NULL",
      default => '0',
    };
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the SLA state of each issue of a page of the issue list: the open timer that needs the most attention
   * (breached, at risk, on track, paused, in this order and the earliest due time first), or "met" when all timers of the
   * issue are met. One query for all issues; the stored moments are compared with the clock of the database.
   *
   * @param int[] $issueIds The issues of the page
   *
   * @return array<int, array{state: string, metric: string, due_at: string|null}> By issue id; an issue without timers
   *                                                                                  (or a deleted one) is missing
   */
  public function listSummaries(array $issueIds): array
  {
    $issueIds = array_values(array_unique(array_map('intval', $issueIds)));
    if ($issueIds === []) {
      return [];
    }
    $marks = implode(',', array_fill(0, count($issueIds), '?'));
    $stmt = $this->db->prepare(
      "SELECT t.issue_id, t.metric, (t.met_at IS NOT NULL) AS met, UNIX_TIMESTAMP(t.due_at) AS due,
              CASE WHEN t.due_at IS NULL THEN 'paused'
                   WHEN t.due_at < NOW() THEN 'breached'
                   WHEN t.warn_at <= NOW() THEN 'at_risk'
                   ELSE 'on_track' END AS state
         FROM issue_sla t JOIN issues i ON i.id = t.issue_id
        WHERE t.issue_id IN ($marks) AND i.deleted_at IS NULL"
    );
    $stmt->execute($issueIds);

    $rank = ['breached' => 0, 'at_risk' => 1, 'on_track' => 2, 'paused' => 3];
    $best = [];
    foreach ($stmt->fetchAll() as $row) {
      $id = (int)$row['issue_id'];
      if ((bool)$row['met']) {
        $best[$id] ??= ['state' => 'met', 'metric' => $row['metric'], 'due' => null];
        continue;
      }
      $candidate = ['state' => $row['state'], 'metric' => $row['metric'], 'due' => $row['due'] === null ? null : (int)$row['due']];
      $current = $best[$id] ?? null;
      $better = $current === null || $current['state'] === 'met'
        || $rank[$candidate['state']] < $rank[$current['state']]
        || ($rank[$candidate['state']] === $rank[$current['state']] && $candidate['due'] !== null && ($current['due'] === null || $candidate['due'] < $current['due']));
      if ($better) {
        $best[$id] = $candidate;
      }
    }

    return array_map(
      static fn(array $b): array => ['state' => $b['state'], 'metric' => $b['metric'], 'due_at' => $b['due'] === null ? null : gmdate('Y-m-d\TH:i:s\Z', $b['due'])],
      $best
    );
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the current timer of every metric of an issue as the API shows it.
   *
   * @param int $issueId The issue
   *
   * @return array<int, array> Empty if the issue has no timers
   */
  public function view(int $issueId): array
  {
    $rows = $this->timerRows($issueId);
    if ($rows === []) {
      return [];
    }
    $now = $this->now();
    $stops = $this->stopsOf(array_column($rows, 'id'));

    $current = [];
    foreach ($rows as $row) {
      $current[$row['metric']] = $row;
    }
    $result = [];
    foreach (self::METRICS as $metric) {
      if (isset($current[$metric])) {
        $result[] = $this->present($current[$metric], $stops[(int)$current[$metric]['id']] ?? [], $now);
      }
    }

    return $result;
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the working time by which timers are over their target (for the report of the breached issues). The time is
   * counted by the calendar of each timer up to now, or up to the moment it was met.
   *
   * @param int[] $timerIds The ids of the rows of issue_sla
   *
   * @return array<int, int|null> Seconds over the target by timer id (at least 0); null if the timer cannot be evaluated
   */
  public function overruns(array $timerIds): array
  {
    $timerIds = array_values(array_unique(array_map('intval', $timerIds)));
    if ($timerIds === []) {
      return [];
    }
    $marks = implode(',', array_fill(0, count($timerIds), '?'));
    $stmt = $this->db->prepare(
      "SELECT t.*, UNIX_TIMESTAMP(t.started_at) AS started, UNIX_TIMESTAMP(t.met_at) AS met, UNIX_TIMESTAMP(t.due_at) AS due, UNIX_TIMESTAMP(t.warn_at) AS warn
         FROM issue_sla t WHERE t.id IN ($marks)"
    );
    $stmt->execute($timerIds);
    $rows = $stmt->fetchAll();
    $stops = $this->stopsOf(array_column($rows, 'id'));
    $now = $this->now();

    $result = [];
    foreach ($rows as $row) {
      $facts = $this->factsOf($row, $stops[(int)$row['id']] ?? []);
      try {
        // A met timer keeps the result it had when it was met
        $elapsed = $facts['met'] !== null && $facts['achieved'] !== null ? $facts['achieved'] : $this->timer($facts)->elapsedSeconds($now);
        $result[(int)$row['id']] = max(0, $elapsed - $facts['minutes'] * 60);
      } catch (Throwable $e) {
        error_log(sprintf('SLA: timer %d cannot be evaluated: %s', $row['id'], $e->getMessage()));
        $result[(int)$row['id']] = null;
      }
    }

    return $result;
  }

  //---------------------------------------------------------------------------
  /**
   * Changes the due time of the current timer of a metric by hand. The calculation is made on minutes, so the new due
   * time becomes the target (in working minutes from the start); the timer is marked as overridden and no later change
   * of the policy touches it.
   *
   * @param int    $issueId The issue (inside a transaction)
   * @param string $metric  first_response or resolution
   * @param int    $dueAt   The new due time (Unix timestamp)
   * @param string $reason  Why (not empty)
   *
   * @return array{old: int|null, new: int|null, timer: array} The old and new due time and the timer as the API shows it
   *
   * @throws SlaOverrideException If the timer does not exist, is met, is stopped, or the time is not possible
   */
  public function override(int $issueId, string $metric, int $dueAt, string $reason): array
  {
    $this->issue($issueId);
    $rows = array_values(array_filter($this->timerRows($issueId), static fn(array $row): bool => $row['metric'] === $metric));
    if ($rows === []) {
      throw new SlaOverrideException('The issue has no such SLA timer', 404);
    }
    $row = $rows[count($rows) - 1];
    $stops = $this->stopsOf([(int)$row['id']])[(int)$row['id']] ?? [];
    $facts = $this->factsOf($row, $stops);
    if ($facts['met'] !== null) {
      throw new SlaOverrideException('The timer is met already', 409);
    }
    $timer = $this->timer($facts);
    // The open stop itself, not the state: a timer that is breached and waiting reports "breached"
    $lastStop = $facts['stops'] === [] ? null : $facts['stops'][count($facts['stops']) - 1];
    if ($lastStop !== null && $lastStop['end'] === null) {
      throw new SlaOverrideException('The clock is stopped. Change the due time when the clock runs again', 409);
    }

    $calculator = $this->calculator($facts['calendar_id']);
    $seconds = $calculator->elapsed($facts['start'], $dueAt, array_map(static fn(array $stop): array => [$stop['start'], $stop['end']], $facts['stops']));
    $minutes = (int)ceil($seconds / 60);
    if ($dueAt <= $facts['start'] || $minutes < 1 || $minutes > 1051200) {
      throw new SlaOverrideException('The due time must be after the start of the clock and within two years of working time', 400);
    }

    $old = $timer->dueAt()?->getTimestamp();
    $this->db->prepare('UPDATE issue_sla SET target_minutes = :minutes, overridden = 1, override_reason = :reason WHERE id = :id')
      ->execute(['minutes' => $minutes, 'reason' => mb_substr($reason, 0, 255), 'id' => $row['id']]);
    $this->doSync($issueId, false);

    $timers = $this->view($issueId);
    $new = null;
    foreach ($timers as $shown) {
      if ($shown['metric'] === $metric) {
        $new = $shown['due_at'] === null ? null : (int)strtotime($shown['due_at']);
      }
    }

    return ['old' => $old, 'new' => $new, 'timer' => array_values(array_filter($timers, static fn(array $shown): bool => $shown['metric'] === $metric))[0]];
  }

  //---------------------------------------------------------------------------
  /**
   * The work of sync(): throws what goes wrong.
   *
   * @param int  $issueId    The issue
   * @param bool $reevaluate Whether the policy is looked for again
   *
   * @return void
   */
  private function doSync(int $issueId, bool $reevaluate): void
  {
    $issue = $this->issue($issueId);
    $rows = $issue === null ? [] : $this->timerRows($issueId);
    if ($rows === []) {
      return;
    }

    $now = $this->now();
    $stops = $this->stopsOf(array_column($rows, 'id'));
    $current = [];
    foreach ($rows as $row) {
      $current[$row['metric']] = $row;
    }

    $policy = null;
    $policyLoaded = false;
    $firstResponse = false;
    foreach ($current as $metric => $row) {
      $before = $this->factsOf($row, $stops[(int)$row['id']] ?? []);
      $facts = $before;

      if ($facts['met'] === null) {
        $timer = $this->timer($facts);

        // The policy is looked for again: a timer that runs moves to the target of the new policy
        if ($reevaluate && !$facts['overridden'] && $timer->state($now) !== SlaTimer::BREACHED) {
          if (!$policyLoaded) {
            $policy = $this->selectPolicy($issue);
            $policyLoaded = true;
          }
          $target = $policy === null ? null : $this->targetFor($policy, $issue['priority_id'], $metric);
          if ($policy !== null && $target !== null && ($target !== $facts['minutes'] || $policy['id'] !== $facts['policy_id'] || $policy['calendar_id'] !== $facts['calendar_id'])) {
            $facts['policy_id'] = $policy['id'];
            $facts['calendar_id'] = $policy['calendar_id'];
            $facts['minutes'] = $target;
            $timer = $this->timer($facts);
          }
        }

        // Deleted: the clock stops. Restored: it runs again, unless a waiting status stops it
        if ($issue['deleted']) {
          $timer = $timer->cancel($this->clamp($facts, $now));
        } else {
          $timer = $timer->restore($this->clamp($facts, $now));
          $timer = in_array($issue['status_id'], $this->pauseStatusIds($facts['policy_id']), true)
            ? $timer->pause($this->clamp($facts, $now))
            : $timer->resume($this->clamp($facts, $now));
        }

        // The first public comment of an agent is the first response
        if ($metric === 'first_response') {
          $respondedAt = $this->firstResponseAt($issue);
          if ($respondedAt !== null) {
            $timer = $timer->stop($this->clamp($facts, $respondedAt));
          }
        }

        // An issue that is done is resolved, and a response is the least it has got
        if ($issue['category'] === 'done') {
          $timer = $timer->stop($this->clamp($facts, $now));
        }

        $data = $timer->toArray();
        $facts['stops'] = $data['stops'];
        $facts['met'] = $data['met_at'];
        if ($facts['met'] !== null) {
          $facts['achieved'] = $timer->elapsedSeconds($facts['met']);
        }
      }

      $this->save($row, $before, $facts);

      // The resolution of a reopened issue starts again, the old period keeps its result
      if ($metric === 'resolution' && $facts['met'] !== null && $issue['category'] !== 'done' && !$issue['deleted']) {
        $this->startPeriod($issue, $row, $facts, (int)$row['period'] + 1, $now);
      }
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Starts the next period of the resolution after a reopen: the same policy, the target of its priority.
   *
   * @param array $issue The issue
   * @param array $row   The row of the period that ended
   * @param array $facts The facts of that period
   * @param int   $period The number of the new period
   * @param int   $now    The start of the new period
   *
   * @return void
   */
  private function startPeriod(array $issue, array $row, array $facts, int $period, int $now): void
  {
    $policy = $this->policyById($facts['policy_id']);
    $target = $policy === null ? null : $this->targetFor($policy, $issue['priority_id'], 'resolution');
    $this->insertTimer((int)$row['issue_id'], 'resolution', $period, $facts['policy_id'], $facts['calendar_id'], $target ?? $facts['minutes'], $now);

    $this->doSync((int)$row['issue_id'], false);
  }

  //---------------------------------------------------------------------------
  /**
   * Writes what changed in a timer.
   *
   * @param array $row    The row as it was read
   * @param array $before The facts as they were read
   * @param array $facts  The facts now
   *
   * @return void
   */
  private function save(array $row, array $before, array $facts): void
  {
    $timer = $this->timer($facts);
    // The stored copies for the issue list: the due time and the moment the warning threshold is reached. Both are null
    // while the clock is stopped before the target (a breached timer that waits keeps the instant it was breached) and
    // after the target was met.
    $due = null;
    $warn = null;
    if ($facts['met'] === null) {
      try {
        $due = $timer->dueAt()?->getTimestamp();
        $warn = $timer->warnAt($this->warningPercent($facts['policy_id']))?->getTimestamp();
      } catch (Throwable) {
        $due = null;
        $warn = null;
      }
    }

    $storedDue = $row['due'] === null ? null : (int)$row['due'];
    $storedWarn = ($row['warn'] ?? null) === null ? null : (int)$row['warn'];
    if ($before === $facts && $storedDue === $due && $storedWarn === $warn) {
      return;
    }

    $this->db->prepare(
      'UPDATE issue_sla
          SET policy_id = :policy, calendar_id = :calendar, target_minutes = :minutes,
              met_at = IF(:met IS NULL, NULL, FROM_UNIXTIME(:met2)), achieved_seconds = :achieved,
              due_at = IF(:due IS NULL, NULL, FROM_UNIXTIME(:due2)),
              warn_at = IF(:warn IS NULL, NULL, FROM_UNIXTIME(:warn2))
        WHERE id = :id'
    )->execute([
      'policy' => $facts['policy_id'],
      'calendar' => $facts['calendar_id'],
      'minutes' => $facts['minutes'],
      'met' => $facts['met'],
      'met2' => $facts['met'],
      'achieved' => $facts['achieved'],
      'due' => $due,
      'due2' => $due,
      'warn' => $warn,
      'warn2' => $warn,
      'id' => $row['id'],
    ]);

    if ($before['stops'] !== $facts['stops']) {
      $this->db->prepare('DELETE FROM issue_sla_stops WHERE issue_sla_id = :id')->execute(['id' => $row['id']]);
      $insert = $this->db->prepare('INSERT INTO issue_sla_stops (issue_sla_id, started_at, ended_at, reason) VALUES (:id, FROM_UNIXTIME(:start), IF(:end IS NULL, NULL, FROM_UNIXTIME(:end2)), :reason)');
      foreach ($facts['stops'] as $stop) {
        $insert->execute(['id' => $row['id'], 'start' => $stop['start'], 'end' => $stop['end'], 'end2' => $stop['end'], 'reason' => $stop['reason']]);
      }
    }
  }

  //---------------------------------------------------------------------------
  /**
   * @param int $policyId The policy
   *
   * @return int The warning threshold of the policy in percent (80 if the policy is gone)
   */
  private function warningPercent(int $policyId): int
  {
    return (int)($this->policyById($policyId)['warning_percent'] ?? 80);
  }

  //---------------------------------------------------------------------------
  /**
   * @param int         $issueId  The issue
   * @param string      $metric   first_response or resolution
   * @param int         $period   The period (1 for a new issue)
   * @param int         $policyId The policy
   * @param int|null    $calendarId The calendar of the policy (null: around the clock)
   * @param int         $minutes  The target in working minutes
   * @param int         $start    The start of the clock (Unix timestamp)
   *
   * @return void
   */
  private function insertTimer(int $issueId, string $metric, int $period, int $policyId, ?int $calendarId, int $minutes, int $start): void
  {
    $this->db->prepare(
      'INSERT INTO issue_sla (issue_id, metric, period, policy_id, calendar_id, target_minutes, started_at)
       VALUES (:issue, :metric, :period, :policy, :calendar, :minutes, FROM_UNIXTIME(:start))'
    )->execute(['issue' => $issueId, 'metric' => $metric, 'period' => $period, 'policy' => $policyId, 'calendar' => $calendarId, 'minutes' => $minutes, 'start' => $start]);
  }

  //---------------------------------------------------------------------------
  /**
   * Reads and locks the issue with what the timers depend on.
   *
   * @param int $issueId The issue
   *
   * @return array|null Null if there is no such issue
   */
  private function issue(int $issueId): ?array
  {
    $stmt = $this->db->prepare(
      "SELECT i.id, i.status_id, i.type_id, i.product_id, i.priority_id, i.access, i.reporter_id, (i.deleted_at IS NOT NULL) AS deleted,
              UNIX_TIMESTAMP(i.created_at) AS created, c.`key` AS category
         FROM issues i
         JOIN `status` s ON s.id = i.status_id
         JOIN status_categories c ON c.id = s.category_id
        WHERE i.id = :id FOR UPDATE"
    );
    $stmt->execute(['id' => $issueId]);
    $row = $stmt->fetch();
    if (!$row) {
      return null;
    }

    return [
      'id' => (int)$row['id'],
      'status_id' => (int)$row['status_id'],
      'type_id' => (int)$row['type_id'],
      'product_id' => $row['product_id'] === null ? null : (int)$row['product_id'],
      'priority_id' => (int)$row['priority_id'],
      'access' => $row['access'],
      'reporter_id' => $row['reporter_id'] === null ? null : (int)$row['reporter_id'],
      'deleted' => (bool)$row['deleted'],
      'created' => (int)$row['created'],
      'category' => (string)$row['category'],
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * @param int $issueId The issue
   *
   * @return array[] The timers of all periods, by metric and period
   */
  private function timerRows(int $issueId): array
  {
    $stmt = $this->db->prepare(
      'SELECT t.*, UNIX_TIMESTAMP(t.started_at) AS started, UNIX_TIMESTAMP(t.met_at) AS met, UNIX_TIMESTAMP(t.due_at) AS due, UNIX_TIMESTAMP(t.warn_at) AS warn
         FROM issue_sla t WHERE t.issue_id = :id ORDER BY t.metric, t.period'
    );
    $stmt->execute(['id' => $issueId]);

    return $stmt->fetchAll();
  }

  //---------------------------------------------------------------------------
  /**
   * @param int[] $timerIds The timers
   *
   * @return array<int, array[]> The stopped periods by timer id
   */
  private function stopsOf(array $timerIds): array
  {
    if ($timerIds === []) {
      return [];
    }
    $marks = implode(',', array_fill(0, count($timerIds), '?'));
    $stmt = $this->db->prepare(
      "SELECT issue_sla_id, UNIX_TIMESTAMP(started_at) AS started, UNIX_TIMESTAMP(ended_at) AS ended, reason
         FROM issue_sla_stops WHERE issue_sla_id IN ($marks) ORDER BY started_at, id"
    );
    $stmt->execute(array_map('intval', $timerIds));

    $stops = [];
    foreach ($stmt->fetchAll() as $row) {
      $stops[(int)$row['issue_sla_id']][] = ['start' => (int)$row['started'], 'end' => $row['ended'] === null ? null : (int)$row['ended'], 'reason' => $row['reason']];
    }

    return $stops;
  }

  //---------------------------------------------------------------------------
  /**
   * @param array   $row   The row of the timer
   * @param array[] $stops Its stopped periods
   *
   * @return array The facts the timer is made of
   */
  private function factsOf(array $row, array $stops): array
  {
    return [
      'policy_id' => (int)$row['policy_id'],
      'calendar_id' => $row['calendar_id'] === null ? null : (int)$row['calendar_id'],
      'minutes' => (int)$row['target_minutes'],
      'start' => (int)$row['started'],
      'met' => $row['met'] === null ? null : (int)$row['met'],
      'achieved' => $row['achieved_seconds'] === null ? null : (int)$row['achieved_seconds'],
      'overridden' => (bool)$row['overridden'],
      'stops' => $stops,
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * @param array $facts The facts of a timer
   *
   * @return SlaTimer
   */
  private function timer(array $facts): SlaTimer
  {
    return SlaTimer::fromArray($this->calculator($facts['calendar_id']), ['start' => $facts['start'], 'minutes' => $facts['minutes'], 'stops' => $facts['stops'], 'met_at' => $facts['met']]);
  }

  //---------------------------------------------------------------------------
  /**
   * Keeps a moment inside what a timer accepts: not before its start and not before the end of its last stopped
   * period (the clocks of PHP and the database may differ by a second).
   *
   * @param array $facts The facts of the timer
   * @param int   $at    The moment
   *
   * @return int
   */
  private function clamp(array $facts, int $at): int
  {
    $latest = $facts['start'];
    foreach ($facts['stops'] as $stop) {
      $latest = max($latest, $stop['start'], $stop['end'] ?? 0);
    }

    return max($at, $latest);
  }

  //---------------------------------------------------------------------------
  /**
   * @return int The time of the database
   */
  private function now(): int
  {
    return (int)$this->db->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
  }

  //---------------------------------------------------------------------------
  /**
   * Finds the first response of an issue: the first public comment of an agent who is not the reporter. A comment that
   * was deleted afterwards still counts (the response was given).
   *
   * @param array $issue The issue
   *
   * @return int|null The time of the comment
   */
  private function firstResponseAt(array $issue): ?int
  {
    $stmt = $this->db->prepare(
      "SELECT UNIX_TIMESTAMP(c.created_at) FROM comments c
        WHERE c.issue_id = :issue AND c.is_internal = 0 AND c.author_id IS NOT NULL AND (:reporter IS NULL OR c.author_id <> :reporter2)
          AND (
            EXISTS (SELECT 1 FROM user_role ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = c.author_id AND r.name = 'Administrator')
            OR EXISTS (SELECT 1 FROM user_role ur JOIN permission_role pr ON pr.role_id = ur.role_id JOIN permissions p ON p.id = pr.permission_id WHERE ur.user_id = c.author_id AND p.name = 'edit_issue')
            OR EXISTS (SELECT 1 FROM user_group ug JOIN permission_group pg ON pg.group_id = ug.group_id JOIN permissions p ON p.id = pg.permission_id WHERE ug.user_id = c.author_id AND p.name = 'edit_issue')
          )
        ORDER BY c.id LIMIT 1"
    );
    $stmt->execute(['issue' => $issue['id'], 'reporter' => $issue['reporter_id'], 'reporter2' => $issue['reporter_id']]);
    $time = $stmt->fetchColumn();

    return $time === false || $time === null ? null : (int)$time;
  }

  //---------------------------------------------------------------------------
  /**
   * Finds the policy of an issue: the first active policy with conditions that all match, in the sort order, else the
   * default policy (the one without conditions).
   *
   * @param array $issue The issue
   *
   * @return array|null The policy with its targets and pause statuses; null if none applies
   */
  private function selectPolicy(array $issue): ?array
  {
    $default = null;
    foreach ($this->activePolicies() as $policy) {
      $conditions = [$policy['type_id'], $policy['product_id'], $policy['priority_id'], $policy['group_id'], $policy['access']];
      if (count(array_filter($conditions, static fn($value): bool => $value !== null)) === 0) {
        $default ??= $policy;
        continue;
      }
      if ($this->matches($policy, $issue)) {
        return $policy;
      }
    }

    return $default;
  }

  //---------------------------------------------------------------------------
  /**
   * @param array $policy The policy
   * @param array $issue  The issue
   *
   * @return bool Whether every condition of the policy holds for the issue
   */
  private function matches(array $policy, array $issue): bool
  {
    foreach (['type_id', 'product_id', 'priority_id'] as $field) {
      if ($policy[$field] !== null && $policy[$field] !== $issue[$field]) {
        return false;
      }
    }
    if ($policy['access'] !== null && $policy['access'] !== $issue['access']) {
      return false;
    }
    if ($policy['group_id'] !== null) {
      if ($issue['reporter_id'] === null) {
        return false;
      }
      $stmt = $this->db->prepare('SELECT 1 FROM user_group WHERE user_id = :user AND group_id = :group');
      $stmt->execute(['user' => $issue['reporter_id'], 'group' => $policy['group_id']]);
      if ($stmt->fetchColumn() === false) {
        return false;
      }
    }

    return true;
  }

  //---------------------------------------------------------------------------
  /**
   * @return array[] The active policies by sort order, each with targets and pause_status_ids
   */
  private function activePolicies(): array
  {
    if ($this->policies !== null) {
      return $this->policies;
    }

    $rows = $this->db->query('SELECT * FROM sla_policies WHERE active = 1 ORDER BY sort_order, id')->fetchAll();
    $this->policies = array_map(fn(array $row): array => $this->policyFromRow($row), $rows);

    return $this->policies;
  }

  //---------------------------------------------------------------------------
  /**
   * @param int $id The policy
   *
   * @return array|null The policy (also an inactive one), null if it does not exist
   */
  private function policyById(int $id): ?array
  {
    // The policies that were read already, else only this one (most syncs need no more than the pause statuses of one policy)
    foreach ($this->policies ?? [] as $policy) {
      if ($policy['id'] === $id) {
        return $policy;
      }
    }
    if (isset($this->single[$id])) {
      return $this->single[$id];
    }
    $stmt = $this->db->prepare('SELECT * FROM sla_policies WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $this->single[$id] = $row ? $this->policyFromRow($row) : null;
  }

  //---------------------------------------------------------------------------
  /**
   * @param array $row The row of sla_policies
   *
   * @return array
   */
  private function policyFromRow(array $row): array
  {
    $id = (int)$row['id'];
    $targets = $this->db->prepare('SELECT priority_id, metric, minutes FROM sla_policy_targets WHERE policy_id = :id');
    $targets->execute(['id' => $id]);
    $pause = $this->db->prepare('SELECT status_id FROM sla_policy_pause_statuses WHERE policy_id = :id');
    $pause->execute(['id' => $id]);
    $nullable = static fn($value): ?int => $value === null ? null : (int)$value;

    return [
      'id' => $id,
      'name' => $row['name'],
      'calendar_id' => $nullable($row['calendar_id']),
      'warning_percent' => (int)$row['warning_percent'],
      'type_id' => $nullable($row['type_id']),
      'product_id' => $nullable($row['product_id']),
      'priority_id' => $nullable($row['priority_id']),
      'group_id' => $nullable($row['group_id']),
      'access' => $row['access'],
      'targets' => array_map(static fn(array $target): array => ['priority_id' => $nullable($target['priority_id']), 'metric' => $target['metric'], 'minutes' => (int)$target['minutes']], $targets->fetchAll()),
      'pause_status_ids' => array_map('intval', $pause->fetchAll(PDO::FETCH_COLUMN)),
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the target a policy has for a priority and a metric: the row of the priority, else the row for any priority.
   *
   * @param array  $policy     The policy
   * @param int    $priorityId The priority of the issue
   * @param string $metric     first_response or resolution
   *
   * @return int|null The target in working minutes, null if there is none
   */
  private function targetFor(array $policy, int $priorityId, string $metric): ?int
  {
    $any = null;
    foreach ($policy['targets'] as $target) {
      if ($target['metric'] !== $metric) {
        continue;
      }
      if ($target['priority_id'] === $priorityId) {
        return $target['minutes'];
      }
      if ($target['priority_id'] === null) {
        $any = $target['minutes'];
      }
    }

    return $any;
  }

  //---------------------------------------------------------------------------
  /**
   * @param int $policyId The policy
   *
   * @return int[] The statuses that stop its clock
   */
  private function pauseStatusIds(int $policyId): array
  {
    return $this->policyById($policyId)['pause_status_ids'] ?? [];
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the calculator for the business hours of a calendar.
   *
   * @param int|null $calendarId The calendar; null for around the clock
   *
   * @return SlaCalculator
   */
  private function calculator(?int $calendarId): SlaCalculator
  {
    $key = $calendarId ?? 'none';
    if (isset($this->calculators[$key])) {
      return $this->calculators[$key];
    }

    $calculator = null;
    if ($calendarId !== null) {
      try {
        $calculator = $this->loadCalendar($calendarId);
      } catch (Throwable $e) {
        error_log(sprintf('SLA: the calendar %d cannot be used, the clock runs around the clock: %s', $calendarId, $e->getMessage()));
      }
    }

    return $this->calculators[$key] = $calculator ?? new SlaCalculator(SlaCalculator::allDay('UTC'));
  }

  //---------------------------------------------------------------------------
  /**
   * @param int $calendarId The calendar
   *
   * @return SlaCalculator
   */
  private function loadCalendar(int $calendarId): SlaCalculator
  {
    $calendar = $this->db->prepare('SELECT timezone FROM sla_calendars WHERE id = :id');
    $calendar->execute(['id' => $calendarId]);
    $timezone = $calendar->fetchColumn();
    if ($timezone === false) {
      throw new \RuntimeException('The calendar does not exist');
    }

    $hours = $this->db->prepare('SELECT weekday, start_time, end_time FROM sla_calendar_hours WHERE calendar_id = :id ORDER BY weekday, start_time');
    $hours->execute(['id' => $calendarId]);
    $byDay = [];
    foreach ($hours->fetchAll() as $row) {
      $byDay[(int)$row['weekday']][] = [$row['start_time'], $row['end_time']];
    }

    $holidays = $this->db->prepare('SELECT date_from AS `from`, date_to AS `to`, recurring FROM sla_holidays WHERE calendar_id = :id');
    $holidays->execute(['id' => $calendarId]);

    return new SlaCalculator(['timezone' => $timezone, 'hours' => $byDay], array_map(static fn(array $row): array => ['from' => $row['from'], 'to' => $row['to'], 'recurring' => (bool)$row['recurring']], $holidays->fetchAll()));
  }

  //---------------------------------------------------------------------------
  /**
   * Shapes a timer for the API.
   *
   * @param array   $row   The row of the timer
   * @param array[] $stops Its stopped periods
   * @param int     $now   The time of the database
   *
   * @return array
   */
  private function present(array $row, array $stops, int $now): array
  {
    $facts = $this->factsOf($row, $stops);
    $timer = $this->timer($facts);
    $policy = $this->policyById($facts['policy_id']);
    $iso = static fn(?int $time): ?string => $time === null ? null : gmdate('Y-m-d\TH:i:s\Z', $time);
    $target = $facts['minutes'] * 60;

    // What the calendar says can fail (a calendar that never opens): the timer is shown without a state then, not as an error
    $state = null;
    $atRisk = false;
    $due = null;
    try {
      $state = $timer->state($now);
      $atRisk = $timer->isAtRisk($now, $policy['warning_percent'] ?? 80);
    } catch (Throwable $e) {
      error_log(sprintf('SLA: timer %d cannot be evaluated: %s', $row['id'], $e->getMessage()));
    }
    // A met timer keeps the result it had when it was met, whatever happens to the calendar afterwards
    try {
      $elapsed = $facts['met'] !== null ? ($facts['achieved'] ?? $timer->elapsedSeconds($now)) : $timer->elapsedSeconds($now);
    } catch (Throwable) {
      $elapsed = $facts['achieved'] ?? 0;
    }
    if ($facts['met'] === null) {
      try {
        $due = $timer->dueAt()?->getTimestamp();
      } catch (Throwable) {
        $due = null;
      }
    }

    return [
      'metric' => $row['metric'],
      'period' => (int)$row['period'],
      'state' => $state,
      'policy' => ['id' => $facts['policy_id'], 'name' => $policy['name'] ?? null],
      'target_minutes' => $facts['minutes'],
      'started_at' => $iso($facts['start']),
      'due_at' => $iso($due),
      'met_at' => $iso($facts['met']),
      'elapsed_seconds' => $elapsed,
      'remaining_seconds' => $target - $elapsed,
      'overrun_seconds' => max(0, $elapsed - $target),
      'met_in_time' => $facts['met'] === null ? null : $elapsed <= $target,
      'at_risk' => $atRisk,
      'warning_percent' => $policy['warning_percent'] ?? 80,
      // Whether the clock is stopped now (a waiting status, or a deleted issue); a stopped clock has no live countdown
      'clock_stopped' => $facts['met'] === null && $facts['stops'] !== [] && $facts['stops'][count($facts['stops']) - 1]['end'] === null,
      'overridden' => $facts['overridden'],
      'override_reason' => $row['override_reason'],
    ];
  }
}
