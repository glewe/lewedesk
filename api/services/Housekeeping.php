<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AttachmentModel;
use App\Models\GitHubCommitCacheModel;
use App\Models\MailInboxModel;
use App\Utils\IssueConfig;

/**
 * The periodic cleanup: tells people about SLA timers that are at risk or breached (SlaNotifier), sends the waiting and retried mails, deletes the notifications past the retention period, the
 * old mails, the forgotten temporary uploads and the audit log entries past their retention period.
 *
 * It needs no cron job: the API calls runIfDue() after every response, and the first request after the interval runs
 * it (the open browser pages poll the notifications, so there is always traffic while somebody works with the app).
 * The scripts api/housekeeping.php and api/cleanup_uploads.php remain as an option for sites that want a fixed schedule.
 */
class Housekeeping
{
  /** Seconds between two runs */
  private const INTERVAL = 300;

  public function __construct(
    private readonly MailOutbox $outbox,
    private readonly NotificationService $notifications,
    private readonly AttachmentModel $attachments,
    private readonly AttachmentStorage $storage,
    private readonly IssueConfig $config,
    private readonly AuditLog $audit,
    private readonly string $stampFile,
    private readonly ?MailInboxModel $mailInbox = null,
    private readonly ?SlaNotifier $sla = null,
    private readonly ?GitHubCommitCacheModel $githubCache = null
  ) {
  }

  //---------------------------------------------------------------------------
  /**
   * Runs the cleanup when the last run is longer ago than the interval. Parallel requests are kept apart by a lock on
   * the stamp file, so only one of them runs it.
   *
   * @return bool Whether this call did the cleanup
   */
  public function runIfDue(): bool
  {
    return $this->execute(false) !== null;
  }

  //---------------------------------------------------------------------------
  /**
   * Runs the cleanup now, whatever the interval says (the button on the system settings page). It starts the interval
   * again, like a run that was due. Parallel runs are kept apart by the same lock.
   *
   * @return array|null What run() gives; null if another run is in progress
   */
  public function runNow(): ?array
  {
    return $this->execute(true);
  }

  //---------------------------------------------------------------------------
  /**
   * Takes the lock on the stamp file and runs the cleanup when it is due (or forced).
   *
   * @param bool $force Whether to ignore the interval
   *
   * @return array|null What run() gives; null if it did not run (not due, or another run holds the lock)
   */
  private function execute(bool $force): ?array
  {
    $handle = @fopen($this->stampFile, 'c+');
    if ($handle === false) {
      // Without a stamp file nothing is kept apart: a forced run goes on, the automatic one stays away
      return $force ? $this->run() : null;
    }

    try {
      if (!flock($handle, LOCK_EX | LOCK_NB)) {
        return null;
      }
      clearstatcache(true, $this->stampFile);
      if (!$force && time() - (int)filemtime($this->stampFile) < self::INTERVAL) {
        return null;
      }
      // Stamp first: a failing run is tried again after the interval, not with every request
      ftruncate($handle, 0);
      fwrite($handle, (string)time());
      fflush($handle);

      return $this->run();
    } finally {
      flock($handle, LOCK_UN);
      fclose($handle);
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Does the cleanup now.
   *
   * @return array{sent: int, notifications: int, mails: int, uploads: int, audit: int, sla: array} What was sent and deleted, and the SLA
   *                                                                                              timers that were announced (breached, at_risk, skipped)
   */
  public function run(): array
  {
    // First: what the check queues is sent in this run
    $sla = ['breached' => 0, 'at_risk' => 0, 'skipped' => 0];
    try {
      $sla = $this->sla?->run() ?? $sla;
    } catch (\Throwable $e) {
      error_log('SLA notification check failed: ' . $e->getMessage());
    }

    $sent = $this->outbox->process();
    $deleted = $this->notifications->purge();

    $stored = $this->attachments->purgeExpired($this->config->tempUploadCleanupHours());
    foreach ($stored as $name) {
      $this->storage->remove($name);
    }

    // Entries past audit_retention_days (0 keeps them); a problem here must not stop the rest of the cleanup
    $audit = 0;
    try {
      $audit = $this->audit->purge($this->config->auditRetentionDays());
    } catch (\Throwable $e) {
      error_log('Audit log purge failed: ' . $e->getMessage());
    }

    // The log of the mails Email to ticket read is kept as long as the audit log
    try {
      $this->mailInbox?->purge($this->config->auditRetentionDays());
    } catch (\Throwable $e) {
      error_log('Email to ticket log purge failed: ' . $e->getMessage());
    }

    // The cache of the GitHub commits (entries older than a day)
    try {
      $this->githubCache?->purge();
    } catch (\Throwable $e) {
      error_log('GitHub commit cache purge failed: ' . $e->getMessage());
    }

    return ['sent' => $sent, 'notifications' => $deleted['notifications'], 'mails' => $deleted['mails'], 'uploads' => count($stored), 'audit' => $audit, 'sla' => $sla];
  }
}
