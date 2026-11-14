<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

//---------------------------------------------------------------------------
/**
 * Data access for the SLA policies (tables sla_policies, sla_policy_targets and sla_policy_pause_statuses, see
 * _docs/SLA.md).
 *
 * A policy has conditions (type, product, priority, group, access; null is "any"), a calendar (null is 24/7), a warning
 * threshold, targets per priority and metric, and the statuses that stop the clock. The policy without any condition is
 * the default policy.
 *
 * The rules (valid input, who may do what) are checked by the controller; this class only reads and writes.
 */
class SlaPolicyModel
{
  /** The conditions of a policy: field => the table of the values (access has fixed values) */
  public const CONDITIONS = ['type_id' => 'issue_types', 'product_id' => 'products', 'priority_id' => 'priorities', 'group_id' => 'groups'];

  public const ACCESS = ['public', 'registered', 'reporter'];

  public const METRICS = ['first_response', 'resolution'];

  /** The longest name, in characters */
  public const MAX_NAME = 100;

  /** The longest target: two years in minutes */
  public const MAX_MINUTES = 1051200;

  /** The tables whose values a policy can use, with the policy columns that hold them (see usedBy()) */
  private const USAGE = [
    'issue_types' => ['sla_policies' => 'type_id'],
    'products' => ['sla_policies' => 'product_id'],
    'priorities' => ['sla_policies' => 'priority_id', 'sla_policy_targets' => 'priority_id'],
    'groups' => ['sla_policies' => 'group_id'],
    'status' => ['sla_policy_pause_statuses' => 'status_id'],
  ];

