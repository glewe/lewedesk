<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\IssueVisibility;
use PDO;

//---------------------------------------------------------------------------
/**
 * Model for reading the history of an issue. The issue is read through the central visibility check
 * (App\Services\IssueVisibility), so the history of an issue the viewer may not see is never returned.
 */
class HistoryModel
{
  /** Events about a comment (or about the attachment of an internal comment) that only users who may see internal comments learn about when it is internal */
  private const HIDDEN_FOR_INTERNAL = ['comment_edited', 'comment_deleted', 'attachment_added', 'attachment_deleted'];

  private PDO $db;
  private IssueVisibility $visibility;

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
   * Lists the history of an issue. Entries about internal comments are limited for users who may not see
   * internal comments: only "comment added" stays, without the link to the comment.
   *
   * @param int    $issueId         The issue id
   * @param bool   $includeInternal Whether the viewer may see internal comments
   * @param string $order           asc (oldest first) or desc (newest first)
   *
   * @return array[]
   */
  public function list(int $issueId, bool $includeInternal, string $order): array
  {
    $direction = $order === 'asc' ? 'ASC' : 'DESC';
    $hidden = '';
    if (!$includeInternal) {
      $events = implode(', ', array_map(fn(string $event): string => $this->db->quote($event), self::HIDDEN_FOR_INTERNAL));
      $hidden = " AND NOT (h.is_internal = 1 AND h.event IN ($events))";
    }

    $stmt = $this->db->prepare(
      "SELECT h.id, h.actor_id, h.event, h.field, h.old_value, h.new_value, h.comment_id, h.is_internal, h.created_at,
              u.username, u.first_name, u.last_name, u.avatar
         FROM issue_history h LEFT JOIN users u ON u.id = h.actor_id
        WHERE h.issue_id = :issue$hidden
        ORDER BY h.created_at $direction, h.id $direction"
    );
    $stmt->execute(['issue' => $issueId]);

    return array_map(fn(array $row): array => $this->present($row, $includeInternal), $stmt->fetchAll());
  }

  //---------------------------------------------------------------------------
  /**
   * Shapes a row for the API. The id of the actor's account is not part of it; a missing actor means the
   * account was deleted.
   *
   * @param array $row             The database row
   * @param bool  $includeInternal Whether the viewer may see internal comments
   *
   * @return array
   */
  private function present(array $row, bool $includeInternal): array
  {
    $internal = (bool)$row['is_internal'];

    return [
      'id' => (int)$row['id'],
      'event' => $row['event'],
      'field' => $row['field'],
      'old_value' => $row['old_value'],
      'new_value' => $row['new_value'],
      'comment_id' => $internal && !$includeInternal ? null : ($row['comment_id'] === null ? null : (int)$row['comment_id']),
      'is_internal' => $internal,
      'actor' => $row['actor_id'] === null
        ? ['id' => null, 'username' => null, 'display_name' => null, 'avatar' => null, 'deleted' => true]
        : IssueModel::userFields((int)$row['actor_id'], (string)$row['username'], $row['first_name'], $row['last_name'], $row['avatar']),
      'created_at' => $row['created_at'],
    ];
  }
}
