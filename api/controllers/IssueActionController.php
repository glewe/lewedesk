<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\IssueModel;
use App\Models\IssueWorkflowModel;
use App\Services\CommentPolicy;
use App\Services\IssueActions;
use App\Services\IssueConflictException;
use App\Services\IssueVisibility;
use App\Services\NotificationService;
use App\Services\RateLimiter;

//---------------------------------------------------------------------------
/**
 * REST controller for the workflow actions of an issue.
 *
 *   POST /issues/{id}/actions/respond        {"body": "...", "internal": false}
 *   POST /issues/{id}/actions/resolve        {"resolution_id": 3, "body": "..." (optional)}
 *   POST /issues/{id}/actions/close
 *   POST /issues/{id}/actions/reopen         {"body": "..."}
 *   POST /issues/{id}/actions/assign         {"assignee_id": 7 (or null to unassign), "body": "..." (optional)}
 *   POST /issues/{id}/actions/assign_me
 *   POST /issues/{id}/actions/watch
 *   POST /issues/{id}/actions/unwatch
 *   POST /issues/{id}/actions/move           {"product_id": 4} (also POST /issues/{key}/move): the issue gets a new key
 *   POST /issues/{id}/actions/change_access  {"access": "reporter"}
 *   POST /issues/{id}/actions/delete
 *   POST /issues/{id}/actions/restore
 *
 * The edit action is the edit page (PUT /issues/{id}). The login is required by the caller (index.php); which
 * actions the viewer may use on which issue is decided by App\Services\IssueActions, the same class that
 * lists the available actions in the issue. An issue the viewer may not see is answered with 404. The
 * answer carries the changed issue.
 */
class IssueActionController
{
  private const MAX_BODY = 20000;

  private IssueModel $issues;
  private IssueWorkflowModel $workflow;
  private IssueActions $actions;
  private CommentPolicy $policy;
  private IssueVisibility $visibility;
  private NotificationService $notifier;
  private RateLimiter $limiter;

  /** @var string[] The events of the action that is performed (see NotificationService) */
  private array $events = [];

  /** Whether the comment of the action is internal */
  private bool $internalComment = false;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param IssueModel         $issues     The issue model
   * @param IssueWorkflowModel $workflow   The model that executes the actions
   * @param IssueActions       $actions    The action rules
   * @param CommentPolicy      $policy     The comment rules
   * @param IssueVisibility    $visibility The visibility rules
   * @param NotificationService $notifier  The notifications about the changes
   * @param RateLimiter        $limiter    The limit of comments per hour (the comment of an action counts)
   */
  public function __construct(IssueModel $issues, IssueWorkflowModel $workflow, IssueActions $actions, CommentPolicy $policy, IssueVisibility $visibility, NotificationService $notifier, RateLimiter $limiter)
  {
    $this->issues = $issues;
    $this->workflow = $workflow;
    $this->actions = $actions;
    $this->policy = $policy;
    $this->visibility = $visibility;
    $this->notifier = $notifier;
    $this->limiter = $limiter;
  }

