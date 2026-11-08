<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\IssueHistory;
use App\Services\IssueVisibility;
use PDO;

//---------------------------------------------------------------------------
/**
 * Model for the comments of an issue. The issue of a comment is always read through the central
 * visibility check (App\Services\IssueVisibility), so a comment of an issue the viewer may not see is
 * never returned. Internal comments are only returned when the caller says the viewer may see them.
 */
class CommentModel
{
  private PDO $db;
  private IssueVisibility $visibility;
  private IssueHistory $history;
  private AttachmentModel $attachments;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param PDO             $db         The database connection
   * @param IssueVisibility $visibility The visibility rules
   */
  public function __construct(PDO $db, IssueVisibility $visibility)
  {
    $this->db = $db;
    $this->visibility = $visibility;
    $this->history = new IssueHistory($db);
    $this->attachments = new AttachmentModel($db, $visibility);
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the facts about an issue that the comment rules need. Returns null if the issue does not exist or
   * the viewer may not see it.
   *
   * @param int $issueId The issue id
   *
   * @return array{id: int, reporter_id: int|null, deleted_at: string|null, status_key: string}|null
   */
  public function issue(int $issueId): ?array
  {
    [$scope, $params] = $this->visibility->scope('i');
    $stmt = $this->db->prepare(
      "SELECT i.id, i.reporter_id, i.deleted_at, s.`key` AS status_key
         FROM issues i JOIN `status` s ON s.id = i.status_id
        WHERE i.id = :id AND $scope"
    );
    $stmt->execute($params + ['id' => $issueId]);
    $row = $stmt->fetch();
    if (!$row) {
      return null;
    }

    return [
      'id' => (int)$row['id'],
      'reporter_id' => $row['reporter_id'] === null ? null : (int)$row['reporter_id'],
      'deleted_at' => $row['deleted_at'],
      // A status without key (user defined) never matches a rule that names a status
      'status_key' => (string)$row['status_key'],
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the comments of an issue.
   *
   * @param int    $issueId         The issue id
   * @param bool   $includeInternal Whether internal comments are returned
   * @param string $order           asc (oldest first) or desc (newest first)
   *
   * @return array[] The comments as database rows with the author columns (see present() of the controller)
   */
  public function list(int $issueId, bool $includeInternal, string $order): array
  {
    $direction = $order === 'desc' ? 'DESC' : 'ASC';
    $internal = $includeInternal ? '' : ' AND c.is_internal = 0';

    $stmt = $this->db->prepare($this->selectSql() . " WHERE c.issue_id = :issue$internal ORDER BY c.created_at $direction, c.id $direction");
    $stmt->execute(['issue' => $issueId]);

    return array_map(fn(array $row): array => $this->normalize($row), $stmt->fetchAll());
  }

  //---------------------------------------------------------------------------
  /**
   * Gets one comment of an issue.
   *
   * @param int  $id              The comment id
   * @param int  $issueId         The issue id (a comment of another issue is not found)
   * @param bool $includeInternal Whether an internal comment may be returned
   *
   * @return array|null Null if there is no such comment or it is hidden from the viewer
   */
  public function find(int $id, int $issueId, bool $includeInternal): ?array
  {
    $internal = $includeInternal ? '' : ' AND c.is_internal = 0';
    $stmt = $this->db->prepare($this->selectSql() . " WHERE c.id = :id AND c.issue_id = :issue$internal");
    $stmt->execute(['id' => $id, 'issue' => $issueId]);
    $row = $stmt->fetch();

    return $row ? $this->normalize($row) : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Adds a comment in a transaction of its own. The issue counts as updated.
   *
   * @param int    $issueId  The issue id
   * @param int    $authorId The id of the commenting user
   * @param string $body     The text (markdown)
   * @param bool   $internal Whether the comment is internal
   *
   * @return int The new comment id
   */
  public function create(int $issueId, int $authorId, string $body, bool $internal): int
  {
    $this->db->beginTransaction();
    try {
      $id = $this->insert($issueId, $authorId, $body, $internal);
      $this->db->commit();
    } catch (\Throwable $e) {
      $this->db->rollBack();
      throw $e;
    }

    return $id;
  }

  //---------------------------------------------------------------------------
  /**
   * Adds a comment inside the transaction of the caller (the workflow actions add their comment together
   * with their other changes). The issue counts as updated, the history gets its entry, and the author
   * becomes a watcher of the issue with the first comment (they can unwatch afterwards).
   *
   * @param int    $issueId  The issue id
   * @param int    $authorId The id of the commenting user
   * @param string $body     The text (markdown)
   * @param bool   $internal Whether the comment is internal
   *
   * @return int The new comment id
   */
  public function insert(int $issueId, int $authorId, string $body, bool $internal): int
  {
    // Deleted comments count: whoever unwatched after the first comment is not made a watcher again
    $first = $this->db->prepare('SELECT COUNT(*) FROM comments WHERE issue_id = :issue AND author_id = :author');
    $first->execute(['issue' => $issueId, 'author' => $authorId]);
    $isFirst = (int)$first->fetchColumn() === 0;

    $this->db->prepare('INSERT INTO comments (issue_id, author_id, body, is_internal) VALUES (:issue, :author, :body, :internal)')
      ->execute(['issue' => $issueId, 'author' => $authorId, 'body' => $body, 'internal' => $internal ? 1 : 0]);
    $id = (int)$this->db->lastInsertId();
    $this->touchIssue($issueId);
    $this->history->log($issueId, $authorId, 'comment_added', null, null, null, $internal, $id);
    $this->attachments->linkInline($issueId, $id, $authorId, $body, $internal);
    if ($isFirst) {
      $this->db->prepare('INSERT IGNORE INTO issue_watcher (issue_id, user_id) VALUES (:issue, :user)')->execute(['issue' => $issueId, 'user' => $authorId]);
    }

    return $id;
  }

  //---------------------------------------------------------------------------
  /**
   * Changes the text of a comment and marks it as edited. A text that did not change is not an edit.
   * The issue counts as updated when the text changed.
   *
   * @param int    $id      The comment id
   * @param int    $issueId The issue id
   * @param string $body    The new text (markdown)
   *
   * @return bool Whether the comment changed
   */
  public function updateBody(int $id, int $issueId, string $body): bool
  {
    $this->db->beginTransaction();
    try {
      $stmt = $this->db->prepare('SELECT body, is_internal FROM comments WHERE id = :id AND issue_id = :issue AND deleted_at IS NULL FOR UPDATE');
      $stmt->execute(['id' => $id, 'issue' => $issueId]);
      $row = $stmt->fetch();

      // Compared in PHP: the database collation ignores case and accents, so it would miss such an edit
      $changed = $row !== false && $row['body'] !== $body;
      if ($changed) {
        $this->db->prepare('UPDATE comments SET body = :body, edited_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
          ->execute(['body' => $body, 'id' => $id]);
        $this->touchIssue($issueId);
        $this->history->log($issueId, $this->visibility->viewerId(), 'comment_edited', null, null, null, (bool)$row['is_internal'], $id);
        $this->attachments->linkInline($issueId, $id, (int)$this->visibility->viewerId(), $body, (bool)$row['is_internal']);
      }
      $this->db->commit();
    } catch (\Throwable $e) {
      $this->db->rollBack();
      throw $e;
    }

    return $changed;
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes a comment: the row stays so that the timeline can show the placeholder, the text is cleared.
   * The issue counts as updated.
   *
   * @param int $id      The comment id
   * @param int $issueId The issue id
   *
   * @return void
   */
  public function delete(int $id, int $issueId): void
  {
    $this->db->beginTransaction();
    try {
      $internal = $this->db->prepare('SELECT is_internal FROM comments WHERE id = :id AND issue_id = :issue AND deleted_at IS NULL FOR UPDATE');
      $internal->execute(['id' => $id, 'issue' => $issueId]);
      $isInternal = (bool)$internal->fetchColumn();

      $stmt = $this->db->prepare(
        "UPDATE comments SET body = '', deleted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
          WHERE id = :id AND issue_id = :issue AND deleted_at IS NULL"
      );
      $stmt->execute(['id' => $id, 'issue' => $issueId]);
      if ($stmt->rowCount() > 0) {
        $this->touchIssue($issueId);
        $this->history->log($issueId, $this->visibility->viewerId(), 'comment_deleted', null, null, null, $isInternal, $id);
      }
      $this->db->commit();
    } catch (\Throwable $e) {
      $this->db->rollBack();
      throw $e;
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Builds the SELECT of a comment with its author (without WHERE and ORDER BY).
   *
   * @return string
   */
  private function selectSql(): string
  {
    return 'SELECT c.id, c.issue_id, c.author_id, c.body, c.is_internal, c.edited_at, c.deleted_at, c.created_at, c.updated_at,
                   u.username AS author_username, u.first_name AS author_first_name, u.last_name AS author_last_name, u.avatar AS author_avatar
              FROM comments c LEFT JOIN users u ON u.id = c.author_id';
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the columns of a comment row their types and the author the shape of the other user fields. A
   * missing author means the account was deleted. A deleted comment keeps neither text nor author.
   *
   * @param array $row The row of selectSql()
   *
   * @return array
   */
  private function normalize(array $row): array
  {
    $deleted = $row['deleted_at'] !== null;

    return [
      'id' => (int)$row['id'],
      'issue_id' => (int)$row['issue_id'],
      'author_id' => $row['author_id'] === null ? null : (int)$row['author_id'],
      'author' => $deleted ? null : ($row['author_id'] === null
        ? ['id' => null, 'username' => null, 'display_name' => null, 'avatar' => null, 'deleted' => true]
        : IssueModel::userFields((int)$row['author_id'], (string)$row['author_username'], $row['author_first_name'], $row['author_last_name'], $row['author_avatar'])),
      'body' => $deleted ? null : $row['body'],
      'is_internal' => (bool)$row['is_internal'],
      'edited_at' => $row['edited_at'],
      'deleted_at' => $row['deleted_at'],
      'created_at' => $row['created_at'],
      'updated_at' => $row['updated_at'],
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * Sets the Updated timestamp of an issue (a comment is a change of the issue).
   *
   * @param int $issueId The issue id
   *
   * @return void
   */
  private function touchIssue(int $issueId): void
  {
    $this->db->prepare('UPDATE issues SET updated_at = CURRENT_TIMESTAMP WHERE id = :id')->execute(['id' => $issueId]);
  }
}
