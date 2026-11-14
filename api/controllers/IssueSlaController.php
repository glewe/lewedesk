<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\IssueModel;
use App\Services\AuditLog;
use App\Services\IssueHistory;
use App\Services\IssueVisibility;
use App\Services\SlaOverrideException;
use App\Services\SlaService;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

//---------------------------------------------------------------------------
/**
 * REST controller for the SLA timers of one issue (see _docs/SLA.md, section 6).
 *
 *   PUT /issues/{id}/sla/{metric}   change the due time by hand ({"due_at": "2026-11-14T10:00:00Z", "reason": "..."});
 *                                   metric is first_response or resolution
 *
 * The timers are read with the issue (GET /issues/{id} has the list "sla" for the holders of view_sla).
 *
 * The change is written to the history of the issue (an entry that only users who may see internal comments get to see)
 * and to the audit log. The timer is marked as overridden, and no later change of the policy touches it.
 *
 * Authorization (manage_sla and the license) is done by the caller (index.php); an issue the user may not see answers 404.
 */
class IssueSlaController
{
  public function __construct(
    private readonly IssueModel $issues,
    private readonly IssueVisibility $visibility,
    private readonly SlaService $sla,
    private readonly AuditLog $audit,
    private readonly PDO $db
  ) {
  }

  //---------------------------------------------------------------------------
  /**
   * Dispatches the request.
   *
   * @param string $method HTTP method
   * @param int    $id     The issue
   * @param string $metric first_response or resolution
   *
   * @return void
   */
  public function handle(string $method, int $id, string $metric): void
  {
    if ($method !== 'PUT') {
      $this->respond(['status' => 'error', 'message' => 'Method not allowed'], 405);
      return;
    }

    $issue = $this->issues->find($id);
    if ($issue === null) {
      $this->respond(['status' => 'error', 'message' => 'Issue not found'], 404);
      return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $input = is_array($input) ? $input : [];
    $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
    if ($reason === '' || mb_strlen($reason) > 255 || preg_match('/[\x00-\x1F\x7F]/', $reason)) {
      $this->respond(['status' => 'error', 'message' => 'The reason is required and may have up to 255 characters (one line)'], 400);
      return;
    }
    $dueAt = $this->time($input['due_at'] ?? null);
    if ($dueAt === null) {
      $this->respond(['status' => 'error', 'message' => 'due_at must be a date and time such as 2026-11-14T10:00:00Z'], 400);
      return;
    }

    try {
      $result = $this->issues->transaction(function () use ($id, $metric, $dueAt, $reason, $issue): array {
        $result = $this->sla->override($id, $metric, $dueAt, $reason);
        $iso = static fn(?int $time): ?string => $time === null ? null : gmdate('Y-m-d\TH:i:s\Z', $time);
        (new IssueHistory($this->db))->log($id, $this->visibility->viewerId(), 'field_changed', 'sla_' . $metric, $iso($result['old']), $iso($result['new']), true);
        $this->audit->log('sla_timer.overridden', null, 'issue', $id, (string)$issue['key'], ['due_at' => $iso($result['old'])], ['due_at' => $iso($result['new']), 'metric' => $metric, 'reason' => $reason]);

        return $result;
      });
    } catch (SlaOverrideException $e) {
      $this->respond(['status' => 'error', 'message' => $e->getMessage()], $e->getCode() ?: 400);
      return;
    } catch (Throwable $e) {
      error_log('SLA override failed: ' . $e->getMessage());
      $this->respond(['status' => 'error', 'message' => 'The due time could not be changed'], 500);
      return;
    }

    $this->respond(['status' => 'success', 'message' => 'Due time changed', 'data' => $result['timer']]);
  }

  //---------------------------------------------------------------------------
  /**
   * Reads a date and time as a Unix timestamp. Times without a zone are UTC.
   *
   * @param mixed $value The input
   *
   * @return int|null Null if it is not a valid date and time
   */
  private function time(mixed $value): ?int
  {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/', trim($value))) {
      return null;
    }
    try {
      return (new DateTimeImmutable(trim($value), new DateTimeZone('UTC')))->getTimestamp();
    } catch (Throwable) {
      return null;
    }
  }

  //---------------------------------------------------------------------------
  /**
   * @param array $payload The body
   * @param int   $status  The HTTP status
   *
   * @return void
   */
  private function respond(array $payload, int $status = 200): void
  {
    http_response_code($status);
    echo json_encode($payload);
  }
}
