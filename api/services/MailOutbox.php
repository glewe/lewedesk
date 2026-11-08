<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

//---------------------------------------------------------------------------
/**
 * The queue of outgoing emails (table mail_outbox).
 *
 * A request puts its mails into the queue (queue()) and sends them after the response was delivered (process(), called
 * from a shutdown function), so nobody waits for the mail server. A mail is tried at most twice: the first failure
 * leaves it pending, the second try is made once RETRY_AFTER seconds have passed (by the next request that sends mail,
 * or by the cron script api/housekeeping.php) and its failure is final: the mail is marked failed and written to the PHP
 * error log. Several processes can work on the queue at the same time, every mail is claimed before it is sent.
 */
class MailOutbox
{
  /** Seconds to wait before a failed mail is tried again */
  public const RETRY_AFTER = 60;

  /** Tries per mail */
  private const MAX_ATTEMPTS = 2;

  /** Mails handled by one process() call */
  private const BATCH = 25;

  /** Seconds after which a mail that is still being sent counts as lost (the process died) and is tried again */
  private const STALE_AFTER = 300;

  /** Days after which sent and failed mails are deleted */
  private const KEEP_DAYS = 14;

  private PDO $db;
  private MailService $mail;
  private bool $queued = false;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param PDO         $db   The database connection
   * @param MailService $mail The mail sender
   */
  public function __construct(PDO $db, MailService $mail)
  {
    $this->db = $db;
    $this->mail = $mail;
  }

  //---------------------------------------------------------------------------
  /**
   * Puts an email into the queue.
   *
   * @param string $to      Recipient address
   * @param string $subject Subject line
   * @param string $html    HTML body
   * @param string $text    Plain text body
   *
   * @return void
   */
  public function queue(string $to, string $subject, string $html, string $text): void
  {
    $this->db->prepare('INSERT INTO mail_outbox (recipient, subject, body_html, body_text) VALUES (:to, :subject, :html, :text)')
      ->execute(['to' => $to, 'subject' => mb_substr($subject, 0, 255), 'html' => $html, 'text' => $text]);
    $this->queued = true;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether this request put a mail into the queue.
   *
   * @return bool
   */
  public function hasQueued(): bool
  {
    return $this->queued;
  }

  //---------------------------------------------------------------------------
  /**
   * Sends the mails that are due: new ones, and the ones that failed once and waited long enough.
   *
   * @return int The number of mails that were sent
   */
  public function process(): int
  {
    // A mail whose process died while sending is tried again
    $this->db->exec("UPDATE mail_outbox SET status = 'pending' WHERE status = 'sending' AND attempted_at < NOW() - INTERVAL " . self::STALE_AFTER . ' SECOND');

    $due = $this->db->prepare(
      "SELECT id FROM mail_outbox
        WHERE status = 'pending' AND attempts < :max AND (attempted_at IS NULL OR attempted_at < NOW() - INTERVAL " . self::RETRY_AFTER . ' SECOND)
        ORDER BY id LIMIT ' . self::BATCH
    );
    $due->bindValue('max', self::MAX_ATTEMPTS, PDO::PARAM_INT);
    $due->execute();

    $sent = 0;
    foreach ($due->fetchAll(PDO::FETCH_COLUMN) as $id) {
      $sent += $this->deliver((int)$id) ? 1 : 0;
    }

    return $sent;
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes the sent and the failed mails that are older than the keeping time.
   *
   * @return int The number of deleted mails
   */
  public function purge(): int
  {
    return (int)$this->db->exec("DELETE FROM mail_outbox WHERE status IN ('sent', 'failed') AND created_at < NOW() - INTERVAL " . self::KEEP_DAYS . ' DAY');
  }

  //---------------------------------------------------------------------------
  /**
   * Claims one mail and sends it.
   *
   * @param int $id The mail id
   *
   * @return bool Whether the mail was sent
   */
  private function deliver(int $id): bool
  {
    // Only the process that changes the status from pending owns the mail
    $claim = $this->db->prepare("UPDATE mail_outbox SET status = 'sending', attempts = attempts + 1, attempted_at = NOW() WHERE id = :id AND status = 'pending'");
    $claim->execute(['id' => $id]);
    if ($claim->rowCount() === 0) {
      return false;
    }

    $stmt = $this->db->prepare('SELECT recipient, subject, body_html, body_text, attempts FROM mail_outbox WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    if ($this->mail->send($row['recipient'], $row['subject'], $row['body_html'], $row['body_text'])) {
      $this->db->prepare("UPDATE mail_outbox SET status = 'sent', last_error = NULL WHERE id = :id")->execute(['id' => $id]);

      return true;
    }

    $final = (int)$row['attempts'] >= self::MAX_ATTEMPTS;
    $error = mb_substr($this->mail->lastError(), 0, 255);
    $this->db->prepare('UPDATE mail_outbox SET status = :status, last_error = :error WHERE id = :id')
      ->execute(['status' => $final ? 'failed' : 'pending', 'error' => $error, 'id' => $id]);
    if ($final) {
      error_log(sprintf('Mail %d to %s failed twice and is given up: %s', $id, $row['recipient'], $error));
    }

    return false;
  }
}
