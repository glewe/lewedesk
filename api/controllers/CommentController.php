<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\CommentModel;
use App\Services\CommentPolicy;
use App\Services\NotificationService;
use App\Services\RateLimiter;

//---------------------------------------------------------------------------
/**
 * REST controller for the comments of an issue.
 *
 *   GET    /issues/{id}/comments               list (?order=asc|desc, default asc), with the rights of the viewer
 *   POST   /issues/{id}/comments               add ({"body": "...", "internal": false})
 *   PUT    /issues/{id}/comments/{commentId}   edit the text ({"body": "..."})
 *   DELETE /issues/{id}/comments/{commentId}   delete (the timeline shows a placeholder)
 *
 * Authorization on the route level (view_issue to read, comment_issue to add, a login to change) is done by
 * the caller (index.php). The rest is decided here by App\Services\CommentPolicy and by the visibility
 * rules inside the model. An issue the viewer may not see is answered with 404, as if it did not exist, and
 * so is a comment the viewer may not see (an internal one, for example).
 */
class CommentController
{
  private const MAX_BODY = 20000;

  private CommentModel $model;
  private CommentPolicy $policy;
  private RateLimiter $limiter;
  private NotificationService $notifier;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param CommentModel  $model   The model
   * @param CommentPolicy $policy  The comment rules
   * @param RateLimiter   $limiter The limit of comments per hour
   * @param NotificationService $notifier The notifications about new comments
   */
  public function __construct(CommentModel $model, CommentPolicy $policy, RateLimiter $limiter, NotificationService $notifier)
  {
    $this->model = $model;
    $this->policy = $policy;
    $this->limiter = $limiter;
    $this->notifier = $notifier;
  }

