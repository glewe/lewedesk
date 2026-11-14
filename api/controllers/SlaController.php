<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\SlaCalendarModel;
use App\Models\SlaPolicyModel;
use App\Services\AuditLog;
use App\Services\SlaCalculator;
use App\Services\SlaService;

//---------------------------------------------------------------------------
/**
 * REST controller for the SLA configuration (see _docs/SLA.md, sections 4 and 5).
 *
 *   GET    /sla/calendars          list the calendars with their opening hours
 *   POST   /sla/calendars          create ({"name", "description", "timezone", "hours"})
 *   GET    /sla/calendars/{id}     one calendar with its holidays
 *   PUT    /sla/calendars/{id}     change (only the fields that are sent; "hours" is replaced as a whole)
 *   DELETE /sla/calendars/{id}     delete; 409 while a policy uses it
 *
 *   GET    /sla/holidays?calendar_id=1   the holidays of a calendar
 *   POST   /sla/holidays                 create ({"calendar_id", "name", "from", "to", "recurring"})
 *   GET    /sla/holidays/{id}            one holiday
 *   PUT    /sla/holidays/{id}            change (name, from, to, recurring)
 *   DELETE /sla/holidays/{id}            delete
 *
 *   GET    /sla/policies           list the policies in the order they are tried
 *   POST   /sla/policies           create
 *   GET    /sla/policies/{id}      one policy
 *   PUT    /sla/policies/{id}      change (only the fields that are sent; "targets" and "pause_status_ids" are replaced as a whole)
 *   DELETE /sla/policies/{id}      delete; 409 while timers of issues use it (deactivate it instead)
 *
 *   POST   /sla/apply              start the timers of the open issues that have none ("apply to open issues")
 *
 * A calendar is {"name", "description", "timezone": "Europe/Berlin", "hours": {"1": [["09:00", "17:00"]], ...}}: the
 * weekdays are ISO numbers (1 = Monday ... 7 = Sunday), a day without ranges is closed, "24:00" is the end of the day.
 *
 * A policy is {"name", "description", "active", "sort_order", "calendar_id" (null: around the clock), "warning_percent",
 * "type_id", "product_id", "priority_id", "group_id", "access" (null: any), "escalation_user_id" and "escalation_group_id" (null: nobody;
 * the people SLA breaches are escalated to), "targets": [{"priority_id" (null: any
 * priority), "metric": "first_response"|"resolution", "minutes"}], "pause_status_ids": []}. A new policy without
 * "pause_status_ids" gets the statuses "Waiting for customer", "Pending" and "On hold". At most one policy has no
 * condition at all: the default policy.
 *
 * Authorization (manage_sla and the license) is done by the caller (index.php).
 */
class SlaController
{
  /** The message for a name that is taken (it also tells the callers to answer 409) */
  private const NAME_TAKEN = 'A record with this name exists already';

  public function __construct(
    private readonly SlaCalendarModel $calendars,
    private readonly SlaPolicyModel $policies,
    private readonly AuditLog $audit,
    private readonly ?SlaService $service = null
  ) {
  }

  //---------------------------------------------------------------------------
  /**
   * Dispatches the request.
   *
   * @param string   $method   HTTP method
   * @param string   $resource calendars, holidays or policies
   * @param int|null $id       The id of the path
   *
   * @return void
   */
  public function handle(string $method, string $resource, ?int $id): void
  {
    if ($resource === 'apply') {
      $method === 'POST' && $id === null ? $this->apply() : $this->fail(405, 'Method not allowed');
      return;
    }
    if ($id === null && !in_array($method, ['GET', 'POST'], true)) {
      $this->fail(405, 'Method not allowed');
      return;
    }
    if ($id !== null && !in_array($method, ['GET', 'PUT', 'DELETE'], true)) {
      $this->fail(405, 'Method not allowed');
      return;
    }

    match ($resource) {
      'calendars' => $this->calendar($method, $id),
      'holidays' => $this->holiday($method, $id),
      default => $this->policy($method, $id),
    };
  }

