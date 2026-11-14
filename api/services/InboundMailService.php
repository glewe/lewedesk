<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AttachmentModel;
use App\Models\CommentModel;
use App\Models\IssueModel;
use App\Models\MailInboxModel;
use App\Models\PasswordResetModel;
use App\Models\UserModel;
use App\Services\Mailbox\ImapSocketClient;
use App\Services\Mailbox\MailboxClient;
use App\Services\Mailbox\MailboxException;
use App\Utils\IssueConfig;
use App\Utils\MailInConfig;
use App\Utils\MailInSettings;
use DateTimeZone;
use PDO;

require_once __DIR__ . '/../utils/MailInConfig.php';
require_once __DIR__ . '/../utils/MailInSettings.php';
require_once __DIR__ . '/../utils/IssueConfig.php';
require_once __DIR__ . '/../models/MailInboxModel.php';
require_once __DIR__ . '/../models/UserModel.php';
require_once __DIR__ . '/../models/PasswordResetModel.php';
require_once __DIR__ . '/../models/IssueModel.php';
require_once __DIR__ . '/../models/CommentModel.php';
require_once __DIR__ . '/../models/AttachmentModel.php';
require_once __DIR__ . '/IssueHistory.php';
require_once __DIR__ . '/IssueVisibility.php';
require_once __DIR__ . '/CommentPolicy.php';
require_once __DIR__ . '/IssueActions.php';
require_once __DIR__ . '/RateLimiter.php';
require_once __DIR__ . '/AttachmentStorage.php';
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/NotificationMail.php';
require_once __DIR__ . '/MailOutbox.php';
require_once __DIR__ . '/MailActorAuth.php';
require_once __DIR__ . '/MailActor.php';
require_once __DIR__ . '/MailParser.php';
require_once __DIR__ . '/MailQuoteStripper.php';
require_once __DIR__ . '/Mailbox/ImapSocketClient.php';

//---------------------------------------------------------------------------
/**
 * Email-to-ticket (docs: _docs/EMAIL_TO_TICKET.md): reads the support mailbox and turns the mails into issues and
 * comments.
 *
 * A run connects, reads the unseen mails of the inbox (oldest first, a limited number), handles each one and moves it
 * into the folder Processed (became an issue or a comment) or Rejected (everything else). Nothing is ever deleted.
 *
 * A mail is handled as the user whose address it comes from, with exactly that user's rights (MailActor): the same
 * rules as in the browser decide whether the mail may create an issue, comment on the issue, attach files. Mails that
 * cannot be traced to a user are rejected (the unknown-sender policies create_user and anonymous come with phase 5).
 *
 * Every mail is claimed in the table mail_inbox before it is handled (its Message-ID is unique), so a mail is never
 * handled twice, even if the run dies halfway or the move out of the inbox fails.
 */
class InboundMailService
{
  /** Largest mail that is loaded, in bytes (the limit of the attachments per issue is 10 MB by default) */
  private const MAX_MAIL_BYTES = 10485760;

  /** Seconds after which a run stops taking new mails, so it never holds up the web server for long */
  private const RUN_SECONDS = 25;

  /** Whether a reply to a closed issue adds its comment (question 7 of the spec); a deleted issue never takes one */
  private const COMMENT_ON_CLOSED = true;

  /** How often a mail that failed with an error is tried (the first try included) before it is moved to Rejected */
  private const MAX_ATTEMPTS = 3;

  /** The most accounts that mails may create within an hour (policy create_user) */
  private const MAX_NEW_ACCOUNTS = 10;

  /** The account that is the reporter of the issues of senders who are not users (policy anonymous) */
  private const REQUESTER_USERNAME = 'email_requester';
  private const REQUESTER_EMAIL = 'email-requester@mailin.invalid';

  private const MAX_SUMMARY = 200;
  private const MAX_DESCRIPTION = 50000;
  private const MAX_COMMENT = 20000;

  /** @var callable */
  private $clientFactory;

  /** @var callable */
  private $isLicensed;

  /** @var callable */
  private $externalAuth;

  /**
   * Constructor.
   *
   * @param PDO                 $db            The database connection
   * @param MailInConfig        $env           The mailbox (.env)
   * @param MailInSettings      $settings      The mailin_* settings
   * @param IssueConfig         $config        The issue settings (key prefix, attachment limits, rate limits)
   * @param array               $systemSettings All system settings
   * @param MailInboxModel      $inbox         The log of the read mails
   * @param MailParser          $parser        The mail parser
   * @param NotificationService $notifier      The notifications of the application
   * @param NotificationMail    $mail          The mail templates
   * @param MailOutbox          $outbox        The mail queue
   * @param AttachmentStorage   $storage       The attachment files
   * @param AuditLog            $audit         The audit log
   * @param UserModel           $users         The users (accounts for unknown senders)
   * @param PasswordResetModel  $resets        The password reset tokens (the link in the welcome mail)
   * @param callable            $clientFactory Gives a MailboxClient (not connected yet)
   * @param callable            $isLicensed    Tells whether the license allows the feature
   * @param callable            $externalAuth  Tells whether LDAP or SSO is active (then administrators create the accounts)
   * @param string              $stampFile     File that holds the time and the result of the last run
   * @param string              $tempDir       Folder for the parts of a mail while they are checked
   */
  public function __construct(
    private readonly PDO $db,
    private readonly MailInConfig $env,
    private readonly MailInSettings $settings,
    private readonly IssueConfig $config,
    private readonly array $systemSettings,
    private readonly MailInboxModel $inbox,
    private readonly MailParser $parser,
    private readonly NotificationService $notifier,
    private readonly NotificationMail $mail,
    private readonly MailOutbox $outbox,
    private readonly AttachmentStorage $storage,
    private readonly AuditLog $audit,
    private readonly UserModel $users,
    private readonly PasswordResetModel $resets,
    callable $clientFactory,
    callable $isLicensed,
    callable $externalAuth,
    private readonly string $stampFile,
    private readonly string $tempDir
  ) {
    $this->clientFactory = $clientFactory;
    $this->isLicensed = $isLicensed;
    $this->externalAuth = $externalAuth;
  }

