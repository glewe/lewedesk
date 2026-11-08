<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\IssueModel;
use App\Models\NotificationModel;
use App\Models\NotificationPreferenceModel;
use App\Utils\IssueConfig;
use PDO;

//---------------------------------------------------------------------------
/**
 * The event service: turns what happened to an issue into in-app notifications and queued emails (see "Email and
 * notification events" in _docs/TICKET_SPEC.md).
 *
 *   issue_created   Email: reporter and the support email address (support_email). In-app: reporter and all Agents
 *   issue_assigned  Email and in-app: reporter, assignee, watchers
 *   status_changed  Email and in-app: reporter, assignee, watchers
 *   comment_added   Email and in-app: reporter, assignee, watchers
 *
 * One action sends one combined message per recipient (Resolve = comment + status change is one message), and the
 * acting user is part of the recipients like everybody else; the message names them. A recipient only gets what
 * they may see: users without access to the issue get nothing, an internal comment only goes to users holding
 * view_internal_comments. Every user can switch each event off per channel (email, in-app) in the profile; an
 * action that only consists of switched off events sends nothing to that user. A combined message holds the
 * events the user kept on.
 *
 * The emails are queued (MailOutbox) and sent after the response. A failure here never fails the action that
 * caused it: it is written to the PHP error log.
 */
class NotificationService
{
  public const CREATED = 'issue_created';
  public const ASSIGNED = 'issue_assigned';
  public const STATUS = 'status_changed';
  public const COMMENT = 'comment_added';

  /** The events in the order a combined message lists them */
  private const ORDER = [self::CREATED, self::ASSIGNED, self::STATUS, self::COMMENT];

  private PDO $db;
  private AuthService $auth;
  private IssueConfig $config;
  private NotificationModel $notifications;
  private NotificationPreferenceModel $preferences;
  private MailOutbox $outbox;
  private NotificationMail $mail;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param PDO                         $db            The database connection
   * @param AuthService                 $auth          The authentication service (the permissions of the recipients)
   * @param IssueConfig                 $config        The issue settings (issue key, support email, retention)
   * @param NotificationModel           $notifications The in-app notifications
   * @param NotificationPreferenceModel $preferences   The notification settings of the users
   * @param MailOutbox                  $outbox        The mail queue
   * @param NotificationMail            $mail          The email templates
   */
  public function __construct(
    PDO $db,
    AuthService $auth,
    IssueConfig $config,
    NotificationModel $notifications,
    NotificationPreferenceModel $preferences,
    MailOutbox $outbox,
    NotificationMail $mail
  ) {
    $this->db = $db;
    $this->auth = $auth;
    $this->config = $config;
    $this->notifications = $notifications;
    $this->preferences = $preferences;
    $this->outbox = $outbox;
    $this->mail = $mail;
  }

