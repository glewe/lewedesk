<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AttachmentModel;
use App\Models\IssueModel;
use App\Services\AttachmentStorage;
use App\Services\IssueActions;
use App\Services\IssueVisibility;
use App\Utils\IssueConfig;

//---------------------------------------------------------------------------
/**
 * REST controller for the attachments of issues.
 *
 *   GET    /issues/{id}/attachments                  list, with the limits and what the viewer may do
 *   POST   /issues/{id}/attachments                  upload (multipart: file; optional inline=1, internal=1)
 *   DELETE /issues/{id}/attachments/{attachmentId}   delete
 *   GET    /attachments/{id}                         download (the file, after the access check)
 *   GET    /attachments/temp?token=...               list the uploads made while a new issue is written
 *   POST   /attachments/temp                         upload for a new issue (multipart: file, token; optional inline=1)
 *   DELETE /attachments/temp/{id}                    delete such an upload
 *
 * Authorization on the route level (view_issue to read, a login to change, create_issue for the temporary
 * uploads) is done by the caller (index.php). The rest is decided here: the visibility rules for the issue
 * (an issue the viewer may not see is answered with 404, and so is an attachment the viewer may not see), the
 * rules of App\Services\IssueActions for adding and deleting, and the limits of the Issue Config settings.
 * Files are only handed out by download() and never from a path of the web server.
 */
class AttachmentController
{
  private AttachmentModel $model;
  private AttachmentStorage $storage;
  private IssueModel $issues;
  private IssueActions $actions;
  private IssueVisibility $visibility;
  private IssueConfig $config;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param AttachmentModel   $model      The model
   * @param AttachmentStorage $storage    The file storage
   * @param IssueModel        $issues     The issue model (reads issues through the visibility check)
   * @param IssueActions      $actions    The rules for adding and deleting attachments
   * @param IssueVisibility   $visibility The visibility rules
   * @param IssueConfig       $config     The issue settings (limits)
   */
  public function __construct(AttachmentModel $model, AttachmentStorage $storage, IssueModel $issues, IssueActions $actions, IssueVisibility $visibility, IssueConfig $config)
  {
    $this->model = $model;
    $this->storage = $storage;
    $this->issues = $issues;
    $this->actions = $actions;
    $this->visibility = $visibility;
    $this->config = $config;
  }

  //---------------------------------------------------------------------------
  /**
   * Dispatches a request for the attachments of an issue.
   *
   * @param string   $method       HTTP method
   * @param int      $issueId      The issue number
   * @param int|null $attachmentId The attachment number, or null for the attachments of the issue
   *
   * @return void
   */
  public function handleIssue(string $method, int $issueId, ?int $attachmentId): void
  {
    $issue = $this->issues->find($issueId);
    if ($issue === null) {
      $this->fail(404, 'Issue not found');
      return;
    }
    $facts = $this->actions->facts($issue);

    if ($attachmentId === null) {
      match ($method) {
        'GET' => $this->list($facts),
        'POST' => $this->upload($facts),
        default => $this->fail(405, 'Method not allowed'),
      };
      return;
    }

    $method === 'DELETE' ? $this->delete($facts, $attachmentId) : $this->fail(405, 'Method not allowed');
  }

  //---------------------------------------------------------------------------
  /**
   * Dispatches a request for the temporary uploads of a new issue.
   *
   * @param string   $method HTTP method
   * @param int|null $id     The attachment number, or null
   *
   * @return void
   */
  public function handleTemporary(string $method, ?int $id): void
  {
    $userId = (int)$this->visibility->viewerId();

    if ($id !== null) {
      $method === 'DELETE' ? $this->deleteTemporary($id, $userId) : $this->fail(405, 'Method not allowed');
      return;
    }

    match ($method) {
      'GET' => $this->listTemporary($userId),
      'POST' => $this->uploadTemporary($userId),
      default => $this->fail(405, 'Method not allowed'),
    };
  }