  //---------------------------------------------------------------------------
  /**
   * Performs an action.
   *
   * @param string $method HTTP method
   * @param int    $id     The issue number
   * @param string $action The action key
   *
   * @return void
   */
  public function handle(string $method, int $id, string $action): void
  {
    if ($method !== 'POST') {
      $this->fail(405, 'Method not allowed');
      return;
    }
    // The edit page has no action endpoint; an unknown action is reported before the issue is looked at
    if ($action === 'edit' || !in_array($action, IssueActions::ACTIONS, true)) {
      $this->fail(404, 'Unknown action');
      return;
    }

    $issue = $this->issues->find($id);
    if ($issue === null) {
      $this->fail(404, 'Issue not found');
      return;
    }

    $facts = $this->actions->facts($issue);
    $denial = $this->actions->denial($action, $facts);
    if ($denial !== null) {
      $this->fail($denial[0], $denial[1]);
      return;
    }

    $actorId = (int)$this->visibility->viewerId();
    $input = $this->input();

    try {
      $message = match ($action) {
        'respond' => $this->respondAction($facts, $actorId, $input),
        'resolve' => $this->resolveAction($facts, $actorId, $input),
        'close' => $this->closeAction($facts, $actorId),
        'reopen' => $this->reopenAction($facts, $actorId, $input),
        'assign' => $this->assignAction($facts, $actorId, $input),
        'assign_me' => $this->assignMeAction($facts, $actorId),
        'move' => $this->moveAction($facts, $actorId, $input),
        'change_access' => $this->changeAccessAction($facts, $actorId, $input),
        'watch' => $this->watchAction($facts, $actorId, true),
        'unwatch' => $this->watchAction($facts, $actorId, false),
        'delete' => $this->deleteAction($facts, $actorId, true),
        'restore' => $this->deleteAction($facts, $actorId, false),
      };
    } catch (IssueConflictException $e) {
      $this->fail(409, $e->getMessage());
      return;
    }
    // A string is the success message, an array is the error [status, message]
    if (is_array($message)) {
      $this->fail($message[0], $message[1]);
      return;
    }

    $this->notifier->notify($id, $actorId, $this->events, $this->internalComment);

    $changed = $this->issues->find($id);
    $this->respond(['status' => 'success', 'message' => $message, 'data' => $changed === null ? ['id' => $id] : $this->presented($changed)]);
  }

  //---------------------------------------------------------------------------
  /**
   * Respond: comment (mandatory, internal for users who may), status follows the responder.
   *
   * @return string|array The success message, or [status, message]
   */
  private function respondAction(array $facts, int $actorId, array $input): string|array
  {
    $body = $this->body($input['body'] ?? null, true);
    if (is_array($body)) {
      return $body;
    }
    $internal = $input['internal'] ?? false;
    if (!is_bool($internal)) {
      return [400, 'Internal must be true or false'];
    }
    if ($internal && !$this->policy->canPostInternal()) {
      return [403, 'Permission required: view_internal_comments'];
    }

    if (($limited = $this->commentLimit($actorId)) !== null) {
      return $limited;
    }

    $statusKey = $this->actions->respondStatus($facts, $internal);
    $this->workflow->respond($facts, $actorId, $body, $internal, $statusKey);
    $this->events = $statusKey !== null && $statusKey !== $facts['status_key']
      ? [NotificationService::COMMENT, NotificationService::STATUS]
      : [NotificationService::COMMENT];
    $this->internalComment = $internal;

    return 'Response added';
  }

  //---------------------------------------------------------------------------
  /**
   * Resolve: resolution (mandatory, active), comment (optional).
   *
   * @return string|array The success message, or [status, message]
   */
  private function resolveAction(array $facts, int $actorId, array $input): string|array
  {
    $resolutionId = $this->recordId($input['resolution_id'] ?? null);
    if ($resolutionId === null) {
      return [400, 'Resolution is required'];
    }
    if (!$this->issues->lookupExists('resolutions', $resolutionId)) {
      return [400, 'Resolution does not exist or is not active'];
    }
    // The fixed version is optional; left out (or null) it stays as it is
    $fixedVersionId = null;
    if (($input['fixed_version_id'] ?? null) !== null && $input['fixed_version_id'] !== '') {
      $fixedVersionId = $this->recordId($input['fixed_version_id']);
      $version = $fixedVersionId === null ? null : $this->issues->versionStatusForIssue($fixedVersionId, $facts['id']);
      if ($version === null) {
        return [400, 'Fixed version does not exist for the product of the issue'];
      }
      if ($version['status'] === 'archived' && !$version['is_current']) {
        return [400, 'Fixed version is archived and cannot be chosen'];
      }
    }

    $body = $this->optionalBody($input, $facts);
    if (is_array($body)) {
      return $body;
    }

    if ($body !== null && ($limited = $this->commentLimit($actorId)) !== null) {
      return $limited;
    }

    $this->workflow->resolve($facts, $actorId, $resolutionId, $body, $fixedVersionId);
    $this->events = $body === null ? [NotificationService::STATUS] : [NotificationService::COMMENT, NotificationService::STATUS];

    return 'Issue resolved';
  }

