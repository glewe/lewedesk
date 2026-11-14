<?php

declare(strict_types=1);

namespace App\Models;

require_once __DIR__ . '/../services/SlaService.php';

use App\Services\IssueActions;
use App\Services\IssueConflictException;
use App\Services\IssueHistory;
use App\Services\IssueSearch;
use App\Services\IssueVisibility;
use App\Services\SlaService;
use App\Utils\IssueConfig;
use PDO;

//---------------------------------------------------------------------------
/**
 * Model for issues. Every read goes through the central visibility check (App\Services\IssueVisibility):
 * what the viewer may not see is not returned, whatever the caller asks for.
 */
class IssueModel
{
  /**
   * The quick views and their conditions. The list filters by one of them and the counts sum all of them,
   * so the badge of a view and its list cannot disagree. A condition may use :view_user (the viewer).
   * The category keys are fixed system keys (see status_categories in db_init.sql).
   */
  public const VIEWS = [
    'all' => 'i.deleted_at IS NULL',
    'mine' => 'i.deleted_at IS NULL AND i.reporter_id = :view_user',
    'assigned' => 'i.deleted_at IS NULL AND i.assignee_id = :view_user',
    'unassigned' => 'i.deleted_at IS NULL AND i.assignee_id IS NULL',
    'open' => "i.deleted_at IS NULL AND c.`key` = 'open'",
    'waiting' => "i.deleted_at IS NULL AND c.`key` = 'waiting'",
    'in_progress' => "i.deleted_at IS NULL AND c.`key` = 'in_progress'",
    'done' => "i.deleted_at IS NULL AND c.`key` = 'done'",
    'deleted' => 'i.deleted_at IS NOT NULL',
    // The issues with an SLA timer that is breached (for the holders of view_sla only: see slaVisible())
    'sla_breached' => 'i.deleted_at IS NULL AND EXISTS (SELECT 1 FROM issue_sla bt WHERE bt.issue_id = i.id AND bt.met_at IS NULL AND bt.due_at < NOW())',
  ];

  /** Sort keys of the list (as sent by the DataTables column "data") and their ORDER BY expressions */
  public const SORT_COLUMNS = [
    'id' => 'i.id',
    'summary' => 'i.summary',
    'type' => 't.name',
    'status' => 's.name',
    'priority' => 'pr.sort_order',
    'reporter' => 'ru.username',
    'assignee' => 'au.username',
    'created_at' => 'i.created_at',
    'updated_at' => 'i.updated_at',
    // The earliest due time of an open SLA timer, issues without one last (see list(); for the holders of view_sla only)
    'sla' => '(SELECT MIN(st.due_at) FROM issue_sla st WHERE st.issue_id = i.id AND st.met_at IS NULL)',
  ];

  /** The tables of an issue row: the list counts and selects from the same joins */
  private const FROM_SQL = 'FROM issues i
               JOIN issue_types t ON t.id = i.type_id
               JOIN `status` s ON s.id = i.status_id
               JOIN priorities pr ON pr.id = i.priority_id
               JOIN status_categories c ON c.id = s.category_id
          LEFT JOIN users ru ON ru.id = i.reporter_id
          LEFT JOIN users au ON au.id = i.assignee_id';

