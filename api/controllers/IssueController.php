<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AttachmentModel;
use App\Models\IssueModel;
use App\Services\IssueActions;
use App\Services\IssueConflictException;
use App\Services\IssueSearch;
use App\Services\IssueVisibility;
use App\Services\NotificationService;
use App\Services\RateLimiter;
use App\Services\SlaService;
use DateTimeImmutable;
use DateTimeZone;

//---------------------------------------------------------------------------
/**
 * REST controller for issues.
 *
 *   GET  /issues          list (DataTables server-side processing; q searches, see IssueSearch)
 *   POST /issues          create (optional tag_ids; upload_token attaches the temporary uploads made under that token)
 *   GET  /issues/counts   number of issues per quick view
 *   GET  /issues/people   reporters and assignees of the visible issues (list filters)
 *   GET  /issues/accessible?ids=1,2  which of the issues the viewer may see (id and summary), at most 100
 *   GET  /issues/agents   the users who can be assigned (role Agent; assign_issue)
 *   GET  /issues/reporters  the users who can be made the reporter (Agents and Administrators)
 *   GET  /issues/{id}     read, with the actions the viewer may use (see IssueActions) and the choices of Change access
 *   PUT  /issues/{id}     edit (the Edit page: edit_issue; any of type_id, priority_id, summary, description, access, product_id, version,
 *                         tag_ids and, with change_issue_status, status_id and resolution_id, with assign_issue, assignee_id, as Agent or Administrator, reporter_id and watcher_ids)
 *   PUT  /issues/{id}/tags  replace the tags of an issue ({"tag_ids": [1, 2]})
 *
 * Authorization on the route level (view_issue to read, create_issue to create) is done by the
 * caller (index.php). Which issues a viewer sees is decided by App\Services\IssueVisibility inside the
 * model; an issue the viewer may not see is answered with 404, as if it did not exist.
 */
class IssueController
{
  private const ACCESS_VALUES = ['public', 'registered', 'reporter'];
  private const MAX_PAGE_SIZE = 100;
  private const DEFAULT_PAGE_SIZE = 25;
  private const MAX_SUMMARY = 200;
  private const MAX_DESCRIPTION = 50000;
  private const MAX_VERSION = 50;
  private const MAX_TAGS = 20;
  private const MAX_REFERENCES = 100;
  private const MAX_FILTER_VALUES = 100;

  private IssueModel $model;
  private IssueVisibility $visibility;
  private IssueActions $actions;
  private AttachmentModel $attachments;
  private RateLimiter $limiter;
  private NotificationService $notifier;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param IssueModel      $model       The model
   * @param IssueVisibility $visibility  The visibility rules
   * @param IssueActions    $actions     The action rules (the available actions are part of an issue)
   * @param AttachmentModel $attachments The attachment model (temporary uploads are attached to a new issue)
   * @param RateLimiter     $limiter     The limit of issues per hour
   * @param NotificationService $notifier The notifications about new and changed issues
   */
  public function __construct(IssueModel $model, IssueVisibility $visibility, IssueActions $actions, AttachmentModel $attachments, RateLimiter $limiter, NotificationService $notifier)
  {
    $this->model = $model;
    $this->visibility = $visibility;
    $this->actions = $actions;
    $this->attachments = $attachments;
    $this->limiter = $limiter;
    $this->notifier = $notifier;
  }

  //---------------------------------------------------------------------------
  /**
   * Dispatches the request.
   *
   * @param string      $method HTTP method
   * @param string|null $param  The path segment after /issues ("12", "counts", "people", "accessible" or null)
   * @param string|null $sub    The path segment after the id ("tags", "watchers" or null)
   *
   * @return void
   */
  public function handle(string $method, ?string $param, ?string $sub = null): void
  {
    if ($sub === 'watchers') {
      $method === 'GET' ? $this->watcherCandidates((int)$param) : $this->fail(405, 'Method not allowed');
      return;
    }

    if ($sub === 'tags') {
      $method === 'PUT' ? $this->updateTags((int)$param) : $this->fail(405, 'Method not allowed');
      return;
    }

    if ($param === null) {
      match ($method) {
        'GET' => $this->list(),
        'POST' => $this->create(),
        default => $this->fail(405, 'Method not allowed'),
      };
      return;
    }

    if ($param === 'agents') {
      $method === 'GET' ? $this->agents() : $this->fail(405, 'Method not allowed');
      return;
    }

    if ($param === 'reporters') {
      $method === 'GET' ? $this->reporters() : $this->fail(405, 'Method not allowed');
      return;
    }

    if ($param === 'accessible') {
      $method === 'GET' ? $this->accessible() : $this->fail(405, 'Method not allowed');
      return;
    }

    if ($param === 'counts' || $param === 'people') {
      $method === 'GET'
        ? $this->respond(['status' => 'success', 'data' => $param === 'counts' ? $this->model->counts() : $this->model->people()])
        : $this->fail(405, 'Method not allowed');
      return;
    }

    match ($method) {
      'GET' => $this->read((int)$param),
      'PUT' => $this->update((int)$param),
      default => $this->fail(405, 'Method not allowed'),
    };
  }

