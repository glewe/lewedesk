<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\CustomViewModel;
use App\Services\AuditLog;

//---------------------------------------------------------------------------
/**
 * REST controller for the custom views of the issue list (see _docs/VIEWS.md).
 *
 *   GET    /custom-views             the views for the select of the issue list: own views first, then the shared views of others
 *   GET    /custom-views?scope=own   the views of the logged in user
 *   GET    /custom-views?scope=all   all views (the caller (index.php) requires manage_custom_views)
 *   GET    /custom-views/{id}        one view: its owner, any logged in user for a shared view, manage_custom_views for all
 *   POST   /custom-views             create ({"name", "shared", "filters"}); 409 with code name_exists and existing_id when the user has a view with that name
 *   PUT    /custom-views/{id}        change name, shared and filters (only the fields that are sent); owner or manage_custom_views
 *   DELETE /custom-views/{id}        delete; owner or manage_custom_views
 *
 * A view that the user may not see answers 404, a visible one that the user may not change answers 403.
 * The login is required by the caller (index.php).
 */
class CustomViewController
{
  private CustomViewModel $model;
  private AuditLog $audit;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param CustomViewModel $model The model
   * @param AuditLog        $audit The audit log
   */
  public function __construct(CustomViewModel $model, AuditLog $audit)
  {
    $this->model = $model;
    $this->audit = $audit;
  }

  //---------------------------------------------------------------------------
  /**
   * Dispatches the request.
   *
   * @param string      $method    HTTP method
   * @param int         $userId    The logged in user
   * @param bool        $canManage Whether the user holds manage_custom_views
   * @param int|null    $id        The view id of the path
   * @param string|null $scope     The "scope" parameter of a list request
   *
   * @return void
   */
  public function handle(string $method, int $userId, bool $canManage, ?int $id, ?string $scope): void
  {
    if ($id === null) {
      match ($method) {
        'GET' => $this->list($userId, $canManage, $scope),
        'POST' => $this->create($userId),
        default => $this->fail(405, 'Method not allowed'),
      };
      return;
    }

    $view = $this->model->find($id);
    $own = $view !== null && ($view['owner']['id'] ?? null) === $userId;
    if ($view === null || (!$own && !$view['shared'] && !$canManage)) {
      $this->fail(404, 'Custom view not found');
      return;
    }

    if ($method === 'GET') {
      $this->respond(['status' => 'success', 'data' => $this->present($view, $userId)]);
      return;
    }
    if (!in_array($method, ['PUT', 'DELETE'], true)) {
      $this->fail(405, 'Method not allowed');
      return;
    }
    if (!$own && !$canManage) {
      $this->audit->logFailure('security.permission_denied', null, 'customview', $id, $view['name'], null, ['message' => 'Custom view of another user']);
      $this->fail(403, 'Only the owner can change this custom view');
      return;
    }

    $method === 'PUT' ? $this->update($view, $userId) : $this->delete($view);
  }

  //---------------------------------------------------------------------------
  /**
   * Lists views.
   *
   * @param int         $userId    The logged in user
   * @param bool        $canManage Whether the user holds manage_custom_views
   * @param string|null $scope     null (own and shared), "own" or "all"
   *
   * @return void
   */
  private function list(int $userId, bool $canManage, ?string $scope): void
  {
    if ($scope === 'all' && !$canManage) {
      $this->fail(403, 'Permission required: manage_custom_views');
      return;
    }
    if ($scope !== null && !in_array($scope, ['own', 'all'], true)) {
      $this->fail(400, 'Unknown scope');
      return;
    }

    $views = match ($scope) {
      'own' => $this->model->listOwn($userId),
      'all' => $this->model->listAll(),
      default => $this->model->listVisible($userId),
    };
    $this->respond(['status' => 'success', 'data' => array_map(fn(array $view): array => $this->present($view, $userId), $views)]);
  }