  //---------------------------------------------------------------------------
  /**
   * Hands out the file of an attachment. Images are shown in the browser, everything else is a download. The
   * file is never interpreted by the browser: fixed content type, nosniff, and a policy that forbids everything.
   *
   * @param int $id The attachment number
   *
   * @return void
   */
  public function download(int $id): void
  {
    $attachment = $this->model->findVisible($id);
    $path = $attachment === null ? '' : $this->storage->path($attachment['stored_name']);
    if ($attachment === null || !is_file($path)) {
      $this->fail(404, 'Attachment not found');
      return;
    }

    $disposition = $attachment['is_image'] ? 'inline' : 'attachment';
    $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $attachment['name']) ?? 'file';
    header('Content-Type: ' . $attachment['mime_type']);
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header("Content-Disposition: $disposition; filename=\"$fallback\"; filename*=UTF-8''" . rawurlencode($attachment['name']));
    header('Cache-Control: private, no-cache');
    header('Content-Length: ' . filesize($path));
    readfile($path);
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the attachments of an issue. The answer tells what the viewer may do, so the page offers nothing the
   * server would refuse.
   *
   * @param array $facts The facts of the visible issue
   */
  private function list(array $facts): void
  {
    $issueId = $facts['id'];
    $used = $this->model->usedInline($issueId);
    $items = array_map(
      fn(array $attachment): array => $this->present($attachment, $facts, in_array($attachment['id'], $used, true)),
      $this->model->list($issueId, $this->visibility->canViewInternalComments())
    );
    $denial = $this->actions->attachDenial($facts);

    $this->respond([
      'status' => 'success',
      'data' => $items,
      'meta' => [
        'count' => count($items),
        'can_add' => $denial === null,
        // Why a user who may attach files cannot now: "deleted" or "closed"
        'blocked' => $denial !== null && $denial[0] === 409 ? ($facts['deleted_at'] !== null ? 'deleted' : 'closed') : null,
        'limits' => $this->limits(),
      ],
    ]);
  }

