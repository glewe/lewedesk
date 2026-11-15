<?php

declare(strict_types=1);

namespace App\Services;

//---------------------------------------------------------------------------
/**
 * The rules of the issue actions at one place (see "Issue actions" in _docs/TICKET_SPEC.md).
 *
 * The issue API lists the actions the viewer may use (available()), the action endpoint asks the same class
 * again before it does anything (denial()), so the page offers exactly what the server accepts. An action is
 * available when the viewer holds the permission or the relation to the issue (permission rules) and the
 * state of the issue fits (state rules). A deleted issue only takes Restore.
 *
 * The facts of an issue (see facts()) come from the issue model, which reads the issue through the central
 * visibility check, so the row-level rule is met for every issue passed to this class.
 *
 *   edit           edit_issue                                   the edit page (no endpoint of its own: PUT /issues/{id})
 *   respond        may comment; not Resolved/Closed             comment (mandatory), status follows the responder
 *   resolve        change_issue_status; not Resolved/Closed     resolution (mandatory), comment, status Resolved
 *   close          reporter, Agents, Administrators; Resolved   status Closed, the resolution is kept
 *   reopen         reporter, Agents, Administrators; Resolved/Closed; may comment   comment (mandatory), status Open, resolution cleared
 *   assign         assign_issue                                 assignee (an Agent) and comment
 *   assign_me      assign_issue and role Agent; not yet assigned to the viewer
 *   watch/unwatch  every logged in user
 *   move           edit_issue                                  moves the issue to another product (new key)
 *   change_access  reporter, edit_issue                         the reporter can only lower the access
 *   delete/restore delete_issue
 */
class IssueActions
{
  /** All actions, in the order the menu shows them */
  public const ACTIONS = ['edit', 'move', 'respond', 'resolve', 'close', 'reopen', 'assign', 'assign_me', 'watch', 'unwatch', 'change_access', 'delete', 'restore'];

  /** The access values from the widest to the narrowest: the reporter can only move to the right */
  public const ACCESS_ORDER = ['public', 'registered', 'reporter'];

  /** Status keys that end the conversation (no Respond, no Resolve) */
  private const FINISHED = ['resolved', 'closed'];

  private IssueVisibility $visibility;
  private CommentPolicy $policy;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param IssueVisibility $visibility The visibility rules (who is asking, which permissions)
   * @param CommentPolicy   $policy     The comment rules
   */
  public function __construct(IssueVisibility $visibility, CommentPolicy $policy)
  {
    $this->visibility = $visibility;
    $this->policy = $policy;
  }

