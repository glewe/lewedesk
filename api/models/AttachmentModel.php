<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\AttachmentStorage;
use App\Services\AuditLog;
use App\Services\IssueHistory;
use App\Services\IssueVisibility;
use PDO;

//---------------------------------------------------------------------------
/**
 * Model for the attachments of issues (see _docs/TICKET_SPEC.md).
 *
 * An attachment belongs to an issue. Files that are uploaded while a new issue is written belong to nobody
 * yet: they carry a temporary token chosen by the browser (issue_id is NULL) and are attached when the issue
 * is saved (claim()), or deleted by the housekeeping (expired()). Inline images are attachments flagged
 * is_inline; the image of an internal comment is flagged is_internal and only seen by users who may see
 * internal comments. The issue of an attachment is always read through the central visibility check
 * (App\Services\IssueVisibility).
 */
class AttachmentModel
{
  private PDO $db;
  private IssueVisibility $visibility;
  private IssueHistory $history;
  private ?AuditLog $audit;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param PDO             $db         The database connection
   * @param IssueVisibility $visibility The visibility rules
   * @param AuditLog|null   $audit      The audit log (deleting an attachment of an issue is logged)
   */
  public function __construct(PDO $db, IssueVisibility $visibility, ?AuditLog $audit = null)
  {
    $this->audit = $audit;
    $this->db = $db;
    $this->visibility = $visibility;
    $this->history = new IssueHistory($db);
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the attachments of an issue, oldest first.
   *
   * @param int  $issueId         The issue id
   * @param bool $includeInternal Whether attachments of internal comments are returned
   *
   * @return array[] The rows with the uploader columns (see present())
   */
  public function list(int $issueId, bool $includeInternal): array
  {
    $internal = $includeInternal ? '' : ' AND a.is_internal = 0';
    $stmt = $this->db->prepare($this->selectSql() . " WHERE a.issue_id = :issue$internal ORDER BY a.created_at, a.id");
    $stmt->execute(['issue' => $issueId]);

    return array_map(fn(array $row): array => $this->normalize($row), $stmt->fetchAll());
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the temporary attachments of a new issue.
   *
   * @param string $token      The token of the upload
   * @param int    $uploaderId The uploading user
   *
   * @return array[]
   */
  public function listTemporary(string $token, int $uploaderId): array
  {
    $stmt = $this->db->prepare($this->selectSql() . ' WHERE a.issue_id IS NULL AND a.temp_token = :token AND a.uploader_id = :user ORDER BY a.created_at, a.id');
    $stmt->execute(['token' => $token, 'user' => $uploaderId]);

    return array_map(fn(array $row): array => $this->normalize($row), $stmt->fetchAll());
  }

  //---------------------------------------------------------------------------
  /**
   * Gets one attachment, whatever its state, without any access check.
   *
   * @param int $id The attachment id
   *
   * @return array|null
   */
  public function find(int $id): ?array
  {
    $stmt = $this->db->prepare($this->selectSql() . ' WHERE a.id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row ? $this->normalize($row) : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets an attachment the viewer may download: one of an issue the viewer may see (internal ones only for users who
   * may see internal comments), or a temporary one of the viewer.
   *
   * @param int $id The attachment id
   *
   * @return array|null Null if there is no such attachment or it is hidden from the viewer
   */
  public function findVisible(int $id): ?array
  {
    $attachment = $this->find($id);
    if ($attachment === null) {
      return null;
    }

    if ($attachment['issue_id'] === null) {
      $viewerId = $this->visibility->viewerId();

      return $viewerId !== null && $attachment['uploader_id'] === $viewerId ? $attachment : null;
    }
    if ($attachment['is_internal'] && !$this->visibility->canViewInternalComments()) {
      return null;
    }

    return $this->issueVisible($attachment['issue_id']) ? $attachment : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the viewer may see an issue.
   *
   * @param int $issueId The issue id
   *
   * @return bool
   */
  public function issueVisible(int $issueId): bool
  {
    [$scope, $params] = $this->visibility->scope('i');
    $stmt = $this->db->prepare("SELECT 1 FROM issues i WHERE i.id = :id AND $scope");
    $stmt->execute($params + ['id' => $issueId]);

    return (bool)$stmt->fetchColumn();
  }

  //---------------------------------------------------------------------------
  /**
   * Tells how many attachments an issue (or a temporary upload) holds and how many bytes they take.
   *
   * @param int|null    $issueId The issue id, or null for a temporary upload
   * @param string|null $token   The upload token of a temporary upload
   * @param int|null    $userId  The uploading user of a temporary upload
   *
   * @return array{count: int, bytes: int}
   */
  public function usage(?int $issueId, ?string $token = null, ?int $userId = null): array
  {
    if ($issueId !== null) {
      $stmt = $this->db->prepare('SELECT COUNT(*), COALESCE(SUM(size), 0) FROM attachments WHERE issue_id = :issue');
      $stmt->execute(['issue' => $issueId]);
    } else {
      $stmt = $this->db->prepare('SELECT COUNT(*), COALESCE(SUM(size), 0) FROM attachments WHERE issue_id IS NULL AND temp_token = :token AND uploader_id = :user');
      $stmt->execute(['token' => $token, 'user' => $userId]);
    }
    $row = $stmt->fetch(PDO::FETCH_NUM);

    return ['count' => (int)$row[0], 'bytes' => (int)$row[1]];
  }

  //---------------------------------------------------------------------------
  /**
   * Adds an attachment to an issue and checks the limits in the same transaction (the issue row is locked, so
   * two uploads cannot both pass the check). The issue counts as updated and the history gets its entry.
   *
   * @param int      $issueId    The issue id
   * @param array    $file       name, mime, size of the checked file
   * @param string   $stored     The stored name
   * @param int      $uploaderId The uploading user
   * @param bool     $inline     Whether the file is an inline image
   * @param bool     $internal   Whether the attachment belongs to an internal comment
   * @param int      $maxCount   The most attachments per issue
   * @param int      $maxBytes   The most bytes per issue
   *
   * @return int|string The new attachment id, or the message of the limit that is exceeded
   */
  public function addToIssue(int $issueId, array $file, string $stored, int $uploaderId, bool $inline, bool $internal, int $maxCount, int $maxBytes): int|string
  {
    $this->db->beginTransaction();
    try {
      $this->db->prepare('SELECT id FROM issues WHERE id = :id FOR UPDATE')->execute(['id' => $issueId]);
      $limit = self::limitMessage($this->usage($issueId), $file['size'], $maxCount, $maxBytes);
      if ($limit !== null) {
        $this->db->rollBack();

        return $limit;
      }

      $id = $this->insert($issueId, null, $file, $stored, $uploaderId, $inline, $internal);
      $this->db->prepare('UPDATE issues SET updated_at = CURRENT_TIMESTAMP WHERE id = :id')->execute(['id' => $issueId]);
      $this->history->log($issueId, $uploaderId, 'attachment_added', null, null, $file['name'], $internal);
      $this->db->commit();
    } catch (\Throwable $e) {
      if ($this->db->inTransaction()) {
        $this->db->rollBack();
      }
      throw $e;
    }

    return $id;
  }

  //---------------------------------------------------------------------------
  /**
   * Adds a temporary attachment (the issue is not written yet).
   *
   * @param string $token      The token of the upload
   * @param array  $file       name, mime, size of the checked file
   * @param string $stored     The stored name
   * @param int    $uploaderId The uploading user
   * @param bool   $inline     Whether the file is an inline image
   * @param int    $maxCount   The most attachments per issue
   * @param int    $maxBytes   The most bytes per issue
   *
   * @return int|string The new attachment id, or the message of the limit that is exceeded
   */
  public function addTemporary(string $token, array $file, string $stored, int $uploaderId, bool $inline, int $maxCount, int $maxBytes): int|string
  {
    $limit = self::limitMessage($this->usage(null, $token, $uploaderId), $file['size'], $maxCount, $maxBytes);
    if ($limit !== null) {
      return $limit;
    }

    return $this->insert(null, $token, $file, $stored, $uploaderId, $inline, false);
  }

  //---------------------------------------------------------------------------
  /**
   * Attaches the temporary uploads of a user to the issue that was just saved.
   *
   * @param string $token      The token of the upload
   * @param int    $uploaderId The uploading user
   * @param int    $issueId    The new issue
   *
   * @return int The number of attachments
   */
  public function claim(string $token, int $uploaderId, int $issueId): int
  {
    $stmt = $this->db->prepare('UPDATE attachments SET issue_id = :issue, temp_token = NULL WHERE issue_id IS NULL AND temp_token = :token AND uploader_id = :user');
    $stmt->execute(['issue' => $issueId, 'token' => $token, 'user' => $uploaderId]);

    return $stmt->rowCount();
  }

  //---------------------------------------------------------------------------
  /**
   * Links the inline images a comment shows to the comment: the images the author uploaded for it (not linked to a
   * comment yet) take over the internal flag of the comment, so an image of an internal comment is internal.
   * Called inside the transaction that saves the comment.
   *
   * @param int    $issueId   The issue id
   * @param int    $commentId The comment id
   * @param int    $actorId   The user who saves the comment (the uploader of its images)
   * @param string $body      The text of the comment
   * @param bool   $internal  Whether the comment is internal
   *
   * @return void
   */
  public function linkInline(int $issueId, int $commentId, int $actorId, string $body, bool $internal): void
  {
    $ids = self::inlineIds($body);
    if ($ids === []) {
      return;
    }

    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $this->db->prepare(
      "UPDATE attachments SET comment_id = ?, is_internal = ?
        WHERE issue_id = ? AND is_inline = 1 AND uploader_id = ? AND (comment_id IS NULL OR comment_id = ?) AND id IN ($placeholders)"
    )->execute([$commentId, $internal ? 1 : 0, $issueId, $actorId, $commentId, ...$ids]);
  }

  //---------------------------------------------------------------------------
  /**
   * Finds out which attachments of an issue the description or a comment shows as inline image.
   *
   * @param int $issueId The issue id
   *
   * @return int[] The attachment ids that are used inline
   */
  public function usedInline(int $issueId): array
  {
    $texts = [];
    $stmt = $this->db->prepare('SELECT description FROM issues WHERE id = :id');
    $stmt->execute(['id' => $issueId]);
    $texts[] = (string)$stmt->fetchColumn();
    $stmt = $this->db->prepare('SELECT body FROM comments WHERE issue_id = :id AND deleted_at IS NULL');
    $stmt->execute(['id' => $issueId]);
    array_push($texts, ...$stmt->fetchAll(PDO::FETCH_COLUMN));

    $used = [];
    foreach ($texts as $text) {
      foreach (self::inlineIds((string)$text) as $id) {
        $used[$id] = $id;
      }
    }

    return array_values($used);
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes an attachment of an issue. The issue counts as updated and the history gets its entry. The caller
   * removes the file.
   *
   * @param array $attachment The attachment (as returned by find())
   * @param int   $actorId    The acting user
   *
   * @return void
   */
  public function deleteFromIssue(array $attachment, int $actorId): void
  {
    $this->db->beginTransaction();
    try {
      $stmt = $this->db->prepare('DELETE FROM attachments WHERE id = :id');
      $stmt->execute(['id' => $attachment['id']]);
      if ($stmt->rowCount() > 0) {
        $this->db->prepare('UPDATE issues SET updated_at = CURRENT_TIMESTAMP WHERE id = :id')->execute(['id' => $attachment['issue_id']]);
        $this->history->log((int)$attachment['issue_id'], $actorId, 'attachment_deleted', null, $attachment['name'], null, $attachment['is_internal']);
        $this->audit?->log('attachment.deleted', null, 'attachment', (int)$attachment['id'], (string)$attachment['name'], ['issue_id' => (int)$attachment['issue_id']]);
      }
      $this->db->commit();
    } catch (\Throwable $e) {
      $this->db->rollBack();
      throw $e;
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes a temporary attachment. The caller removes the file.
   *
   * @param int $id The attachment id
   *
   * @return void
   */
  public function deleteTemporary(int $id): void
  {
    $this->db->prepare('DELETE FROM attachments WHERE id = :id AND issue_id IS NULL')->execute(['id' => $id]);
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes the temporary uploads that were never attached to an issue within the given time.
   *
   * @param int $hours The age in hours after which an unused temporary upload is deleted
   *
   * @return string[] The stored names of the deleted files (the caller removes them from the disk)
   */
  public function purgeExpired(int $hours): array
  {
    $stmt = $this->db->prepare('SELECT id, stored_name FROM attachments WHERE issue_id IS NULL AND created_at < (NOW() - INTERVAL :hours HOUR)');
    $stmt->bindValue('hours', $hours, PDO::PARAM_INT);
    $stmt->execute();

    $stored = [];
    foreach ($stmt->fetchAll() as $row) {
      $this->deleteTemporary((int)$row['id']);
      $stored[] = (string)$row['stored_name'];
    }

    return $stored;
  }

  //---------------------------------------------------------------------------
  /**
   * Finds the attachment ids a text shows as inline image: ![name](attachment:12).
   *
   * @param string $text The markdown text
   *
   * @return int[]
   */
  public static function inlineIds(string $text): array
  {
    if (preg_match_all('/\(attachment:([1-9]\d{0,8})[\s)]/', $text, $matches) === 0) {
      return [];
    }

    return array_values(array_unique(array_map('intval', $matches[1])));
  }

  //---------------------------------------------------------------------------
  /**
   * Checks the limits per issue.
   *
   * @param array{count: int, bytes: int} $usage    What the issue holds now
   * @param int                           $size     The size of the new file
   * @param int                           $maxCount The most attachments per issue
   * @param int                           $maxBytes The most bytes per issue
   *
   * @return string|null The message of the limit that is exceeded, or null
   */
  public static function limitMessage(array $usage, int $size, int $maxCount, int $maxBytes): ?string
  {
    if ($usage['count'] + 1 > $maxCount) {
      return "An issue can have at most $maxCount attachments (inline images included)";
    }
    if ($usage['bytes'] + $size > $maxBytes) {
      return 'The attachments of an issue must not exceed ' . AttachmentStorage::megabytes($maxBytes) . ' in total';
    }

    return null;
  }

  //---------------------------------------------------------------------------
  /**
   * Inserts the row of an attachment.
   *
   * @param int|null    $issueId    The issue, or null for a temporary upload
   * @param string|null $token      The token of a temporary upload
   * @param array       $file       name, mime, size of the checked file
   * @param string      $stored     The stored name
   * @param int         $uploaderId The uploading user
   * @param bool        $inline     Whether the file is an inline image
   * @param bool        $internal   Whether the attachment belongs to an internal comment
   *
   * @return int The new attachment id
   */
  private function insert(?int $issueId, ?string $token, array $file, string $stored, int $uploaderId, bool $inline, bool $internal): int
  {
    $this->db->prepare(
      'INSERT INTO attachments (issue_id, uploader_id, original_name, stored_name, mime_type, size, is_inline, is_internal, temp_token)
       VALUES (:issue, :uploader, :name, :stored, :mime, :size, :inline, :internal, :token)'
    )->execute([
      'issue' => $issueId,
      'uploader' => $uploaderId,
      'name' => $file['name'],
      'stored' => $stored,
      'mime' => $file['mime'],
      'size' => $file['size'],
      'inline' => $inline ? 1 : 0,
      'internal' => $internal ? 1 : 0,
      'token' => $token,
    ]);

    return (int)$this->db->lastInsertId();
  }

  //---------------------------------------------------------------------------
  /**
   * Builds the SELECT of an attachment with its uploader (without WHERE and ORDER BY).
   *
   * @return string
   */
  private function selectSql(): string
  {
    return 'SELECT a.id, a.issue_id, a.comment_id, a.uploader_id, a.original_name, a.stored_name, a.mime_type, a.size, a.is_inline, a.is_internal, a.created_at,
                   u.username AS uploader_username, u.first_name AS uploader_first_name, u.last_name AS uploader_last_name, u.avatar AS uploader_avatar
              FROM attachments a LEFT JOIN users u ON u.id = a.uploader_id';
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the columns of an attachment row their types and the uploader the shape of the other user fields. A
   * missing uploader means the account was deleted.
   *
   * @param array $row The row of selectSql()
   *
   * @return array
   */
  private function normalize(array $row): array
  {
    return [
      'id' => (int)$row['id'],
      'issue_id' => $row['issue_id'] === null ? null : (int)$row['issue_id'],
      'comment_id' => $row['comment_id'] === null ? null : (int)$row['comment_id'],
      'uploader_id' => $row['uploader_id'] === null ? null : (int)$row['uploader_id'],
      'uploader' => $row['uploader_id'] === null
        ? ['id' => null, 'username' => null, 'display_name' => null, 'avatar' => null, 'deleted' => true]
        : IssueModel::userFields((int)$row['uploader_id'], (string)$row['uploader_username'], $row['uploader_first_name'], $row['uploader_last_name'], $row['uploader_avatar']),
      'name' => $row['original_name'],
      'stored_name' => $row['stored_name'],
      'mime_type' => $row['mime_type'],
      'size' => (int)$row['size'],
      'is_image' => in_array($row['mime_type'], AttachmentStorage::IMAGE_TYPES, true),
      'is_inline' => (bool)$row['is_inline'],
      'is_internal' => (bool)$row['is_internal'],
      'created_at' => $row['created_at'],
    ];
  }
}