  //---------------------------------------------------------------------------
  /**
   * Dispatches the request.
   *
   * @param string   $method    HTTP method
   * @param int      $issueId   The issue number
   * @param int|null $commentId The comment number, or null for the comments of the issue
   *
   * @return void
   */
  public function handle(string $method, int $issueId, ?int $commentId): void
  {
    $issue = $this->model->issue($issueId);
    if ($issue === null) {
      $this->fail(404, 'Issue not found');
      return;
    }

    if ($commentId === null) {
      match ($method) {
        'GET' => $this->list($issue),
        'POST' => $this->create($issue),
        default => $this->fail(405, 'Method not allowed'),
      };
      return;
    }

    match ($method) {
      'PUT' => $this->update($issue, $commentId),
      'DELETE' => $this->delete($issue, $commentId),
      default => $this->fail(405, 'Method not allowed'),
    };
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the comments of an issue. The answer tells what the viewer may do, so the page offers nothing
   * the server would refuse.
   *
   * @param array $issue The visible issue
   */
  private function list(array $issue): void
  {
    $order = $_GET['order'] ?? 'asc';
    if ($order !== 'asc' && $order !== 'desc') {
      $this->fail(400, 'Order must be asc or desc');
      return;
    }

    $comments = array_map(
      fn(array $comment): array => $this->present($comment, $issue),
      $this->model->list($issue['id'], $this->policy->canSeeInternal(), $order)
    );

    $this->respond([
      'status' => 'success',
      'data' => $comments,
      'meta' => [
        // Deleted comments are placeholders and do not count
        'count' => count(array_filter($comments, fn(array $comment): bool => !$comment['deleted'])),
        'can_comment' => $this->policy->canComment($issue),
        'can_internal' => $this->policy->canPostInternal(),
        // Why a user who may comment cannot: "deleted" or "closed"
        'blocked' => $this->policy->mayComment() ? $this->policy->blockedReason($issue) : null,
      ],
    ]);
  }

  //---------------------------------------------------------------------------
  /**
   * Adds a comment.
   *
   * @param array $issue The visible issue
   */
  private function create(array $issue): void
  {
    $reason = $this->policy->blockedReason($issue);
    if ($reason !== null) {
      $this->fail(409, $reason === 'deleted' ? 'A deleted issue cannot be commented' : 'A closed issue cannot be commented');
      return;
    }
    if (!$this->policy->canComment($issue)) {
      $this->fail(403, 'Permission required: comment_issue');
      return;
    }

    $input = $this->input();
    $body = $this->validBody($input['body'] ?? null);
    if ($body['error'] !== null) {
      $this->fail(400, $body['error']);
      return;
    }

    $internal = $input['internal'] ?? false;
    if (!is_bool($internal)) {
      $this->fail(400, 'Internal must be true or false');
      return;
    }
    if ($internal && !$this->policy->canPostInternal()) {
      $this->fail(403, 'Permission required: view_internal_comments');
      return;
    }

    // canComment() above guarantees a logged in user
    $viewerId = (int)$this->policy->viewerId();
    $wait = $this->limiter->hit($viewerId, RateLimiter::COMMENT);
    if ($wait !== null) {
      $this->tooMany('comments', $wait);
      return;
    }

    $id = $this->model->create($issue['id'], $viewerId, $body['text'], $internal);
    $this->notifier->notify($issue['id'], $viewerId, [NotificationService::COMMENT], $internal);
    $comment = $this->model->find($id, $issue['id'], true);
    $this->respond(['status' => 'success', 'message' => 'Comment added', 'data' => $comment === null ? ['id' => $id] : $this->present($comment, $issue)], 201);
  }

  //---------------------------------------------------------------------------
  /**
   * Changes the text of a comment.
   *
   * @param array $issue     The visible issue
   * @param int   $commentId The comment number
   */
  private function update(array $issue, int $commentId): void
  {
    $comment = $this->changeable($issue, $commentId);
    if ($comment === null) {
      return;
    }

    $body = $this->validBody($this->input()['body'] ?? null);
    if ($body['error'] !== null) {
      $this->fail(400, $body['error']);
      return;
    }

    $changed = $this->model->updateBody($commentId, $issue['id'], $body['text']);
    $comment = $this->model->find($commentId, $issue['id'], true);
    $this->respond([
      'status' => 'success',
      'message' => $changed ? 'Comment updated' : 'Comment unchanged',
      'data' => $comment === null ? ['id' => $commentId] : $this->present($comment, $issue),
    ]);
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes a comment.
   *
   * @param array $issue     The visible issue
   * @param int   $commentId The comment number
   */
  private function delete(array $issue, int $commentId): void
  {
    if ($this->changeable($issue, $commentId) === null) {
      return;
    }

    $this->model->delete($commentId, $issue['id']);
    $this->respond(['status' => 'success', 'message' => 'Comment deleted']);
  }

  //---------------------------------------------------------------------------
  /**
   * Finds the comment a request wants to change and checks that the viewer may change it. Sends the error
   * response itself when not.
   *
   * @param array $issue     The visible issue
   * @param int   $commentId The comment number
   *
   * @return array|null The comment, or null after an error response was sent
   */
  private function changeable(array $issue, int $commentId): ?array
  {
    $comment = $this->model->find($commentId, $issue['id'], $this->policy->canSeeInternal());
    // A deleted comment is gone for everybody
    if ($comment === null || $comment['deleted_at'] !== null) {
      $this->fail(404, 'Comment not found');
      return null;
    }
    if ($issue['deleted_at'] !== null) {
      $this->fail(409, 'A deleted issue cannot be changed');
      return null;
    }
    if (!$this->policy->canChange($comment, $issue)) {
      $this->fail(403, 'You are not allowed to change this comment');
      return null;
    }

    return $comment;
  }

  //---------------------------------------------------------------------------
  /**
   * Shapes a comment for the API: with what the viewer may do with it, and without the id of the author's account
   * (the author is given as user fields).
   *
   * @param array $comment The comment of the model
   * @param array $issue   The visible issue
   *
   * @return array
   */
  private function present(array $comment, array $issue): array
  {
    $canChange = $this->policy->canChange($comment, $issue);
    unset($comment['author_id']);

    return $comment + [
      'edited' => $comment['edited_at'] !== null,
      'deleted' => $comment['deleted_at'] !== null,
      'can_edit' => $canChange,
      'can_delete' => $canChange,
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * Validates the text of a comment.
   *
   * @param mixed $value The raw value
   *
   * @return array{text: string, error: string|null} The normalized text, or the error message
   */
  private function validBody(mixed $value): array
  {
    $text = is_string($value) && mb_check_encoding($value, 'UTF-8') ? trim(str_replace("\r\n", "\n", $value)) : null;

    $error = match (true) {
      $text === null => 'Comment must be text',
      $text === '' => 'Comment is required',
      mb_strlen($text) > self::MAX_BODY => 'Comment must not be longer than ' . self::MAX_BODY . ' characters',
      (bool)preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) => 'Comment contains invalid characters',
      default => null,
    };

    return ['text' => $text ?? '', 'error' => $error];
  }

  //---------------------------------------------------------------------------
  /**
   * Reads the JSON request body.
   */
  private function input(): array
  {
    $input = json_decode(file_get_contents('php://input'), true);

    return is_array($input) ? $input : [];
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
   * Sends the answer for a user who used up the comment limit (HTTP 429 with Retry-After).
   *
   * @param string $what The limited thing for the message
   * @param int    $wait Seconds until the next one is possible
   */
  private function tooMany(string $what, int $wait): void
  {
    header('Retry-After: ' . $wait);
    $this->respond([
      'status' => 'error',
      'message' => sprintf('Too many %s in the last hour. Try again in %d minute(s).', $what, (int)ceil($wait / 60)),
      'retry_after' => $wait,
    ], 429);
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
