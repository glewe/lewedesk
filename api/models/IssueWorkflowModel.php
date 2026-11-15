<?php

declare(strict_types=1);

namespace App\Models;

require_once __DIR__ . '/../services/SlaService.php';
require_once __DIR__ . '/../services/IssueNumbering.php';

use App\Services\AuditLog;
use App\Services\IssueConflictException;
use App\Services\IssueHistory;
use App\Services\IssueNumbering;
use App\Services\SlaService;
use PDO;

//---------------------------------------------------------------------------
/**
 * Executes the workflow actions of an issue (see App\Services\IssueActions for the rules and
 * _docs/TICKET_SPEC.md for the effects). The caller checked the rules; every method runs in one
 * transaction that first locks the issue and makes sure it still is in the state that was checked, so an
 * action never works on a stale picture (IssueConflictException). Each change writes its history entries
 * inside the same transaction and moves "Updated". Watcher changes do neither.
 */
class IssueWorkflowModel
{
  private PDO $db;
  private IssueModel $issues;
  private CommentModel $comments;
  private IssueHistory $history;
  private SlaService $sla;
  private ?AuditLog $audit;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param PDO          $db       The database connection
   * @param IssueModel   $issues   The issue model (locking, lookups, watchers)
   * @param CommentModel $comments The comment model (the comment of an action)
   * @param AuditLog|null $audit   The audit log (deleting and restoring an issue is logged)
   */
  public function __construct(PDO $db, IssueModel $issues, CommentModel $comments, ?AuditLog $audit = null)
  {
    $this->audit = $audit;
    $this->db = $db;
    $this->issues = $issues;
    $this->comments = $comments;
    $this->history = new IssueHistory($db);
    $this->sla = new SlaService($db);
  }

  //---------------------------------------------------------------------------
  /**
   * Respond: adds the comment and moves the status to what the responder leads to.
   *
   * @param array       $facts     The facts of the issue (IssueActions::facts())
   * @param int         $actorId   The responding user
   * @param string      $body      The comment (markdown)
   * @param bool        $internal  Whether the comment is internal
   * @param string|null $statusKey The key of the new status, or null to keep the status
   *
   * @return void
   */
  public function respond(array $facts, int $actorId, string $body, bool $internal, ?string $statusKey): void
  {
    $this->issues->transaction(function () use ($facts, $actorId, $body, $internal, $statusKey): void {
      $this->issues->lockIssue($facts);
      $this->comments->insert($facts['id'], $actorId, $body, $internal);
      if ($statusKey !== null) {
        $this->changeStatus($facts, $actorId, $statusKey, null, false);
      }
      $this->sla->sync($facts['id']);
    });
  }

  //---------------------------------------------------------------------------
  /**
   * Resolve: sets the resolution and the status Resolved, with an optional comment.
   *
   * @param array       $facts        The facts of the issue
   * @param int         $actorId      The resolving user
   * @param int         $resolutionId The resolution
   * @param string|null $body         The comment, or null for none
   * @param int|null    $fixedVersionId The fixed version to set, or null to leave it as it is
   *
   * @return void
   */
  public function resolve(array $facts, int $actorId, int $resolutionId, ?string $body, ?int $fixedVersionId = null): void
  {
    $this->issues->transaction(function () use ($facts, $actorId, $resolutionId, $body, $fixedVersionId): void {
      $row = $this->issues->lockIssue($facts);
      if ($body !== null) {
        $this->comments->insert($facts['id'], $actorId, $body, false);
      }
      $this->changeStatus($facts, $actorId, 'resolved', $resolutionId, false);
      $oldVersion = $row['fixed_version_id'] === null ? null : (int)$row['fixed_version_id'];
      if ($fixedVersionId !== null && $fixedVersionId !== $oldVersion) {
        $this->db->prepare('UPDATE issues SET fixed_version_id = :version WHERE id = :id')->execute(['version' => $fixedVersionId, 'id' => $facts['id']]);
        $this->history->log($facts['id'], $actorId, 'field_changed', 'fixed_version', $this->issues->recordValue('product_versions', $oldVersion), $this->issues->recordValue('product_versions', $fixedVersionId));
      }
      $this->sla->sync($facts['id']);
    });
  }

  //---------------------------------------------------------------------------
  /**
   * Close: sets the status Closed. The resolution is kept.
   *
   * @param array $facts   The facts of the issue
   * @param int   $actorId The closing user
   *
   * @return void
   */
  public function close(array $facts, int $actorId): void
  {
    $this->issues->transaction(function () use ($facts, $actorId): void {
      $this->issues->lockIssue($facts);
      $this->changeStatus($facts, $actorId, 'closed', null, true);
      $this->sla->sync($facts['id']);
    });
  }

