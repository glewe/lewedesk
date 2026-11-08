<?php

declare(strict_types=1);

namespace App\Services;

use App\Utils\IssueConfig;
use PDO;

//---------------------------------------------------------------------------
/**
 * Limits how many issues and comments a user creates per hour (see rate_limit_issues and rate_limit_comments
 * in _docs/SYSTEM_SETTINGS.md).
 *
 * Every allowed action is one row in rate_limits; the actions of the last hour are counted (a sliding
 * window). The check and the insert are one statement, so two requests at the same moment cannot both take the
 * last free place. Agents and Administrators (the support staff) are not limited, and a limit of 0 switches
 * the limit off. The database clock is used throughout, so the PHP time zone does not matter.
 */
class RateLimiter
{
  /** The action names stored in rate_limits */
  public const ISSUE = 'issue';
  public const COMMENT = 'comment';

  /** The length of the window in seconds */
  private const WINDOW = 3600;

  private PDO $db;
  private IssueConfig $config;
  private IssueActions $actions;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param PDO          $db      The database connection
   * @param IssueConfig  $config  The issue settings (the limits)
   * @param IssueActions $actions The issue rules (who counts as support staff)
   */
  public function __construct(PDO $db, IssueConfig $config, IssueActions $actions)
  {
    $this->db = $db;
    $this->config = $config;
    $this->actions = $actions;
  }

  //---------------------------------------------------------------------------
  /**
   * Counts an action of a user, unless the user has used up the limit.
   *
   * @param int    $userId The acting user
   * @param string $action RateLimiter::ISSUE or RateLimiter::COMMENT
   *
   * @return int|null Null if the action may go ahead (it is counted now), else the seconds until the next
   *                  action is possible
   */
  public function hit(int $userId, string $action): ?int
  {
    $limit = $action === self::ISSUE ? $this->config->rateLimitIssues() : $this->config->rateLimitComments();
    if ($limit === 0 || $this->actions->isStaff()) {
      return null;
    }

    // Entries outside of the window are of no use any more
    $this->db->exec('DELETE FROM rate_limits WHERE created_at < NOW() - INTERVAL ' . self::WINDOW . ' SECOND');

    $insert = $this->db->prepare(
      'INSERT INTO rate_limits (user_id, action)
       SELECT :user, :action FROM DUAL
       WHERE (SELECT COUNT(*) FROM rate_limits WHERE user_id = :user_count AND action = :action_count) < :max'
    );
    $insert->bindValue('user', $userId, PDO::PARAM_INT);
    $insert->bindValue('action', $action);
    $insert->bindValue('user_count', $userId, PDO::PARAM_INT);
    $insert->bindValue('action_count', $action);
    $insert->bindValue('max', $limit, PDO::PARAM_INT);
    $insert->execute();
    if ($insert->rowCount() > 0) {
      return null;
    }

    // The oldest action in the window leaves it first
    $oldest = $this->db->prepare(
      'SELECT TIMESTAMPDIFF(SECOND, NOW(), MIN(created_at) + INTERVAL ' . self::WINDOW . ' SECOND)
       FROM rate_limits WHERE user_id = :user AND action = :action'
    );
    $oldest->execute(['user' => $userId, 'action' => $action]);

    return max(1, (int)$oldest->fetchColumn());
  }
}
