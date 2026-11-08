<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AuditLogModel;
use App\Services\AuditLog;

//---------------------------------------------------------------------------
/**
 * REST controller for the audit log. Read only: there is no way to add, change or delete an entry through the API
 * (entries are written by App\Services\AuditLog and removed by the retention housekeeping).
 *
 *   GET /audit-log           a page of entries (filters, sorting, paging)
 *   GET /audit-log/{id}      one entry
 *   GET /audit-log/export    all entries that match the filters as a download (format=csv or json)
 *
 * Filters (query string, all optional): from, to (days, Y-m-d, inclusive), actor (user id or part of the username),
 * category, event, target_type, target_id, ip (start of the masked address), outcome (success or failure), q (free
 * text in event, actor and target). The list also takes sort (id, created_at, category, event, outcome, actor, target,
 * ip), dir (asc or desc), page and page_size (default 50, at most 200); "draw" is returned unchanged for DataTables.
 * The export takes sort and dir too, but no paging: it always delivers everything that matches.
 *
 * Authorization is done by the caller (index.php): the permission view_audit_log and the Administrator role.
 */
class AuditLogController
{
  private const DEFAULT_PAGE_SIZE = 50;
  private const MAX_PAGE_SIZE = 200;

  /** The columns of the export, in this order */
  private const EXPORT_COLUMNS = [
    'id', 'created_at', 'actor_id', 'actor_username', 'event', 'category', 'outcome', 'target_type', 'target_id',
    'target_label', 'old_value', 'new_value', 'ip', 'user_agent', 'request_id',
  ];

  /** The columns of the export that hold numbers (they are never changed by the protection against formulas) */
  private const NUMERIC_COLUMNS = ['id', 'actor_id', 'target_id'];

  private AuditLogModel $model;
  private AuditLog $audit;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param AuditLogModel $model The model
   * @param AuditLog      $audit The audit log (the export is logged)
   */
  public function __construct(AuditLogModel $model, AuditLog $audit)
  {
    $this->model = $model;
    $this->audit = $audit;
  }

