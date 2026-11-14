<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

require_once __DIR__ . '/AuthService.php';

//---------------------------------------------------------------------------
/**
 * The user a mail is handled as. Email-to-ticket has no login: the sender of a mail is looked up by the address, and the
 * mail is then handled with exactly the rights of that user. This class stands in for AuthService, so the models and
 * rules (IssueVisibility, CommentPolicy, IssueActions, RateLimiter) can be used as they are, and a mail can never do
 * more than the same person could do in the browser.
 */
class MailActorAuth extends AuthService
{
  private ?array $actor = null;

  /**
   * Constructor.
   *
   * @param PDO $db     The database connection
   * @param int $userId The user the mail is handled as
   */
  public function __construct(PDO $db, private readonly int $userId)
  {
    parent::__construct($db, 0);
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the user of the mail with roles and permissions.
   *
   * @return array|null
   */
  public function currentUser(): ?array
  {
    return $this->actor ??= $this->userData($this->userId);
  }
}