  //---------------------------------------------------------------------------
  /**
   * Counts the comment of an action against the comment limit (support staff are not limited).
   *
   * @param int $actorId The acting user
   *
   * @return array|null Null if the comment may go ahead, else the error [429, message] (Retry-After is sent)
   */
  private function commentLimit(int $actorId): ?array
  {
    $wait = $this->limiter->hit($actorId, RateLimiter::COMMENT);
    if ($wait === null) {
      return null;
    }
    header('Retry-After: ' . $wait);

    return [429, sprintf('Too many comments in the last hour. Try again in %d minute(s).', (int)ceil($wait / 60))];
  }

  //---------------------------------------------------------------------------
  /**
   * Close: nothing to ask.
   *
   * @return string The success message
   */
  private function closeAction(array $facts, int $actorId): string
  {
    $this->workflow->close($facts, $actorId);
    $this->events = [NotificationService::STATUS];

    return 'Issue closed';
  }

  //---------------------------------------------------------------------------
  /**
   * Reopen: comment (mandatory). The comment is allowed on a Closed issue because of the reopening.
   *
   * @return string|array The success message, or [status, message]
   */
  private function reopenAction(array $facts, int $actorId, array $input): string|array
  {
    $body = $this->body($input['body'] ?? null, true);
    if (is_array($body)) {
      return $body;
    }

    if (($limited = $this->commentLimit($actorId)) !== null) {
      return $limited;
    }

    $this->workflow->reopen($facts, $actorId, $body);
    $this->events = [NotificationService::COMMENT, NotificationService::STATUS];

    return 'Issue reopened';
  }

  //---------------------------------------------------------------------------
  /**
   * Assign: an Agent or nobody, comment (optional).
   *
   * @return string|array The success message, or [status, message]
   */
  private function assignAction(array $facts, int $actorId, array $input): string|array
  {
    if (!array_key_exists('assignee_id', $input)) {
      return [400, 'Assignee is required'];
    }
    $assigneeId = null;
    if ($input['assignee_id'] !== null) {
      $assigneeId = $this->recordId($input['assignee_id']);
      if ($assigneeId === null || !$this->issues->isAgent($assigneeId)) {
        return [400, 'Only a user with the role Agent can be assigned'];
      }
    }
    $current = $facts['assignee_id'] === null ? null : (int)$facts['assignee_id'];
    if ($assigneeId === $current) {
      return [409, 'The assignee is unchanged'];
    }
    $body = $this->optionalBody($input, $facts);
    if (is_array($body)) {
      return $body;
    }

    if ($body !== null && ($limited = $this->commentLimit($actorId)) !== null) {
      return $limited;
    }

    $this->workflow->assign($facts, $actorId, $assigneeId, $body);
    $this->events = $body === null ? [NotificationService::ASSIGNED] : [NotificationService::COMMENT, NotificationService::ASSIGNED];

    return $assigneeId === null ? 'Issue unassigned' : 'Issue assigned';
  }

  //---------------------------------------------------------------------------
  /**
   * Assign to me: the viewer is an Agent (the rules checked it).
   *
   * @return string The success message
   */
  private function assignMeAction(array $facts, int $actorId): string
  {
    $this->workflow->assign($facts, $actorId, $actorId, null);
    $this->events = [NotificationService::ASSIGNED];

    return 'Issue assigned to you';
  }

  //---------------------------------------------------------------------------
  /**
   * Move: another product (it exists). The issue gets the next number of that product, the old key keeps working,
   * and both versions are cleared.
   *
   * @return string|array The success message, or [status, message]
   */
  private function moveAction(array $facts, int $actorId, array $input): string|array
  {
    $productId = $this->recordId($input['product_id'] ?? null);
    if ($productId === null || !$this->issues->lookupExists('products', $productId)) {
      return [400, 'Product does not exist'];
    }

    $result = $this->workflow->move($facts, $actorId, $productId);

    return 'Issue moved to ' . $result['new_key'];
  }

