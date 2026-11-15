<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ProductVersionModel;
use App\Services\AuditLog;
use DateTimeImmutable;

//---------------------------------------------------------------------------
/**
 * REST controller for the versions of a product (see _docs/PRODUCTS.md).
 *
 *   GET    /products/{id}/versions                   list, the highest name first (1.10 above 1.9)
 *   POST   /products/{id}/versions                   create (name; description, status, release_date, link optional)
 *   GET    /products/{id}/versions/{vid}             read
 *   PATCH  /products/{id}/versions/{vid}             update (only the fields that are sent are changed; PUT works the same)
 *   DELETE /products/{id}/versions/{vid}             delete; when issues use the version, the body needs "replacement_id":
 *                                                    another version of the product, or "none" to clear the field of the issues
 *   GET    /products/{id}/versions/{vid}/issues      the release notes: the issues with this fixed version, grouped by issue type
 *
 * Authorization on the route level (the product permissions) is done by the caller (index.php). The release notes only
 * list the issues the viewer may see.
 */
class ProductVersionController
{
  private const MAX_NAME = 50;
  private const MAX_DESCRIPTION = 5000;
  private const MAX_LINK = 500;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param ProductVersionModel $model The model
   * @param AuditLog            $audit The audit log
   */
  public function __construct(private readonly ProductVersionModel $model, private readonly AuditLog $audit)
  {
  }

  //---------------------------------------------------------------------------
  /**
   * Dispatches the request.
   *
   * @param string      $method    HTTP method
   * @param int         $productId The product
   * @param int|null    $id        The version, or null for the collection
   * @param string|null $sub       "issues" for the release notes, or null
   *
   * @return void
   */
  public function handle(string $method, int $productId, ?int $id, ?string $sub): void
  {
    if (!$this->model->productExists($productId)) {
      $this->fail(404, 'Product not found');
      return;
    }

    if ($id === null) {
      match ($method) {
        'GET' => $this->respond(['status' => 'success', 'data' => $this->model->list($productId)]),
        'POST' => $this->create($productId),
        default => $this->fail(405, 'Method not allowed'),
      };
      return;
    }

    $version = $this->model->get($productId, $id);
    if ($version === null) {
      $this->fail(404, 'Version not found');
      return;
    }

    if ($sub === 'issues') {
      $method === 'GET'
        ? $this->respond(['status' => 'success', 'data' => ['version' => $version, 'groups' => $this->model->releaseNotes($id)]])
        : $this->fail(405, 'Method not allowed');
      return;
    }

    match ($method) {
      'GET' => $this->respond(['status' => 'success', 'data' => $version]),
      'PATCH', 'PUT' => $this->update($productId, $version),
      'DELETE' => $this->delete($productId, $version),
      default => $this->fail(405, 'Method not allowed'),
    };
  }

  //---------------------------------------------------------------------------
  /**
   * Creates a version.
   *
   * @param int $productId The product
   *
   * @return void
   */
  private function create(int $productId): void
  {
    $data = $this->validate($this->input(), $productId, null);
    if (is_string($data)) {
      $this->fail($data === 'Version already exists' ? 409 : 400, $data);
      return;
    }

    $id = $this->audit->transaction(function () use ($productId, $data): int {
      $id = $this->model->create($productId, $data);
      $this->audit->log('product.version_created', null, 'product_version', $id, $this->label($productId, $data['name']), null, $data);

      return $id;
    });

    $this->respond(['status' => 'success', 'message' => 'Version created', 'data' => $this->model->get($productId, $id)], 201);
  }