  //---------------------------------------------------------------------------
  /**
   * Builds the service with the socket client and the settings of the application.
   *
   * @param PDO                 $db             The database connection
   * @param array               $systemSettings All system settings
   * @param array               $env            The environment ($_ENV)
   * @param NotificationService $notifier       The notifications of the application
   * @param NotificationMail    $mail           The mail templates
   * @param MailOutbox          $outbox         The mail queue
   * @param AuditLog            $audit          The audit log
   * @param callable            $isLicensed     Tells whether the license allows the feature
   * @param callable            $externalAuth   Tells whether LDAP or SSO is active
   * @param string              $root           The application folder (the folder above api)
   *
   * @return self
   */
  public static function create(
    PDO $db,
    array $systemSettings,
    array $env,
    NotificationService $notifier,
    NotificationMail $mail,
    MailOutbox $outbox,
    AuditLog $audit,
    callable $isLicensed,
    callable $externalAuth,
    string $root
  ): self {
    $mailbox = new MailInConfig($env);

    return new self(
      $db,
      $mailbox,
      new MailInSettings($systemSettings),
      new IssueConfig($systemSettings),
      $systemSettings,
      new MailInboxModel($db),
      new MailParser(),
      $notifier,
      $mail,
      $outbox,
      new AttachmentStorage($root . '/uploads/attachments'),
      $audit,
      new UserModel($db),
      new PasswordResetModel($db),
      static fn(): MailboxClient => new ImapSocketClient(
        $mailbox->host(),
        $mailbox->port(),
        $mailbox->encryption(),
        $mailbox->user(),
        $mailbox->password(),
        $mailbox->validateCertificate()
      ),
      $isLicensed,
      $externalAuth,
      $root . '/uploads/.mailin',
      $root . '/uploads/mailin-tmp'
    );
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether Email-to-ticket is switched on in .env and allowed by the license.
   *
   * @return bool
   */
  public function isActive(): bool
  {
    return $this->env->isEnabled() && (bool)($this->isLicensed)();
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the license allows the feature.
   *
   * @return bool
   */
  public function isLicensed(): bool
  {
    return (bool)($this->isLicensed)();
  }

  //---------------------------------------------------------------------------
  /**
   * Tests the connection: logs in, lists the folders, looks at the inbox. Nothing is changed, nothing is created and no
   * mail is loaded.
   *
   * @return array{folders: int, inbox: int, unseen: int, missing: string[]} The number of folders, of mails in the inbox and
   *                                                                        of unseen ones, and which of the folders
   *                                                                        Processed and Rejected do not exist yet (the
   *                                                                        first run creates them)
   *
   * @throws MailboxException
   */
  public function testConnection(): array
  {
    $client = ($this->clientFactory)();
    try {
      $client->connect();
      $folders = $client->folders();
      $known = array_map('strtolower', $folders);
      $missing = array_values(array_filter(
        [$this->env->processedFolder(), $this->env->rejectedFolder()],
        static fn(string $name): bool => !in_array(strtolower($name), $known, true)
      ));
      $inbox = $client->selectFolder($this->env->folder());

      return ['folders' => count($folders), 'inbox' => $inbox, 'unseen' => count($client->unseenUids(100000)), 'missing' => $missing];
    } finally {
      $client->close();
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Runs when the last run is longer ago than mailin_poll_interval. Parallel requests are kept apart by a lock on the
   * stamp file, so only one of them reads the mailbox.
   *
   * @return array|null The result of the run (see run()); null if nothing was due, or the feature is off
   */
  public function runIfDue(): ?array
  {
    return $this->runLocked(false);
  }

  //---------------------------------------------------------------------------
  /**
   * Reads the mailbox now, whether a run is due or not ("Pick up now" on the settings page). The interval starts again
   * from this run. Nothing happens when another run is working on the mailbox at this moment.
   *
   * @return array|null The result of the run (see run()); null if another run is in progress, or the feature is off
   */
  public function runNow(): ?array
  {
    return $this->runLocked(true);
  }

  //---------------------------------------------------------------------------
  /**
   * Reads the mailbox under the lock of the stamp file, and writes the stamp.
   *
   * @param bool $force True to ignore the poll interval
   *
   * @return array|null The result of the run; null if nothing was due (not forced), another run is in progress, or the feature is off
   */
  private function runLocked(bool $force): ?array
  {
    if (!$this->isActive()) {
      return null;
    }
    $handle = @fopen($this->stampFile, 'c+');
    if ($handle === false) {
      return null;
    }

    try {
      if (!flock($handle, LOCK_EX | LOCK_NB)) {
        return null;
      }
      clearstatcache(true, $this->stampFile);
      if (!$force && time() - (int)filemtime($this->stampFile) < $this->settings->pollInterval()) {
        return null;
      }

      $previous = json_decode((string)stream_get_contents($handle), true);
      // Stamp first: a run that fails is tried again after the interval, not with every request
      ftruncate($handle, 0);
      rewind($handle);
      fwrite($handle, json_encode(['time' => time(), 'running' => true] + (is_array($previous) ? $previous : [])));
      fflush($handle);

      $result = $this->run();
      $this->auditFailureStreak($previous, $result);

      ftruncate($handle, 0);
      rewind($handle);
      fwrite($handle, json_encode(['time' => time(), 'running' => false, 'result' => $result]));
      fflush($handle);

      return $result;
    } finally {
      flock($handle, LOCK_UN);
      fclose($handle);
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Reads the mailbox now.
   *
   * @return array{issues: int, comments: int, rejected: int, errors: int, skipped: int, limited: bool, error: string|null} What was
   *               done; "limited" tells that the hourly limit was reached (the rest of the mails waits in the inbox), "error"
   *               is the reason why the mailbox could not be read
   */
  public function run(): array
  {
    $result = ['issues' => 0, 'comments' => 0, 'rejected' => 0, 'errors' => 0, 'skipped' => 0, 'limited' => false, 'error' => null];
    if (!$this->isActive()) {
      return $result;
    }

    $started = microtime(true);
    $client = ($this->clientFactory)();
    try {
      $client->connect();
      $client->ensureFolder($this->env->processedFolder());
      $client->ensureFolder($this->env->rejectedFolder());
      $client->selectFolder($this->env->folder());
      foreach ($client->unseenUids($this->settings->maxMailsPerRun()) as $uid) {
        if (microtime(true) - $started > self::RUN_SECONDS) {
          break;
        }
        // A flood of mails must not become a flood of issues: what is over the limit waits in the inbox for the next hour
        $limit = $this->settings->hourlyLimit();
        if ($limit > 0 && $this->inbox->acceptedSince(3600) >= $limit) {
          $result['limited'] = true;
          break;
        }
        $this->handle($client, $uid, $result);
      }
    } catch (MailboxException $e) {
      $result['error'] = $e->getMessage();
      error_log('Email to ticket: ' . $e->getMessage());
    } finally {
      $client->close();
    }

    // The mails that were queued by the notifications and confirmations go out now
    try {
      if ($this->outbox->hasQueued()) {
        $this->outbox->process();
      }
    } catch (\Throwable $e) {
      error_log('Email to ticket: mails could not be sent: ' . $e->getMessage());
    }

    return $result;
  }

  //---------------------------------------------------------------------------
  /**
   * Handles one mail of the inbox.
   *
   * @param MailboxClient $client The mailbox, with the inbox selected
   * @param int           $uid    The UID of the mail
   * @param array         $result The counters of the run
   *
   * @return void
   */
  private function handle(MailboxClient $client, int $uid, array &$result): void
  {
    if ($client->size($uid) > self::MAX_MAIL_BYTES) {
      $this->reject($client, $uid, '<uid-' . $uid . '-' . bin2hex(random_bytes(4)) . '@mailin.invalid>', '', '', 'The mail is larger than 10 MB', $result);

      return;
    }

    $mail = $this->parser->parse($client->fetchRaw($uid));

    $known = $this->inbox->find($mail->messageId);
    $attempts = 1;
    if ($known !== null && $this->canRetry($known)) {
      // It failed before, without creating anything: another try
      $row = (int)$known['id'];
      $attempts = (int)$known['attempts'] + 1;
      $this->inbox->retry($row);
    } elseif ($known !== null) {
      // Handled before (the move out of the inbox did not work then), or failed for good
      if ($known['result'] === 'error') {
        $this->inbox->finish((int)$known['id'], 'error', mb_substr('Gave up: ' . (string)$known['reason'], 0, 255), null, null);
      }
      $this->file($client, $uid, in_array($known['result'], ['issue', 'comment'], true) ? $this->env->processedFolder() : $this->env->rejectedFolder());
      $result['skipped']++;

      return;
    } else {
      $received = $mail->date?->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
      $row = $this->inbox->claim($mail->messageId, $mail->fromAddress, $mail->subject, $received);
      if ($row === null) {
        $result['skipped']++;

        return;
      }
    }

    try {
      $outcome = $this->decide($mail);
    } catch (\Throwable $e) {
      error_log('Email to ticket: ' . $e::class . ': ' . $e->getMessage());
      $outcome = ['result' => 'error', 'reason' => 'Internal error: ' . $e->getMessage()];
    }

    $this->inbox->finish($row, $outcome['result'], $outcome['reason'] ?? null, $outcome['issue_id'] ?? null, $outcome['comment_id'] ?? null);
    match ($outcome['result']) {
      'issue' => $result['issues']++,
      'comment' => $result['comments']++,
      'error' => $result['errors']++,
      default => $result['rejected']++,
    };
    if (in_array($outcome['result'], ['rejected', 'error'], true)) {
      $this->audit->logQuiet('mail.rejected', [], 'mail', null, $mail->fromAddress, null, ['reason' => $outcome['reason'] ?? '', 'subject' => mb_substr($mail->subject, 0, 100)], 'failure');
    }

    // A mail that failed stays in the inbox for the next run, until it has been tried MAX_ATTEMPTS times
    if ($outcome['result'] === 'error' && $attempts < self::MAX_ATTEMPTS) {
      return;
    }
    $this->file($client, $uid, in_array($outcome['result'], ['issue', 'comment'], true) ? $this->env->processedFolder() : $this->env->rejectedFolder());
  }

  //---------------------------------------------------------------------------
  /**
   * Rejects a mail that was not read (too large): logs it and moves it.
   *
   * @param MailboxClient $client    The mailbox
   * @param int           $uid       The UID
   * @param string        $messageId A made-up id for the log
   * @param string        $from      The sender, if known
   * @param string        $subject   The subject, if known
   * @param string        $reason    Why
   * @param array         $result    The counters of the run
   *
   * @return void
   */
  private function reject(MailboxClient $client, int $uid, string $messageId, string $from, string $subject, string $reason, array &$result): void
  {
    $row = $this->inbox->claim($messageId, $from, $subject, null);
    if ($row !== null) {
      $this->inbox->finish($row, 'rejected', $reason, null, null);
      $this->audit->logQuiet('mail.rejected', [], 'mail', null, $from !== '' ? $from : 'uid ' . $uid, null, ['reason' => $reason], 'failure');
    }
    $result['rejected']++;
    $this->file($client, $uid, $this->env->rejectedFolder());
  }

  //---------------------------------------------------------------------------
  /**
   * Marks a mail as seen and moves it to a folder. A failure here is logged, not thrown: the mail is in the log, so
   * the next run only moves it again.
   *
   * @param MailboxClient $client The mailbox
   * @param int           $uid    The UID
   * @param string        $folder The target folder
   *
   * @return void
   */
  private function file(MailboxClient $client, int $uid, string $folder): void
  {
    try {
      $client->markSeen($uid);
      $client->moveTo($uid, $folder);
    } catch (MailboxException $e) {
      error_log('Email to ticket: mail ' . $uid . ' could not be moved to ' . $folder . ': ' . $e->getMessage());
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether a mail that was seen before is tried again: it failed with an error, nothing was created for it (a row
   * that still says "processing" belongs to a run that died, and may have created something), and it was not tried
   * too often.
   *
   * @param array $known The row of the mail in mail_inbox
   *
   * @return bool
   */
  private function canRetry(array $known): bool
  {
    return $known['result'] === 'error' && $known['reason'] !== 'processing' && (int)$known['attempts'] < self::MAX_ATTEMPTS;
  }

  //---------------------------------------------------------------------------
  /**
   * Decides what becomes of a mail and does it.
   *
   * @param ParsedMail $mail The mail
   *
   * @return array{result: string, reason?: string|null, issue_id?: int|null, comment_id?: int|null}
   */
  protected function decide(ParsedMail $mail): array
  {
    if ($mail->isBounce || $mail->isAutoReply || $mail->isList) {
      return $this->refused('Automatic mail (auto-reply, delivery report or list mail)');
    }
    if ($mail->fromAddress === '') {
      return $this->refused('The mail has no valid sender address');
    }
    if ($mail->isFrom(...$this->ownAddresses())) {
      return $this->refused('The mail comes from the application itself');
    }

    $domain = substr((string)strrchr($mail->fromAddress, '@'), 1);
    if ($this->domainIn($domain, $this->settings->blockedDomains())) {
      return $this->refused('The domain of the sender is blocked');
    }
    $allowed = $this->settings->allowedDomains();
    if ($allowed !== [] && !$this->domainIn($domain, $allowed)) {
      return $this->refused('The domain of the sender is not on the list of allowed domains');
    }

    $userId = $this->userIdByEmail($mail->fromAddress);
    if ($userId === null) {
      return $this->fromUnknownSender($mail);
    }
    $actor = $this->actor($userId);
    if ($actor === null) {
      return $this->refused('The sender is not a user');
    }
    if (!$this->senderIsVerified($actor, $mail)) {
      return $this->refused('The sender address could not be verified (SPF, DKIM, DMARC)');
    }

    $target = $this->target($mail);
    if (isset($target['reject'])) {
      return $this->refused($target['reject']);
    }

    return isset($target['issue'])
      ? $this->addComment($mail, $actor, (int)$target['issue'])
      : $this->createIssue($mail, $actor);
  }

  //---------------------------------------------------------------------------
  /**
   * Gives a rejection.
   *
   * @param string $reason Why the mail was refused
   *
   * @return array{result: string, reason: string}
   */
  private function refused(string $reason): array
  {
    return ['result' => 'rejected', 'reason' => $reason];
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether a domain is on a list. An entry matches the domain itself and the domains below it
   * ("example.com" matches "mail.example.com").
   *
   * @param string   $domain The domain of the sender
   * @param string[] $list   The entries, lower case
   *
   * @return bool
   */
  private function domainIn(string $domain, array $list): bool
  {
    foreach ($list as $entry) {
      if ($domain === $entry || str_ends_with($domain, '.' . $entry)) {
        return true;
      }
    }

    return false;
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the addresses that belong to the application itself; a mail from one of them is never handled (mail loop).
   *
   * @return string[]
   */
  private function ownAddresses(): array
  {
    return array_values(array_filter([
      strtolower(trim((string)($_ENV['MAIL_FROM'] ?? ''))),
      strtolower($this->env->user()),
    ], static fn(string $a): bool => $a !== ''));
  }

  //---------------------------------------------------------------------------
  /**
   * Finds the user with an email address.
   *
   * @param string $address The address, lower case
   *
   * @return int|null
   */
  private function userIdByEmail(string $address): ?int
  {
    $stmt = $this->db->prepare('SELECT id FROM users WHERE LOWER(email) = :email');
    $stmt->execute(['email' => $address]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // The address is unique in the database; more than one match would be a damaged database, so nobody is guessed
    return count($ids) === 1 ? (int)$ids[0] : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Handles a mail from an address that no user has, as the setting mailin_unknown_sender says:
   *   reject       the mail is refused
   *   create_user  an account (role User, no password yet) is made for the address and the mail is handled as that user
   *   anonymous    the mail is handled as the account "Email requester"; the address is kept with the issue for support
   *
   * Both policies need the proof of our own mail server that the sender owns the address (mailin_trust_sender does not
   * count here): otherwise anybody could make accounts, and have mails sent, in the name of others. A mail from an
   * unknown sender can start an issue, but never answer one of a user.
   *
   * @param ParsedMail $mail The mail
   *
   * @return array{result: string, reason?: string|null, issue_id?: int|null, comment_id?: int|null}
   */
  private function fromUnknownSender(ParsedMail $mail): array
  {
    $policy = $this->settings->unknownSenderPolicy();
    if ($policy === 'reject') {
      return $this->refused('The sender is not a user');
    }
    if (!$this->verifiedByServer($mail)) {
      return $this->refused('The sender is not a user, and the address could not be verified (SPF, DKIM, DMARC)');
    }

    $target = $this->target($mail);
    if (isset($target['reject'])) {
      return $this->refused($target['reject']);
    }

    if ($policy === 'anonymous') {
      $actor = $this->actor($this->requesterUserId());
      if ($actor === null) {
        return ['result' => 'error', 'reason' => 'The account "Email requester" could not be made'];
      }
      if (isset($target['issue'])) {
        return $this->isRequesterOf((int)$target['issue'], $mail->fromAddress)
          ? $this->addComment($mail, $actor, (int)$target['issue'])
          : $this->refused('The sender is not the requester of that issue');
      }

      return $this->createIssue($mail, $actor, $mail->fromAddress);
    }

    // create_user
    if (isset($target['issue'])) {
      return $this->refused('A sender who is not a user cannot answer an issue');
    }
    if ((bool)($this->externalAuth)()) {
      return $this->refused('The sender is not a user, and accounts are made by an administrator while LDAP or SSO is active');
    }
    if (strlen($mail->fromAddress) > 100) {
      return $this->refused('The address of the sender is too long for an account');
    }
    if ($this->accountsCreatedSince(3600) >= self::MAX_NEW_ACCOUNTS) {
      return $this->refused('Too many accounts were made from mails in the last hour');
    }

    $actor = $this->actor($this->createAccount($mail));

    return $actor === null ? ['result' => 'error', 'reason' => 'The account could not be made'] : $this->createIssue($mail, $actor);
  }

  //---------------------------------------------------------------------------
  /**
   * Makes an account for the sender of a mail: role User, a username from the address, the name from the From header, a
   * password nobody knows. The person gets a mail with the link to set one.
   *
   * @param ParsedMail $mail The mail
   *
   * @return int The new user
   */
  private function createAccount(ParsedMail $mail): int
  {
    $username = $this->freeUsername($mail->fromAddress);
    [$first, $last] = $this->namesOf($mail->fromName);

    $this->users->create([
      'username' => $username,
      'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
      'email' => $mail->fromAddress,
      'first_name' => $first,
      'last_name' => $last,
    ]);
    $user = $this->users->findByEmail($mail->fromAddress);
    if ($user === null) {
      throw new \RuntimeException('The account could not be read back');
    }
    $userId = (int)$user['id'];
    $this->users->assignRole($userId, 'User');
    // Not quiet: the entries are what limits the accounts per hour
    $this->audit->log('mail.user_created', [], 'user', $userId, $username, null, ['email' => $mail->fromAddress]);

    try {
      $token = $this->resets->create($userId, 7 * 86400);
      [$subject, $html, $text] = $this->mail->buildWelcome($first !== '' ? $first : $username, $this->mail->resetLink($token));
      $this->outbox->queue($mail->fromAddress, $subject, $html, $text);
    } catch (\Throwable $e) {
      error_log('Email to ticket: the welcome mail for ' . $username . ' could not be queued: ' . $e->getMessage());
    }

    return $userId;
  }

  //---------------------------------------------------------------------------
  /**
   * Finds a username that is not taken, based on the part of the address before the @.
   *
   * @param string $address The email address
   *
   * @return string 3 to 50 characters (letters, digits, _ . -)
   */
  private function freeUsername(string $address): string
  {
    $base = trim(preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string)strstr($address, '@', true)) ?? '', '_.-');
    $base = mb_substr($base, 0, 40);
    if (strlen($base) < 3) {
      $base = 'user' . $base;
    }

    $username = $base;
    for ($n = 2; $this->users->findByUsername($username) !== null; $n++) {
      $username = $base . $n;
    }

    return $username;
  }

  //---------------------------------------------------------------------------
  /**
   * Splits the display name of a sender into first and last name ("Anna Müller", "Müller, Anna", "Anna").
   *
   * @param string $name The name from the From header
   *
   * @return array{0: string, 1: string}
   */
  private function namesOf(string $name): array
  {
    $name = trim(preg_replace('/[\x00-\x1F\x7F"<>@]+/u', ' ', $name) ?? '');
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '') {
      return ['', ''];
    }
    if (str_contains($name, ',')) {
      [$last, $first] = array_map('trim', explode(',', $name, 2));
    } elseif (($space = strrpos($name, ' ')) !== false) {
      $first = trim(substr($name, 0, $space));
      $last = trim(substr($name, $space + 1));
    } else {
      $first = $name;
      $last = '';
    }

    return [mb_substr($first, 0, 50), mb_substr($last, 0, 50)];
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the account "Email requester", the reporter of the issues of senders who are not users. It is made when it is
   * needed. Nobody can sign in with it (the password is unknown) and mails to it are not sent (an address that does not
   * exist).
   *
   * @return int The user
   */
  private function requesterUserId(): int
  {
    $existing = $this->users->findByUsername(self::REQUESTER_USERNAME);
    if ($existing !== null) {
      return (int)$existing['id'];
    }

    $this->users->create([
      'username' => self::REQUESTER_USERNAME,
      'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
      'email' => self::REQUESTER_EMAIL,
      'first_name' => 'Email',
      'last_name' => 'requester',
    ]);
    $user = $this->users->findByUsername(self::REQUESTER_USERNAME);
    $userId = (int)($user['id'] ?? 0);
    $this->users->assignRole($userId, 'User');
    $this->audit->logQuiet('mail.requester_created', [], 'user', $userId, self::REQUESTER_USERNAME);

    return $userId;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether an address is the one an issue was started from (policy anonymous).
   *
   * @param int    $issueId The issue
   * @param string $address The address of the sender
   *
   * @return bool
   */
  private function isRequesterOf(int $issueId, string $address): bool
  {
    $stmt = $this->db->prepare('SELECT requester_email FROM issues WHERE id = :id');
    $stmt->execute(['id' => $issueId]);
    $requester = $stmt->fetchColumn();

    return is_string($requester) && $requester !== '' && strcasecmp($requester, $address) === 0;
  }

  //---------------------------------------------------------------------------
  /**
   * Counts the accounts that mails made since a point in time.
   *
   * @param int $seconds How far back
   *
   * @return int
   */
  private function accountsCreatedSince(int $seconds): int
  {
    $stmt = $this->db->prepare("SELECT COUNT(*) FROM audit_log WHERE event = 'mail.user_created' AND created_at > NOW() - INTERVAL :seconds SECOND");
    $stmt->bindValue('seconds', $seconds, PDO::PARAM_INT);
    $stmt->execute();

    return (int)$stmt->fetchColumn();
  }

  //---------------------------------------------------------------------------
  /**
   * Builds the objects that handle a mail as a user.
   *
   * @param int $userId The sender
   *
   * @return MailActor|null Null if the user is gone
   */
  private function actor(int $userId): ?MailActor
  {
    $auth = new MailActorAuth($this->db, $userId);
    $user = $auth->currentUser();
    if ($user === null) {
      return null;
    }

    $visibility = new IssueVisibility($auth, ($this->systemSettings['under_maintenance'] ?? 'false') === 'true');
    $policy = new CommentPolicy($visibility);
    $actions = new IssueActions($visibility, $policy);

    return new MailActor(
      $userId,
      $user,
      $visibility,
      new IssueModel($this->db, $visibility, $this->config),
      new CommentModel($this->db, $visibility),
      $policy,
      $actions,
      new RateLimiter($this->db, $this->config, $actions),
      new AttachmentModel($this->db, $visibility, $this->audit)
    );
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the From address of the mail can be believed. A From address is easy to forge, so the receiving server
   * must have verified it (DMARC, or SPF and DKIM together). Without that, a user is accepted only if the administrator
   * trusts the senders (mailin_trust_sender), and support staff and administrators never.
   *
   * @param MailActor  $actor The sender
   * @param ParsedMail $mail  The mail
   *
   * @return bool
   */
  private function senderIsVerified(MailActor $actor, ParsedMail $mail): bool
  {
    if ($this->verifiedByServer($mail)) {
      return true;
    }

    return $this->settings->trustSender() && !$actor->isPrivileged();
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether our own mail server vouches for the From address: it checked the mail and wrote DMARC pass, or SPF pass
   * and DKIM pass. The result counts only if our own mail server wrote it: the sender can add any Authentication-Results
   * header, so the name in it must be one of MAILIN_AUTH_SERVER.
   *
   * @param ParsedMail $mail The mail
   *
   * @return bool
   */
  private function verifiedByServer(ParsedMail $mail): bool
  {
    return $this->env->trustsAuthServer($mail->authServer)
      && ($mail->authResult('dmarc') === 'pass' || ($mail->authResult('spf') === 'pass' && $mail->authResult('dkim') === 'pass'));
  }

  //---------------------------------------------------------------------------
  /**
   * Finds the issue a mail belongs to: first by the mails it answers (the Message-IDs of the notifications that were
   * sent, and of earlier mails), then by the issue key in the subject.
   *
   * @param ParsedMail $mail The mail
   *
   * @return array{issue?: int, reject?: string} The issue; a reason if the mail must not be used; empty for a new issue
   */
  private function target(ParsedMail $mail): array
  {
    $ids = array_values(array_unique(array_filter([$mail->inReplyTo, ...array_reverse($mail->references)])));
    if ($ids !== []) {
      $marks = implode(', ', array_fill(0, count($ids), '?'));
      $stmt = $this->db->prepare("SELECT message_id, issue_id, is_internal FROM mail_outbox WHERE issue_id IS NOT NULL AND message_id IN ($marks)");
      $stmt->execute($ids);
      $sent = [];
      foreach ($stmt->fetchAll() as $row) {
        $sent[(string)$row['message_id']] = $row;
      }
      $received = $this->inbox->issuesOf($ids);

      // The newest mail that is known decides
      foreach ($ids as $id) {
        $issueId = isset($sent[$id]) ? (int)$sent[$id]['issue_id'] : ($received[$id] ?? null);
        if ($issueId === null || !$this->issueExists($issueId)) {
          continue;
        }
        if (isset($sent[$id]) && (int)$sent[$id]['is_internal'] === 1) {
          return ['reject' => 'The mail answers a notification about an internal comment'];
        }

        return ['issue' => $issueId];
      }
    }

    foreach ($mail->issueIdsInSubject($this->config->keyPrefix()) as $issueId) {
      if ($this->issueExists($issueId)) {
        return ['issue' => $issueId];
      }
    }

    return [];
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether an issue exists (whoever may see it).
   *
   * @param int $issueId The issue
   *
   * @return bool
   */
  private function issueExists(int $issueId): bool
  {
    $stmt = $this->db->prepare('SELECT 1 FROM issues WHERE id = :id');
    $stmt->execute(['id' => $issueId]);

    return $stmt->fetchColumn() !== false;
  }

  //---------------------------------------------------------------------------
  /**
   * Creates an issue from a mail.
   *
   * @param ParsedMail  $mail           The mail
   * @param MailActor   $actor          The sender, or the account "Email requester" for a sender who is not a user
   * @param string|null $requesterEmail The address of a sender who is not a user (kept with the issue); null otherwise
   *
   * @return array{result: string, reason?: string|null, issue_id?: int|null, comment_id?: int|null}
   */
  private function createIssue(ParsedMail $mail, MailActor $actor, ?string $requesterEmail = null): array
  {
    if (!$actor->visibility->hasPermission('create_issue')) {
      return $this->refused('The sender may not create issues (permission create_issue)');
    }
    $typeId = $this->issueTypeId($actor);
    if ($typeId === null) {
      return ['result' => 'error', 'reason' => 'There is no active issue type'];
    }

    $summary = trim(preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $mail->cleanSubject()) ?? '');
    $summary = $summary === '' ? '(no subject)' : mb_substr($summary, 0, self::MAX_SUMMARY);
    $text = $this->limited($mail->text, self::MAX_DESCRIPTION);
    if ($text === '') {
      $text = '(no text)';
    }

    $wait = $actor->limiter->hit($actor->userId, RateLimiter::ISSUE);
    if ($wait !== null) {
      return $this->refused('Too many issues in the last hour for this sender');
    }

    $productId = $this->settings->defaultProductId();
    $issueId = $actor->issues->create([
      'type_id' => $typeId,
      'summary' => $summary,
      'description' => $text,
      'access' => $this->settings->defaultAccess(),
      'product_id' => $productId !== null && $actor->issues->lookupExists('products', $productId) ? $productId : null,
      'version' => null,
    ], $actor->userId);

    // The issue exists now. Whatever goes wrong from here on is logged but does not fail the mail: a second try would
    // create the issue a second time
    try {
      $this->db->prepare("UPDATE issues SET source = 'email', requester_email = :requester WHERE id = :id")->execute(['requester' => $requesterEmail, 'id' => $issueId]);

      [$final, $skipped] = $this->attach($actor, $issueId, $mail, $text, false);
      if ($final !== $text) {
        $this->db->prepare('UPDATE issues SET description = :description WHERE id = :id')
          ->execute(['description' => mb_substr($final, 0, self::MAX_DESCRIPTION + 1000), 'id' => $issueId]);
      }

      $this->notifier->notify($issueId, $actor->userId, [NotificationService::CREATED]);
      $to = $requesterEmail ?? (string)($actor->user['email'] ?? '');
      if ($this->settings->sendConfirmation() && filter_var($to, FILTER_VALIDATE_EMAIL) !== false) {
        [$subject, $html, $plain] = $this->mail->buildReceived(
          ['id' => $issueId, 'key' => $this->config->issueKey($issueId), 'summary' => $summary],
          $requesterEmail === null ? $actor->displayName() : ($mail->fromName !== '' ? $mail->fromName : $requesterEmail),
          $skipped
        );
        $this->outbox->queue($to, $subject, $html, $plain, $issueId);
      }
    } catch (\Throwable $e) {
      error_log('Email to ticket: issue ' . $issueId . ' was created, but not finished: ' . $e->getMessage());
    }

    return ['result' => 'issue', 'issue_id' => $issueId];
  }

  //---------------------------------------------------------------------------
  /**
   * Adds the text of a mail as a comment to an issue.
   *
   * @param ParsedMail $mail    The mail
   * @param MailActor  $actor   The sender
   * @param int        $issueId The issue
   *
   * @return array{result: string, reason?: string|null, issue_id?: int|null, comment_id?: int|null}
   */
  private function addComment(ParsedMail $mail, MailActor $actor, int $issueId): array
  {
    // The same answer for an issue that does not exist and one the sender may not see: nothing is given away
    $issue = $actor->comments->issue($issueId);
    if ($issue === null) {
      return $this->refused('The issue does not exist or the sender may not see it');
    }
    $blocked = $actor->policy->blockedReason($issue);
    if ($blocked === 'deleted' || ($blocked === 'closed' && !self::COMMENT_ON_CLOSED)) {
      return $this->refused($blocked === 'deleted' ? 'The issue is deleted' : 'The issue is closed');
    }
    if (!$actor->policy->mayComment()) {
      return $this->refused('The sender may not comment (permission comment_issue)');
    }

    $text = $this->limited(MailQuoteStripper::strip($mail->text), self::MAX_COMMENT);
    if ($text === '' && $mail->attachments === []) {
      return $this->refused('The reply has no text');
    }
    if ($text === '') {
      $text = '(no text)';
    }

    $wait = $actor->limiter->hit($actor->userId, RateLimiter::COMMENT);
    if ($wait !== null) {
      return $this->refused('Too many comments in the last hour for this sender');
    }

    // A problem with the attachments must not lose the text of the reply
    try {
      [$final] = $this->attach($actor, $issueId, $mail, $text, true);
    } catch (\Throwable $e) {
      error_log('Email to ticket: attachments of a reply to issue ' . $issueId . ' failed: ' . $e->getMessage());
      $final = $this->withoutImageReferences($text, []);
    }
    $commentId = $actor->comments->create($issueId, $actor->userId, mb_substr($final, 0, self::MAX_COMMENT + 1000), false);
    // The comment exists now: a problem with the notifications is logged, a second try would add it a second time
    try {
      $this->notifier->notify($issueId, $actor->userId, [NotificationService::COMMENT], false);
    } catch (\Throwable $e) {
      error_log('Email to ticket: notification for comment ' . $commentId . ' failed: ' . $e->getMessage());
    }

    return ['result' => 'comment', 'issue_id' => $issueId, 'comment_id' => $commentId];
  }

  //---------------------------------------------------------------------------
  /**
   * Stores the attachments of a mail with the issue and puts the inline images into the text.
   *
   * Each file is checked like an upload in the browser (type, content, size and the limits of the issue). A file that is
   * not accepted never stops the mail: it is left out, and the text gets a note.
   *
   * @param MailActor  $actor   The sender
   * @param int        $issueId The issue
   * @param ParsedMail $mail    The mail
   * @param string     $text    The text of the issue or comment, with ![alt](cid:ID) for inline images
   * @param bool       $reply   Whether the text is a reply, where the pictures of a quoted signature are left out
   *
   * @return array{0: string, 1: string[]} The final text, and the files that were not added (name and reason)
   */
  private function attach(MailActor $actor, int $issueId, ParsedMail $mail, string $text, bool $reply): array
  {
    if ($mail->attachments === []) {
      return [$this->withoutImageReferences($text, []), []];
    }

    $issue = $actor->issues->find($issueId);
    $denial = $issue === null ? [403, 'No access to the issue'] : $actor->actions->attachDenial($actor->actions->facts($issue));
    $skipped = [];
    $inline = [];

    foreach ($mail->attachments as $attachment) {
      $name = AttachmentStorage::cleanName($attachment->filename);
      $referenced = $attachment->contentId !== null && str_contains($text, '(cid:' . $attachment->contentId . ')');
      // A picture of the quoted history (the signature of an earlier mail) is not part of the reply
      if ($reply && $attachment->inline && $attachment->referenced && !$referenced) {
        continue;
      }
      if ($denial !== null) {
        $skipped[] = $name . ' (' . $denial[1] . ')';
        continue;
      }

      $stored = $this->storeAttachment($actor, $issueId, $name, $attachment->data, $referenced);
      if (is_string($stored)) {
        $skipped[] = $name . ' (' . $stored . ')';
      } elseif ($referenced) {
        $inline[(string)$attachment->contentId] = ['id' => $stored, 'name' => $name];
      }
    }

    $text = $this->withoutImageReferences($text, $inline);
    if ($skipped !== []) {
      $text .= "\n\n> **Note:** " . count($skipped) . ' attachment(s) were not added: ' . implode('; ', $skipped);
    }

    return [$text, $skipped];
  }

  //---------------------------------------------------------------------------
  /**
   * Checks one file and stores it with the issue.
   *
   * @param MailActor $actor   The sender
   * @param int       $issueId The issue
   * @param string    $name    The file name
   * @param string    $data    The content
   * @param bool      $inline  Whether the file is an inline image
   *
   * @return int|string The attachment id, or the reason why the file was not added
   */
  private function storeAttachment(MailActor $actor, int $issueId, string $name, string $data, bool $inline): int|string
  {
    if (!is_dir($this->tempDir) && !@mkdir($this->tempDir, 0775, true) && !is_dir($this->tempDir)) {
      return 'no place to check the file';
    }
    $tmp = tempnam($this->tempDir, 'm');
    if ($tmp === false || file_put_contents($tmp, $data) === false) {
      return 'the file could not be written';
    }

    $file = $this->storage->inspectLocal($tmp, $name, $this->config->attachmentMaxSize());
    if (isset($file['error'])) {
      @unlink($tmp);

      return $file['error'][1];
    }
    $stored = $this->storage->storeLocal($tmp);
    if ($stored === null) {
      @unlink($tmp);

      return 'the file could not be stored';
    }

    $id = $actor->attachments->addToIssue(
      $issueId,
      ['name' => $file['name'], 'mime' => $file['mime'], 'size' => $file['size']],
      $stored,
      $actor->userId,
      $inline,
      false,
      $this->config->attachmentMaxCount(),
      $this->config->attachmentMaxTotalSize()
    );
    if (is_string($id)) {
      $this->storage->remove($stored);
    }

    return $id;
  }

  //---------------------------------------------------------------------------
  /**
   * Replaces ![alt](cid:ID) in a text: by the application's inline image syntax for the pictures that were stored, by
   * nothing for all others.
   *
   * @param string                                    $text   The text
   * @param array<string, array{id: int, name: string}> $stored The stored pictures by Content-ID
   *
   * @return string
   */
  private function withoutImageReferences(string $text, array $stored): string
  {
    return preg_replace_callback('/!\[([^\]]*)\]\(cid:([^)]*)\)/', static function (array $m) use ($stored): string {
      if (!isset($stored[$m[2]])) {
        return '';
      }
      $alt = trim(str_replace(['[', ']'], '', pathinfo($stored[$m[2]]['name'], PATHINFO_FILENAME)));

      return '![' . ($alt !== '' ? $alt : 'image') . '](attachment:' . $stored[$m[2]]['id'] . ')';
    }, $text) ?? $text;
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the issue type of a new issue: the configured one, if it is active, else the first active one.
   *
   * @param MailActor $actor The sender
   *
   * @return int|null
   */
  private function issueTypeId(MailActor $actor): ?int
  {
    $configured = $this->settings->defaultTypeId();
    if ($configured !== null && $actor->issues->lookupExists('issue_types', $configured)) {
      return $configured;
    }
    $id = $this->db->query('SELECT id FROM issue_types WHERE active = 1 ORDER BY sort_order, id LIMIT 1')->fetchColumn();

    return $id === false ? null : (int)$id;
  }

  //---------------------------------------------------------------------------
  /**
   * Limits a text to the size the application takes: mailin_max_body_size bytes and the maximum of the field.
   *
   * @param string $text     The text
   * @param int    $maxChars The longest text in characters
   *
   * @return string The trimmed text, cut with a marker if it was too long
   */
  private function limited(string $text, int $maxChars): string
  {
    $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
    $cut = false;
    if (strlen($text) > $this->settings->maxBodyBytes()) {
      $text = mb_strcut($text, 0, $this->settings->maxBodyBytes(), 'UTF-8');
      $cut = true;
    }
    if (mb_strlen($text) > $maxChars - 10) {
      $text = mb_substr($text, 0, $maxChars - 10);
      $cut = true;
    }

    return $cut ? rtrim($text) . "\n\n[…]" : $text;
  }

  //---------------------------------------------------------------------------
  /**
   * Writes one audit entry when the mailbox could not be read and the run before could (not for every failure).
   *
   * @param mixed $previous The content of the stamp file before the run
   * @param array $result   The result of the run
   *
   * @return void
   */
  private function auditFailureStreak(mixed $previous, array $result): void
  {
    $failedBefore = is_array($previous) && !empty($previous['result']['error']);
    if ($result['error'] !== null && !$failedBefore) {
      $this->audit->logQuiet('mail.fetch_failed', [], 'mail', null, $this->env->host(), null, ['error' => $result['error']], 'failure');
    }
    $limitedBefore = is_array($previous) && !empty($previous['result']['limited']);
    if (!empty($result['limited']) && !$limitedBefore) {
      $this->audit->logQuiet('mail.limit_hit', [], 'mail', null, $this->env->host(), null, ['limit_per_hour' => $this->settings->hourlyLimit()], 'failure');
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the time and the result of the last run (for the settings page).
   *
   * @return array{time: int, running: bool, result: array|null}|null Null if there was no run yet
   */
  public function status(): ?array
  {
    $content = @file_get_contents($this->stampFile);
    $status = is_string($content) ? json_decode($content, true) : null;

    return is_array($status) && isset($status['time']) ? $status + ['running' => false, 'result' => null] : null;
  }
}
