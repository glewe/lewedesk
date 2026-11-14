<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;

//---------------------------------------------------------------------------
/**
 * The log of the mails that Email-to-ticket read (table mail_inbox). The unique Message-ID makes sure that a mail is
 * never handled twice, whatever happens between reading it and moving it out of the inbox.
 *
 * A mail is claimed first (claim(): result "error", reason "processing"), then handled, then the row is completed with
 * the real result (finish()). A run that dies in between leaves a row that says so; the mail is not handled again, an
 * administrator decides (this is deliberate: a mail may be missed, but it never produces an issue twice).
 */
class MailInboxModel
{
  private PDO $db;

  /**
   * Constructor.
   *
   * @param PDO $db The database connection
   */
  public function __construct(PDO $db)
  {
    $this->db = $db;
  }

  //---------------------------------------------------------------------------
  /**
   * Finds a mail by its Message-ID.
   *
   * @param string $messageId The id, with angle brackets
   *
   * @return array|null id, result, issue_id, comment_id; null if the mail is not known
   */
  public function find(string $messageId): ?array
  {
    $stmt = $this->db->prepare('SELECT id, result, reason, attempts, issue_id, comment_id FROM mail_inbox WHERE message_id = :id');
    $stmt->execute(['id' => mb_substr($messageId, 0, 255)]);
    $row = $stmt->fetch();

    return $row ?: null;
  }

  //---------------------------------------------------------------------------
  /**
   * Claims a mail: nobody else handles it from now on.
   *
   * @param string      $messageId The id, with angle brackets
   * @param string      $from      The sender address
   * @param string      $subject   The subject
   * @param string|null $received  The Date header as "Y-m-d H:i:s" (server time zone), or null
   *
   * @return int|null The row id; null if the mail was claimed before
   */
  public function claim(string $messageId, string $from, string $subject, ?string $received): ?int
  {
    try {
      $this->db->prepare(
        "INSERT INTO mail_inbox (message_id, from_address, subject, result, reason, received_at)
         VALUES (:id, :from, :subject, 'error', 'processing', :received)"
      )->execute([
        'id' => mb_substr($messageId, 0, 255),
        'from' => mb_substr($from, 0, 255),
        'subject' => mb_substr($subject, 0, 255),
        'received' => $received,
      ]);
    } catch (PDOException $e) {
      if ($e->getCode() === '23000') {
        return null;
      }
      throw $e;
    }

    return (int)$this->db->lastInsertId();
  }

  //---------------------------------------------------------------------------
  /**
   * Claims a mail that failed before for another try (nothing was created for it, see InboundMailService).
   *
   * @param int $id The row id
   *
   * @return void
   */
  public function retry(int $id): void
  {
    $this->db->prepare("UPDATE mail_inbox SET result = 'error', reason = 'processing', attempts = attempts + 1 WHERE id = :id")->execute(['id' => $id]);
  }

  //---------------------------------------------------------------------------
  /**
   * Completes the row of a mail.
   *
   * @param int         $id        The row id
   * @param string      $result    issue, comment, rejected or error
   * @param string|null $reason    Why the mail was rejected or failed
   * @param int|null    $issueId   The issue that was created or commented
   * @param int|null    $commentId The comment that was created
   *
   * @return void
   */
  public function finish(int $id, string $result, ?string $reason, ?int $issueId, ?int $commentId): void
  {
    $this->db->prepare('UPDATE mail_inbox SET result = :result, reason = :reason, issue_id = :issue, comment_id = :comment WHERE id = :id')
      ->execute([
        'result' => $result,
        'reason' => $reason === null ? null : mb_substr($reason, 0, 255),
        'issue' => $issueId,
        'comment' => $commentId,
        'id' => $id,
      ]);
  }

  //---------------------------------------------------------------------------
  /**
   * Looks up the issues that earlier mails of the mailbox belong to, for the matching of replies.
   *
   * @param string[] $messageIds Ids with angle brackets
   *
   * @return array<string, int> Message-ID => issue id
   */
  public function issuesOf(array $messageIds): array
  {
    if ($messageIds === []) {
      return [];
    }

    $marks = implode(', ', array_fill(0, count($messageIds), '?'));
    $stmt = $this->db->prepare("SELECT message_id, issue_id FROM mail_inbox WHERE issue_id IS NOT NULL AND message_id IN ($marks)");
    $stmt->execute(array_values($messageIds));

    $issues = [];
    foreach ($stmt->fetchAll() as $row) {
      $issues[(string)$row['message_id']] = (int)$row['issue_id'];
    }

    return $issues;
  }

  //---------------------------------------------------------------------------
  /**
   * Counts what happened in the last 24 hours (for the settings page).
   *
   * @return array{issue: int, comment: int, rejected: int, error: int}
   */
  public function counts(): array
  {
    $counts = ['issue' => 0, 'comment' => 0, 'rejected' => 0, 'error' => 0];
    $stmt = $this->db->query('SELECT result, COUNT(*) AS n FROM mail_inbox WHERE processed_at > NOW() - INTERVAL 1 DAY GROUP BY result');
    foreach ($stmt->fetchAll() as $row) {
      $counts[(string)$row['result']] = (int)$row['n'];
    }

    return $counts;
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the latest mails of the log.
   *
   * @param int $limit How many
   *
   * @return array[] Newest first: id, from_address, subject, result, reason, attempts, issue_id, comment_id, processed_at
   */
  public function recent(int $limit): array
  {
    $stmt = $this->db->prepare('SELECT id, from_address, subject, result, reason, attempts, issue_id, comment_id, processed_at FROM mail_inbox ORDER BY id DESC LIMIT :limit');
    $stmt->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
  }

  //---------------------------------------------------------------------------
  /**
   * Counts the mails that became an issue or a comment since a point in time (for the hourly limit).
   *
   * @param int $seconds How far back
   *
   * @return int
   */
  public function acceptedSince(int $seconds): int
  {
    $stmt = $this->db->prepare("SELECT COUNT(*) FROM mail_inbox WHERE result IN ('issue', 'comment') AND processed_at > NOW() - INTERVAL :seconds SECOND");
    $stmt->bindValue('seconds', $seconds, PDO::PARAM_INT);
    $stmt->execute();

    return (int)$stmt->fetchColumn();
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes the rows older than the retention time.
   *
   * @param int $days Days; 0 keeps everything
   *
   * @return int The number of deleted rows
   */
  public function purge(int $days): int
  {
    if ($days <= 0) {
      return 0;
    }
    $stmt = $this->db->prepare('DELETE FROM mail_inbox WHERE processed_at < NOW() - INTERVAL :days DAY');
    $stmt->bindValue('days', $days, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->rowCount();
  }
}