  /** Lookup tables that an issue field can point to, with the extra condition for new values */
  private const LOOKUP_TABLES = ['issue_types' => true, 'status' => true, 'resolutions' => true, 'priorities' => true, 'products' => false];

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
   * Gets one issue with its type, priority, status, resolution, product, tags, reporter, assignee and watchers.
   * Returns null if the issue does not exist or the viewer may not see it.
   *
   * @param int $id The issue id
   *
   * @return array|null
   */
  public function find(int $id): ?array
  {
    [$scope, $params] = $this->visibility->scope('i');
    $stmt = $this->db->prepare($this->selectSql(true) . " WHERE i.id = :id AND $scope");
    $stmt->execute($params + ['id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
      return null;
    }

    $issue = $this->present($row, true);
    $issue['tags'] = $this->tags($id);
    $issue['watchers'] = $this->watchers($id);
    $issue['watcher_count'] = $this->watcherCount($id);
    $issue['is_watching'] = $this->isWatching($id);

    return $issue;
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the issues for a DataTables server-side request.
   *
   * @param array $query view (all, mine, assigned, unassigned, open, waiting, in_progress, done, deleted),
   *                     filters (status_id, type_id, priority_id, product_id, reporter_id, tag_id, participant_id: int; assignee_id: int|'none'),
   *                     search (term, optional; see IssueSearch), sort (key of SORT_COLUMNS), direction (asc|desc), start, length
   *
   * @return array{rows: array, total: int, filtered: int}
   */
  public function list(array $query): array
  {
    [$scope, $params] = $this->visibility->scope('i');
    [$viewSql, $viewParams] = $this->viewCondition($query['view']);
    $baseWhere = "$scope AND $viewSql";
    $baseParams = $params + $viewParams;

    [$filterSql, $filterParams] = $this->filterConditions($query['filters']);
    $search = isset($query['search']) ? (new IssueSearch($this->visibility, $this->config))->condition($query['search']) : null;
    if ($search !== null) {
      $filterSql = $filterSql === '' ? $search[0] : "$filterSql AND $search[0]";
      $filterParams += $search[1];
    }
    $where = $filterSql === '' ? $baseWhere : "$baseWhere AND $filterSql";

    $total = $this->count($baseWhere, $baseParams);
    $filtered = $filterSql === '' ? $total : $this->count($where, $baseParams + $filterParams);

    $direction = $query['direction'] === 'asc' ? ' ASC' : ' DESC';
    $sortColumn = self::SORT_COLUMNS[$query['sort']];
    // Issues without an open timer (or with a stopped one) come last in both directions
    $orderBy = ($query['sort'] === 'sla' ? "ISNULL($sortColumn) ASC, " : '') . $sortColumn . $direction . ', i.id DESC';
    $limit = (int)$query['length'];
    $offset = (int)$query['start'];

    $stmt = $this->db->prepare($this->selectSql(false) . " WHERE $where ORDER BY $orderBy LIMIT $limit OFFSET $offset");
    $stmt->execute($baseParams + $filterParams);

    $rows = array_map(fn(array $row): array => $this->present($row, false), $stmt->fetchAll());
    $tags = $this->tagsOf(array_column($rows, 'id'));
    // The SLA state of each row, in one query (only for the holders of view_sla, and not in the limited mode of the license)
    $sla = $this->slaVisible() ? (new SlaService($this->db))->listSummaries(array_column($rows, 'id')) : null;
    foreach ($rows as &$row) {
      $row['tags'] = $tags[$row['id']] ?? [];
      if ($sla !== null) {
        $row['sla'] = $sla[$row['id']] ?? null;
      }
    }
    unset($row);

    return [
      'rows' => $rows,
      'total' => $total,
      'filtered' => $filtered,
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * Counts the issues of every quick view for the viewer (they vary with the visibility).
   *
   * @return array<string, int> all, mine, assigned, unassigned, open, waiting, in_progress, done and,
   *                            for users who may see deleted issues, deleted
   */
  public function counts(): array
  {
    [$scope, $params] = $this->visibility->scope('i');

    $sums = [];
    foreach (self::VIEWS as $name => $condition) {
      if ($name === 'sla_breached' && !$this->slaVisible()) {
        continue;
      }
      $sums[] = "COALESCE(SUM($condition), 0) AS `$name`";
    }

    $stmt = $this->db->prepare(
      'SELECT ' . implode(', ', $sums) . "
         FROM issues i
         JOIN `status` s ON s.id = i.status_id
         JOIN status_categories c ON c.id = s.category_id
        WHERE $scope"
    );
    // An anonymous visitor has no issues of their own (0 is never a user id)
    $stmt->execute($params + ['view_user' => $this->visibility->viewerId() ?? 0]);
    $counts = array_map('intval', $stmt->fetch());

    // Only users who may see deleted issues learn how many there are (the scope hides them from the others anyway)
    if (!$this->visibility->canSeeDeleted()) {
      unset($counts['deleted']);
    }

    return $counts;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the viewer may see the SLA data of the issues (permission view_sla, and the license has SLA switched on).
   *
   * @return bool
   */
  public function slaVisible(): bool
  {
    return SlaService::$timersEnabled && $this->visibility->hasPermission('view_sla');
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the users that report or are assigned to the issues the viewer may see (for the list filters).
   * Nobody else is listed, so the list never discloses an account the viewer has no issue of. The email
   * address is never part of it.
   *
   * @return array{reporters: array, assignees: array} Users with id, username, display_name, avatar and deleted
   */
  public function people(): array
  {
    [$scope, $params] = $this->visibility->scope('i');

    $people = [];
    foreach (['reporters' => 'reporter_id', 'assignees' => 'assignee_id'] as $key => $column) {
      $stmt = $this->db->prepare(
        "SELECT DISTINCT u.id, u.username, u.first_name, u.last_name, u.avatar
           FROM issues i JOIN users u ON u.id = i.$column
          WHERE $scope
          ORDER BY u.username"
      );
      $stmt->execute($params);
      $people[$key] = array_map(
        fn(array $u): array => $this->userFields((int)$u['id'], $u['username'], $u['first_name'], $u['last_name'], $u['avatar']),
        $stmt->fetchAll()
      );
    }

    return $people;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells which of the given issues the viewer may see, with their summaries (for auto-linked issue keys
   * in texts). Issues that do not exist or are hidden from the viewer are left out, both look the same.
   *
   * @param int[] $ids The issue ids (unique)
   *
   * @return array<int, array{id: int, summary: string}> The visible issues in ascending order of the id
   */
  public function accessible(array $ids): array
  {
    if (empty($ids)) {
      return [];
    }

    [$scope, $params] = $this->visibility->scope('i');
    $placeholders = [];
    foreach (array_values($ids) as $index => $id) {
      $placeholders[] = ":ref$index";
      $params["ref$index"] = (int)$id;
    }

    $stmt = $this->db->prepare('SELECT i.id, i.summary FROM issues i WHERE ' . $scope . ' AND i.id IN (' . implode(', ', $placeholders) . ') ORDER BY i.id');
    $stmt->execute($params);

    return array_map(fn(array $row): array => ['id' => (int)$row['id'], 'summary' => (string)$row['summary']], $stmt->fetchAll());
  }

  //---------------------------------------------------------------------------
  /**
   * Creates an issue with the initial status "Open".
   *
   * @param array $data type_id, summary, description, access, product_id (nullable), version (nullable), tag_ids (optional),
   *                    priority_id (optional; the priority Normal if missing)
   * @param int   $reporterId The id of the reporting user
   *
   * @return int The new issue id
   */
  public function create(array $data, int $reporterId): int
  {
    $this->db->beginTransaction();
    try {
      $stmt = $this->db->prepare(
        "INSERT INTO issues (type_id, status_id, priority_id, reporter_id, product_id, version, access, summary, description)
         VALUES (:type_id, (SELECT id FROM `status` WHERE `key` = 'open'),
                 COALESCE(:priority_id, (SELECT id FROM priorities WHERE `key` = 'normal')),
                 :reporter_id, :product_id, :version, :access, :summary, :description)"
      );
      $stmt->execute([
        'type_id' => $data['type_id'],
        'priority_id' => $data['priority_id'] ?? null,
        'reporter_id' => $reporterId,
        'product_id' => $data['product_id'],
        'version' => $data['version'],
        'access' => $data['access'],
        'summary' => $data['summary'],
        'description' => $data['description'],
      ]);
      $id = (int)$this->db->lastInsertId();
      (new IssueHistory($this->db))->log($id, $reporterId, 'issue_created');
      $this->addWatcher($id, $reporterId);

      $tagIds = $data['tag_ids'] ?? [];
      if (!empty($tagIds)) {
        $values = implode(', ', array_fill(0, count($tagIds), '(?, ?)'));
        $params = [];
        foreach ($tagIds as $tagId) {
          array_push($params, $id, $tagId);
        }
        $this->db->prepare("INSERT INTO issue_tag (issue_id, tag_id) VALUES $values")->execute($params);
      }

      // The SLA timers of the issue (see _docs/SLA.md); a failure there never fails the creation
      (new SlaService($this->db))->startTimers($id);

      $this->db->commit();
    } catch (\Throwable $e) {
      $this->db->rollBack();
      throw $e;
    }

    return $id;
  }

  //---------------------------------------------------------------------------
  /**
   * Replaces the tags of an issue. The change counts as an update of the issue ("Updated" moves) when the
   * set of tags is different. The caller checked that the viewer may change the issue.
   *
   * @param int   $id     The issue id
   * @param int[] $tagIds The new tag ids (unique, all existing)
   *
   * @return bool Whether the tags changed
   */
  public function setTags(int $id, array $tagIds): bool
  {
    return $this->transaction(fn(): bool => $this->replaceTags($id, $tagIds));
  }

  //---------------------------------------------------------------------------
  /**
   * Replaces the tags of an issue inside the transaction of the caller: touches the issue and writes the
   * history entry when the set of tags is different.
   *
   * @param int   $id     The issue id
   * @param int[] $tagIds The new tag ids (unique, all existing)
   *
   * @return bool Whether the tags changed
   */
  private function replaceTags(int $id, array $tagIds): bool
  {
    $stmt = $this->db->prepare('SELECT tag_id FROM issue_tag WHERE issue_id = :id');
    $stmt->execute(['id' => $id]);
    $current = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $remove = array_values(array_diff($current, $tagIds));
    $add = array_values(array_diff($tagIds, $current));
    if (empty($remove) && empty($add)) {
      return false;
    }

    $before = $this->tagNames($id);
    if (!empty($remove)) {
      $placeholders = implode(', ', array_fill(0, count($remove), '?'));
      $this->db->prepare("DELETE FROM issue_tag WHERE issue_id = ? AND tag_id IN ($placeholders)")->execute([$id, ...$remove]);
    }
    foreach ($add as $tagId) {
      $this->db->prepare('INSERT INTO issue_tag (issue_id, tag_id) VALUES (?, ?)')->execute([$id, $tagId]);
    }
    $this->db->prepare('UPDATE issues SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$id]);
    (new IssueHistory($this->db))->log($id, $this->visibility->viewerId(), 'field_changed', 'tags', $before, $this->tagNames($id));

    return true;
  }

  //---------------------------------------------------------------------------
  /**
   * Runs a change in a transaction: committed when the callback returns, rolled back when it throws.
   *
   * @param callable $work The change
   *
   * @return mixed What the callback returned
   */
  public function transaction(callable $work): mixed
  {
    $this->db->beginTransaction();
    try {
      $result = $work();
      $this->db->commit();
    } catch (\Throwable $e) {
      $this->db->rollBack();
      throw $e;
    }

    return $result;
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the SLA timers of an issue as the API shows them (the controller checks who may see them).
   *
   * @param int $id The issue id
   *
   * @return array[] Empty if the issue has no timers
   */
  public function slaTimers(int $id): array
  {
    return (new SlaService($this->db))->view($id);
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the names of the tags of an issue as a comma separated text (for the history).
   *
   * @param int $id The issue id
   *
   * @return string|null The names, or null if the issue has no tags
   */
  private function tagNames(int $id): ?string
  {
    $stmt = $this->db->prepare('SELECT g.name FROM issue_tag it JOIN tags g ON g.id = it.tag_id WHERE it.issue_id = :id ORDER BY g.name');
    $stmt->execute(['id' => $id]);
    $names = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return empty($names) ? null : implode(', ', $names);
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the tags of an issue (for the response of the tag assignment).
   *
   * @param int $id The issue id
   *
   * @return array
   */
  public function tagsOfIssue(int $id): array
  {
    return $this->tags($id);
  }

  //---------------------------------------------------------------------------
  /**
   * Locks the row of an issue (inside a transaction) and checks that it still is the issue the rules were asked
   * about: status, assignee, access and deletion must not have changed meanwhile.
   *
   * @param array $facts The facts of the issue as checked (IssueActions::facts())
   *
   * @return array The locked row: type_id, status_id, resolution_id, priority_id, product_id, assignee_id, access, version, summary, description
   *
   * @throws IssueConflictException If somebody else changed the issue in between
   */
  public function lockIssue(array $facts): array
  {
    $stmt = $this->db->prepare(
      'SELECT type_id, status_id, resolution_id, priority_id, product_id, assignee_id, access, version, summary, description, deleted_at
         FROM issues WHERE id = :id FOR UPDATE'
    );
    $stmt->execute(['id' => $facts['id']]);
    $row = $stmt->fetch();

    $assignee = $row === false || $row['assignee_id'] === null ? null : (int)$row['assignee_id'];
    $expected = $facts['assignee_id'] === null ? null : (int)$facts['assignee_id'];
    if ($row === false || (int)$row['status_id'] !== $facts['status_id'] || $assignee !== $expected || $row['access'] !== $facts['access'] || $row['deleted_at'] !== $facts['deleted_at']) {
      throw new IssueConflictException('The issue was changed by somebody else. Please reload the page.');
    }

    return $row;
  }

  //---------------------------------------------------------------------------
  /**
   * Finds a status by its key (the protected statuses of the workflow).
   *
   * @param string $key The status key
   *
   * @return array|null id, key and name
   */
  public function statusByKey(string $key): ?array
  {
    $stmt = $this->db->prepare('SELECT id, `key`, name FROM `status` WHERE `key` = :key');
    $stmt->execute(['key' => $key]);
    $row = $stmt->fetch();

    return $row ? ['id' => (int)$row['id'], 'key' => $row['key'], 'name' => $row['name']] : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets what the rules need to know about a status.
   *
   * @param int $id The status id
   *
   * @return array|null id, key, name and category_key
   */
  public function statusInfo(int $id): ?array
  {
    $stmt = $this->db->prepare('SELECT s.id, s.`key`, s.name, c.`key` AS category_key FROM `status` s JOIN status_categories c ON c.id = s.category_id WHERE s.id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row ? ['id' => (int)$row['id'], 'key' => $row['key'], 'name' => $row['name'], 'category_key' => (string)$row['category_key']] : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the value of a lookup record for the history: the key of a protected record (the page translates
   * it), else the name.
   *
   * @param string   $table issue_types, status, resolutions, priorities or products
   * @param int|null $id    The record id
   *
   * @return string|null Null for no record
   */
  public function recordValue(string $table, ?int $id): ?string
  {
    if ($id === null) {
      return null;
    }
    if (!array_key_exists($table, self::LOOKUP_TABLES)) {
      throw new \InvalidArgumentException('Unknown lookup table');
    }

    // Products have no key
    $select = $table === 'products' ? 'name' : 'COALESCE(`key`, name)';
    $stmt = $this->db->prepare("SELECT $select FROM `$table` WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $value = $stmt->fetchColumn();

    return $value === false ? null : (string)$value;
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the display name of a user for the history.
   *
   * @param int|null $userId The user id
   *
   * @return string|null Null for no user
   */
  public function userLabel(?int $userId): ?string
  {
    if ($userId === null) {
      return null;
    }

    $stmt = $this->db->prepare('SELECT username, first_name, last_name FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();

    return $row ? self::userFields($userId, (string)$row['username'], $row['first_name'], $row['last_name'], null)['display_name'] : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the users who can be assigned to issues: the users with the role Agent.
   *
   * @return array User fields (no email address)
   */
  public function agents(): array
  {
    $stmt = $this->db->query(
      "SELECT DISTINCT u.id, u.username, u.first_name, u.last_name, u.avatar
         FROM users u JOIN user_role ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
        WHERE r.name = 'Agent' ORDER BY u.username"
    );

    return array_map(fn(array $u): array => self::userFields((int)$u['id'], $u['username'], $u['first_name'], $u['last_name'], $u['avatar']), $stmt->fetchAll());
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether a user has the role Agent (only those can be assigned).
   *
   * @param int $userId The user id
   *
   * @return bool
   */
  public function isAgent(int $userId): bool
  {
    $stmt = $this->db->prepare("SELECT COUNT(*) FROM user_role ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :id AND r.name = 'Agent'");
    $stmt->execute(['id' => $userId]);

    return (int)$stmt->fetchColumn() > 0;
  }

  //---------------------------------------------------------------------------
  /**
   * Makes a user a watcher of an issue (nothing happens if they already are). A watcher change is not an
   * update of the issue.
   *
   * @param int $issueId The issue id
   * @param int $userId  The user id
   *
   * @return void
   */
  public function addWatcher(int $issueId, int $userId): void
  {
    $this->db->prepare('INSERT IGNORE INTO issue_watcher (issue_id, user_id) VALUES (:issue, :user)')->execute(['issue' => $issueId, 'user' => $userId]);
  }

  //---------------------------------------------------------------------------
  /**
   * Stops a user from watching an issue.
   *
   * @param int $issueId The issue id
   * @param int $userId  The user id
   *
   * @return void
   */
  public function removeWatcher(int $issueId, int $userId): void
  {
    $this->db->prepare('DELETE FROM issue_watcher WHERE issue_id = :issue AND user_id = :user')->execute(['issue' => $issueId, 'user' => $userId]);
  }

  //---------------------------------------------------------------------------
  /**
   * Makes the given users the watchers of an issue: the others stop watching. The caller validated the ids.
   *
   * @param int   $issueId The issue id
   * @param int[] $userIds The user ids
   *
   * @return void
   */
  private function replaceWatchers(int $issueId, array $userIds): void
  {
    if ($userIds === []) {
      $this->db->prepare('DELETE FROM issue_watcher WHERE issue_id = :id')->execute(['id' => $issueId]);
      return;
    }

    $marks = implode(',', array_fill(0, count($userIds), '?'));
    $this->db->prepare("DELETE FROM issue_watcher WHERE issue_id = ? AND user_id NOT IN ($marks)")->execute([$issueId, ...$userIds]);
    foreach ($userIds as $userId) {
      $this->addWatcher($issueId, $userId);
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the users who could watch an issue: those who can see it (view_issue, and for a Reporter issue the
   * reporter and the users who see all issues).
   *
   * @param string   $access     The access of the issue
   * @param int|null $reporterId The reporter id
   *
   * @return array
   */
  public function watcherCandidates(string $access, ?int $reporterId): array
  {
    $sql = 'SELECT u.id, u.username, u.first_name, u.last_name, u.avatar FROM users u WHERE ' . $this->holds('u.id', 'view_issue');
    $params = [];
    if ($access === 'reporter') {
      $sql .= ' AND (u.id <=> :reporter OR ' . $this->holds('u.id', 'view_all_issues') . ')';
      $params['reporter'] = $reporterId;
    }
    $stmt = $this->db->prepare($sql . ' ORDER BY u.username');
    $stmt->execute($params);

    return array_map(fn(array $u): array => self::userFields((int)$u['id'], $u['username'], $u['first_name'], $u['last_name'], $u['avatar']), $stmt->fetchAll());
  }

  //---------------------------------------------------------------------------
  /**
   * Removes the watchers who cannot see an issue (after its access was lowered): users without view_issue,
   * and, for a Reporter issue, everybody but the reporter and the users who see all issues.
   *
   * @param int $issueId The issue id
   *
   * @return void
   */
  public function removeWatchersWithoutAccess(int $issueId): void
  {
    $this->db->prepare(
      'DELETE w FROM issue_watcher w JOIN issues i ON i.id = w.issue_id
        WHERE w.issue_id = :id
          AND (NOT ' . $this->holds('w.user_id', 'view_issue') . "
               OR (i.access = 'reporter' AND NOT (w.user_id <=> i.reporter_id) AND NOT " . $this->holds('w.user_id', 'view_all_issues') . '))'
    )->execute(['id' => $issueId]);
  }

  //---------------------------------------------------------------------------
  /**
   * Builds the SQL condition "the user holds the permission" (through a role or a group; Administrators hold
   * all permissions).
   *
   * @param string $userColumn The column that holds the user id
   * @param string $permission The permission name (a constant of the code, never user input)
   *
   * @return string
   */
  private function holds(string $userColumn, string $permission): string
  {
    $name = $this->db->quote($permission);

    return "(EXISTS (SELECT 1 FROM user_role ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = $userColumn AND r.name = 'Administrator')
          OR EXISTS (SELECT 1 FROM permissions p JOIN permission_role pr ON pr.permission_id = p.id JOIN user_role ur ON ur.role_id = pr.role_id
                      WHERE ur.user_id = $userColumn AND p.name = $name)
          OR EXISTS (SELECT 1 FROM permissions p JOIN permission_group pg ON pg.permission_id = p.id JOIN user_group ug ON ug.group_id = pg.group_id
                      WHERE ug.user_id = $userColumn AND p.name = $name))";
  }

  //---------------------------------------------------------------------------
  /**
   * Changes fields of an issue (the Edit page). Only the given fields are looked at; what is not different
   * is left alone. Every changed field gets its history entry, "Updated" moves when anything changed, a new
   * assignee becomes a watcher, and the watchers who lose access are removed. The caller validated the values
   * and the resolution rules.
   *
   * @param array $facts   The facts of the issue as checked (IssueActions::facts())
   * @param array $changes Any of type_id, priority_id, summary, description, access, product_id, version, tag_ids, status_id, resolution_id, assignee_id
   * @param int   $actorId The id of the user who edits
   *
   * @return bool Whether anything changed
   *
   * @throws IssueConflictException If somebody else changed the issue in between
   */
  public function edit(array $facts, array $changes, int $actorId): bool
  {
    $id = $facts['id'];
    $history = new IssueHistory($this->db);

    return $this->transaction(function () use ($facts, $changes, $actorId, $id, $history): bool {
      $row = $this->lockIssue($facts);
      $sets = [];
      $params = ['id' => $id];
      $log = [];

      // Lookup fields: type, priority, status, resolution, product
      foreach (['type_id' => ['type', 'issue_types'], 'priority_id' => ['priority', 'priorities'], 'status_id' => ['status', 'status'], 'resolution_id' => ['resolution', 'resolutions'], 'product_id' => ['product', 'products']] as $column => [$field, $table]) {
        if (!array_key_exists($column, $changes)) {
          continue;
        }
        $old = $row[$column] === null ? null : (int)$row[$column];
        if ($old === $changes[$column]) {
          continue;
        }
        $sets[] = "$column = :$column";
        $params[$column] = $changes[$column];
        $log[] = [$field, $this->recordValue($table, $old), $this->recordValue($table, $changes[$column])];
      }

      // Assignee
      if (array_key_exists('assignee_id', $changes) && ($row['assignee_id'] === null ? null : (int)$row['assignee_id']) !== $changes['assignee_id']) {
        $sets[] = 'assignee_id = :assignee_id';
        $params['assignee_id'] = $changes['assignee_id'];
        $log[] = ['assignee', $this->userLabel($row['assignee_id'] === null ? null : (int)$row['assignee_id']), $this->userLabel($changes['assignee_id'])];
      }

      // Text fields. The description can be long, so the history only says that it changed. The comparison is
      // done here because the database collation ignores case and accents.
      foreach (['summary' => true, 'version' => true, 'access' => true, 'description' => false] as $field => $keepValues) {
        if (!array_key_exists($field, $changes) || $row[$field] === $changes[$field]) {
          continue;
        }
        $sets[] = "$field = :$field";
        $params[$field] = $changes[$field];
        $log[] = [$field, $keepValues ? $row[$field] : null, $keepValues ? $changes[$field] : null];
      }

      if (!empty($sets)) {
        $this->db->prepare('UPDATE issues SET ' . implode(', ', $sets) . ', updated_at = CURRENT_TIMESTAMP WHERE id = :id')->execute($params);
        foreach ($log as [$field, $old, $new]) {
          $history->log($id, $actorId, 'field_changed', $field, $old, $new);
        }
      }

      $tagsChanged = array_key_exists('tag_ids', $changes) && $this->replaceTags($id, $changes['tag_ids']);

      // The watchers chosen on the Edit page replace the current ones (not an update of the issue)
      $watchersSet = array_key_exists('watcher_ids', $changes);
      if ($watchersSet) {
        $this->replaceWatchers($id, $changes['watcher_ids']);
      }
      if (isset($params['assignee_id'])) {
        $this->addWatcher($id, $params['assignee_id']);
      }
      if ($watchersSet || (isset($params['access']) && array_search($params['access'], IssueActions::ACCESS_ORDER, true) > array_search($row['access'], IssueActions::ACCESS_ORDER, true))) {
        $this->removeWatchersWithoutAccess($id);
      }

      // The SLA timers follow the issue: a new status, a deleted comment, and a new policy when type, priority, product or access changed
      (new SlaService($this->db))->sync($id, array_intersect(['type_id', 'priority_id', 'product_id', 'access'], array_keys($params)) !== []);

      return !empty($sets) || $tagsChanged;
    });
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether a record of a lookup table can be chosen for an issue.
   *
   * @param string $table      issue_types, status, resolutions, priorities or products
   * @param int    $id         The record id
   * @param bool   $activeOnly Whether the record must be active (ignored for tables without that column)
   *
   * @return bool
   */
  public function lookupExists(string $table, int $id, bool $activeOnly = true): bool
  {
    if (!array_key_exists($table, self::LOOKUP_TABLES)) {
      throw new \InvalidArgumentException('Unknown lookup table');
    }

    $active = $activeOnly && self::LOOKUP_TABLES[$table] ? ' AND active = 1' : '';
    $stmt = $this->db->prepare("SELECT COUNT(*) FROM `$table` WHERE id = :id$active");
    $stmt->execute(['id' => $id]);

    return (int)$stmt->fetchColumn() > 0;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether all given ids are tags.
   *
   * @param int[] $ids The tag ids (unique)
   *
   * @return bool
   */
  public function tagsExist(array $ids): bool
  {
    if (empty($ids)) {
      return true;
    }

    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $stmt = $this->db->prepare("SELECT COUNT(*) FROM tags WHERE id IN ($placeholders)");
    $stmt->execute(array_values($ids));

    return (int)$stmt->fetchColumn() === count($ids);
  }

  //---------------------------------------------------------------------------
  /**
   * Counts all issues, soft deleted ones included, without any visibility check. Only for system
   * decisions (e.g. locking the issue key prefix), never for output to a user.
   *
   * @return int
   */
  public function countAll(): int
  {
    return (int)$this->db->query('SELECT COUNT(*) FROM issues')->fetchColumn();
  }

  //---------------------------------------------------------------------------
  /**
   * Counts all issues that are not deleted, without any visibility check. Only for system decisions (the license
   * limit), never for output to a user.
   *
   * @return int
   */
  public function countActive(): int
  {
    return (int)$this->db->query('SELECT COUNT(*) FROM issues WHERE deleted_at IS NULL')->fetchColumn();
  }

  //---------------------------------------------------------------------------
  /**
   * Builds the SELECT of an issue row (without WHERE and ORDER BY).
   *
   * @param bool $detail Whether to select the description and the details needed on the issue page
   *
   * @return string
   */
  private function selectSql(bool $detail): string
  {
    $extra = $detail
      ? ', i.description, i.version, i.resolution_id, i.product_id, i.deleted_at, i.requester_email,
         r.`key` AS resolution_key, r.name AS resolution_name, r.icon AS resolution_icon,
         r.color AS resolution_color, r.background_color AS resolution_bg,
         p.name AS product_name, p.icon AS product_icon, p.color AS product_color, p.background_color AS product_bg,
         c.name AS category_name'
      : '';
    $joins = $detail
      ? ' LEFT JOIN resolutions r ON r.id = i.resolution_id LEFT JOIN products p ON p.id = i.product_id'
      : '';

    return "SELECT i.id, i.summary, i.access, i.source, i.created_at, i.updated_at,
                   i.type_id, t.`key` AS type_key, t.name AS type_name, t.icon AS type_icon,
                   t.color AS type_color, t.background_color AS type_bg,
                   i.status_id, s.`key` AS status_key, s.name AS status_name, s.icon AS status_icon,
                   s.color AS status_color, s.background_color AS status_bg,
                   s.category_id, c.`key` AS category_key,
                   i.priority_id, pr.`key` AS priority_key, pr.name AS priority_name, pr.icon AS priority_icon,
                   pr.color AS priority_color, pr.background_color AS priority_bg,
                   i.reporter_id, ru.username AS reporter_username, ru.first_name AS reporter_first_name,
                   ru.last_name AS reporter_last_name, ru.avatar AS reporter_avatar, ru.email AS reporter_email,
                   i.assignee_id, au.username AS assignee_username, au.first_name AS assignee_first_name,
                   au.last_name AS assignee_last_name, au.avatar AS assignee_avatar, au.email AS assignee_email
                   $extra " . self::FROM_SQL . " $joins";
  }

  //---------------------------------------------------------------------------
  /**
   * Counts the rows of a query.
   *
   * @param string $where  The WHERE condition
   * @param array  $params The parameters of the condition
   *
   * @return int
   */
  private function count(string $where, array $params): int
  {
    $stmt = $this->db->prepare("SELECT COUNT(*) " . self::FROM_SQL . " WHERE $where");
    $stmt->execute($params);

    return (int)$stmt->fetchColumn();
  }

  //---------------------------------------------------------------------------
  /**
   * Builds the condition of a quick view (see VIEWS). Deleted issues only show up in the view "deleted";
   * the visibility scope decides who may open it.
   *
   * @param string $view The quick view
   *
   * @return array{0: string, 1: array} The condition and its parameters
   */
  private function viewCondition(string $view): array
  {
    $condition = self::VIEWS[$view];

    return [$condition, str_contains($condition, ':view_user') ? ['view_user' => $this->visibility->viewerId() ?? 0] : []];
  }

  //---------------------------------------------------------------------------
  /**
   * Builds the conditions of the list filters.
   *
   * @param array $filters The validated filters
   *
   * @return array{0: string, 1: array} The conditions joined with AND (empty string for none) and their parameters
   */
  private function filterConditions(array $filters): array
  {
    $conditions = [];
    $params = [];

    // Each of these filters is a list of ids: an issue matches if it has any of them
    foreach (['status_id' => 'i.status_id', 'type_id' => 'i.type_id', 'priority_id' => 'i.priority_id', 'product_id' => 'i.product_id', 'reporter_id' => 'i.reporter_id', 'status_category_id' => 's.category_id'] as $key => $column) {
      if (!empty($filters[$key])) {
        $conditions[] = $this->inList($column, "f_$key", $filters[$key], $params);
      }
    }

    // Where the issue came from: web, email, or both
    if (!empty($filters['source'])) {
      $conditions[] = $this->inList('i.source', 'f_source', $filters['source'], $params);
    }

    // Assignees: user ids and/or 'none' (unassigned)
    if (!empty($filters['assignee_id'])) {
      $ids = array_values(array_filter($filters['assignee_id'], 'is_int'));
      $parts = [];
      if (in_array('none', $filters['assignee_id'], true)) {
        $parts[] = 'i.assignee_id IS NULL';
      }
      if ($ids !== []) {
        $parts[] = $this->inList('i.assignee_id', 'f_assignee_id', $ids, $params);
      }
      $conditions[] = '(' . implode(' OR ', $parts) . ')';
    }

    // Participant: reporter, assignee, author of a comment or watcher (one placeholder per use, as PDO requires)
    if (isset($filters['participant_id'])) {
      $conditions[] = '(i.reporter_id = :f_participant_reporter OR i.assignee_id = :f_participant_assignee'
        . ' OR EXISTS (SELECT 1 FROM comments pc WHERE pc.issue_id = i.id AND pc.author_id = :f_participant_comment AND pc.deleted_at IS NULL)'
        . ' OR EXISTS (SELECT 1 FROM issue_watcher pw WHERE pw.issue_id = i.id AND pw.user_id = :f_participant_watcher))';
      foreach (['reporter', 'assignee', 'comment', 'watcher'] as $role) {
        $params["f_participant_$role"] = $filters['participant_id'];
      }
    }

    // SLA: an open timer of the issue is in one of the states (the caller checks that the viewer may use the filter)
    if (!empty($filters['sla_state'])) {
      $states = array_map(static fn(string $state): string => SlaService::stateCondition($state, 'ft'), $filters['sla_state']);
      $conditions[] = 'i.deleted_at IS NULL AND EXISTS (SELECT 1 FROM issue_sla ft WHERE ft.issue_id = i.id AND ft.met_at IS NULL AND (' . implode(' OR ', $states) . '))';
      if (in_array('due_today', $filters['sla_state'], true)) {
        $params['sla_day_end'] = (int)($filters['sla_day_end'] ?? 0);
      }
    }

    if (!empty($filters['tag_id'])) {
      $conditions[] = 'EXISTS (SELECT 1 FROM issue_tag it WHERE it.issue_id = i.id AND ' . $this->inList('it.tag_id', 'f_tag_id', $filters['tag_id'], $params) . ')';
    }

    return [implode(' AND ', $conditions), $params];
  }

  //---------------------------------------------------------------------------
  /**
   * Builds "column IN (:name_0, :name_1, ...)" and adds the parameters.
   *
   * @param string $column The column
   * @param string $name   The prefix of the placeholders
   * @param int[]  $ids    The ids (not empty)
   * @param array  $params The parameters, extended by reference
   *
   * @return string The condition
   */
  private function inList(string $column, string $name, array $ids, array &$params): string
  {
    $placeholders = [];
    foreach (array_values($ids) as $index => $id) {
      $placeholders[] = ":{$name}_$index";
      $params["{$name}_$index"] = $id;
    }

    return "$column IN (" . implode(', ', $placeholders) . ')';
  }

  //---------------------------------------------------------------------------
  /**
   * Shapes a database row for the API.
   *
   * @param array $row    The row of selectSql()
   * @param bool  $detail Whether the row carries the detail columns
   *
   * @return array
   */
  private function present(array $row, bool $detail): array
  {
    $seeEmail = $this->visibility->canViewAll();

    $issue = [
      'id' => (int)$row['id'],
      'key' => $this->config->issueKey((int)$row['id']),
      'summary' => $row['summary'],
      'access' => $row['access'],
      // web or email (Email to ticket)
      'source' => $row['source'],
      'created_at' => $row['created_at'],
      'updated_at' => $row['updated_at'],
      'type' => $this->lookup($row, 'type', (int)$row['type_id']),
      'priority' => $this->lookup($row, 'priority', (int)$row['priority_id']),
      'status' => $this->lookup($row, 'status', (int)$row['status_id']) + [
        'category_id' => (int)$row['category_id'],
        'category_key' => $row['category_key'],
      ],
      // A missing reporter means the account was deleted, a missing assignee means unassigned
      'reporter' => $row['reporter_id'] === null
        ? ['id' => null, 'username' => null, 'display_name' => null, 'avatar' => null, 'deleted' => true]
        : $this->user($row, 'reporter', $seeEmail),
      'assignee' => $row['assignee_id'] === null ? null : $this->user($row, 'assignee', $seeEmail),
    ];

    if ($detail) {
      $issue['description'] = $row['description'];
      $issue['version'] = $row['version'];
      $issue['deleted_at'] = $row['deleted_at'];
      // The address of a sender who is not a user (Email to ticket, policy anonymous): for support staff only
      $issue['requester_email'] = $seeEmail ? $row['requester_email'] : null;
      $issue['status']['category_name'] = $row['category_name'];
      $issue['resolution'] = $row['resolution_id'] === null ? null : $this->lookup($row, 'resolution', (int)$row['resolution_id']);
      $issue['product'] = $row['product_id'] === null ? null : [
        'id' => (int)$row['product_id'],
        'name' => $row['product_name'],
        'icon' => $row['product_icon'],
        'color' => $row['product_color'],
        'background_color' => $row['product_bg'],
      ];
    }

    return $issue;
  }

  //---------------------------------------------------------------------------
  /**
   * Shapes the columns of a lookup record (type, priority, status, resolution) of a row.
   *
   * @param array  $row    The row
   * @param string $prefix The column prefix
   * @param int    $id     The record id
   *
   * @return array
   */
  private function lookup(array $row, string $prefix, int $id): array
  {
    return [
      'id' => $id,
      'key' => $row["{$prefix}_key"],
      'name' => $row["{$prefix}_name"],
      'icon' => $row["{$prefix}_icon"],
      'color' => $row["{$prefix}_color"],
      'background_color' => $row["{$prefix}_bg"],
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * Shapes a user of a row. The email address is only given to users who see all issues.
   *
   * @param array  $row      The row
   * @param string $prefix   reporter or assignee
   * @param bool   $seeEmail Whether to include the email address
   *
   * @return array
   */
  private function user(array $row, string $prefix, bool $seeEmail): array
  {
    $user = $this->userFields(
      (int)$row["{$prefix}_id"],
      (string)$row["{$prefix}_username"],
      $row["{$prefix}_first_name"],
      $row["{$prefix}_last_name"],
      $row["{$prefix}_avatar"]
    );
    if ($seeEmail) {
      $user['email'] = $row["{$prefix}_email"];
    }

    return $user;
  }

  //---------------------------------------------------------------------------
  /**
   * Builds the public fields of a user: username, display name and avatar, never the email address.
   *
   * @param int         $id        The user id
   * @param string      $username  The username
   * @param string|null $firstName The first name
   * @param string|null $lastName  The last name
   * @param string|null $avatar    The avatar ("sample/<file>", "upload/<file>")
   *
   * @return array
   */
  public static function userFields(int $id, string $username, ?string $firstName, ?string $lastName, ?string $avatar): array
  {
    $name = trim(trim((string)$firstName) . ' ' . trim((string)$lastName));

    return [
      'id' => $id,
      'username' => $username,
      'display_name' => $name !== '' ? $name : $username,
      'avatar' => $avatar,
      'deleted' => false,
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the tags of an issue.
   *
   * @param int $issueId The issue id
   *
   * @return array
   */
  private function tags(int $issueId): array
  {
    $stmt = $this->db->prepare(
      'SELECT g.id, g.name, g.color, g.background_color FROM issue_tag it JOIN tags g ON g.id = it.tag_id WHERE it.issue_id = :id ORDER BY g.name'
    );
    $stmt->execute(['id' => $issueId]);

    return array_map(fn(array $tag): array => ['id' => (int)$tag['id']] + $tag, $stmt->fetchAll());
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the tags of several issues with one query (for the list).
   *
   * @param int[] $issueIds The issue ids
   *
   * @return array<int, array> The tags by issue id
   */
  private function tagsOf(array $issueIds): array
  {
    if (empty($issueIds)) {
      return [];
    }

    $placeholders = implode(', ', array_fill(0, count($issueIds), '?'));
    $stmt = $this->db->prepare(
      "SELECT it.issue_id, g.id, g.name, g.color, g.background_color
         FROM issue_tag it JOIN tags g ON g.id = it.tag_id
        WHERE it.issue_id IN ($placeholders) ORDER BY g.name"
    );
    $stmt->execute(array_values($issueIds));

    $tags = [];
    foreach ($stmt->fetchAll() as $row) {
      $tags[(int)$row['issue_id']][] = ['id' => (int)$row['id'], 'name' => $row['name'], 'color' => $row['color'], 'background_color' => $row['background_color']];
    }

    return $tags;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the watchers of an issue. Anonymous visitors do not get the names of the watchers.
   *
   * @param int $issueId The issue id
   *
   * @return array
   */
  private function watchers(int $issueId): array
  {
    if ($this->visibility->viewerId() === null) {
      return [];
    }

    $stmt = $this->db->prepare(
      'SELECT u.id, u.username, u.first_name, u.last_name, u.avatar
         FROM issue_watcher w JOIN users u ON u.id = w.user_id
        WHERE w.issue_id = :id ORDER BY u.username'
    );
    $stmt->execute(['id' => $issueId]);

    return array_map(
      fn(array $u): array => $this->userFields((int)$u['id'], $u['username'], $u['first_name'], $u['last_name'], $u['avatar']),
      $stmt->fetchAll()
    );
  }

  //---------------------------------------------------------------------------
  /**
   * Counts the watchers of an issue.
   *
   * @param int $issueId The issue id
   *
   * @return int
   */
  private function watcherCount(int $issueId): int
  {
    $stmt = $this->db->prepare('SELECT COUNT(*) FROM issue_watcher WHERE issue_id = :id');
    $stmt->execute(['id' => $issueId]);

    return (int)$stmt->fetchColumn();
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the logged in viewer watches an issue.
   *
   * @param int $issueId The issue id
   *
   * @return bool
   */
  private function isWatching(int $issueId): bool
  {
    $userId = $this->visibility->viewerId();
    if ($userId === null) {
      return false;
    }

    $stmt = $this->db->prepare('SELECT COUNT(*) FROM issue_watcher WHERE issue_id = :id AND user_id = :user');
    $stmt->execute(['id' => $issueId, 'user' => $userId]);

    return (int)$stmt->fetchColumn() > 0;
  }
}
