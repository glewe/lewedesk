<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\SlaReportModel;
use DateTimeImmutable;
use DateTimeZone;

//---------------------------------------------------------------------------
/**
 * REST controller for the SLA report (see _docs/SLA.md, section 12).
 *
 *   GET /sla/reports?from=2026-11-01&to=2026-11-30&group_by=policy&tz=Europe/Berlin
 *   GET /sla/reports?...&format=csv&part=summary|breached     the same as a CSV file
 *
 * from and to are days (both included) in the time zone tz (default UTC); the default is the last 30 days. The period is
 * at most 366 days. group_by is policy (default), product, assignee or priority. The rule that decides which timer
 * belongs to a period is described at SlaReportModel.
 *
 * The caller (index.php) has checked the permission view_sla and the license; the model limits everything to the
 * issues the user may see.
 */
class SlaReportController
{
  /** The longest period of a report in days */
  public const MAX_DAYS = 366;

  public function __construct(private readonly SlaReportModel $model)
  {
  }

  //---------------------------------------------------------------------------
  /**
   * Dispatches the request.
   *
   * @param string $method HTTP method
   *
   * @return void
   */
  public function handle(string $method): void
  {
    if ($method !== 'GET') {
      $this->fail(405, 'Method not allowed');
    }

    $input = $this->input();
    if (is_string($input)) {
      $this->fail(400, $input);
    }
    ['from' => $from, 'to' => $to, 'group_by' => $groupBy, 'tz' => $tz, 'format' => $format, 'part' => $part] = $input;
    $zone = new DateTimeZone($tz);
    // Both days are included: the period ends at the start of the day after "to"
    $start = (new DateTimeImmutable($from . ' 00:00:00', $zone))->getTimestamp();
    $end = (new DateTimeImmutable($to . ' 00:00:00', $zone))->modify('+1 day')->getTimestamp();

    if ($format === 'csv') {
      $this->csv($part, $from, $to, $groupBy, $start, $end);
      return;
    }

    $report = $this->model->report($start, $end, $groupBy);
    $breachedTotal = 0;
    foreach ($report['totals'] as $stats) {
      $breachedTotal += $stats['met_late'] + $stats['breached_open'];
    }
    $breached = $this->model->breached($start, $end);

    echo json_encode([
      'status' => 'success',
      'data' => [
        'from' => $from,
        'to' => $to,
        'tz' => $tz,
        'group_by' => $groupBy,
        'totals' => $report['totals'],
        'groups' => $report['groups'],
        'breached' => $breached,
        'breached_total' => $breachedTotal,
        'breached_truncated' => $breachedTotal > count($breached),
      ],
    ]);
  }