  //---------------------------------------------------------------------------
  /**
   * Updates the fields of a version that were sent.
   *
   * @param int   $productId The product
   * @param array $existing  The version as it is
   *
   * @return void
   */
  private function update(int $productId, array $existing): void
  {
    $data = $this->validate($this->input(), $productId, $existing);
    if (is_string($data)) {
      $this->fail($data === 'Version already exists' ? 409 : 400, $data);
      return;
    }

    $this->audit->transaction(function () use ($productId, $existing, $data): void {
      $this->model->update($existing['id'], $data);
      $diff = AuditLog::diff($existing, $data, array_keys($data));
      if ($diff['new'] !== []) {
        $this->audit->log('product.version_updated', null, 'product_version', $existing['id'], $this->label($productId, (string)($data['name'] ?? $existing['name'])), $diff['old'], $diff['new']);
      }
    });

    $this->respond(['status' => 'success', 'message' => 'Version updated', 'data' => $this->model->get($productId, $existing['id'])]);
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes a version. Issues that use it get the replacement version (or none) first.
   *
   * @param int   $productId The product
   * @param array $version   The version
   *
   * @return void
   */
  private function delete(int $productId, array $version): void
  {
    $inUse = $this->model->usageCount($version['id']);
    $replacementId = null;

    if ($inUse > 0) {
      $input = $this->input();
      if (!array_key_exists('replacement_id', $input) || $input['replacement_id'] === '' || $input['replacement_id'] === null) {
        $this->respond([
          'status' => 'error',
          'message' => "The version is used by $inUse issue(s). Choose a replacement or \"none\".",
          'replacement_required' => true,
          'in_use' => $inUse,
        ], 409);
        return;
      }

      $given = $input['replacement_id'];
      if ($given !== 'none') {
        if (is_bool($given) || is_array($given) || !preg_match('/^[1-9]\d{0,8}$/', (string)$given)) {
          $this->fail(400, 'Replacement must be a version id or "none"');
          return;
        }
        $replacementId = (int)$given;
        if ($replacementId === $version['id']) {
          $this->fail(400, 'The replacement must not be the version to delete');
          return;
        }
        if ($this->model->get($productId, $replacementId) === null) {
          $this->fail(400, 'Replacement version does not exist for this product');
          return;
        }
      }
    }

    $this->audit->transaction(function () use ($productId, $version, $replacementId, $inUse): void {
      $replacement = $replacementId === null ? null : ($this->model->get($productId, $replacementId)['name'] ?? null);
      $this->model->delete($version['id'], $replacementId);
      $this->audit->log(
        'product.version_deleted',
        null,
        'product_version',
        $version['id'],
        $this->label($productId, (string)$version['name']),
        ['name' => $version['name'], 'status' => $version['status']],
        $inUse > 0 ? ['replacement' => $replacement] : null
      );
    });

    $this->respond(['status' => 'success', 'message' => 'Version deleted']);
  }

  //---------------------------------------------------------------------------
  /**
   * Validates and normalizes the input. When creating, missing fields get their defaults; when updating, missing
   * fields stay unchanged (the rules look at the version as it will be).
   *
   * @param array      $input     The raw input
   * @param int        $productId The product
   * @param array|null $existing  The version as it is when updating
   *
   * @return array|string The fields to write, or an error message
   */
  private function validate(array $input, int $productId, ?array $existing): array|string
  {
    $data = [];

    if ($existing === null || array_key_exists('name', $input)) {
      $name = is_string($input['name'] ?? null) && mb_check_encoding($input['name'], 'UTF-8') ? trim($input['name']) : '';
      if ($name === '') {
        return 'Name is required';
      }
      if (mb_strlen($name) > self::MAX_NAME) {
        return 'Name must not be longer than ' . self::MAX_NAME . ' characters';
      }
      if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
        return 'Name must be a single line';
      }
      $other = $this->model->findByName($productId, $name);
      if ($other !== null && ($existing === null || $other['id'] !== $existing['id'])) {
        return 'Version already exists';
      }
      $data['name'] = $name;
    }

    if ($existing === null || array_key_exists('description', $input)) {
      $raw = $input['description'] ?? '';
      if (!is_string($raw) || !mb_check_encoding($raw, 'UTF-8')) {
        return 'Description must be text';
      }
      $description = trim(str_replace("\r\n", "\n", $raw));
      if (mb_strlen($description) > self::MAX_DESCRIPTION) {
        return 'Description must not be longer than ' . self::MAX_DESCRIPTION . ' characters';
      }
      if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $description)) {
        return 'Description contains invalid characters';
      }
      $data['description'] = $description === '' ? null : $description;
    }

    if ($existing === null || array_key_exists('status', $input)) {
      $status = $input['status'] ?? 'planned';
      if (!is_string($status) || !in_array($status, ProductVersionModel::STATUSES, true)) {
        return 'Status must be one of ' . implode(', ', ProductVersionModel::STATUSES);
      }
      $data['status'] = $status;
    }

    if ($existing === null || array_key_exists('release_date', $input)) {
      $raw = $input['release_date'] ?? null;
      if ($raw === null || $raw === '') {
        $data['release_date'] = null;
      } elseif (is_string($raw) && ($date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw)) !== false && $date->format('Y-m-d') === $raw) {
        $data['release_date'] = $raw;
      } else {
        return 'Release date must be a date like 2026-03-31';
      }
    }

    if ($existing === null || array_key_exists('link', $input)) {
      $raw = $input['link'] ?? null;
      if ($raw === null || $raw === '') {
        $data['link'] = null;
      } else {
        $link = is_string($raw) ? trim($raw) : '';
        $scheme = $link === '' ? null : parse_url($link, PHP_URL_SCHEME);
        if (
          $link === '' || mb_strlen($link) > self::MAX_LINK || preg_match('/[\x00-\x20\x7F]/', $link)
          || filter_var($link, FILTER_VALIDATE_URL) === false || !in_array(strtolower((string)$scheme), ['http', 'https'], true)
        ) {
          return 'Link must be a web address (http or https) of at most ' . self::MAX_LINK . ' characters';
        }
        $data['link'] = $link;
      }
    }

    // A released version has a release date (the form fills in today); the rule looks at the version as it will be
    $status = $data['status'] ?? $existing['status'] ?? 'planned';
    $date = array_key_exists('release_date', $data) ? $data['release_date'] : ($existing['release_date'] ?? null);
    if ($status === 'released' && $date === null) {
      return 'Release date is required for a released version';
    }

    return $data;
  }

  //---------------------------------------------------------------------------
  /**
   * Builds the label of a version for the audit log: the product and the version.
   *
   * @param int    $productId The product
   * @param string $name      The version name
   *
   * @return string
   */
  private function label(int $productId, string $name): string
  {
    return ($this->model->productName($productId) ?? '#' . $productId) . ' ' . $name;
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
   * Sends a JSON error response.
   */
  private function fail(int $status, string $message): void
  {
    $this->respond(['status' => 'error', 'message' => $message], $status);
  }
}
