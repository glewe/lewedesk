<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

//---------------------------------------------------------------------------
/**
 * Tells people that an SLA timer is at risk or breached (see _docs/SLA.md, section 8 and Phase 6). It runs inside the
 * periodic cleanup (Housekeeping::run(), at most every five minutes, or api/housekeeping.php on a fixed schedule), so a
 * mail can be up to one interval late.
 *
 * The check reads the moments that SlaService stores with every timer (due_at and warn_at) and compares them with the
 * clock of the database: a timer is "breached" when its due time has passed and "at risk" when its warning moment has
 * passed. Each event is sent once per timer: the timer is claimed with an UPDATE that sets the flag
 * (at_risk_notified_at, breached_notified_at) only while it is still empty, and only the process that changed the row
 * sends, so parallel requests cannot send twice. A timer that is breached before anybody looked also counts as having
 * been at risk (nobody gets "at risk" after "breached"). A timer that is met, whose issue is deleted, or whose clock
 * stopped before the target (no due time) says nothing. A reopened issue has a new timer and so gets new messages.
 *
 * Who is told: the assignee and the watchers (not the reporter, customers never hear about SLA) for both events, and for
 * a breach also the escalation user and the members of the escalation group of the policy. NotificationService drops
 * everybody who may not see the issue or does not hold view_sla, and respects the notification settings of the users.
 * A breach also writes an internal entry to the history of the issue (visible to the staff only).
 *
 * Events that are older than STALE_AFTER seconds are marked as sent without telling anybody: after an outage, after the
 * feature was off, or after "Apply to open issues" gave old issues timers that were breached long ago, the staff does not
 * get hundreds of mails about the past.
 */
class SlaNotifier
{
  /** The most recent crossing that is still announced, in seconds (a day) */
  public const STALE_AFTER = 86400;

  /** The most timers handled per event and run, so one run stays short (the rest follows at the next run) */
  private const BATCH = 200;

  public function __construct(private readonly PDO $db, private readonly NotificationService $notifications)
  {
  }

  //---------------------------------------------------------------------------
  /**
   * Looks for timers that crossed a border and tells the people involved.
   *
   * @return array{breached: int, at_risk: int, skipped: int} The timers announced and the stale ones that were only marked
   */
  public function run(): array
  {
    $result = ['breached' => 0, 'at_risk' => 0, 'skipped' => 0];
    if (!SlaService::$timersEnabled) {
      return $result;
    }

    foreach (['breached', 'at_risk'] as $kind) {
      try {
        foreach ($this->candidates($kind) as $timer) {
          if (!$this->claim($timer['id'], $kind)) {
            continue;
          }
          if ($timer['age'] > self::STALE_AFTER) {
            $result['skipped']++;
            continue;
          }
          $this->announce($timer, $kind);
          $result[$kind]++;
        }
      } catch (Throwable $e) {
        error_log(sprintf('SLA notification check (%s) failed: %s', $kind, $e->getMessage()));
      }
    }

    return $result;
  }

  //---------------------------------------------------------------------------
  /**
   * Finds timers that are over the border and not announced yet.
   *
   * @param string $kind breached or at_risk
   *
   * @return array<int, array> id, issue_id, metric, policy_id, due (Unix time) and age (seconds since the border was crossed)
   */
  private function candidates(string $kind): array
  {
    $border = $kind === 'breached' ? 't.due_at' : 't.warn_at';
    // A running timer that has its warning moment only counts while it is not breached (the breach covers it)
    $extra = $kind === 'breached' ? 't.due_at < NOW() AND t.breached_notified_at IS NULL' : 't.due_at >= NOW() AND t.warn_at <= NOW() AND t.at_risk_notified_at IS NULL';
    $stmt = $this->db->query(
      "SELECT t.id, t.issue_id, t.metric, t.policy_id, UNIX_TIMESTAMP(t.due_at) AS due, UNIX_TIMESTAMP() - UNIX_TIMESTAMP($border) AS age
         FROM issue_sla t JOIN issues i ON i.id = t.issue_id
        WHERE t.met_at IS NULL AND i.deleted_at IS NULL AND $extra
        ORDER BY $border, t.id
        LIMIT " . self::BATCH
    );

    return array_map(static fn(array $row): array => [
      'id' => (int)$row['id'],
      'issue_id' => (int)$row['issue_id'],
      'metric' => $row['metric'],
      'policy_id' => (int)$row['policy_id'],
      'due' => (int)$row['due'],
      'age' => (int)$row['age'],
    ], $stmt->fetchAll());
  }