  //---------------------------------------------------------------------------
  /**
   * Reopen: adds the comment, sets the status Open and clears the resolution.
   *
   * @param array  $facts   The facts of the issue
   * @param int    $actorId The reopening user
   * @param string $body    The comment (markdown)
   *
   * @return void
   */
  public function reopen(array $facts, int $actorId, string $body): void
  {
    $this->issues->transaction(function () use ($facts, $actorId, $body): void {
      $this->issues->lockIssue($facts);
      $this->comments->insert($facts['id'], $actorId, $body, false);
      $this->changeStatus($facts, $actorId, 'open', null, false);
      $this->sla->sync($facts['id']);
    });
  }

  //---------------------------------------------------------------------------
  /**
   * Assign: sets (or, with null, clears) the assignee, with an optional comment. A new assignee becomes a
   * watcher of the issue.
   *
   * @param array       $facts      The facts of the issue
   * @param int         $actorId    The assigning user
   * @param int|null    $assigneeId The new assignee (an Agent), or null for none
   * @param string|null $body       The comment, or null for none
   *
   * @return void
   */
  public function assign(array $facts, int $actorId, ?int $assigneeId, ?string $body): void
  {
    $this->issues->transaction(function () use ($facts, $actorId, $assigneeId, $body): void {
      $this->issues->lockIssue($facts);
      if ($body !== null) {
        $this->comments->insert($facts['id'], $actorId, $body, false);
      }

      $this->db->prepare('UPDATE issues SET assignee_id = :assignee, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
        ->execute(['assignee' => $assigneeId, 'id' => $facts['id']]);
      $previous = $facts['assignee_id'] === null ? null : (int)$facts['assignee_id'];
      $this->history->log($facts['id'], $actorId, 'field_changed', 'assignee', $this->issues->userLabel($previous), $this->issues->userLabel($assigneeId));
      if ($assigneeId !== null) {
        $this->issues->addWatcher($facts['id'], $assigneeId);
      }
      $this->sla->sync($facts['id']);
    });
  }

  //---------------------------------------------------------------------------
  /**
   * Change access: sets the access and removes the watchers who lose access.
   *
   * @param array  $facts   The facts of the issue
   * @param int    $actorId The user who changes the access
   * @param string $access  The new access (public, registered or reporter)
   *
   * @return void
   */
  public function changeAccess(array $facts, int $actorId, string $access): void
  {
    $this->issues->transaction(function () use ($facts, $actorId, $access): void {
      $this->issues->lockIssue($facts);
      $this->db->prepare('UPDATE issues SET access = :access, updated_at = CURRENT_TIMESTAMP WHERE id = :id')->execute(['access' => $access, 'id' => $facts['id']]);
      $this->history->log($facts['id'], $actorId, 'field_changed', 'access', $facts['access'], $access);
      $this->issues->removeWatchersWithoutAccess($facts['id']);
      $this->sla->sync($facts['id'], true);
    });
  }

  //---------------------------------------------------------------------------
  /**
   * Move: gives the issue to another product. It gets the next number of that product (a new key), its old key stays
   * valid as an alias, and the versions are cleared (they belong to the old product). The SLA timers follow the
   * issue, because the product is an input of the SLA policy.
   *
   * @param array $facts     The facts of the issue
   * @param int   $actorId   The moving user
   * @param int   $productId The target product (it exists; the caller checked)
   *
   * @return array{old_key: string, new_key: string, number: int}
   *
   * @throws IssueConflictException If somebody else changed the issue in between, or it already belongs to the product
   */
  public function move(array $facts, int $actorId, int $productId): array
  {
    return $this->issues->transaction(function () use ($facts, $actorId, $productId): array {
      $row = $this->issues->lockIssue($facts);
      if ((int)$row['product_id'] === $productId) {
        throw new IssueConflictException('The issue already belongs to this product');
      }

      $oldProduct = $this->issues->recordValue('products', (int)$row['product_id']);
      $versions = [];
      foreach (['affected_version_id' => 'affected_version', 'fixed_version_id' => 'fixed_version'] as $column => $field) {
        if ($row[$column] !== null) {
          $versions[$field] = $this->issues->recordValue('product_versions', (int)$row[$column]);
        }
      }

      $result = (new IssueNumbering($this->db))->move($facts['id'], $productId);

      $newProduct = $this->issues->recordValue('products', $productId);
      $this->history->log($facts['id'], $actorId, 'field_changed', 'product', $oldProduct, $newProduct);
      $this->history->log($facts['id'], $actorId, 'field_changed', 'key', $result['old_key'], $result['new_key']);
      foreach ($versions as $field => $name) {
        $this->history->log($facts['id'], $actorId, 'field_changed', $field, $name, null);
      }

      $summary = $this->db->prepare('SELECT summary FROM issues WHERE id = :id');
      $summary->execute(['id' => $facts['id']]);
      $this->audit?->log(
        'issue.moved',
        ['id' => $actorId, 'username' => $this->username($actorId)],
        'issue',
        $facts['id'],
        $result['new_key'] . ' ' . (string)$summary->fetchColumn(),
        ['key' => $result['old_key'], 'product' => $oldProduct],
        ['key' => $result['new_key'], 'product' => $newProduct]
      );
      $this->sla->sync($facts['id'], true);

      return $result;
    });
  }

  //---------------------------------------------------------------------------
  /**
   * Delete or restore: soft deletes an issue or brings it back. Comments and attachments stay as they are.
   *
   * @param array $facts   The facts of the issue
   * @param int   $actorId The acting user
   * @param bool  $deleted Whether to delete (true) or restore (false)
   *
   * @return void
   */
  public function setDeleted(array $facts, int $actorId, bool $deleted): void
  {
    $this->issues->transaction(function () use ($facts, $actorId, $deleted): void {
      $this->issues->lockIssue($facts);
      $this->db->prepare('UPDATE issues SET deleted_at = ' . ($deleted ? 'CURRENT_TIMESTAMP' : 'NULL') . ', updated_at = CURRENT_TIMESTAMP WHERE id = :id')
        ->execute(['id' => $facts['id']]);
      $this->history->log($facts['id'], $actorId, $deleted ? 'issue_deleted' : 'issue_restored');

      // The audit log only records that it happened; the activity of the issue stays in its history
      $summary = $this->db->prepare('SELECT summary FROM issues WHERE id = :id');
      $summary->execute(['id' => $facts['id']]);
      $this->audit?->log($deleted ? 'issue.deleted' : 'issue.restored', ['id' => $actorId, 'username' => $this->username($actorId)], 'issue', $facts['id'], (string)$summary->fetchColumn());
      $this->sla->sync($facts['id']);
    });
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the username of a user (for the audit log).
   *
   * @param int $userId The user id
   *
   * @return string
   */
  private function username(int $userId): string
  {
    $stmt = $this->db->prepare('SELECT username FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);

    return (string)$stmt->fetchColumn();
  }

  //---------------------------------------------------------------------------
  /**
   * Sets the status of an issue (inside a transaction) and applies the resolution rules: the resolution is
   * set with the status Resolved, kept when a Resolved issue is closed, and cleared otherwise. A change
   * that leaves everything as it is writes nothing.
   *
   * @param array    $facts          The facts of the issue
   * @param int      $actorId        The acting user
   * @param string   $statusKey      The key of the new status
   * @param int|null $resolutionId   The resolution to set (Resolve), or null
   * @param bool     $keepResolution Whether the current resolution stays (Close)
   *
   * @return void
   */
  private function changeStatus(array $facts, int $actorId, string $statusKey, ?int $resolutionId, bool $keepResolution): void
  {
    $status = $this->issues->statusByKey($statusKey);
    if ($status === null) {
      throw new \RuntimeException("The status $statusKey is missing");
    }

    $oldResolutionId = $facts['resolution'] === null ? null : (int)$facts['resolution']['id'];
    $newResolutionId = $keepResolution ? $oldResolutionId : $resolutionId;

    $this->db->prepare('UPDATE issues SET status_id = :status, resolution_id = :resolution, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
      ->execute(['status' => $status['id'], 'resolution' => $newResolutionId, 'id' => $facts['id']]);

    if ($status['id'] !== $facts['status_id']) {
      $this->history->log($facts['id'], $actorId, 'field_changed', 'status', $facts['status']['key'] ?? $facts['status']['name'], $status['key']);
    }
    if ($newResolutionId !== $oldResolutionId) {
      $this->history->log($facts['id'], $actorId, 'field_changed', 'resolution', $this->issues->recordValue('resolutions', $oldResolutionId), $this->issues->recordValue('resolutions', $newResolutionId));
    }
  }
}
