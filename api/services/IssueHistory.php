<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

//---------------------------------------------------------------------------
/**
 * Writes the activity history of issues (table issue_history): who did what, and when.
 *
 * The models call log() inside the transaction of the change they record, so a change and its history
 * entry stand or fall together. The history never holds the text of a comment (an internal comment must
 * not leak through it); a field change holds the old and the new value as text.
 *
 * Events: issue_created, issue_deleted, issue_restored, comment_added, comment_edited, comment_deleted, attachment_added (the file
 * name as new value), attachment_deleted (the file name as old value), field_changed (with the
 * field: tags, type, status, resolution, product, summary, description, version, access, assignee).
 */
class IssueHistory
{
  private PDO $db;

  //---------------------------------------------------------------------------
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
   * Adds an entry to the history of an issue.
   *
   * @param int         $issueId   The issue id
   * @param int|null    $actorId   The id of the acting user (null: unknown)
   * @param string      $event     The event key
   * @param string|null $field     The changed field (field_changed only)
   * @param string|null $oldValue  The value before the change
   * @param string|null $newValue  The value after the change
   * @param bool        $internal  Whether the entry belongs to an internal comment
   * @param int|null    $commentId The comment the entry is about
   *
   * @return void
   */
  public function log(int $issueId, ?int $actorId, string $event, ?string $field = null, ?string $oldValue = null, ?string $newValue = null, bool $internal = false, ?int $commentId = null): void
  {
    $this->db->prepare(
      'INSERT INTO issue_history (issue_id, actor_id, event, field, old_value, new_value, comment_id, is_internal)
       VALUES (:issue, :actor, :event, :field, :old, :new, :comment, :internal)'
    )->execute([
      'issue' => $issueId,
      'actor' => $actorId,
      'event' => $event,
      'field' => $field,
      'old' => $oldValue,
      'new' => $newValue,
      'comment' => $commentId,
      'internal' => $internal ? 1 : 0,
    ]);
  }
}