  //---------------------------------------------------------------------------
  /**
   * Starts the timers of the open issues that have none, with the policies as they are now. The clock of such a timer
   * starts when the issue was created, so it can be breached at once.
   *
   * @return void
   */
  private function apply(): void
  {
    if ($this->service === null) {
      $this->fail(500, 'The SLA service is not available');
      return;
    }
    if (!SlaService::$timersEnabled) {
      http_response_code(402);
      echo json_encode(['status' => 'error', 'code' => 'license_limit', 'message' => 'This feature is currently disabled due to an invalid license.']);
      return;
    }

    $result = $this->service->applyToOpenIssues();
    $this->audit->log('sla_policy.applied', null, null, null, null, null, $result);
    $this->respond(['status' => 'success', 'message' => 'Timers started', 'data' => $result]);
  }

  //---------------------------------------------------------------------------
  /**
   * @param string   $method HTTP method
   * @param int|null $id     The calendar
   *
   * @return void
   */
  private function calendar(string $method, ?int $id): void
  {
    if ($id === null) {
      $method === 'GET' ? $this->respond(['status' => 'success', 'data' => $this->calendars->all()]) : $this->createCalendar();
      return;
    }

    $calendar = $this->calendars->find($id);
    if ($calendar === null) {
      $this->fail(404, 'Calendar not found');
      return;
    }

    match ($method) {
      'GET' => $this->respond(['status' => 'success', 'data' => $calendar]),
      'PUT' => $this->updateCalendar($calendar),
      default => $this->deleteCalendar($calendar),
    };
  }

  //---------------------------------------------------------------------------
  /**
   * Creates a calendar.
   *
   * @return void
   */
  private function createCalendar(): void
  {
    $data = $this->cleanCalendar($this->input(), null);
    if (is_string($data)) {
      $this->fail($data === self::NAME_TAKEN ? 409 : 400, $data);
      return;
    }

    $id = $this->audit->transaction(function () use ($data): int {
      $id = $this->calendars->create($data);
      $this->audit->log('sla_calendar.created', null, 'sla_calendar', $id, $data['name'], null, $data);
      return $id;
    });

    $this->respond(['status' => 'success', 'message' => 'Calendar created', 'data' => $this->calendars->find($id)], 201);
  }