  //---------------------------------------------------------------------------
  /**
   * Takes a timer for this process: sets the flag if it is still empty and the border is still crossed.
   *
   * @param int    $timerId The timer
   * @param string $kind    breached or at_risk
   *
   * @return bool Whether this call set the flag (only then the caller may send)
   */
  private function claim(int $timerId, string $kind): bool
  {
    if ($kind === 'breached') {
      // A breach also settles the warning: nobody hears "at risk" afterwards
      $sql = 'UPDATE issue_sla SET breached_notified_at = NOW(), at_risk_notified_at = COALESCE(at_risk_notified_at, NOW())
               WHERE id = :id AND met_at IS NULL AND breached_notified_at IS NULL AND due_at < NOW()';
    } else {
      $sql = 'UPDATE issue_sla SET at_risk_notified_at = NOW()
               WHERE id = :id AND met_at IS NULL AND at_risk_notified_at IS NULL AND due_at >= NOW() AND warn_at <= NOW()';
    }
    $stmt = $this->db->prepare($sql);
    $stmt->execute(['id' => $timerId]);

    return $stmt->rowCount() === 1;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells the people about one timer and, for a breach, writes the history entry.
   *
   * @param array  $timer A row of candidates()
   * @param string $kind  breached or at_risk
   *
   * @return void
   */
  private function announce(array $timer, string $kind): void
  {
    $recipients = $this->recipients($timer['issue_id'], $timer['policy_id'], $kind === 'breached');
    $event = $kind === 'breached' ? NotificationService::SLA_BREACHED : NotificationService::SLA_AT_RISK;
    $this->notifications->notifySla($timer['issue_id'], $event, $timer['metric'], $timer['due'], $recipients);

    if ($kind === 'breached') {
      try {
        // The staff sees it in the history; the reader of the history shows the due time
        (new IssueHistory($this->db))->log($timer['issue_id'], null, 'field_changed', 'sla_breached_' . $timer['metric'], null, gmdate('Y-m-d\TH:i:s\Z', $timer['due']), true);
      } catch (Throwable $e) {
        error_log(sprintf('SLA history entry for issue %d failed: %s', $timer['issue_id'], $e->getMessage()));
      }
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the people to tell about a timer: the assignee, the watchers and, for a breach, the escalation user and group.
   *
   * @param int  $issueId   The issue
   * @param int  $policyId  The policy of the timer
   * @param bool $escalate  Whether the escalation people of the policy are included
   *
   * @return int[]
   */
  private function recipients(int $issueId, int $policyId, bool $escalate): array
  {
    $ids = [];
    $stmt = $this->db->prepare('SELECT assignee_id FROM issues WHERE id = :id AND assignee_id IS NOT NULL');
    $stmt->execute(['id' => $issueId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
      $ids[(int)$id] = true;
    }

    $stmt = $this->db->prepare('SELECT user_id FROM issue_watcher WHERE issue_id = :id');
    $stmt->execute(['id' => $issueId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
      $ids[(int)$id] = true;
    }

    if ($escalate) {
      $stmt = $this->db->prepare('SELECT escalation_user_id, escalation_group_id FROM sla_policies WHERE id = :id');
      $stmt->execute(['id' => $policyId]);
      $policy = $stmt->fetch();
      if ($policy && $policy['escalation_user_id'] !== null) {
        $ids[(int)$policy['escalation_user_id']] = true;
      }
      if ($policy && $policy['escalation_group_id'] !== null) {
        $members = $this->db->prepare('SELECT user_id FROM user_group WHERE group_id = :id');
        $members->execute(['id' => $policy['escalation_group_id']]);
        foreach ($members->fetchAll(PDO::FETCH_COLUMN) as $id) {
          $ids[(int)$id] = true;
        }
      }
    }

    return array_keys($ids);
  }
}
