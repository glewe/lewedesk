<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\MailInboxModel;
use App\Services\InboundMailService;
use App\Services\Mailbox\MailboxException;
use App\Utils\IssueKey;
use App\Utils\MailInConfig;
use App\Utils\MailInSettings;

require_once __DIR__ . '/../utils/IssueKey.php';

//---------------------------------------------------------------------------
/**
 * REST controller for the administration of Email-to-ticket (see _docs/EMAIL_TO_TICKET.md).
 *
 *   GET  /mailin/status   the state: is it on, the mailbox (without the password), the last run, what happened in the last
 *                         24 hours and the latest mails of the log
 *   POST /mailin/test     logs in to the mailbox, looks at the folders and the inbox, changes nothing
 *   POST /mailin/run      reads the mailbox now ("Pick up now"); the poll interval starts again from this run
 *
 * The settings themselves (mailin_*) are saved with the other system settings. The permission (manage_settings) is
 * checked by the caller (index.php).
 */
class MailInController
{
  /** How many mails of the log are given */
  private const RECENT = 30;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param InboundMailService $service  The service that reads the mailbox
   * @param MailInConfig       $env      The mailbox (.env)
   * @param MailInSettings     $settings The mailin_* settings
   * @param MailInboxModel     $inbox    The log of the read mails
   * @param IssueKey           $keys     The issue keys
   */
  public function __construct(
    private readonly InboundMailService $service,
    private readonly MailInConfig $env,
    private readonly MailInSettings $settings,
    private readonly MailInboxModel $inbox,
    private readonly IssueKey $keys
  ) {
  }

  //---------------------------------------------------------------------------
  /**
   * Dispatches the request.
   *
   * @param string $method HTTP method
   * @param string $action status or test
   *
   * @return void
   */
  public function handle(string $method, string $action): void
  {
    if ($action === 'status' && $method === 'GET') {
      $this->respond(['status' => 'success', 'data' => $this->status()]);

      return;
    }
    if ($action === 'test' && $method === 'POST') {
      $this->test();

      return;
    }
    if ($action === 'run' && $method === 'POST') {
      $this->run();

      return;
    }

    $known = in_array($action, ['status', 'test', 'run'], true);
    $this->respond(['status' => 'error', 'message' => $known ? 'Method not allowed' : 'Not found'], $known ? 405 : 404);
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the state of Email-to-ticket.
   *
   * @return array<string, mixed>
   */
  private function status(): array
  {
    $last = $this->service->status();
    $recent = array_map(fn(array $row): array => [
      'id' => (int)$row['id'],
      'from' => (string)$row['from_address'],
      'subject' => (string)$row['subject'],
      'result' => (string)$row['result'],
      'reason' => $row['reason'] === null ? null : (string)$row['reason'],
      'attempts' => (int)$row['attempts'],
      'issue_id' => $row['issue_id'] === null ? null : (int)$row['issue_id'],
      'issue_key' => $row['issue_id'] === null ? null : $this->keys->forId((int)$row['issue_id']),
      'comment_id' => $row['comment_id'] === null ? null : (int)$row['comment_id'],
      'processed_at' => (string)$row['processed_at'],
    ], $this->inbox->recent(self::RECENT));

    return [
      'enabled' => $this->env->isEnabled(),
      'licensed' => $this->service->isLicensed(),
      'active' => $this->service->isActive(),
      'mailbox' => [
        'host' => $this->env->host(),
        'port' => $this->env->port(),
        'encryption' => $this->env->encryption(),
        'user' => $this->env->user(),
        'folder' => $this->env->folder(),
        'processed_folder' => $this->env->processedFolder(),
        'rejected_folder' => $this->env->rejectedFolder(),
        'auth_servers' => $this->env->authServers(),
      ],
      'poll_interval' => $this->settings->pollInterval(),
      'last_run' => $last === null ? null : ['time' => (int)$last['time'], 'running' => (bool)$last['running'], 'result' => $last['result']],
      'counts' => $this->inbox->counts(),
      'recent' => $recent,
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * Tests the connection to the mailbox.
   *
   * @return void
   */
  private function test(): void
  {
    if (!$this->env->isEnabled()) {
      $this->respond(['status' => 'error', 'message' => 'Email to ticket is not switched on in the .env file (MAILIN_YES=1, MAILIN_HOST and the login)'], 409);

      return;
    }

    try {
      $this->respond(['status' => 'success', 'data' => $this->service->testConnection()]);
    } catch (MailboxException $e) {
      // The message never contains the password
      $this->respond(['status' => 'error', 'message' => $e->getMessage()], 502);
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Reads the mailbox now. The answer tells what was done; "busy" is true when another run was working on the mailbox.
   *
   * @return void
   */
  private function run(): void
  {
    if (!$this->service->isActive()) {
      $this->respond(['status' => 'error', 'message' => 'Email to ticket is not switched on (MAILIN_YES=1 in the .env file, and the license)'], 409);

      return;
    }

    $result = $this->service->runNow();
    $this->respond(['status' => 'success', 'data' => ['busy' => $result === null, 'result' => $result]]);
  }

  //---------------------------------------------------------------------------
  /**
   * Sends a JSON answer.
   *
   * @param array<string, mixed> $body   The answer
   * @param int                  $status HTTP status code
   *
   * @return void
   */
  private function respond(array $body, int $status = 200): void
  {
    http_response_code($status);
    echo json_encode($body);
  }
}