  //---------------------------------------------------------------------------
  /**
   * Changes a calendar.
   *
   * @param array $calendar The calendar before the change
   *
   * @return void
   */
  private function updateCalendar(array $calendar): void
  {
    $data = $this->cleanCalendar($this->input(), $calendar);
    if (is_string($data)) {
      $this->fail($data === self::NAME_TAKEN ? 409 : 400, $data);
      return;
    }

    $before = ['name' => $calendar['name'], 'description' => $calendar['description'], 'timezone' => $calendar['timezone'], 'hours' => json_decode(json_encode($calendar['hours']), true)];
    $diff = AuditLog::diff($before, $data, array_keys($before));

    $this->audit->transaction(function () use ($calendar, $data, $diff): void {
      $this->calendars->update($calendar['id'], $data);
      if ($diff['new'] !== []) {
        $this->audit->log('sla_calendar.updated', null, 'sla_calendar', $calendar['id'], $data['name'], $diff['old'], $diff['new']);
      }
    });
    if ($diff['new'] !== []) {
      $this->refreshTimers($calendar['id'], null);
    }

    $this->respond(['status' => 'success', 'message' => 'Calendar updated', 'data' => $this->calendars->find($calendar['id'])]);
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes a calendar that no policy uses.
   *
   * @param array $calendar The calendar
   *
   * @return void
   */
  private function deleteCalendar(array $calendar): void
  {
    $timers = $this->calendars->timerCount($calendar['id']);
    if ($timers > 0) {
      $this->respond(['status' => 'error', 'code' => 'in_use', 'message' => 'The calendar is used by SLA timers of issues and cannot be deleted', 'timers' => $timers], 409);
      return;
    }
    $names = $this->calendars->policyNames($calendar['id']);
    if ($names !== []) {
      $this->respond(['status' => 'error', 'code' => 'in_use', 'message' => 'The calendar is used by the SLA policies: ' . implode(', ', $names), 'policies' => $names], 409);
      return;
    }

    $this->audit->transaction(function () use ($calendar): void {
      $this->calendars->delete($calendar['id']);
      $this->audit->log('sla_calendar.deleted', null, 'sla_calendar', $calendar['id'], $calendar['name'], ['name' => $calendar['name'], 'timezone' => $calendar['timezone']]);
    });

    $this->respond(['status' => 'success', 'message' => 'Calendar deleted']);
  }

  //---------------------------------------------------------------------------
  /**
   * @param string   $method HTTP method
   * @param int|null $id     The holiday
   *
   * @return void
   */
  private function holiday(string $method, ?int $id): void
  {
    if ($id === null) {
      if ($method === 'GET') {
        $calendarId = self::toInt($_GET['calendar_id'] ?? null);
        if ($calendarId === null || $this->calendars->find($calendarId) === null) {
          $this->fail(400, 'calendar_id is required and must be a calendar');
          return;
        }
        $this->respond(['status' => 'success', 'data' => $this->calendars->holidays($calendarId)]);
        return;
      }
      $this->createHoliday();
      return;
    }

    $holiday = $this->calendars->findHoliday($id);
    if ($holiday === null) {
      $this->fail(404, 'Holiday not found');
      return;
    }

    match ($method) {
      'GET' => $this->respond(['status' => 'success', 'data' => $holiday]),
      'PUT' => $this->updateHoliday($holiday),
      default => $this->deleteHoliday($holiday),
    };
  }

  //---------------------------------------------------------------------------
  /**
   * Creates a holiday.
   *
   * @return void
   */
  private function createHoliday(): void
  {
    $input = $this->input();
    $calendarId = self::toInt($input['calendar_id'] ?? null);
    $calendar = $calendarId === null ? null : $this->calendars->find($calendarId);
    if ($calendar === null) {
      $this->fail(400, 'calendar_id is required and must be a calendar');
      return;
    }
    if ($this->calendars->countHolidays($calendar['id']) >= SlaCalendarModel::MAX_HOLIDAYS) {
      $this->fail(400, 'A calendar can have up to ' . SlaCalendarModel::MAX_HOLIDAYS . ' holidays');
      return;
    }

    $data = $this->cleanHoliday($input, null);
    if (is_string($data)) {
      $this->fail(400, $data);
      return;
    }
    $data['calendar_id'] = $calendar['id'];

    $id = $this->audit->transaction(function () use ($data, $calendar): int {
      $id = $this->calendars->createHoliday($data);
      $this->audit->log('sla_holiday.created', null, 'sla_holiday', $id, $data['name'], null, $data + ['calendar' => $calendar['name']]);
      return $id;
    });
    $this->refreshTimers($calendar['id'], null);

    $this->respond(['status' => 'success', 'message' => 'Holiday created', 'data' => $this->calendars->findHoliday($id)], 201);
  }

  //---------------------------------------------------------------------------
  /**
   * Refreshes the stored due and warning moments of the open timers after a change of a calendar or a policy (the issue
   * list reads them). Nothing happens while the license has SLA switched off.
   *
   * @param int|null $calendarId The calendar that changed
   * @param int|null $policyId   The policy that changed
   *
   * @return void
   */
  private function refreshTimers(?int $calendarId, ?int $policyId): void
  {
    if ($this->service === null || !SlaService::$timersEnabled) {
      return;
    }
    try {
      $this->service->refreshOpenTimers($calendarId, $policyId);
    } catch (\Throwable $e) {
      error_log('SLA: the timers could not be refreshed: ' . $e->getMessage());
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Changes a holiday.
   *
   * @param array $holiday The holiday before the change
   *
   * @return void
   */
  private function updateHoliday(array $holiday): void
  {
    $data = $this->cleanHoliday($this->input(), $holiday);
    if (is_string($data)) {
      $this->fail(400, $data);
      return;
    }

    $before = ['name' => $holiday['name'], 'from' => $holiday['from'], 'to' => $holiday['to'], 'recurring' => $holiday['recurring']];
    $diff = AuditLog::diff($before, $data, array_keys($before));

    $this->audit->transaction(function () use ($holiday, $data, $diff): void {
      $this->calendars->updateHoliday($holiday['id'], $data);
      if ($diff['new'] !== []) {
        $this->audit->log('sla_holiday.updated', null, 'sla_holiday', $holiday['id'], $data['name'], $diff['old'], $diff['new']);
      }
    });
    if ($diff['new'] !== []) {
      $this->refreshTimers((int)$holiday['calendar_id'], null);
    }

    $this->respond(['status' => 'success', 'message' => 'Holiday updated', 'data' => $this->calendars->findHoliday($holiday['id'])]);
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes a holiday.
   *
   * @param array $holiday The holiday
   *
   * @return void
   */
  private function deleteHoliday(array $holiday): void
  {
    $this->audit->transaction(function () use ($holiday): void {
      $this->calendars->deleteHoliday($holiday['id']);
      $this->audit->log('sla_holiday.deleted', null, 'sla_holiday', $holiday['id'], $holiday['name'], ['name' => $holiday['name'], 'from' => $holiday['from'], 'to' => $holiday['to'], 'recurring' => $holiday['recurring']]);
    });
    $this->refreshTimers((int)$holiday['calendar_id'], null);

    $this->respond(['status' => 'success', 'message' => 'Holiday deleted']);
  }

  //---------------------------------------------------------------------------
  /**
   * @param string   $method HTTP method
   * @param int|null $id     The policy
   *
   * @return void
   */
  private function policy(string $method, ?int $id): void
  {
    if ($id === null) {
      $method === 'GET' ? $this->respond(['status' => 'success', 'data' => $this->policies->all()]) : $this->createPolicy();
      return;
    }

    $policy = $this->policies->find($id);
    if ($policy === null) {
      $this->fail(404, 'Policy not found');
      return;
    }

    match ($method) {
      'GET' => $this->respond(['status' => 'success', 'data' => $policy]),
      'PUT' => $this->updatePolicy($policy),
      default => $this->deletePolicy($policy),
    };
  }

  //---------------------------------------------------------------------------
  /**
   * Creates a policy.
   *
   * @return void
   */
  private function createPolicy(): void
  {
    $result = $this->cleanPolicy($this->input(), null);
    if (isset($result['error'])) {
      $this->respond(['status' => 'error', 'message' => $result['error']] + ($result['extra'] ?? []), $result['http']);
      return;
    }
    $data = $result['data'];

    $id = $this->audit->transaction(function () use ($data): int {
      $id = $this->policies->create($data);
      $this->audit->log('sla_policy.created', null, 'sla_policy', $id, $data['name'], null, $data);
      return $id;
    });

    $this->respond(['status' => 'success', 'message' => 'Policy created', 'data' => $this->policies->find($id)], 201);
  }

  //---------------------------------------------------------------------------
  /**
   * Changes a policy.
   *
   * @param array $policy The policy before the change
   *
   * @return void
   */
  private function updatePolicy(array $policy): void
  {
    $result = $this->cleanPolicy($this->input(), $policy);
    if (isset($result['error'])) {
      $this->respond(['status' => 'error', 'message' => $result['error']] + ($result['extra'] ?? []), $result['http']);
      return;
    }
    $data = $result['data'];

    $fields = ['name', 'description', 'active', 'sort_order', 'calendar_id', 'warning_percent', 'type_id', 'product_id', 'priority_id', 'group_id', 'access', 'escalation_user_id', 'escalation_group_id', 'targets', 'pause_status_ids'];
    $diff = AuditLog::diff(array_intersect_key($policy, array_flip($fields)), $data, $fields);

    $this->audit->transaction(function () use ($policy, $data, $diff): void {
      $this->policies->update($policy['id'], $data);
      if ($diff['new'] !== []) {
        $this->audit->log('sla_policy.updated', null, 'sla_policy', $policy['id'], $data['name'], $diff['old'], $diff['new']);
      }
    });
    // The warning moment of the running timers follows the threshold of the policy
    if (array_key_exists('warning_percent', $diff['new'])) {
      $this->refreshTimers(null, $policy['id']);
    }

    $this->respond(['status' => 'success', 'message' => 'Policy updated', 'data' => $this->policies->find($policy['id'])]);
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes a policy that no timer uses.
   *
   * @param array $policy The policy
   *
   * @return void
   */
  private function deletePolicy(array $policy): void
  {
    if ($policy['timers'] > 0) {
      $this->respond(['status' => 'error', 'code' => 'in_use', 'message' => 'The policy has SLA timers of issues and cannot be deleted. Deactivate it instead.', 'timers' => $policy['timers']], 409);
      return;
    }

    $this->audit->transaction(function () use ($policy): void {
      $this->policies->delete($policy['id']);
      $this->audit->log('sla_policy.deleted', null, 'sla_policy', $policy['id'], $policy['name'], ['name' => $policy['name'], 'description' => $policy['description'], 'targets' => $policy['targets']]);
    });

    $this->respond(['status' => 'success', 'message' => 'Policy deleted']);
  }

  //---------------------------------------------------------------------------
  /**
   * Checks the input of a calendar. Fields that are not sent keep their value when a calendar is changed.
   *
   * @param array      $input    The input
   * @param array|null $existing The calendar before the change; null when it is created
   *
   * @return array|string The calendar (name, description, timezone, hours) or the error message
   */
  private function cleanCalendar(array $input, ?array $existing): array|string
  {
    $name = array_key_exists('name', $input) || $existing === null ? self::cleanText($input['name'] ?? null, SlaCalendarModel::MAX_NAME) : $existing['name'];
    if ($name === null || $name === '') {
      return 'The name is required and may have up to ' . SlaCalendarModel::MAX_NAME . ' characters (one line)';
    }
    if ($this->calendars->findByName($name, $existing['id'] ?? null) !== null) {
      return self::NAME_TAKEN;
    }

    $description = array_key_exists('description', $input) ? self::cleanText($input['description'], 255, true) : ($existing['description'] ?? null);
    if (array_key_exists('description', $input) && $description === null && $input['description'] !== null && $input['description'] !== '') {
      return 'The description may have up to 255 characters (one line)';
    }

    $timezone = array_key_exists('timezone', $input) ? $input['timezone'] : ($existing['timezone'] ?? null);
    $hours = array_key_exists('hours', $input) ? $input['hours'] : ($existing === null ? null : json_decode(json_encode($existing['hours']), true));
    if (!is_array($hours)) {
      return 'The opening hours are required (an object with the weekdays 1 to 7)';
    }
    foreach ($hours as $ranges) {
      if (is_array($ranges) && count($ranges) > SlaCalendarModel::MAX_RANGES_PER_DAY) {
        return 'A day can have up to ' . SlaCalendarModel::MAX_RANGES_PER_DAY . ' ranges of opening hours';
      }
    }

    $errors = SlaCalculator::validate(['timezone' => $timezone, 'hours' => $hours]);
    if ($errors !== []) {
      return $errors[0];
    }

    // Weekdays without a range are left out; the times become HH:MM
    $clean = [];
    foreach ($hours as $weekday => $ranges) {
      foreach ($ranges as [$start, $end]) {
        $clean[(int)$weekday][] = [self::time((string)$start), self::time((string)$end)];
      }
    }
    ksort($clean);

    return ['name' => $name, 'description' => $description === '' ? null : $description, 'timezone' => $timezone, 'hours' => $clean];
  }

  //---------------------------------------------------------------------------
  /**
   * Checks the input of a holiday. Fields that are not sent keep their value when a holiday is changed.
   *
   * @param array      $input    The input
   * @param array|null $existing The holiday before the change; null when it is created
   *
   * @return array|string The holiday (name, from, to, recurring) or the error message
   */
  private function cleanHoliday(array $input, ?array $existing): array|string
  {
    $name = array_key_exists('name', $input) || $existing === null ? self::cleanText($input['name'] ?? null, SlaCalendarModel::MAX_NAME) : $existing['name'];
    if ($name === null || $name === '') {
      return 'The name is required and may have up to ' . SlaCalendarModel::MAX_NAME . ' characters (one line)';
    }

    $from = array_key_exists('from', $input) ? $input['from'] : ($existing['from'] ?? null);
    // A holiday of one day needs no end
    $to = array_key_exists('to', $input) ? $input['to'] : (array_key_exists('from', $input) && $existing === null ? $from : ($existing['to'] ?? $from));
    $recurring = array_key_exists('recurring', $input) ? $input['recurring'] : ($existing['recurring'] ?? false);
    if (!is_bool($recurring)) {
      return 'recurring must be true or false';
    }

    $errors = SlaCalculator::validate(['timezone' => 'UTC', 'hours' => [1 => [['00:00', '24:00']]]], [['from' => $from, 'to' => $to]]);
    if ($errors !== []) {
      return $errors[0];
    }

    return ['name' => $name, 'from' => (string)$from, 'to' => (string)$to, 'recurring' => $recurring];
  }

  //---------------------------------------------------------------------------
  /**
   * Checks the input of a policy. Fields that are not sent keep their value when a policy is changed.
   *
   * @param array      $input    The input
   * @param array|null $existing The policy before the change; null when it is created
   *
   * @return array ['data' => the policy] or ['error' => message, 'http' => status, 'extra' => more fields of the answer]
   */
  private function cleanPolicy(array $input, ?array $existing): array
  {
    $fail = static fn(string $message, int $http = 400, array $extra = []): array => ['error' => $message, 'http' => $http, 'extra' => $extra];
    $value = static fn(string $field, mixed $default) => array_key_exists($field, $input) ? $input[$field] : ($existing[$field] ?? $default);

    $name = array_key_exists('name', $input) || $existing === null ? self::cleanText($input['name'] ?? null, SlaPolicyModel::MAX_NAME) : $existing['name'];
    if ($name === null || $name === '') {
      return $fail('The name is required and may have up to ' . SlaPolicyModel::MAX_NAME . ' characters (one line)');
    }
    $same = $this->policies->findByName($name, $existing['id'] ?? null);
    if ($same !== null) {
      return $fail(self::NAME_TAKEN, 409, ['code' => 'name_exists', 'existing_id' => (int)$same['id']]);
    }

    $description = $value('description', null);
    if ($description !== null && (!is_string($description) || mb_strlen($description) > 255 || preg_match('/[\x00-\x1F\x7F]/', $description))) {
      return $fail('The description may have up to 255 characters (one line)');
    }

    $active = $value('active', true);
    if (!is_bool($active)) {
      return $fail('active must be true or false');
    }

    $sortOrder = array_key_exists('sort_order', $input) ? self::toInt($input['sort_order']) : ($existing['sort_order'] ?? $this->policies->nextSortOrder());
    if ($sortOrder === null || $sortOrder < 0 || $sortOrder > 99999) {
      return $fail('sort_order must be a number from 0 to 99999');
    }

    $warning = array_key_exists('warning_percent', $input) ? self::toInt($input['warning_percent']) : ($existing['warning_percent'] ?? 80);
    if ($warning === null || $warning < 1 || $warning > 100) {
      return $fail('warning_percent must be a number from 1 to 100');
    }

    // A policy without calendar runs around the clock
    $calendarId = $value('calendar_id', null);
    if ($calendarId !== null) {
      $calendarId = self::toInt($calendarId);
      if ($calendarId === null || !$this->policies->exists('sla_calendars', $calendarId)) {
        return $fail('The calendar does not exist');
      }
    }

    $conditions = [];
    foreach (SlaPolicyModel::CONDITIONS as $field => $table) {
      $id = $value($field, null);
      if ($id !== null) {
        $id = self::toInt($id);
        if ($id === null || !$this->policies->exists($table, $id)) {
          return $fail("The value of $field does not exist");
        }
      }
      $conditions[$field] = $id;
    }
    $access = $value('access', null);
    if ($access !== null && !in_array($access, SlaPolicyModel::ACCESS, true)) {
      return $fail('access must be public, registered or reporter');
    }
    $conditions['access'] = $access;

    if (!array_filter($conditions, static fn($v): bool => $v !== null)) {
      $default = $this->policies->findDefault($existing['id'] ?? null);
      if ($default !== null) {
        return $fail('The policy "' . $default['name'] . '" is the default policy (no conditions) already. Give this policy at least one condition.', 409, ['code' => 'default_exists', 'existing_id' => (int)$default['id']]);
      }
    }

    // Who a breach is escalated to (a user and/or a group); neither is a condition of the policy
    $escalation = [];
    foreach (['escalation_user_id' => 'users', 'escalation_group_id' => 'groups'] as $field => $table) {
      $id = $value($field, null);
      if ($id !== null) {
        $id = self::toInt($id);
        if ($id === null || !$this->policies->exists($table, $id)) {
          return $fail("The value of $field does not exist");
        }
      }
      $escalation[$field] = $id;
    }

    $targets = $this->cleanTargets($value('targets', []));
    if (is_string($targets)) {
      return $fail($targets);
    }

    $statusIds = array_key_exists('pause_status_ids', $input) ? $input['pause_status_ids'] : ($existing['pause_status_ids'] ?? $this->policies->defaultPauseStatusIds());
    if (!is_array($statusIds) || !array_is_list($statusIds) || count($statusIds) > 100) {
      return $fail('pause_status_ids must be a list of status ids');
    }
    $cleanStatuses = [];
    foreach ($statusIds as $statusId) {
      $statusId = self::toInt($statusId);
      if ($statusId === null || !$this->policies->exists('status', $statusId)) {
        return $fail('A status of pause_status_ids does not exist');
      }
      $cleanStatuses[$statusId] = $statusId;
    }

    return ['data' => [
      'name' => $name,
      'description' => $description === '' ? null : $description,
      'active' => $active,
      'sort_order' => $sortOrder,
      'calendar_id' => $calendarId,
      'warning_percent' => $warning,
    ] + $conditions + $escalation + ['targets' => $targets, 'pause_status_ids' => array_values($cleanStatuses)]];
  }

  //---------------------------------------------------------------------------
  /**
   * Checks the targets of a policy.
   *
   * @param mixed $targets The input: a list of {"priority_id", "metric", "minutes"}
   *
   * @return array|string The cleaned targets or the error message
   */
  private function cleanTargets(mixed $targets): array|string
  {
    if (!is_array($targets) || !array_is_list($targets) || count($targets) > 100) {
      return 'targets must be a list of targets';
    }

    $clean = [];
    $seen = [];
    foreach ($targets as $target) {
      if (!is_array($target)) {
        return 'A target must be an object with priority_id, metric and minutes';
      }
      $priorityId = $target['priority_id'] ?? null;
      if ($priorityId !== null) {
        $priorityId = self::toInt($priorityId);
        if ($priorityId === null || !$this->policies->exists('priorities', $priorityId)) {
          return 'The priority of a target does not exist';
        }
      }
      $metric = $target['metric'] ?? null;
      if (!in_array($metric, SlaPolicyModel::METRICS, true)) {
        return 'The metric of a target must be first_response or resolution';
      }
      $minutes = self::toInt($target['minutes'] ?? null);
      if ($minutes === null || $minutes < 1 || $minutes > SlaPolicyModel::MAX_MINUTES) {
        return 'The minutes of a target must be a whole number from 1 to ' . SlaPolicyModel::MAX_MINUTES;
      }
      $key = ($priorityId ?? 'any') . '/' . $metric;
      if (isset($seen[$key])) {
        return 'A policy can have only one target per priority and metric';
      }
      $seen[$key] = true;
      $clean[] = ['priority_id' => $priorityId, 'metric' => $metric, 'minutes' => $minutes];
    }

    return $clean;
  }

  //---------------------------------------------------------------------------
  /**
   * Checks a text: a string of one line with up to $max characters, trimmed.
   *
   * @param mixed $text       The input
   * @param int   $max        The most characters
   * @param bool  $allowEmpty Whether an empty text or null is fine (then the result is "")
   *
   * @return string|null Null if the input is not valid
   */
  private static function cleanText(mixed $text, int $max, bool $allowEmpty = false): ?string
  {
    if ($text === null && $allowEmpty) {
      return '';
    }
    if (!is_string($text)) {
      return null;
    }
    $text = trim($text);
    if (($text === '' && !$allowEmpty) || mb_strlen($text) > $max || preg_match('/[\x00-\x1F\x7F]/', $text) || !mb_check_encoding($text, 'UTF-8')) {
      return null;
    }

    return $text;
  }

  //---------------------------------------------------------------------------
  /**
   * Reads a whole number from a number or a string of digits.
   *
   * @param mixed $value The input
   *
   * @return int|null Null if it is something else
   */
  private static function toInt(mixed $value): ?int
  {
    if (is_int($value)) {
      return $value;
    }

    return is_string($value) && preg_match('/^\d{1,9}$/', $value) ? (int)$value : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Writes a time as HH:MM.
   *
   * @param string $time "H:MM", "HH:MM" or "HH:MM:SS"
   *
   * @return string
   */
  private static function time(string $time): string
  {
    [$hours, $minutes] = array_map('intval', explode(':', $time));

    return sprintf('%02d:%02d', $hours, $minutes);
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