  public function __construct(private readonly PDO $db)
  {
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the policies in the order they are tried (active ones first, then the sort order).
   *
   * @return array<int, array>
   */
  public function all(): array
  {
    $rows = $this->db->query(
      'SELECT p.*, (SELECT COUNT(*) FROM issue_sla s WHERE s.policy_id = p.id) AS timers
         FROM sla_policies p
        ORDER BY p.active DESC, p.sort_order, p.name'
    )->fetchAll();

    $targets = $this->targetsOf(null);
    $pause = $this->pauseStatusesOf(null);

    return array_map(fn(array $row): array => $this->format($row, $targets[(int)$row['id']] ?? [], $pause[(int)$row['id']] ?? []), $rows);
  }

  //---------------------------------------------------------------------------
  /**
   * Reads one policy.
   *
   * @param int $id The policy
   *
   * @return array|null Null if there is no such policy
   */
  public function find(int $id): ?array
  {
    $stmt = $this->db->prepare('SELECT p.*, (SELECT COUNT(*) FROM issue_sla s WHERE s.policy_id = p.id) AS timers FROM sla_policies p WHERE p.id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row ? $this->format($row, $this->targetsOf($id)[$id] ?? [], $this->pauseStatusesOf($id)[$id] ?? []) : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Finds a policy by its name.
   *
   * @param string   $name     The name
   * @param int|null $exceptId A policy to leave out (the one that is being changed)
   *
   * @return array|null id and name
   */
  public function findByName(string $name, ?int $exceptId = null): ?array
  {
    $stmt = $this->db->prepare('SELECT id, name FROM sla_policies WHERE name = :name AND id <> :except');
    $stmt->execute(['name' => $name, 'except' => $exceptId ?? 0]);

    return $stmt->fetch() ?: null;
  }

  //---------------------------------------------------------------------------
  /**
   * Finds the default policy: the one without any condition.
   *
   * @param int|null $exceptId A policy to leave out (the one that is being changed)
   *
   * @return array|null id and name
   */
  public function findDefault(?int $exceptId = null): ?array
  {
    $stmt = $this->db->prepare(
      'SELECT id, name FROM sla_policies
        WHERE type_id IS NULL AND product_id IS NULL AND priority_id IS NULL AND group_id IS NULL AND access IS NULL AND id <> :except
        LIMIT 1'
    );
    $stmt->execute(['except' => $exceptId ?? 0]);

    return $stmt->fetch() ?: null;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether a record exists (the ids a policy refers to).
   *
   * @param string $table One of the tables of CONDITIONS, "status", "sla_calendars" or "users"
   * @param int    $id    The id
   *
   * @return bool
   */
  public function exists(string $table, int $id): bool
  {
    if (!in_array($table, [...array_values(self::CONDITIONS), 'status', 'sla_calendars', 'users'], true)) {
      return false;
    }
    $stmt = $this->db->prepare('SELECT 1 FROM `' . $table . '` WHERE id = :id');
    $stmt->execute(['id' => $id]);

    return $stmt->fetchColumn() !== false;
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the ids of the statuses that stop the clock by default: waiting for the customer, pending and on hold.
   *
   * @return int[]
   */
  public function defaultPauseStatusIds(): array
  {
    $ids = $this->db->query("SELECT id FROM `status` WHERE `key` IN ('waiting_for_customer', 'pending', 'on_hold') ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

    return array_map('intval', $ids);
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the next sort order for a new policy (after the last one, in steps of 10).
   *
   * @return int
   */
  public function nextSortOrder(): int
  {
    return (int)$this->db->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM sla_policies')->fetchColumn();
  }

  //---------------------------------------------------------------------------
  /**
   * Creates a policy with its targets and pause statuses.
   *
   * @param array $data The validated policy (see SlaController)
   *
   * @return int The id
   */
  public function create(array $data): int
  {
    $stmt = $this->db->prepare(
      'INSERT INTO sla_policies (name, description, active, sort_order, calendar_id, warning_percent, type_id, product_id, priority_id, group_id, access, escalation_user_id, escalation_group_id)
       VALUES (:name, :description, :active, :sort_order, :calendar_id, :warning_percent, :type_id, :product_id, :priority_id, :group_id, :access, :escalation_user_id, :escalation_group_id)'
    );
    $stmt->execute($this->bind($data));
    $id = (int)$this->db->lastInsertId();
    $this->replaceTargets($id, $data['targets']);
    $this->replacePauseStatuses($id, $data['pause_status_ids']);

    return $id;
  }

  //---------------------------------------------------------------------------
  /**
   * Changes a policy; targets and pause statuses are replaced as a whole.
   *
   * @param int   $id   The policy
   * @param array $data The validated policy (see SlaController)
   *
   * @return void
   */
  public function update(int $id, array $data): void
  {
    $stmt = $this->db->prepare(
      'UPDATE sla_policies SET name = :name, description = :description, active = :active, sort_order = :sort_order, calendar_id = :calendar_id,
              warning_percent = :warning_percent, type_id = :type_id, product_id = :product_id, priority_id = :priority_id, group_id = :group_id, access = :access,
              escalation_user_id = :escalation_user_id, escalation_group_id = :escalation_group_id
        WHERE id = :id'
    );
    $stmt->execute($this->bind($data) + ['id' => $id]);
    $this->replaceTargets($id, $data['targets']);
    $this->replacePauseStatuses($id, $data['pause_status_ids']);
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes a policy with its targets (the controller makes sure no timer uses it).
   *
   * @param int $id The policy
   *
   * @return void
   */
  public function delete(int $id): void
  {
    $this->db->prepare('DELETE FROM sla_policies WHERE id = :id')->execute(['id' => $id]);
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the names of the policies that use values of a lookup table, so that a value that is in use is not deleted.
   *
   * @param string $table The table of the lookup (issue_types, products, priorities, groups or status)
   * @param int[]  $ids   The ids that are about to be deleted
   *
   * @return string[] The names of the policies, empty if none uses them
   */
  public function usedBy(string $table, array $ids): array
  {
    if (!isset(self::USAGE[$table]) || $ids === []) {
      return [];
    }

    $marks = implode(',', array_fill(0, count($ids), '?'));
    $names = [];
    foreach (self::USAGE[$table] as $usingTable => $column) {
      $sql = $usingTable === 'sla_policies'
        ? "SELECT p.name FROM sla_policies p WHERE p.`$column` IN ($marks)"
        : "SELECT p.name FROM sla_policies p JOIN `$usingTable` x ON x.policy_id = p.id WHERE x.`$column` IN ($marks)";
      $stmt = $this->db->prepare($sql);
      $stmt->execute(array_values($ids));
      $names = [...$names, ...$stmt->fetchAll(PDO::FETCH_COLUMN)];
    }
    $names = array_values(array_unique($names));
    sort($names);

    return $names;
  }

  //---------------------------------------------------------------------------
  /**
   * @param array $data The validated policy
   *
   * @return array The parameters of the INSERT and UPDATE statements
   */
  private function bind(array $data): array
  {
    return [
      'name' => $data['name'],
      'description' => $data['description'],
      'active' => $data['active'] ? 1 : 0,
      'sort_order' => $data['sort_order'],
      'calendar_id' => $data['calendar_id'],
      'warning_percent' => $data['warning_percent'],
      'type_id' => $data['type_id'],
      'product_id' => $data['product_id'],
      'priority_id' => $data['priority_id'],
      'group_id' => $data['group_id'],
      'access' => $data['access'],
      'escalation_user_id' => $data['escalation_user_id'],
      'escalation_group_id' => $data['escalation_group_id'],
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * @param int     $policyId The policy
   * @param array[] $targets  priority_id (or null), metric and minutes
   *
   * @return void
   */
  private function replaceTargets(int $policyId, array $targets): void
  {
    $this->db->prepare('DELETE FROM sla_policy_targets WHERE policy_id = :id')->execute(['id' => $policyId]);

    $insert = $this->db->prepare('INSERT INTO sla_policy_targets (policy_id, priority_id, metric, minutes) VALUES (:policy, :priority, :metric, :minutes)');
    foreach ($targets as $target) {
      $insert->execute(['policy' => $policyId, 'priority' => $target['priority_id'], 'metric' => $target['metric'], 'minutes' => $target['minutes']]);
    }
  }

  //---------------------------------------------------------------------------
  /**
   * @param int   $policyId  The policy
   * @param int[] $statusIds The statuses that stop the clock
   *
   * @return void
   */
  private function replacePauseStatuses(int $policyId, array $statusIds): void
  {
    $this->db->prepare('DELETE FROM sla_policy_pause_statuses WHERE policy_id = :id')->execute(['id' => $policyId]);

    $insert = $this->db->prepare('INSERT INTO sla_policy_pause_statuses (policy_id, status_id) VALUES (:policy, :status)');
    foreach ($statusIds as $statusId) {
      $insert->execute(['policy' => $policyId, 'status' => $statusId]);
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Reads the targets of one or all policies, most specific first (a priority before "any priority").
   *
   * @param int|null $policyId The policy; null for all
   *
   * @return array<int, array[]> The targets by policy id
   */
  private function targetsOf(?int $policyId): array
  {
    $stmt = $this->db->prepare(
      'SELECT t.policy_id, t.priority_id, t.metric, t.minutes
         FROM sla_policy_targets t
         LEFT JOIN priorities pr ON pr.id = t.priority_id
        WHERE (:id IS NULL OR t.policy_id = :id2)
        ORDER BY t.policy_id, t.priority_id IS NULL, pr.sort_order, t.metric'
    );
    $stmt->execute(['id' => $policyId, 'id2' => $policyId]);

    $targets = [];
    foreach ($stmt->fetchAll() as $row) {
      $targets[(int)$row['policy_id']][] = [
        'priority_id' => $row['priority_id'] === null ? null : (int)$row['priority_id'],
        'metric' => $row['metric'],
        'minutes' => (int)$row['minutes'],
      ];
    }

    return $targets;
  }

  //---------------------------------------------------------------------------
  /**
   * Reads the statuses that stop the clock of one or all policies.
   *
   * @param int|null $policyId The policy; null for all
   *
   * @return array<int, int[]> The status ids by policy id
   */
  private function pauseStatusesOf(?int $policyId): array
  {
    $stmt = $this->db->prepare('SELECT policy_id, status_id FROM sla_policy_pause_statuses WHERE (:id IS NULL OR policy_id = :id2) ORDER BY policy_id, status_id');
    $stmt->execute(['id' => $policyId, 'id2' => $policyId]);

    $ids = [];
    foreach ($stmt->fetchAll() as $row) {
      $ids[(int)$row['policy_id']][] = (int)$row['status_id'];
    }

    return $ids;
  }

  //---------------------------------------------------------------------------
  /**
   * @param array   $row      The policy row
   * @param array[] $targets  The targets of the policy
   * @param int[]   $statuses The statuses that stop the clock
   *
   * @return array
   */
  private function format(array $row, array $targets, array $statuses): array
  {
    $conditions = ['type_id', 'product_id', 'priority_id', 'group_id'];
    $policy = [
      'id' => (int)$row['id'],
      'name' => $row['name'],
      'description' => $row['description'],
      'active' => (bool)$row['active'],
      'sort_order' => (int)$row['sort_order'],
      'calendar_id' => $row['calendar_id'] === null ? null : (int)$row['calendar_id'],
      'warning_percent' => (int)$row['warning_percent'],
    ];
    foreach ($conditions as $field) {
      $policy[$field] = $row[$field] === null ? null : (int)$row[$field];
    }
    $policy['access'] = $row['access'];
    // Who a breach is escalated to (not conditions: they do not make a policy specific)
    $policy['escalation_user_id'] = $row['escalation_user_id'] === null ? null : (int)$row['escalation_user_id'];
    $policy['escalation_group_id'] = $row['escalation_group_id'] === null ? null : (int)$row['escalation_group_id'];
    $policy['is_default'] = $row['access'] === null && array_reduce($conditions, fn(bool $none, string $field): bool => $none && $row[$field] === null, true);
    $policy['targets'] = $targets;
    $policy['pause_status_ids'] = $statuses;
    $policy['timers'] = (int)$row['timers'];
    $policy['created_at'] = $row['created_at'];
    $policy['updated_at'] = $row['updated_at'];

    return $policy;
  }
}