  //---------------------------------------------------------------------------
  /**
   * Change access: one of the values the viewer may choose.
   *
   * @return string|array The success message, or [status, message]
   */
  private function changeAccessAction(array $facts, int $actorId, array $input): string|array
  {
    $access = $input['access'] ?? null;
    if (!is_string($access) || !in_array($access, IssueActions::ACCESS_ORDER, true)) {
      return [400, 'Access must be public, registered or reporter'];
    }
    if ($access === $facts['access']) {
      return [409, 'The access is unchanged'];
    }
    if (!in_array($access, $this->actions->accessOptions($facts), true)) {
      return [403, 'The reporter can only lower the access'];
    }

    $this->workflow->changeAccess($facts, $actorId, $access);

    return 'Access changed';
  }

  //---------------------------------------------------------------------------
  /**
   * Watch or unwatch. A watcher change does not change the issue.
   *
   * @return string The success message
   */
  private function watchAction(array $facts, int $actorId, bool $watch): string
  {
    $watch ? $this->issues->addWatcher($facts['id'], $actorId) : $this->issues->removeWatcher($facts['id'], $actorId);

    return $watch ? 'You watch the issue now' : 'You do not watch the issue any more';
  }

  //---------------------------------------------------------------------------
  /**
   * Delete or restore.
   *
   * @return string The success message
   */
  private function deleteAction(array $facts, int $actorId, bool $deleted): string
  {
    $this->workflow->setDeleted($facts, $actorId, $deleted);

    return $deleted ? 'Issue deleted' : 'Issue restored';
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

    return $issue;
  }

  //---------------------------------------------------------------------------
  /**
   * Reads the optional comment of an action: absent or empty means none. A comment needs the permission
   * comment_issue and an issue that takes comments.
   *
   * @param array $input The request body
   * @param array $facts The facts of the issue
   *
   * @return string|array|null The text, null for none, or [status, message]
   */
  private function optionalBody(array $input, array $facts): string|array|null
  {
    $raw = $input['body'] ?? null;
    if ($raw === null || (is_string($raw) && trim($raw) === '')) {
      return null;
    }
    // A Closed issue takes a comment through Reopen only
    if ($this->policy->blockedReason(['deleted_at' => $facts['deleted_at'], 'status_key' => $facts['status_key']]) !== null) {
      return [409, 'A closed issue cannot be commented'];
    }

    return $this->body($raw, false);
  }

  //---------------------------------------------------------------------------
  /**
   * Validates the text of a comment.
   *
   * @param mixed $value    The raw value
   * @param bool  $required Whether the action asks for a comment (Reopen may comment on a Closed issue)
   *
   * @return string|array The normalized text, or [status, message]
   */
  private function body(mixed $value, bool $required): string|array
  {
    $text = is_string($value) && mb_check_encoding($value, 'UTF-8') ? trim(str_replace("\r\n", "\n", $value)) : null;

    $error = match (true) {
      $text === null => 'Comment must be text',
      $text === '' => 'Comment is required',
      mb_strlen($text) > self::MAX_BODY => 'Comment must not be longer than ' . self::MAX_BODY . ' characters',
      (bool)preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) => 'Comment contains invalid characters',
      default => null,
    };
    if ($error !== null) {
      return [400, $error];
    }
    if (!$required && !$this->policy->mayComment()) {
      return [403, 'Permission required: comment_issue'];
    }

    return (string)$text;
  }

  //---------------------------------------------------------------------------
  /**
   * Reads a record id: a positive whole number, as number or string.
   *
   * @param mixed $value The raw value
   *
   * @return int|null Null if it is not a record id
   */
  private function recordId(mixed $value): ?int
  {
    if (is_bool($value) || is_array($value) || !preg_match('/^[1-9]\d{0,8}$/', (string)$value)) {
      return null;
    }

    return (int)$value;
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