  //---------------------------------------------------------------------------
  /**
   * Creates a view.
   *
   * @param int $userId The logged in user, who becomes the owner
   *
   * @return void
   */
  private function create(int $userId): void
  {
    $input = $this->input();
    $name = CustomViewModel::cleanName($input['name'] ?? null);
    $filters = CustomViewModel::cleanFilters($input['filters'] ?? []);
    $shared = $input['shared'] ?? false;
    if ($name === null) {
      $this->fail(400, 'The name is required and may have up to ' . CustomViewModel::MAX_NAME . ' characters');
      return;
    }
    if ($filters === null || !is_bool($shared)) {
      $this->fail(400, 'Invalid filters or shared setting');
      return;
    }

    $existing = $this->model->findByName($userId, $name);
    if ($existing !== null) {
      $this->respond(['status' => 'error', 'code' => 'name_exists', 'message' => 'You already have a custom view with this name', 'existing_id' => $existing['id']], 409);
      return;
    }

    $id = $this->audit->transaction(function () use ($userId, $name, $shared, $filters): int {
      $id = $this->model->create($userId, $name, $shared, $filters);
      $this->audit->log('customview.created', null, 'customview', $id, $name, null, ['shared' => $shared, 'filters' => $filters]);
      return $id;
    });

    $this->respond(['status' => 'success', 'message' => 'Custom view saved', 'data' => $this->present($this->model->find($id), $userId)], 201);
  }

  //---------------------------------------------------------------------------
  /**
   * Changes a view. A name that the owner uses already for another view answers 409 (a view without owner has no such rule).
   *
   * @param array $view   The view before the change
   * @param int   $userId The logged in user
   *
   * @return void
   */
  private function update(array $view, int $userId): void
  {
    $input = $this->input();
    $name = array_key_exists('name', $input) ? CustomViewModel::cleanName($input['name']) : $view['name'];
    $shared = $input['shared'] ?? $view['shared'];
    $filters = array_key_exists('filters', $input) ? CustomViewModel::cleanFilters($input['filters']) : CustomViewModel::cleanFilters((array)$view['filters']);
    if ($name === null) {
      $this->fail(400, 'The name is required and may have up to ' . CustomViewModel::MAX_NAME . ' characters');
      return;
    }
    if ($filters === null || !is_bool($shared)) {
      $this->fail(400, 'Invalid filters or shared setting');
      return;
    }

    $ownerId = $view['owner']['id'] ?? null;
    $clash = $ownerId !== null ? $this->model->findByName($ownerId, $name, $view['id']) : null;
    if ($clash !== null) {
      $this->respond(['status' => 'error', 'code' => 'name_exists', 'message' => 'A custom view with this name exists already', 'existing_id' => $clash['id']], 409);
      return;
    }

    $before = ['name' => $view['name'], 'shared' => $view['shared'], 'filters' => (array)$view['filters']];
    $after = ['name' => $name, 'shared' => $shared, 'filters' => $filters];
    $diff = AuditLog::diff($before, $after, ['name', 'shared', 'filters']);

    $this->audit->transaction(function () use ($view, $name, $shared, $filters, $diff): void {
      $this->model->update($view['id'], $name, $shared, $filters);
      if ($diff['new'] !== []) {
        $this->audit->log('customview.updated', null, 'customview', $view['id'], $name, $diff['old'], $diff['new']);
      }
    });

    $this->respond(['status' => 'success', 'message' => 'Custom view saved', 'data' => $this->present($this->model->find($view['id']), $userId)]);
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes a view.
   *
   * @param array $view The view
   *
   * @return void
   */
  private function delete(array $view): void
  {
    $this->audit->transaction(function () use ($view): void {
      $this->model->delete($view['id']);
      $this->audit->log('customview.deleted', null, 'customview', $view['id'], $view['name'], ['shared' => $view['shared'], 'filters' => (array)$view['filters']]);
    });

    $this->respond(['status' => 'success', 'message' => 'Custom view deleted']);
  }

  //---------------------------------------------------------------------------
  /**
   * Adds the "own" marker to a view: whether the logged in user is its owner.
   *
   * @param array $view   The view (see CustomViewModel::format())
   * @param int   $userId The logged in user
   *
   * @return array
   */
  private function present(array $view, int $userId): array
  {
    $view['own'] = ($view['owner']['id'] ?? null) === $userId;

    return $view;
  }

  //---------------------------------------------------------------------------
  /**
   * Reads the JSON body.
   *
   * @return array
   */
  private function input(): array
  {
    $input = json_decode(file_get_contents('php://input'), true);

    return is_array($input) ? $input : [];
  }

  //---------------------------------------------------------------------------
  /**
   * Sends a JSON response.
   *
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

  //---------------------------------------------------------------------------
  /**
   * Sends a JSON error response.
   *
   * @param int    $status  The HTTP status
   * @param string $message The message
   *
   * @return void
   */
  private function fail(int $status, string $message): void
  {
    $this->respond(['status' => 'error', 'message' => $message], $status);
  }
}
