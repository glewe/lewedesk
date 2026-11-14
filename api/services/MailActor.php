<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AttachmentModel;
use App\Models\CommentModel;
use App\Models\IssueModel;

//---------------------------------------------------------------------------
/**
 * The set of objects that handle a mail as one user: the models and the rules, all looking at the sender of the mail
 * (see MailActorAuth) instead of a logged in browser user. Built by InboundMailService for every mail.
 */
final class MailActor
{
  /**
   * Constructor.
   *
   * @param int              $userId      The sender
   * @param array            $user        The sender with roles and permissions (AuthService::userData())
   * @param IssueVisibility  $visibility  Who sees what, as the sender
   * @param IssueModel       $issues      The issues, as the sender sees them
   * @param CommentModel     $comments    The comments, as the sender sees them
   * @param CommentPolicy    $policy      The comment rules
   * @param IssueActions     $actions     The rules of the issue actions (attachments, staff)
   * @param RateLimiter      $limiter     The hourly limits of issues and comments per user
   * @param AttachmentModel  $attachments The attachments
   */
  public function __construct(
    public readonly int $userId,
    public readonly array $user,
    public readonly IssueVisibility $visibility,
    public readonly IssueModel $issues,
    public readonly CommentModel $comments,
    public readonly CommentPolicy $policy,
    public readonly IssueActions $actions,
    public readonly RateLimiter $limiter,
    public readonly AttachmentModel $attachments
  ) {
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the name of the sender as the application shows it.
   *
   * @return string
   */
  public function displayName(): string
  {
    $name = trim((string)($this->user['first_name'] ?? '') . ' ' . (string)($this->user['last_name'] ?? ''));

    return $name !== '' ? $name : (string)$this->user['username'];
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the sender is an administrator or holds the permissions of support staff.
   *
   * @return bool
   */
  public function isPrivileged(): bool
  {
    return !empty($this->user['is_admin']) || $this->actions->isStaff();
  }
}