  //---------------------------------------------------------------------------
  /**
   * Boils an issue of the issue model down to the facts the rules need.
   *
   * @param array $issue The issue as returned by IssueModel::find()
   *
   * @return array
   */
  public function facts(array $issue): array
  {
    return [
      'id' => (int)$issue['id'],
      'reporter_id' => $issue['reporter']['id'] ?? null,
      'assignee_id' => $issue['assignee']['id'] ?? null,
      'access' => $issue['access'],
      'deleted_at' => $issue['deleted_at'],
      'status_id' => (int)$issue['status']['id'],
      // A status without key (user defined) never matches a rule that names a status
      'status_key' => (string)($issue['status']['key'] ?? ''),
      'category_key' => (string)$issue['status']['category_key'],
      'status' => $issue['status'],
      'resolution' => $issue['resolution'],
      'is_watching' => (bool)$issue['is_watching'],
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the viewer acts as support staff ("Agents/Administrators" in the spec): whoever holds
   * change_issue_status, the permission both roles have and the User role does not.
   *
   * @return bool
   */
  public function isStaff(): bool
  {
    return $this->visibility->viewerId() !== null && $this->visibility->hasPermission('change_issue_status');
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the viewer holds the role Agent (Administrators do not count: they cannot be assigned).
   *
   * @return bool
   */
  public function isAgent(): bool
  {
    $viewer = $this->visibility->viewer();

    return $viewer !== null && in_array('Agent', $viewer['roles'], true);
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the viewer is the reporter of the issue.
   *
   * @param array $facts The facts of the issue
   *
   * @return bool
   */
  public function isReporter(array $facts): bool
  {
    $viewerId = $this->visibility->viewerId();

    return $viewerId !== null && $facts['reporter_id'] !== null && (int)$facts['reporter_id'] === $viewerId;
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the status a response leads to: Waiting for customer when the support team responds (also if the
   * responder is the reporter), Waiting for agent when the reporter responds, no change for everybody else
   * and for internal comments.
   *
   * @param array $facts    The facts of the issue
   * @param bool  $internal Whether the comment is internal
   *
   * @return string|null The status key, or null for no change
   */
  public function respondStatus(array $facts, bool $internal): ?string
  {
    if ($internal) {
      return null;
    }
    // The reporter answering a request of the support team hands the issue back, even if they are staff themselves
    if ($facts['status_key'] === 'waiting_for_customer' && $this->isReporter($facts)) {
      return 'waiting_for_agent';
    }
    if ($this->isStaff()) {
      return 'waiting_for_customer';
    }

    return $this->isReporter($facts) ? 'waiting_for_agent' : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the actions the viewer may use on an issue.
   *
   * @param array $facts The facts of the issue
   *
   * @return string[] The action keys, in menu order
   */
  public function available(array $facts): array
  {
    return array_values(array_filter(self::ACTIONS, fn(string $action): bool => $this->denial($action, $facts) === null));
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the access values the viewer may choose for an issue with the Change access action: the reporter
   * only the narrower ones, users with edit_issue every other one.
   *
   * @param array $facts The facts of the issue
   *
   * @return string[]
   */
  public function accessOptions(array $facts): array
  {
    if ($this->denial('change_access', $facts) !== null) {
      return [];
    }

    $current = (int)array_search($facts['access'], self::ACCESS_ORDER, true);
    $any = $this->visibility->hasPermission('edit_issue');
    $options = [];
    foreach (self::ACCESS_ORDER as $rank => $access) {
      if ($access !== $facts['access'] && ($any || $rank > $current)) {
        $options[] = $access;
      }
    }

    return $options;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells why the viewer may not perform an action on an issue.
   *
   * @param string $action The action key
   * @param array  $facts  The facts of the issue
   *
   * @return array{0: int, 1: string}|null The HTTP status (401, 403, 404 or 409) and the message, or null if the action is available
   */
  public function denial(string $action, array $facts): ?array
  {
    if (!in_array($action, self::ACTIONS, true)) {
      return [404, 'Unknown action'];
    }
    if ($this->visibility->viewerId() === null) {
      return [401, 'Authentication required'];
    }

    $related = $this->isReporter($facts) || $this->isStaff();

    // The permission rules: who may use the action at all
    $missing = match ($action) {
      'edit', 'move' => $this->missing('edit_issue'),
      'respond' => $this->policy->mayComment() ? null : 'Permission required: comment_issue',
      'resolve' => $this->missing('change_issue_status'),
      'close' => $related ? null : 'You are not allowed to close this issue',
      'reopen' => !$related ? 'You are not allowed to reopen this issue' : ($this->policy->mayComment() ? null : 'Permission required: comment_issue'),
      'assign' => $this->missing('assign_issue'),
      'assign_me' => $this->missing('assign_issue') ?? ($this->isAgent() ? null : 'Only an Agent can take an issue'),
      'change_access' => $this->isReporter($facts) ? null : $this->missing('edit_issue'),
      'delete', 'restore' => $this->missing('delete_issue'),
      default => null,
    };
    if ($missing !== null) {
      return [403, $missing];
    }

    // The state rules: whether the issue allows the action now
    if ($action === 'restore') {
      return $facts['deleted_at'] === null ? [409, 'The issue is not deleted'] : null;
    }
    if ($facts['deleted_at'] !== null) {
      return [409, 'A deleted issue cannot be changed'];
    }

    $finished = in_array($facts['status_key'], self::FINISHED, true);
    $narrowest = self::ACCESS_ORDER[count(self::ACCESS_ORDER) - 1];
    $conflict = match ($action) {
      'respond', 'resolve' => $finished ? 'The issue is already resolved or closed' : null,
      'close' => $facts['status_key'] === 'resolved' ? null : 'Only a resolved issue can be closed',
      'reopen' => $finished ? null : 'Only a resolved or closed issue can be reopened',
      'assign_me' => $facts['assignee_id'] !== null && (int)$facts['assignee_id'] === $this->visibility->viewerId() ? 'The issue is already assigned to you' : null,
      'watch' => $facts['is_watching'] ? 'You already watch this issue' : null,
      'unwatch' => $facts['is_watching'] ? null : 'You do not watch this issue',
      'change_access' => !$this->visibility->hasPermission('edit_issue') && $facts['access'] === $narrowest ? 'The access cannot be lowered any further' : null,
      default => null,
    };

    return $conflict === null ? null : [409, $conflict];
  }

  //---------------------------------------------------------------------------
  /**
   * Tells why the viewer may not add an attachment (or an inline image) to an issue. Adding an attachment is
   * not a menu action of its own: the issue page offers it in the attachments section.
   *
   *   - A login is required. The reporter may always attach files to their own issue, everybody else needs
   *     the permission add_attachment.
   *   - A deleted issue and a Closed one take no attachments.
   *
   * @param array $facts The facts of the issue
   *
   * @return array{0: int, 1: string}|null The HTTP status (401, 403 or 409) and the message, or null if the viewer may add attachments
   */
  public function attachDenial(array $facts): ?array
  {
    if ($this->visibility->viewerId() === null) {
      return [401, 'Authentication required'];
    }
    if (!$this->isReporter($facts) && !$this->visibility->hasPermission('add_attachment')) {
      return [403, 'Permission required: add_attachment'];
    }
    if ($facts['deleted_at'] !== null) {
      return [409, 'A deleted issue cannot be changed'];
    }

    return $facts['status_key'] === 'closed' ? [409, 'A closed issue takes no attachments'] : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the viewer may add attachments and inline images to an issue.
   *
   * @param array $facts The facts of the issue
   *
   * @return bool
   */
  public function canAttach(array $facts): bool
  {
    return $this->attachDenial($facts) === null;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the viewer may delete an attachment: its uploader, and the support team (Agents, Administrators).
   * Nobody changes a deleted issue.
   *
   * @param array $attachment The attachment (uploader_id)
   * @param array $facts      The facts of the issue
   *
   * @return bool
   */
  public function canDeleteAttachment(array $attachment, array $facts): bool
  {
    $userId = $this->visibility->viewerId();
    if ($userId === null || $facts['deleted_at'] !== null) {
      return false;
    }

    return ($attachment['uploader_id'] !== null && (int)$attachment['uploader_id'] === $userId) || $this->isStaff();
  }

  //---------------------------------------------------------------------------
  /**
   * Returns the name of a permission the viewer lacks, as the message of the refusal.
   *
   * @param string $permission The permission name
   *
   * @return string|null The message if the viewer does not hold the permission, else null
   */
  private function missing(string $permission): ?string
  {
    return $this->visibility->hasPermission($permission) ? null : 'Permission required: ' . $permission;
  }
}
