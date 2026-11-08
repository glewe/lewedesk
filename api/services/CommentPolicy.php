<?php

declare(strict_types=1);

namespace App\Services;

//---------------------------------------------------------------------------
/**
 * The rules for comments at one place (see "Permissions" and "Issue actions" in _docs/TICKET_SPEC.md).
 *
 * The issue passed to the methods must come from the central visibility check (CommentModel::issue()),
 * so the row-level rule for commenting is already met: a user holding comment_issue who can see an issue
 * may comment on it. Users see their own, Public and Registered issues, Agents and Administrators all of
 * them, and that is exactly who may comment (Phase 18 adds the action rules on top of this class).
 *
 *   - Commenting needs a login and the permission comment_issue. Nobody comments on a deleted issue or
 *     on a Closed one (a Closed issue only takes a comment through Reopen).
 *   - Internal comments are written and read by users holding view_internal_comments.
 *   - A comment is changed (edited, deleted) by holders of edit_any_comment, and by its author. The
 *     reporter of an issue cannot change their own comments once the issue is Resolved or Closed.
 */
class CommentPolicy
{
  /** Status keys on which the reporter can no longer change their comments */
  private const LOCKED_FOR_REPORTER = ['resolved', 'closed'];

  /** Status key of an issue that takes no comments */
  private const CLOSED = 'closed';

  private IssueVisibility $visibility;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param IssueVisibility $visibility The visibility rules (who is asking, which permissions)
   */
  public function __construct(IssueVisibility $visibility)
  {
    $this->visibility = $visibility;
  }

  //---------------------------------------------------------------------------
  /**
   * Returns the id of the logged in user, or null for an anonymous visitor.
   *
   * @return int|null
   */
  public function viewerId(): ?int
  {
    return $this->visibility->viewerId();
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the viewer may see internal comments.
   *
   * @return bool
   */
  public function canSeeInternal(): bool
  {
    return $this->visibility->canViewInternalComments();
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the viewer may flag a new comment as internal.
   *
   * @return bool
   */
  public function canPostInternal(): bool
  {
    return $this->visibility->viewerId() !== null && $this->canSeeInternal();
  }

  //---------------------------------------------------------------------------
  /**
   * Tells why an issue takes no comments.
   *
   * @param array $issue The issue (deleted_at, status_key)
   *
   * @return string|null "deleted", "closed", or null if the state of the issue allows comments
   */
  public function blockedReason(array $issue): ?string
  {
    if ($issue['deleted_at'] !== null) {
      return 'deleted';
    }

    return $issue['status_key'] === self::CLOSED ? 'closed' : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the viewer holds the permission to comment, whatever the state of the issue.
   *
   * @return bool
   */
  public function mayComment(): bool
  {
    return $this->visibility->viewerId() !== null && $this->visibility->hasPermission('comment_issue');
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the viewer may add a comment to an issue.
   *
   * @param array $issue The visible issue (deleted_at, status_key)
   *
   * @return bool
   */
  public function canComment(array $issue): bool
  {
    return $this->mayComment() && $this->blockedReason($issue) === null;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the viewer may edit or delete a comment.
   *
   * @param array $comment The comment (author_id, is_internal, deleted_at)
   * @param array $issue   The visible issue (reporter_id, deleted_at, status_key)
   *
   * @return bool
   */
  public function canChange(array $comment, array $issue): bool
  {
    $userId = $this->visibility->viewerId();
    if ($userId === null || $issue['deleted_at'] !== null || $comment['deleted_at'] !== null) {
      return false;
    }
    // A comment the viewer cannot see cannot be changed either
    if ($comment['is_internal'] && !$this->canSeeInternal()) {
      return false;
    }
    if ($this->visibility->hasPermission('edit_any_comment')) {
      return true;
    }
    if ($comment['author_id'] === null || (int)$comment['author_id'] !== $userId || !$this->visibility->hasPermission('comment_issue')) {
      return false;
    }

    $isReporter = $issue['reporter_id'] !== null && (int)$issue['reporter_id'] === $userId;

    return !($isReporter && in_array($issue['status_key'], self::LOCKED_FOR_REPORTER, true));
  }
}