  //---------------------------------------------------------------------------
  /**
   * Reads one issue.
   */
  private function read(int $id): void
  {
    $issue = $this->model->find($id);
    $issue !== null ? $this->respond(['status' => 'success', 'data' => $this->presented($issue)]) : $this->fail(404, 'Issue not found');
  }

  //---------------------------------------------------------------------------
  /**
   * Adds what the page needs to the issue: the actions the viewer may use and, for Change access, the choices.
   *
   * @param array $issue The issue of the model
   *
   * @return array
   */
  private function presented(array $issue): array
  {
    $facts = $this->actions->facts($issue);
    $issue['actions'] = $this->actions->available($facts);
    $issue['access_options'] = $this->actions->accessOptions($facts);
    $issue['can_attach'] = $this->actions->canAttach($facts);
    $issue['can_manage_watchers'] = $this->canManageWatchers();
    // The SLA timers are for the staff (view_sla); customers get nothing, not even an empty list
    // (not in the limited mode of the license: the timers are not evaluated then, their facts are only kept)
    if (SlaService::$timersEnabled && $this->visibility->hasPermission('view_sla')) {
      $issue['sla'] = $this->model->slaTimers((int)$issue['id']);
    }

    return $issue;
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the users who can be assigned (role Agent). For users who may assign issues or edit them.
   */
  private function agents(): void
  {
    if (!$this->visibility->hasPermission('assign_issue') && !$this->visibility->hasPermission('edit_issue')) {
      $this->visibility->viewerId() === null ? $this->fail(401, 'Authentication required') : $this->fail(403, 'Permission required: assign_issue');
      return;
    }

    $this->respond(['status' => 'success', 'data' => $this->model->agents()]);
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the users who can be made the reporter of an issue (every user). For Agents and Administrators.
   */
  private function reporters(): void
  {
    if (!$this->canManageWatchers()) {
      $this->fail(403, 'Only an Agent or an Administrator can change the reporter');
      return;
    }

    $this->respond(['status' => 'success', 'data' => $this->model->reporters()]);
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the viewer may add and remove watchers (the roles Agent and Administrator).
   *
   * @return bool
   */
  private function canManageWatchers(): bool
  {
    $roles = $this->visibility->viewer()['roles'] ?? [];

    return in_array('Agent', $roles, true) || in_array('Administrator', $roles, true);
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the users who can watch an issue (for the Edit page). For Agents and Administrators.
   */
  private function watcherCandidates(int $id): void
  {
    $issue = $this->model->find($id);
    if ($issue === null) {
      $this->fail(404, 'Issue not found');
      return;
    }
    if (!$this->canManageWatchers()) {
      $this->fail(403, 'Only an Agent or an Administrator can change the watchers');
      return;
    }

    $this->respond(['status' => 'success', 'data' => $this->model->watcherCandidates($issue['access'], isset($issue['reporter']['id']) ? (int)$issue['reporter']['id'] : null)]);
  }

  //---------------------------------------------------------------------------
  /**
   * Edits an issue (the Edit page). Only the fields that are sent are looked at. The issue must be visible to
   * the user (else 404 as for a missing issue).
   */
  private function update(int $id): void
  {
    $issue = $this->model->find($id);
    if ($issue === null) {
      $this->fail(404, 'Issue not found');
      return;
    }

    $facts = $this->actions->facts($issue);
    $denial = $this->actions->denial('edit', $facts);
    if ($denial !== null) {
      $this->fail($denial[0], $denial[1]);
      return;
    }

    $changes = $this->validateEdit($this->input(), $issue);
    if (is_string($changes)) {
      $this->fail(400, $changes);
      return;
    }
    if (isset($changes['error'])) {
      $this->fail($changes['error'][0], $changes['error'][1]);
      return;
    }

    try {
      $changed = $this->model->edit($facts, $changes, (int)$this->visibility->viewerId());
    } catch (IssueConflictException $e) {
      $this->fail(409, $e->getMessage());
      return;
    }

    if ($changed) {
      $this->announceEdit($facts, $changes);
    }

    $updated = $this->model->find($id);
    $this->respond([
      'status' => 'success',
      'message' => $changed ? 'Issue updated' : 'Issue unchanged',
      'data' => $updated === null ? ['id' => $id] : $this->presented($updated),
    ]);
  }

  //---------------------------------------------------------------------------
  /**
   * Notifies about what the Edit page changed that the notifications cover: the status and the assignee.
   *
   * @param array $facts   The facts of the issue before the change
   * @param array $changes The validated changes
   *
   * @return void
   */
  private function announceEdit(array $facts, array $changes): void
  {
    $events = [];
    if (array_key_exists('assignee_id', $changes) && $changes['assignee_id'] !== ($facts['assignee_id'] === null ? null : (int)$facts['assignee_id'])) {
      $events[] = NotificationService::ASSIGNED;
    }
    if (array_key_exists('status_id', $changes) && (int)$changes['status_id'] !== $facts['status_id']) {
      $events[] = NotificationService::STATUS;
    }

    $this->notifier->notify($facts['id'], $this->visibility->viewerId(), $events);
  }

  //---------------------------------------------------------------------------
  /**
   * Validates the changes of the Edit page and applies the resolution rules: a status of the category Done
   * needs a resolution, every other status has none.
   *
   * @param array $input The raw input
   * @param array $issue The issue as it is
   *
   * @return array|string The changes, an error message (400), or ['error' => [status, message]]
   */
  private function validateEdit(array $input, array $issue): array|string
  {
    $changes = $this->validateFields($input, false);
    if (is_string($changes)) {
      return $changes;
    }

    // A type that is inactive now may stay on an old issue, but cannot be chosen
    if (isset($changes['type_id']) && $changes['type_id'] !== (int)$issue['type']['id'] && !$this->model->lookupExists('issue_types', $changes['type_id'])) {
      return 'Type does not exist or is not active';
    }

    // The same for the priority
    if (isset($changes['priority_id']) && $changes['priority_id'] !== (int)$issue['priority']['id'] && !$this->model->lookupExists('priorities', $changes['priority_id'])) {
      return 'Priority does not exist or is not active';
    }

    // The reporter can be changed by Agents and Administrators (the same roles that manage the watchers)
    $reporterId = isset($issue['reporter']['id']) ? (int)$issue['reporter']['id'] : null;
    if (array_key_exists('reporter_id', $input)) {
      if (!$this->canManageWatchers()) {
        return ['error' => [403, 'Only an Agent or an Administrator can change the reporter']];
      }
      $newReporter = $this->lookupId($input['reporter_id']);
      if ($newReporter === null || !$this->model->userExists($newReporter)) {
        return 'Reporter must be an existing user';
      }
      if ($newReporter !== $reporterId) {
        $changes['reporter_id'] = $newReporter;
        $reporterId = $newReporter;
      }
    }

    if (array_key_exists('assignee_id', $input)) {
      if (!$this->visibility->hasPermission('assign_issue')) {
        return ['error' => [403, 'Permission required: assign_issue']];
      }
      $assigneeId = $input['assignee_id'] === null ? null : $this->lookupId($input['assignee_id']);
      if ($input['assignee_id'] !== null && ($assigneeId === null || !$this->model->isAgent($assigneeId))) {
        return 'Only a user with the role Agent can be assigned';
      }
      $changes['assignee_id'] = $assigneeId;
    }

    if (array_key_exists('watcher_ids', $input)) {
      if (!$this->canManageWatchers()) {
        return ['error' => [403, 'Only an Agent or an Administrator can change the watchers']];
      }
      if (!is_array($input['watcher_ids']) || count($input['watcher_ids']) > 500) {
        return 'watcher_ids must be a list of user ids';
      }
      $ids = [];
      foreach ($input['watcher_ids'] as $raw) {
        $userId = $this->lookupId($raw);
        if ($userId === null) {
          return 'watcher_ids must be a list of user ids';
        }
        $ids[$userId] = $userId;
      }
      // Whatever the access will be after this edit
      $access = $changes['access'] ?? $issue['access'];
      $allowed = array_column($this->model->watcherCandidates($access, $reporterId), 'id');
      if (array_diff($ids, $allowed) !== []) {
        return 'Only users who can see the issue can watch it';
      }
      $changes['watcher_ids'] = array_values($ids);
    }

    if (array_key_exists('status_id', $input) || array_key_exists('resolution_id', $input)) {
      if (!$this->visibility->hasPermission('change_issue_status')) {
        return ['error' => [403, 'Permission required: change_issue_status']];
      }

      $currentStatusId = (int)$issue['status']['id'];
      $statusId = $currentStatusId;
      if (array_key_exists('status_id', $input)) {
        $statusId = $this->lookupId($input['status_id']) ?? 0;
        if ($statusId !== $currentStatusId && !$this->model->lookupExists('status', $statusId)) {
          return 'Status does not exist or is not active';
        }
        $changes['status_id'] = $statusId;
      }
      $status = $this->model->statusInfo($statusId);
      if ($status === null) {
        return 'Status does not exist or is not active';
      }

      $currentResolutionId = $issue['resolution'] === null ? null : (int)$issue['resolution']['id'];
      $resolutionId = $currentResolutionId;
      if (array_key_exists('resolution_id', $input)) {
        $raw = $input['resolution_id'];
        $resolutionId = $raw === null || $raw === '' ? null : $this->lookupId($raw);
        if ($raw !== null && $raw !== '' && ($resolutionId === null || ($resolutionId !== $currentResolutionId && !$this->model->lookupExists('resolutions', $resolutionId)))) {
          return 'Resolution does not exist or is not active';
        }
      }

      if ($status['category_key'] === 'done') {
        if ($resolutionId === null) {
          return 'Resolution is required for a status of the category Done';
        }
      } else {
        if (array_key_exists('resolution_id', $input) && $resolutionId !== null) {
          return 'A resolution can only be set on a status of the category Done';
        }
        // Leaving the category Done clears the resolution
        $resolutionId = null;
      }
      $changes['resolution_id'] = $resolutionId;
    }

    return $changes;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells which of the issues in the parameter "ids" (comma separated, e.g. 12,15,40) the viewer may see.
   * Used to link issue keys in texts: a key of an issue the viewer may not see stays plain text.
   */
  private function accessible(): void
  {
    $raw = $_GET['ids'] ?? '';
    if (!is_string($raw) || $raw === '') {
      $this->fail(400, 'Issue ids are required');
      return;
    }

    $ids = [];
    foreach (explode(',', $raw) as $item) {
      $id = $this->lookupId($item);
      if ($id === null) {
        $this->fail(400, 'Issue ids must be a comma separated list of issue numbers');
        return;
      }
      $ids[$id] = $id;
    }
    if (count($ids) > self::MAX_REFERENCES) {
      $this->fail(400, 'At most ' . self::MAX_REFERENCES . ' issues can be asked at once');
      return;
    }

    $this->respond(['status' => 'success', 'data' => $this->model->accessible(array_values($ids))]);
  }

  //---------------------------------------------------------------------------
  /**
   * Replaces the tags of an issue. The issue must be visible to the user (else 404 as for a missing issue).
   */
  private function updateTags(int $id): void
  {
    $issue = $this->model->find($id);
    if ($issue === null) {
      $this->fail(404, 'Issue not found');
      return;
    }
    if ($issue['deleted_at'] !== null) {
      $this->fail(409, 'A deleted issue cannot be changed');
      return;
    }

    $tagIds = $this->tagIds($this->input()['tag_ids'] ?? null);
    if (is_string($tagIds)) {
      $this->fail(400, $tagIds);
      return;
    }

    $changed = $this->model->setTags($id, $tagIds);
    $this->respond(['status' => 'success', 'message' => $changed ? 'Tags updated' : 'Tags unchanged', 'data' => $this->model->tagsOfIssue($id)]);
  }

  //---------------------------------------------------------------------------
  /**
   * Validates a list of tag ids: whole numbers, no duplicates, all existing tags.
   *
   * @param mixed $value The raw value (null: no tags)
   *
   * @return int[]|string The ids, or an error message
   */
  private function tagIds(mixed $value): array|string
  {
    if ($value === null) {
      return [];
    }
    if (!is_array($value) || !array_is_list($value)) {
      return 'Tags must be a list of tag ids';
    }

    $ids = [];
    foreach ($value as $item) {
      $id = $this->lookupId($item);
      if ($id === null) {
        return 'Tags must be a list of tag ids';
      }
      $ids[$id] = $id;
    }
    $ids = array_values($ids);

    if (count($ids) > self::MAX_TAGS) {
      return 'An issue can have at most ' . self::MAX_TAGS . ' tags';
    }
    if (!$this->model->tagsExist($ids)) {
      return 'Tag does not exist';
    }

    return $ids;
  }

  //---------------------------------------------------------------------------
  /**
   * Lists issues for DataTables (draw, start, length, order[0][column], columns[n][data]) and the
   * parameters view, status_id, status_category_id, type_id, priority_id, product_id, assignee_id (a number or "none"), reporter_id,
   * tag_id and participant_id (reporter, assignee, commenter or watcher), and q (search term, at most 100 characters: issue key or number, or words that must all be found
   * in summary, description, comments, product or tags).
   */
  private function list(): void
  {
    $view = (string)($_GET['view'] ?? 'all');
    if (!array_key_exists($view, IssueModel::VIEWS)) {
      $this->fail(400, 'Unknown view');
      return;
    }
    if ($view === 'mine' || $view === 'assigned') {
      if ($this->visibility->viewerId() === null) {
        $this->fail(401, 'Authentication required');
        return;
      }
    }
    if ($view === 'deleted' && !$this->visibility->canSeeDeleted()) {
      $this->fail(403, 'Permission required: delete_issue');
      return;
    }

    $filters = $this->filters();
    if (is_string($filters)) {
      $this->fail(400, $filters);
      return;
    }

    // The SLA view, filter and sort are for the holders of view_sla (otherwise they would tell which issues are breached)
    [$sort, $direction] = $this->sorting();
    if (($view === 'sla_breached' || isset($filters['sla_state']) || $sort === 'sla') && !$this->model->slaVisible()) {
      $this->fail(403, 'Permission required: view_sla');
      return;
    }

    $search = $_GET['q'] ?? '';
    if (!is_string($search) || mb_strlen($search) > IssueSearch::MAX_LENGTH) {
      $this->fail(400, 'Search term must be text of at most ' . IssueSearch::MAX_LENGTH . ' characters');
      return;
    }

    $result = $this->model->list([
      'view' => $view,
      'filters' => $filters,
      'search' => trim($search),
      'sort' => $sort,
      'direction' => $direction,
      'start' => $this->number($_GET['start'] ?? 0, 0, 1000000, 0),
      'length' => $this->number($_GET['length'] ?? self::DEFAULT_PAGE_SIZE, 1, self::MAX_PAGE_SIZE, self::DEFAULT_PAGE_SIZE),
    ]);

    $this->respond([
      'status' => 'success',
      // DataTables echoes the draw counter to discard out-of-order responses
      'draw' => $this->number($_GET['draw'] ?? 0, 0, PHP_INT_MAX, 0),
      'recordsTotal' => $result['total'],
      'recordsFiltered' => $result['filtered'],
      'data' => $result['rows'],
    ]);
  }

  //---------------------------------------------------------------------------
  /**
   * Creates an issue. The reporter, the initial status and the timestamps are set by the server.
   */
  private function create(): void
  {
    $input = $this->input();
    $reporterId = $this->visibility->viewerId();
    if ($reporterId === null) {
      $this->fail(401, 'Authentication required');
      return;
    }

    // Honeypot: the field is invisible, so only a bot fills it in. The bot is told that all went well and nothing is saved.
    if (isset($input['website']) && $input['website'] !== '') {
      error_log(sprintf('Honeypot: issue of user %d rejected from %s', $reporterId, $_SERVER['REMOTE_ADDR'] ?? 'unknown'));
      $this->respond(['status' => 'success', 'message' => 'Issue created', 'data' => ['id' => 0]], 201);
      return;
    }

    $data = $this->validateNew($input);
    if (is_string($data)) {
      $this->fail(400, $data);
      return;
    }

    $wait = $this->limiter->hit($reporterId, RateLimiter::ISSUE);
    if ($wait !== null) {
      header('Retry-After: ' . $wait);
      $this->respond([
        'status' => 'error',
        'message' => sprintf('Too many issues in the last hour. Try again in %d minute(s).', (int)ceil($wait / 60)),
        'retry_after' => $wait,
      ], 429);
      return;
    }

    $id = $this->model->create($data, $reporterId);
    // The files that were uploaded while the issue was written (and the inline images of its description) belong to it now
    if ($data['upload_token'] !== null) {
      $this->attachments->claim($data['upload_token'], $reporterId, $id);
    }
    $this->notifier->notify($id, $reporterId, [NotificationService::CREATED]);
    // The reporter may lack view_issue in a custom permission setup, then there is nothing to read back
    $issue = $this->model->find($id);
    $this->respond(['status' => 'success', 'message' => 'Issue created', 'data' => $issue ?? ['id' => $id]], 201);
  }

  //---------------------------------------------------------------------------
  /**
   * Validates and normalizes the data of a new issue.
   *
   * @param array $input The raw input
   *
   * @return array|string Normalized data or an error message
   */
  private function validateNew(array $input): array|string
  {
    $fields = $this->validateFields($input, true);
    if (is_string($fields)) {
      return $fields;
    }

    $token = $input['upload_token'] ?? null;
    if ($token !== null && $token !== '' && (!is_string($token) || preg_match('/^[a-f0-9]{32}$/', $token) !== 1)) {
      return 'Upload token is invalid';
    }

    return $fields + ['product_id' => null, 'version' => null, 'upload_token' => $token === '' ? null : $token];
  }

  //---------------------------------------------------------------------------
  /**
   * Validates and normalizes the fields of an issue that the create form and the Edit page share.
   *
   * @param array $input  The raw input
   * @param bool  $create Whether a new issue is created (type, summary and description are required, access
   *                      defaults to Registered) or an existing one edited (only the fields that are sent are looked at)
   *
   * @return array|string The normalized fields that were given, or an error message
   */
  private function validateFields(array $input, bool $create): array|string
  {
    $fields = [];

    if ($create || array_key_exists('type_id', $input)) {
      $typeId = $this->lookupId($input['type_id'] ?? null);
      if ($typeId === null) {
        return 'Type is required';
      }
      // On the Edit page the type of the issue may be inactive by now: the caller checks a changed type
      if (!$this->model->lookupExists('issue_types', $typeId, $create)) {
        return 'Type does not exist or is not active';
      }
      $fields['type_id'] = $typeId;
    }

    // The priority is mandatory: on create it defaults to Normal when missing
    if ($create ? isset($input['priority_id']) && $input['priority_id'] !== '' : array_key_exists('priority_id', $input)) {
      $priorityId = $this->lookupId($input['priority_id']);
      if ($priorityId === null) {
        return 'Priority is required';
      }
      // On the Edit page the priority of the issue may be inactive by now: the caller checks a changed priority
      if (!$this->model->lookupExists('priorities', $priorityId, $create)) {
        return 'Priority does not exist or is not active';
      }
      $fields['priority_id'] = $priorityId;
    }

    if ($create || array_key_exists('summary', $input)) {
      $summary = $this->text($input['summary'] ?? null);
      if ($summary === null || $summary === '') {
        return 'Summary is required';
      }
      if (mb_strlen($summary) > self::MAX_SUMMARY) {
        return 'Summary must not be longer than ' . self::MAX_SUMMARY . ' characters';
      }
      if (preg_match('/[\x00-\x1F\x7F]/', $summary)) {
        return 'Summary must be a single line';
      }
      $fields['summary'] = $summary;
    }

    if ($create || array_key_exists('description', $input)) {
      $description = $this->text($input['description'] ?? null);
      if ($description === null || $description === '') {
        return 'Description is required';
      }
      if (mb_strlen($description) > self::MAX_DESCRIPTION) {
        return 'Description must not be longer than ' . self::MAX_DESCRIPTION . ' characters';
      }
      if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $description)) {
        return 'Description contains invalid characters';
      }
      $fields['description'] = $description;
    }

    if ($create || array_key_exists('access', $input)) {
      $access = $input['access'] ?? ($create ? 'registered' : null);
      if (!is_string($access) || !in_array($access, self::ACCESS_VALUES, true)) {
        return 'Access must be public, registered or reporter';
      }
      $fields['access'] = $access;
    }

    if ($create ? isset($input['product_id']) : array_key_exists('product_id', $input)) {
      $productId = null;
      if ($input['product_id'] !== null && $input['product_id'] !== '') {
        $productId = $this->lookupId($input['product_id']);
        if ($productId === null || !$this->model->lookupExists('products', $productId)) {
          return 'Product does not exist';
        }
      }
      $fields['product_id'] = $productId;
    }

    if ($create ? isset($input['version']) : array_key_exists('version', $input)) {
      $version = $input['version'] === null ? '' : $this->text($input['version']);
      if ($version === null) {
        return 'Version must be text';
      }
      if (mb_strlen($version) > self::MAX_VERSION) {
        return 'Version must not be longer than ' . self::MAX_VERSION . ' characters';
      }
      if (preg_match('/[\x00-\x1F\x7F]/', $version)) {
        return 'Version must be a single line';
      }
      $fields['version'] = $version === '' ? null : $version;
    }

    if ($create || array_key_exists('tag_ids', $input)) {
      $tagIds = $this->tagIds($input['tag_ids'] ?? null);
      if (is_string($tagIds)) {
        return $tagIds;
      }
      $fields['tag_ids'] = $tagIds;
    }

    return $fields;
  }

  //---------------------------------------------------------------------------
  /**
   * Reads the list filters from the query string.
   *
   * @return array|string The filters that were given, or an error message
   */
  private function filters(): array|string
  {
    $filters = [];

    // These accept several ids, comma-separated (e.g. status_id=1,3); a match on any of them counts
    foreach (['status_id', 'status_category_id', 'type_id', 'priority_id', 'product_id', 'reporter_id', 'tag_id'] as $key) {
      if (isset($_GET[$key]) && $_GET[$key] !== '') {
        $ids = $this->idList($_GET[$key], false);
        if ($ids === null) {
          return "Filter $key must be a list of whole numbers";
        }
        $filters[$key] = $ids;
      }
    }

    if (isset($_GET['source']) && $_GET['source'] !== '') {
      $sources = is_string($_GET['source']) ? array_unique(explode(',', $_GET['source'])) : [];
      if ($sources === [] || array_diff($sources, ['web', 'email']) !== []) {
        return 'Filter source must be web, email or both';
      }
      $filters['source'] = array_values($sources);
    }

    // SLA state of an open timer: one or more of the states, comma-separated (e.g. sla_state=breached,at_risk)
    if (isset($_GET['sla_state']) && $_GET['sla_state'] !== '') {
      $states = is_string($_GET['sla_state']) ? array_values(array_unique(explode(',', $_GET['sla_state']))) : [];
      if ($states === [] || array_diff($states, SlaService::LIST_STATES) !== []) {
        return 'Filter sla_state must be one or more of ' . implode(', ', SlaService::LIST_STATES);
      }
      $filters['sla_state'] = $states;
      // The end of the day for "due_today" in the time zone of the viewer (an IANA name such as Europe/Berlin; UTC without it)
      $zone = 'UTC';
      if (isset($_GET['sla_tz']) && $_GET['sla_tz'] !== '') {
        $zone = is_string($_GET['sla_tz']) && strlen($_GET['sla_tz']) <= 64 && in_array($_GET['sla_tz'], DateTimeZone::listIdentifiers(), true) ? $_GET['sla_tz'] : null;
        if ($zone === null) {
          return 'Filter sla_tz must be a time zone such as Europe/Berlin';
        }
      }
      $filters['sla_day_end'] = (new DateTimeImmutable('tomorrow', new DateTimeZone($zone)))->getTimestamp();
    }

    if (isset($_GET['participant_id']) && $_GET['participant_id'] !== '') {
      $id = $this->lookupId($_GET['participant_id']);
      if ($id === null) {
        return 'Filter participant_id must be a whole number';
      }
      $filters['participant_id'] = $id;
    }

    if (isset($_GET['assignee_id']) && $_GET['assignee_id'] !== '') {
      // Whole numbers and "none" (unassigned), comma-separated
      $ids = $this->idList($_GET['assignee_id'], true);
      if ($ids === null) {
        return 'Filter assignee_id must be a list of whole numbers or "none"';
      }
      $filters['assignee_id'] = $ids;
    }

    return $filters;
  }

  //---------------------------------------------------------------------------
  /**
   * Reads a comma-separated list of ids from the query string.
   *
   * @param mixed $value     The raw parameter
   * @param bool  $allowNone Whether the word "none" is a valid entry (kept as the string 'none')
   *
   * @return array<int|string>|null The distinct entries (at most MAX_FILTER_VALUES), or null if the value is not valid
   */
  private function idList(mixed $value, bool $allowNone): ?array
  {
    if (!is_string($value)) {
      return null;
    }

    $list = [];
    foreach (explode(',', $value) as $part) {
      $part = trim($part);
      if ($allowNone && $part === 'none') {
        $entry = 'none';
      } else {
        $entry = $this->lookupId($part);
        if ($entry === null) {
          return null;
        }
      }
      $list[$entry] = $entry;
    }

    return count($list) > self::MAX_FILTER_VALUES ? null : array_values($list);
  }

  //---------------------------------------------------------------------------
  /**
   * Reads the sort order from the DataTables parameters. Unknown columns fall back to the default
   * (Updated, descending).
   *
   * @return array{0: string, 1: string} Sort key and direction (asc or desc)
   */
  private function sorting(): array
  {
    $order = $_GET['order'][0] ?? null;
    if (!is_array($order)) {
      return ['updated_at', 'desc'];
    }

    $index = $order['column'] ?? null;
    $key = is_scalar($index) ? ($_GET['columns'][$index]['data'] ?? null) : null;
    if (!is_string($key) || !array_key_exists($key, IssueModel::SORT_COLUMNS)) {
      return ['updated_at', 'desc'];
    }

    return [$key, ($order['dir'] ?? '') === 'asc' ? 'asc' : 'desc'];
  }

  //---------------------------------------------------------------------------
  /**
   * Reads a whole number within a range.
   *
   * @param mixed $value   The raw value
   * @param int   $min     Smallest value
   * @param int   $max     Largest value
   * @param int   $default Value for invalid input
   *
   * @return int
   */
  private function number(mixed $value, int $min, int $max, int $default): int
  {
    if (is_array($value) || !preg_match('/^-?\d{1,18}$/', (string)$value)) {
      return $default;
    }

    return max($min, min($max, (int)$value));
  }

  //---------------------------------------------------------------------------
  /**
   * Reads a record id: a positive whole number, as number or string.
   *
   * @param mixed $value The raw value
   *
   * @return int|null Null if it is not a record id
   */
  private function lookupId(mixed $value): ?int
  {
    if (is_bool($value) || is_array($value) || !preg_match('/^[1-9]\d{0,8}$/', (string)$value)) {
      return null;
    }

    return (int)$value;
  }

  //---------------------------------------------------------------------------
  /**
   * Reads a text value: trimmed, valid UTF-8, with Windows line endings turned into Unix ones.
   *
   * @param mixed $value The raw value
   *
   * @return string|null Null if the value is not a string
   */
  private function text(mixed $value): ?string
  {
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
      return null;
    }

    return trim(str_replace("\r\n", "\n", $value));
  }

  //---------------------------------------------------------------------------
  /**
   * Reads the JSON request body.
   */
  private function input(): array
  {
    $input = json_decode(file_get_contents('php://input'), true);

    return is_array($input) ? $input : [];
  }

  //---------------------------------------------------------------------------
  /**
   * Sends a JSON response.
   */
  private function respond(array $payload, int $status = 200): void
  {
    http_response_code($status);
    echo json_encode($payload);
  }

  //---------------------------------------------------------------------------
  /**
   * Sends a JSON error response.
   */
  private function fail(int $status, string $message): void
  {
    $this->respond(['status' => 'error', 'message' => $message], $status);
  }
}