  //---------------------------------------------------------------------------
  /**
   * Dispatches the request.
   *
   * @param string      $method HTTP method
   * @param string|null $param  The path segment after /audit-log ("12", "export" or null)
   *
   * @return void
   */
  public function handle(string $method, ?string $param): void
  {
    if ($method !== 'GET') {
      $this->fail(405, 'Method not allowed');
      return;
    }

    if ($param === null) {
      $this->list();
    } elseif ($param === 'export') {
      $this->export();
    } else {
      $this->read((int)$param);
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Lists a page of entries.
   *
   * @return void
   */
  private function list(): void
  {
    $filters = $this->filters();
    $order = $this->order();
    $page = $this->integer($_GET['page'] ?? null, 1, intdiv(PHP_INT_MAX, 1000), 1);
    $pageSize = $this->integer($_GET['page_size'] ?? null, 1, self::MAX_PAGE_SIZE, self::DEFAULT_PAGE_SIZE);
    if (is_string($filters) || is_string($order)) {
      $this->fail(400, is_string($filters) ? $filters : $order);
      return;
    }

    $result = $this->model->list($filters, $order[0], $order[1], $page, $pageSize);
    $this->respond([
      'status' => 'success',
      'data' => $result['rows'],
      'total' => $result['total'],
      'filtered' => $result['filtered'],
      'page' => $page,
      'page_size' => $pageSize,
      // The names DataTables uses in server-side mode
      'draw' => $this->integer($_GET['draw'] ?? null, 0, PHP_INT_MAX, 0),
      'recordsTotal' => $result['total'],
      'recordsFiltered' => $result['filtered'],
    ]);
  }

  //---------------------------------------------------------------------------
  /**
   * Reads one entry.
   *
   * @param int $id The entry id
   *
   * @return void
   */
  private function read(int $id): void
  {
    $entry = $id > 0 ? $this->model->get($id) : null;
    $entry !== null ? $this->respond(['status' => 'success', 'data' => $entry]) : $this->fail(404, 'Audit log entry not found');
  }

  //---------------------------------------------------------------------------
  /**
   * Sends all entries that match the filters as a CSV or JSON download. There is no row limit; the entries are read
   * and sent one by one, so the size of the log does not matter for the memory. The export is logged first (a bulk
   * extraction of this data is itself an event worth recording), with the filters and the number of entries.
   *
   * @return void
   */
  private function export(): void
  {
    $format = (string)($_GET['format'] ?? 'csv');
    $filters = $this->filters();
    $order = $this->order();
    if (!in_array($format, ['csv', 'json'], true)) {
      $this->fail(400, 'Format must be csv or json');
      return;
    }
    if (is_string($filters) || is_string($order)) {
      $this->fail(400, is_string($filters) ? $filters : $order);
      return;
    }

    $this->audit->log('audit.exported', null, null, null, null, null, [
      'format' => $format,
      'filters' => array_filter($filters, fn(string $value): bool => $value !== ''),
      'entries' => $this->model->count($filters),
    ]);

    set_time_limit(0);
    ignore_user_abort(false);
    while (ob_get_level() > 0) {
      ob_end_clean();
    }
    header('Content-Type: ' . ($format === 'csv' ? 'text/csv; charset=utf-8' : 'application/json; charset=utf-8'));
    header('Content-Disposition: attachment; filename="audit-log-' . date('Ymd-His') . '.' . $format . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    $entries = $this->model->stream($filters, $order[0], $order[1]);
    $format === 'csv' ? $this->writeCsv($entries) : $this->writeJson($entries);
  }

  //---------------------------------------------------------------------------
  /**
   * Writes the entries as CSV: UTF-8 with a byte order mark (so that spreadsheet programs read umlauts correctly), one
   * header line, the old and new values as JSON text.
   *
   * @param iterable<array> $entries The presented entries
   *
   * @return void
   */
  private function writeCsv(iterable $entries): void
  {
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, self::EXPORT_COLUMNS, ',', '"', '');

    $count = 0;
    foreach ($entries as $entry) {
      $cells = [];
      foreach (self::EXPORT_COLUMNS as $column) {
        $value = $entry[$column] ?? null;
        $cells[] = in_array($column, self::NUMERIC_COLUMNS, true) ? $value : self::csvCell($value);
      }
      fputcsv($out, $cells, ',', '"', '');
      if (++$count % 500 === 0) {
        flush();
      }
    }
    fclose($out);
  }

  //---------------------------------------------------------------------------
  /**
   * Writes the entries as one JSON array.
   *
   * @param iterable<array> $entries The presented entries
   *
   * @return void
   */
  private function writeJson(iterable $entries): void
  {
    echo "[\n";
    $first = true;
    $count = 0;
    foreach ($entries as $entry) {
      echo ($first ? '' : ",\n") . json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
      $first = false;
      if (++$count % 500 === 0) {
        flush();
      }
    }
    echo "\n]\n";
  }

  //---------------------------------------------------------------------------
  /**
   * Prepares a value for a CSV cell. Structures become JSON text. A text that a spreadsheet program would take for a
   * formula (it starts with =, +, -, @, a tab or a carriage return) gets a leading apostrophe, so that data in the log
   * (a user name, a label) cannot run anything when the file is opened.
   *
   * @param mixed $value The value
   *
   * @return string
   */
  private static function csvCell(mixed $value): string
  {
    if ($value === null) {
      return '';
    }
    $text = is_array($value) ? (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : (string)$value;

    return $text !== '' && strpbrk($text[0], "=+-@\t\r") !== false ? "'" . $text : $text;
  }

  //---------------------------------------------------------------------------
  /**
   * Reads and checks the filters of the query string.
   *
   * @return array<string, string>|string The filters (all keys, empty text = not set), or an error message
   */
  private function filters(): array|string
  {
    $filters = [];
    foreach (['from', 'to', 'actor', 'category', 'event', 'target_type', 'target_id', 'ip', 'outcome', 'q'] as $name) {
      $value = $_GET[$name] ?? '';
      if (!is_string($value)) {
        return "Filter $name must be a text";
      }
      $filters[$name] = trim($value);
    }

    foreach (['from', 'to'] as $name) {
      $day = $filters[$name];
      if ($day !== '' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]))) {
        return "Filter $name must be a day like 2026-01-31";
      }
    }
    if ($filters['from'] !== '' && $filters['to'] !== '' && $filters['from'] > $filters['to']) {
      return 'Filter from must not be after to';
    }

    $rules = [
      'actor' => ['/^.{1,50}$/u', 'Filter actor must be a user id or part of a username'],
      'category' => [null, 'Filter category is not known'],
      'event' => ['/^[a-z0-9_.]{1,50}$/', 'Filter event is not valid'],
      'target_type' => ['/^[a-z0-9_]{1,30}$/', 'Filter target_type is not valid'],
      'target_id' => ['/^\d{1,10}$/', 'Filter target_id must be a number'],
      'ip' => ['/^[0-9a-fA-F:.]{1,45}$/', 'Filter ip must be the start of an IP address'],
      'outcome' => [null, 'Filter outcome must be success or failure'],
      'q' => ['/^.{1,100}$/su', 'Filter q must not be longer than 100 characters'],
    ];
    foreach ($rules as $name => [$pattern, $message]) {
      $value = $filters[$name];
      if ($value === '') {
        continue;
      }
      $valid = match ($name) {
        'category' => in_array($value, AuditLogModel::CATEGORIES, true),
        'outcome' => in_array($value, ['success', 'failure'], true),
        default => preg_match($pattern, $value) === 1,
      };
      if (!$valid) {
        return $message;
      }
    }

    return $filters;
  }

  //---------------------------------------------------------------------------
  /**
   * Reads and checks the sort order of the query string (default: newest first).
   *
   * @return array{0: string, 1: string}|string The sort key and direction, or an error message
   */
  private function order(): array|string
  {
    $sort = $_GET['sort'] ?? 'created_at';
    $dir = strtolower((string)($_GET['dir'] ?? 'desc'));
    if (!is_string($sort) || !AuditLogModel::isSortKey($sort)) {
      return 'Unknown sort column';
    }
    if (!in_array($dir, ['asc', 'desc'], true)) {
      return 'Direction must be asc or desc';
    }

    return [$sort, $dir];
  }

  //---------------------------------------------------------------------------
  /**
   * Reads a whole number from a query string value; anything else, or a number outside of the range, gives the default.
   *
   * @param mixed $value   The value
   * @param int   $min     The smallest allowed number
   * @param int   $max     The largest allowed number
   * @param int   $default The default
   *
   * @return int
   */
  private function integer(mixed $value, int $min, int $max, int $default): int
  {
    if (!is_string($value) || !preg_match('/^\d{1,15}$/', $value)) {
      return $default;
    }
    $number = (int)$value;

    return $number >= $min && $number <= $max ? $number : $default;
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
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
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