  //---------------------------------------------------------------------------
  /**
   * Adds an uploaded file to an issue.
   *
   * @param array $facts The facts of the visible issue
   */
  private function upload(array $facts): void
  {
    $denial = $this->actions->attachDenial($facts);
    if ($denial !== null) {
      $this->fail($denial[0], $denial[1]);
      return;
    }

    $file = $this->checkedFile();
    if ($file === null) {
      return;
    }
    $inline = $this->flag('inline');
    // Only users who see internal comments can write one, and only they can mark an image as belonging to one
    $internal = $this->flag('internal');
    if ($internal && !($inline && $this->visibility->canViewInternalComments())) {
      $this->fail(403, 'Permission required: view_internal_comments');
      return;
    }

    $stored = $this->storage->store($file['tmp']);
    if ($stored === null) {
      $this->fail(500, 'The file could not be stored');
      return;
    }

    $userId = (int)$this->visibility->viewerId();
    try {
      $id = $this->model->addToIssue($facts['id'], $file, $stored, $userId, $inline, $internal, $this->config->attachmentMaxCount(), $this->config->attachmentMaxTotalSize());
    } catch (\Throwable $e) {
      $this->storage->remove($stored);
      throw $e;
    }
    if (is_string($id)) {
      $this->storage->remove($stored);
      $this->fail(409, $id);
      return;
    }

    $attachment = $this->model->find($id);
    $this->respond(['status' => 'success', 'message' => 'Attachment added', 'data' => $attachment === null ? ['id' => $id] : $this->present($attachment, $facts, false)], 201);
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes an attachment of an issue. The inline images that show it turn into the note "Image removed".
   *
   * @param array $facts        The facts of the visible issue
   * @param int   $attachmentId The attachment number
   */
  private function delete(array $facts, int $attachmentId): void
  {
    $attachment = $this->model->find($attachmentId);
    // An attachment of another issue, or one the viewer may not see, is not found
    if ($attachment === null || $attachment['issue_id'] !== $facts['id'] || ($attachment['is_internal'] && !$this->visibility->canViewInternalComments())) {
      $this->fail(404, 'Attachment not found');
      return;
    }
    if ($facts['deleted_at'] !== null) {
      $this->fail(409, 'A deleted issue cannot be changed');
      return;
    }
    if (!$this->actions->canDeleteAttachment($attachment, $facts)) {
      $this->fail(403, 'You are not allowed to delete this attachment');
      return;
    }

    $this->model->deleteFromIssue($attachment, (int)$this->visibility->viewerId());
    $this->storage->remove($attachment['stored_name']);
    $this->respond(['status' => 'success', 'message' => 'Attachment deleted']);
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the uploads the user made for a new issue.
   *
   * @param int $userId The logged in user
   */
  private function listTemporary(int $userId): void
  {
    $token = $this->token($_GET['token'] ?? null);
    if ($token === null) {
      $this->fail(400, 'Upload token is invalid');
      return;
    }

    $items = array_map(fn(array $attachment): array => $this->present($attachment, null, false), $this->model->listTemporary($token, $userId));
    $this->respond(['status' => 'success', 'data' => $items, 'meta' => ['count' => count($items), 'can_add' => true, 'blocked' => null, 'limits' => $this->limits()]]);
  }

  //---------------------------------------------------------------------------
  /**
   * Stores a file that is uploaded while a new issue is written. It is attached when the issue is saved, and
   * deleted after the configured hours if the issue is never saved.
   *
   * @param int $userId The logged in user
   */
  private function uploadTemporary(int $userId): void
  {
    $token = $this->token($_POST['token'] ?? null);
    if ($token === null) {
      $this->fail(400, 'Upload token is invalid');
      return;
    }

    $file = $this->checkedFile();
    if ($file === null) {
      return;
    }

    // Opportunistic housekeeping: forgotten uploads of other users are cleaned up whenever somebody uploads
    $this->purgeExpired();

    $stored = $this->storage->store($file['tmp']);
    if ($stored === null) {
      $this->fail(500, 'The file could not be stored');
      return;
    }

    try {
      $id = $this->model->addTemporary($token, $file, $stored, $userId, $this->flag('inline'), $this->config->attachmentMaxCount(), $this->config->attachmentMaxTotalSize());
    } catch (\Throwable $e) {
      $this->storage->remove($stored);
      throw $e;
    }
    if (is_string($id)) {
      $this->storage->remove($stored);
      $this->fail(409, $id);
      return;
    }

    $attachment = $this->model->find($id);
    $this->respond(['status' => 'success', 'message' => 'Attachment added', 'data' => $attachment === null ? ['id' => $id] : $this->present($attachment, null, false)], 201);
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes an upload of a new issue (only its uploader can).
   *
   * @param int $id     The attachment number
   * @param int $userId The logged in user
   */
  private function deleteTemporary(int $id, int $userId): void
  {
    $attachment = $this->model->find($id);
    if ($attachment === null || $attachment['issue_id'] !== null || $attachment['uploader_id'] !== $userId) {
      $this->fail(404, 'Attachment not found');
      return;
    }

    $this->model->deleteTemporary($id);
    $this->storage->remove($attachment['stored_name']);
    $this->respond(['status' => 'success', 'message' => 'Attachment deleted']);
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes the temporary uploads that are older than the configured number of hours, with their files.
   *
   * @return void
   */
  public function purgeExpired(): void
  {
    foreach ($this->model->purgeExpired($this->config->tempUploadCleanupHours()) as $stored) {
      $this->storage->remove($stored);
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Takes the uploaded file of the request and checks it. Sends the error response itself when the file is not
   * acceptable.
   *
   * @return array|null The checked file (name, mime, size, tmp), or null after an error response was sent
   */
  private function checkedFile(): ?array
  {
    // A request larger than post_max_size arrives without any data
    if ($_FILES === [] && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
      $this->fail(413, 'The file is too large (maximum ' . AttachmentStorage::megabytes($this->config->attachmentMaxSize()) . ')');
      return null;
    }
    if (!isset($_FILES['file']) || !is_array($_FILES['file']) || is_array($_FILES['file']['error'] ?? null)) {
      $this->fail(400, 'No file was received');
      return null;
    }

    $file = $this->storage->inspect($_FILES['file'], $this->config->attachmentMaxSize());
    if (isset($file['error'])) {
      $this->fail($file['error'][0], $file['error'][1]);
      return null;
    }

    return $file;
  }

  //---------------------------------------------------------------------------
  /**
   * Shapes an attachment for the API: with what the viewer may do with it, without the stored name and the id of
   * the uploader's account.
   *
   * @param array      $attachment The attachment of the model
   * @param array|null $facts      The facts of its issue (null for a temporary upload)
   * @param bool       $usedInline Whether the description or a comment shows it as image
   *
   * @return array
   */
  private function present(array $attachment, ?array $facts, bool $usedInline): array
  {
    unset($attachment['stored_name'], $attachment['uploader_id'], $attachment['comment_id']);
    $attachment['used_inline'] = $usedInline;
    $attachment['can_delete'] = $facts === null
      ? true
      : $this->actions->canDeleteAttachment(['uploader_id' => $attachment['uploader']['id']], $facts);

    return $attachment;
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the limits to the page.
   *
   * @return array{max_size: int, max_count: int, max_total_size: int, extensions: string[]}
   */
  private function limits(): array
  {
    return [
      'max_size' => $this->config->attachmentMaxSize(),
      'max_count' => $this->config->attachmentMaxCount(),
      'max_total_size' => $this->config->attachmentMaxTotalSize(),
      'extensions' => ['txt', 'md', 'pdf', 'zip', 'gif', 'jpg', 'png'],
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * Reads the token of a temporary upload: 32 hexadecimal characters.
   *
   * @param mixed $value The raw value
   *
   * @return string|null Null if it is not a token
   */
  private function token(mixed $value): ?string
  {
    return is_string($value) && preg_match('/^[a-f0-9]{32}$/', $value) === 1 ? $value : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Reads a flag of the multipart form: "1" or "true" is on.
   *
   * @param string $name The field name
   *
   * @return bool
   */
  private function flag(string $name): bool
  {
    return in_array($_POST[$name] ?? '', ['1', 'true'], true);
  }

  //---------------------------------------------------------------------------
  /**
   * Sends a JSON response.
   */
  private function respond(array $payload, int $status = 200): void
  {
    http_response_code($status);
    echo json_encode($payload);
  }

  //---------------------------------------------------------------------------
  /**
   * Sends a JSON error response.
   */
  private function fail(int $status, string $message): void
  {
    $this->respond(['status' => 'error', 'message' => $message], $status);
  }
}