  //---------------------------------------------------------------------------
  /**
   * Reads and checks the query parameters.
   *
   * @return array|string The checked values (from, to, group_by, tz, format, part), or the message of the first problem
   */
  private function input(): array|string
  {
    $text = static fn(string $name): ?string => isset($_GET[$name]) && is_string($_GET[$name]) && $_GET[$name] !== '' ? $_GET[$name] : null;
    foreach (['from', 'to', 'group_by', 'tz', 'format', 'part'] as $name) {
      if (isset($_GET[$name]) && !is_string($_GET[$name])) {
        return "Parameter $name must be a text";
      }
    }

    $tz = $text('tz') ?? 'UTC';
    if (strlen($tz) > 64 || !in_array($tz, DateTimeZone::listIdentifiers(), true)) {
      return 'Parameter tz must be a time zone such as Europe/Berlin';
    }
    $zone = new DateTimeZone($tz);

    $day = static function (?string $value): ?string {
      if ($value === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return null;
      }

      return $value;
    };
    foreach (['from', 'to'] as $name) {
      if ($text($name) !== null && $day($text($name)) === null) {
        return "Parameter $name must be a date (YYYY-MM-DD)";
      }
    }
    $to = $text('to') ?? (new DateTimeImmutable('now', $zone))->format('Y-m-d');
    $from = $text('from') ?? (new DateTimeImmutable($to . ' 00:00:00', $zone))->modify('-29 days')->format('Y-m-d');
    if ($from > $to) {
      return 'The start of the period must not be after its end';
    }
    $days = (int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1;
    if ($days > self::MAX_DAYS) {
      return 'The period must not be longer than ' . self::MAX_DAYS . ' days';
    }

    $groupBy = $text('group_by') ?? 'policy';
    if (!in_array($groupBy, SlaReportModel::GROUPS, true)) {
      return 'Parameter group_by must be one of: ' . implode(', ', SlaReportModel::GROUPS);
    }
    $format = $text('format') ?? 'json';
    if (!in_array($format, ['json', 'csv'], true)) {
      return 'Parameter format must be json or csv';
    }
    $part = $text('part') ?? 'summary';
    if (!in_array($part, ['summary', 'breached'], true)) {
      return 'Parameter part must be summary or breached';
    }

    return ['from' => $from, 'to' => $to, 'group_by' => $groupBy, 'tz' => $tz, 'format' => $format, 'part' => $part];
  }

  //---------------------------------------------------------------------------
  /**
   * Sends the report as a CSV file (UTF-8 with a byte order mark so that Excel reads the umlauts).
   *
   * @param string $part    summary (one row per group and metric) or breached (one row per breached timer)
   * @param string $from    The first day
   * @param string $to      The last day
   * @param string $groupBy The grouping of the summary
   * @param int    $start   The start of the period (Unix time)
   * @param int    $end     The end of the period (Unix time)
   *
   * @return void
   */
  private function csv(string $part, string $from, string $to, string $groupBy, int $start, int $end): void
  {
    $rows = [];
    if ($part === 'breached') {
      $rows[] = ['issue', 'summary', 'metric', 'policy', 'assignee', 'target_minutes', 'due_at', 'met_at', 'overrun_seconds'];
      foreach ($this->model->breached($start, $end, SlaReportModel::BREACHED_LIMIT_EXPORT) as $row) {
        $rows[] = [
          $row['issue']['key'], $row['issue']['summary'], $row['metric'], $row['policy'], $row['assignee']['display_name'] ?? '',
          $row['target_minutes'], $row['due_at'] ?? '', $row['met_at'] ?? '', $row['overrun_seconds'] ?? '',
        ];
      }
    } else {
      $rows[] = [$groupBy, 'metric', 'timers', 'met_in_time', 'met_late', 'breached_open', 'compliance_percent', 'avg_seconds', 'median_seconds'];
      $report = $this->model->report($start, $end, $groupBy);
      $line = static fn(string $label, string $metric, array $s): array => [
        $label, $metric, $s['total'], $s['met_in_time'], $s['met_late'], $s['breached_open'], $s['compliance'] ?? '', $s['avg_seconds'] ?? '', $s['median_seconds'] ?? '',
      ];
      foreach ($report['groups'] as $group) {
        foreach ($group['stats'] as $metric => $stats) {
          $rows[] = $line($group['none'] ? '' : (string)$group['name'], $metric, $stats);
        }
      }
      foreach ($report['totals'] as $metric => $stats) {
        $rows[] = $line('(all)', $metric, $stats);
      }
    }

    header('Content-Type: text/csv; charset=utf-8');
    header(sprintf('Content-Disposition: attachment; filename="sla-%s-%s_%s.csv"', $part, $from, $to));
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF";
    foreach ($rows as $row) {
      echo implode(',', array_map([self::class, 'cell'], $row)), "\r\n";
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Writes one value as a CSV cell. A text that a spreadsheet would read as a formula (it starts with =, +, - or @, or with
   * a tab or a carriage return) gets an apostrophe in front, line breaks become spaces, and the cell is quoted.
   *
   * @param mixed $value The value
   *
   * @return string
   */
  public static function cell(mixed $value): string
  {
    if (is_int($value) || is_float($value)) {
      return (string)$value;
    }
    $text = str_replace(["\r\n", "\r", "\n"], ' ', (string)($value ?? ''));
    if ($text !== '' && strpbrk($text[0], "=+-@\t") !== false) {
      $text = "'" . $text;
    }

    return '"' . str_replace('"', '""', $text) . '"';
  }

  //---------------------------------------------------------------------------
  /**
   * Ends the request with an error.
   *
   * @param int    $status  HTTP status
   * @param string $message The message
   *
   * @return never
   */
  private function fail(int $status, string $message): never
  {
    http_response_code($status);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
  }
}