  //---------------------------------------------------------------------------
  /**
   * Announces an action on an issue. Call it after the action was saved.
   *
   * @param int      $issueId  The issue
   * @param int|null $actorId  The user who acted
   * @param string[] $events   The events of the action (CREATED, ASSIGNED, STATUS, COMMENT)
   * @param bool     $internal Whether the comment of the action is internal
   *
   * @return void
   */
  public function notify(int $issueId, ?int $actorId, array $events, bool $internal = false): void
  {
    try {
      $events = array_values(array_intersect(self::ORDER, $events));
      if ($events !== []) {
        $this->deliver($issueId, $actorId, $events, $internal);
      }
    } catch (\Throwable $e) {
      error_log(sprintf('Notification for issue %d failed: %s', $issueId, $e->getMessage()));
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Housekeeping: deletes the notifications past the retention period and the old mails of the queue.
   *
   * @return array{notifications: int, mails: int} What was deleted
   */
  public function purge(): array
  {
    return ['notifications' => $this->notifications->purgeExpired($this->config->notificationRetentionDays()), 'mails' => $this->outbox->purge()];
  }

  //---------------------------------------------------------------------------
  /**
   * Creates the notifications and queues the emails.
   *
   * @param int      $issueId  The issue
   * @param int|null $actorId  The user who acted
   * @param string[] $events   The events of the action, in the order of ORDER
   * @param bool     $internal Whether the comment of the action is internal
   *
   * @return void
   */
  private function deliver(int $issueId, ?int $actorId, array $events, bool $internal): void
  {
    $issue = $this->issue($issueId);
    if ($issue === null || $issue['deleted_at'] !== null) {
      return;
    }

    $message = [
      'events' => $events,
      'actor' => $actorId === null ? '' : (string)$this->displayName($actorId),
      'status_key' => $issue['status_key'],
      'status_name' => $issue['status_name'],
      'assignee' => $issue['assignee'],
      'internal' => $internal && in_array(self::COMMENT, $events, true),
    ];
    $mailIssue = ['id' => $issueId, 'key' => $this->config->issueKey($issueId), 'summary' => $issue['summary']];

    // The recipients with their channels
    $targets = [];
    if (in_array(self::CREATED, $events, true)) {
      if ($issue['reporter_id'] !== null) {
        $targets[$issue['reporter_id']] = ['email' => true, 'in_app' => true];
      }
      foreach ($this->agents() as $agentId) {
        $targets[$agentId] ??= ['email' => false, 'in_app' => true];
      }
    } else {
      foreach ($this->involved($issueId) as $userId) {
        $targets[$userId] = ['email' => true, 'in_app' => true];
      }
    }

    $users = $this->users(array_keys($targets));
    $preferences = $this->preferences->forUsers(array_keys($users));
    $mailed = [];
    $skipActor = $actorId !== null && !$this->notifiesOwnActions($actorId);
    foreach ($targets as $userId => $channels) {
      $user = $users[$userId] ?? null;
      if ($user === null || !$this->maySee($user, $issue) || ($skipActor && $userId === $actorId)) {
        continue;
      }

      // An internal comment is not for everybody who may see the issue
      $allowed = $events;
      if ($message['internal'] && !$this->auth->hasPermission($user, 'view_internal_comments')) {
        $allowed = array_values(array_diff($events, [self::COMMENT]));
      }

      foreach (['in_app', 'email'] as $channel) {
        if (!$channels[$channel]) {
          continue;
        }
        $wanted = array_values(array_filter($allowed, fn(string $event): bool => $preferences[$userId][$event][$channel]));
        if ($wanted === []) {
          continue;
        }
        $content = ['events' => $wanted] + $message;

        if ($channel === 'in_app') {
          $this->notifications->insert($userId, $issueId, $actorId, $wanted[0], $this->storedData($content), $content['internal'] && in_array(self::COMMENT, $wanted, true));
        } elseif (filter_var($user['email'], FILTER_VALIDATE_EMAIL) !== false) {
          [$subject, $html, $text] = $this->mail->build($mailIssue, $content, $this->greetingName($user));
          $this->outbox->queue($user['email'], $subject, $html, $text);
          $mailed[strtolower($user['email'])] = true;
        }
      }
    }

    // The support team hears about every new issue (not covered by user settings)
    if (in_array(self::CREATED, $events, true) && !isset($mailed[strtolower($this->config->supportEmail())])) {
      [$subject, $html, $text] = $this->mail->build($mailIssue, ['events' => [self::CREATED]] + $message, 'Support');
      $this->outbox->queue($this->config->supportEmail(), $subject, $html, $text);
    }

    $this->notifications->purgeExpired($this->config->notificationRetentionDays());
  }

  //---------------------------------------------------------------------------
  /**
   * Reads what the messages say about an issue.
   *
   * @param int $issueId The issue
   *
   * @return array|null Null if there is no such issue
   */
  private function issue(int $issueId): ?array
  {
    $stmt = $this->db->prepare(
      "SELECT i.reporter_id, i.assignee_id, i.access, i.summary, i.deleted_at, s.`key` AS status_key, s.name AS status_name,
              u.username, u.first_name, u.last_name
         FROM issues i
         JOIN `status` s ON s.id = i.status_id
         LEFT JOIN users u ON u.id = i.assignee_id
        WHERE i.id = :id"
    );
    $stmt->execute(['id' => $issueId]);
    $row = $stmt->fetch();
    if (!$row) {
      return null;
    }

    return [
      'reporter_id' => $row['reporter_id'] === null ? null : (int)$row['reporter_id'],
      'access' => $row['access'],
      'summary' => $row['summary'],
      'deleted_at' => $row['deleted_at'],
      'status_key' => $row['status_key'],
      'status_name' => $row['status_name'],
      'assignee' => $row['assignee_id'] === null ? null : IssueModel::userFields((int)$row['assignee_id'], $row['username'], $row['first_name'], $row['last_name'], null)['display_name'],
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the users involved in an issue: the reporter, the assignee and the watchers.
   *
   * @param int $issueId The issue
   *
   * @return int[]
   */
  private function involved(int $issueId): array
  {
    $stmt = $this->db->prepare(
      'SELECT reporter_id AS user_id FROM issues WHERE id = :reporter
       UNION SELECT assignee_id FROM issues WHERE id = :assignee
       UNION SELECT user_id FROM issue_watcher WHERE issue_id = :watcher'
    );
    $stmt->execute(['reporter' => $issueId, 'assignee' => $issueId, 'watcher' => $issueId]);

    return array_map('intval', array_filter($stmt->fetchAll(PDO::FETCH_COLUMN), fn($id): bool => $id !== null));
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the users with the role Agent.
   *
   * @return int[]
   */
  private function agents(): array
  {
    $stmt = $this->db->query("SELECT DISTINCT ur.user_id FROM user_role ur JOIN roles r ON r.id = ur.role_id WHERE r.name = 'Agent'");

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
  }

  //---------------------------------------------------------------------------
  /**
   * Loads the users with their roles and permissions.
   *
   * @param int[] $userIds The user ids
   *
   * @return array<int, array> The users by id (users that do not exist are missing)
   */
  private function users(array $userIds): array
  {
    $users = [];
    foreach ($userIds as $userId) {
      $user = $this->auth->userData((int)$userId);
      if ($user !== null) {
        $users[(int)$userId] = $user;
      }
    }

    return $users;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether a user may see an issue under the visibility rules (see IssueVisibility): they hold view_issue and,
   * for an issue with the access Reporter, are the reporter or see all issues.
   *
   * @param array $user  The user with permissions
   * @param array $issue The issue (see issue())
   *
   * @return bool
   */
  private function maySee(array $user, array $issue): bool
  {
    if (!$this->auth->hasPermission($user, 'view_issue')) {
      return false;
    }
    if ($issue['access'] === 'reporter' && (int)$user['id'] !== $issue['reporter_id'] && !$this->auth->hasPermission($user, 'view_all_issues')) {
      return false;
    }

    return true;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether a user wants to be notified about their own actions (the profile option notify_own_actions, on by default).
   *
   * @param int $userId The user
   *
   * @return bool
   */
  private function notifiesOwnActions(int $userId): bool
  {
    $stmt = $this->db->prepare("SELECT pref_value FROM user_preferences WHERE user_id = :user AND pref_key = 'notify_own_actions'");
    $stmt->execute(['user' => $userId]);

    return $stmt->fetchColumn() !== 'false';
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the display name of a user.
   *
   * @param int $userId The user
   *
   * @return string|null Null if the user does not exist
   */
  private function displayName(int $userId): ?string
  {
    $user = $this->auth->userData($userId);

    return $user === null ? null : IssueModel::userFields($userId, $user['username'], $user['first_name'], $user['last_name'], null)['display_name'];
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the name a user is greeted with in an email: the first name, else the username.
   *
   * @param array $user The user
   *
   * @return string
   */
  private function greetingName(array $user): string
  {
    return trim((string)$user['first_name']) !== '' ? (string)$user['first_name'] : (string)$user['username'];
  }

  //---------------------------------------------------------------------------
  /**
   * Picks what is stored with an in-app notification: the data the message is rebuilt from.
   *
   * @param array $content The message
   *
   * @return array
   */
  private function storedData(array $content): array
  {
    $data = ['events' => $content['events']];
    if (in_array(self::STATUS, $content['events'], true)) {
      $data['status_key'] = $content['status_key'];
      $data['status_name'] = $content['status_name'];
    }
    if (in_array(self::ASSIGNED, $content['events'], true)) {
      $data['assignee'] = $content['assignee'];
    }

    return $data;
  }
}
