<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

//---------------------------------------------------------------------------
/**
 * Data access for the SLA business hours (tables sla_calendars, sla_calendar_hours and sla_holidays, see _docs/SLA.md).
 *
 * A calendar is shaped like the calendar of App\Services\SlaCalculator: its opening hours are an object by ISO weekday
 * ("1" = Monday ... "7" = Sunday) with the ranges as ["09:00", "17:00"] ("24:00" is the end of the day).
 *
 * The rules (valid hours, who may do what) are checked by the controller; this class only reads and writes.
 */
class SlaCalendarModel
{
  /** The most ranges of opening hours of one day */
  public const MAX_RANGES_PER_DAY = 8;

  /** The most holidays of one calendar */
  public const MAX_HOLIDAYS = 500;

  /** The longest name, in characters */
  public const MAX_NAME = 100;

  public function __construct(private readonly PDO $db)
  {
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the calendars with their opening hours.
   *
   * @return array<int, array>
   */
  public function all(): array
  {
    $rows = $this->db->query(
      'SELECT c.id, c.name, c.description, c.timezone, c.created_at, c.updated_at,
              (SELECT COUNT(*) FROM sla_holidays h WHERE h.calendar_id = c.id) AS holiday_count,
              (SELECT COUNT(*) FROM sla_policies p WHERE p.calendar_id = c.id) AS policy_count
         FROM sla_calendars c
        ORDER BY c.name'
    )->fetchAll();

    $hours = $this->hoursOf(null);

    return array_map(fn(array $row): array => $this->format($row, $hours[(int)$row['id']] ?? []), $rows);
  }

  //---------------------------------------------------------------------------
  /**
   * Reads one calendar with its opening hours and holidays.
   *
   * @param int $id The calendar
   *
   * @return array|null Null if there is no such calendar
   */
  public function find(int $id): ?array
  {
    $stmt = $this->db->prepare(
      'SELECT c.id, c.name, c.description, c.timezone, c.created_at, c.updated_at,
              (SELECT COUNT(*) FROM sla_holidays h WHERE h.calendar_id = c.id) AS holiday_count,
              (SELECT COUNT(*) FROM sla_policies p WHERE p.calendar_id = c.id) AS policy_count
         FROM sla_calendars c WHERE c.id = :id'
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
      return null;
    }

    $calendar = $this->format($row, $this->hoursOf($id)[$id] ?? []);
    $calendar['holidays'] = $this->holidays($id);

    return $calendar;
  }

  //---------------------------------------------------------------------------
  /**
   * Finds a calendar by its name (the comparison ignores the case).
   *
   * @param string   $name      The name
   * @param int|null $exceptId  A calendar to leave out (the one that is being changed)
   *
   * @return array|null
   */
  public function findByName(string $name, ?int $exceptId = null): ?array
  {
    $stmt = $this->db->prepare('SELECT id, name FROM sla_calendars WHERE name = :name AND id <> :except');
    $stmt->execute(['name' => $name, 'except' => $exceptId ?? 0]);

    return $stmt->fetch() ?: null;
  }

  //---------------------------------------------------------------------------
  /**
   * Creates a calendar.
   *
   * @param array $data name, description, timezone, hours (validated)
   *
   * @return int The id
   */
  public function create(array $data): int
  {
    $stmt = $this->db->prepare('INSERT INTO sla_calendars (name, description, timezone) VALUES (:name, :description, :timezone)');
    $stmt->execute(['name' => $data['name'], 'description' => $data['description'], 'timezone' => $data['timezone']]);
    $id = (int)$this->db->lastInsertId();
    $this->replaceHours($id, $data['hours']);

    return $id;
  }

  //---------------------------------------------------------------------------
  /**
   * Changes a calendar; the opening hours are replaced as a whole.
   *
   * @param int   $id   The calendar
   * @param array $data name, description, timezone, hours (validated)
   *
   * @return void
   */
  public function update(int $id, array $data): void
  {
    $stmt = $this->db->prepare('UPDATE sla_calendars SET name = :name, description = :description, timezone = :timezone WHERE id = :id');
    $stmt->execute(['name' => $data['name'], 'description' => $data['description'], 'timezone' => $data['timezone'], 'id' => $id]);
    $this->replaceHours($id, $data['hours']);
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes a calendar with its hours and holidays (the controller makes sure no policy uses it).
   *
   * @param int $id The calendar
   *
   * @return void
   */
  public function delete(int $id): void
  {
    $this->db->prepare('DELETE FROM sla_calendars WHERE id = :id')->execute(['id' => $id]);
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the names of the policies that use a calendar.
   *
   * @param int $id The calendar
   *
   * @return string[]
   */
  public function policyNames(int $id): array
  {
    $stmt = $this->db->prepare('SELECT name FROM sla_policies WHERE calendar_id = :id ORDER BY name');
    $stmt->execute(['id' => $id]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
  }

  //---------------------------------------------------------------------------
  /**
   * Counts the SLA timers of issues that run on a calendar.
   *
   * @param int $id The calendar
   *
   * @return int
   */
  public function timerCount(int $id): int
  {
    $stmt = $this->db->prepare('SELECT COUNT(*) FROM issue_sla WHERE calendar_id = :id');
    $stmt->execute(['id' => $id]);

    return (int)$stmt->fetchColumn();
  }

  //---------------------------------------------------------------------------
  /**
   * Lists the holidays of a calendar.
   *
   * @param int $calendarId The calendar
   *
   * @return array<int, array>
   */
  public function holidays(int $calendarId): array
  {
    $stmt = $this->db->prepare('SELECT id, calendar_id, name, date_from, date_to, recurring FROM sla_holidays WHERE calendar_id = :id ORDER BY date_from, id');
    $stmt->execute(['id' => $calendarId]);

    return array_map(fn(array $row): array => $this->formatHoliday($row), $stmt->fetchAll());
  }

  //---------------------------------------------------------------------------
  /**
   * Reads one holiday.
   *
   * @param int $id The holiday
   *
   * @return array|null
   */
  public function findHoliday(int $id): ?array
  {
    $stmt = $this->db->prepare('SELECT id, calendar_id, name, date_from, date_to, recurring FROM sla_holidays WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row ? $this->formatHoliday($row) : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Counts the holidays of a calendar.
   *
   * @param int $calendarId The calendar
   *
   * @return int
   */
  public function countHolidays(int $calendarId): int
  {
    $stmt = $this->db->prepare('SELECT COUNT(*) FROM sla_holidays WHERE calendar_id = :id');
    $stmt->execute(['id' => $calendarId]);

    return (int)$stmt->fetchColumn();
  }

  //---------------------------------------------------------------------------
  /**
   * Creates a holiday.
   *
   * @param array $data calendar_id, name, from, to, recurring (validated)
   *
   * @return int The id
   */
  public function createHoliday(array $data): int
  {
    $stmt = $this->db->prepare('INSERT INTO sla_holidays (calendar_id, name, date_from, date_to, recurring) VALUES (:calendar, :name, :from, :to, :recurring)');
    $stmt->execute(['calendar' => $data['calendar_id'], 'name' => $data['name'], 'from' => $data['from'], 'to' => $data['to'], 'recurring' => $data['recurring'] ? 1 : 0]);

    return (int)$this->db->lastInsertId();
  }

  //---------------------------------------------------------------------------
  /**
   * Changes a holiday (it stays in its calendar).
   *
   * @param int   $id   The holiday
   * @param array $data name, from, to, recurring (validated)
   *
   * @return void
   */
  public function updateHoliday(int $id, array $data): void
  {
    $stmt = $this->db->prepare('UPDATE sla_holidays SET name = :name, date_from = :from, date_to = :to, recurring = :recurring WHERE id = :id');
    $stmt->execute(['name' => $data['name'], 'from' => $data['from'], 'to' => $data['to'], 'recurring' => $data['recurring'] ? 1 : 0, 'id' => $id]);
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes a holiday.
   *
   * @param int $id The holiday
   *
   * @return void
   */
  public function deleteHoliday(int $id): void
  {
    $this->db->prepare('DELETE FROM sla_holidays WHERE id = :id')->execute(['id' => $id]);
  }

  //---------------------------------------------------------------------------
  /**
   * Replaces the opening hours of a calendar.
   *
   * @param int   $calendarId The calendar
   * @param array $hours      The ranges by weekday, as ['09:00', '17:00'] pairs
   *
   * @return void
   */
  private function replaceHours(int $calendarId, array $hours): void
  {
    $this->db->prepare('DELETE FROM sla_calendar_hours WHERE calendar_id = :id')->execute(['id' => $calendarId]);

    $insert = $this->db->prepare('INSERT INTO sla_calendar_hours (calendar_id, weekday, start_time, end_time) VALUES (:calendar, :weekday, :start, :end)');
    foreach ($hours as $weekday => $ranges) {
      foreach ($ranges as [$start, $end]) {
        $insert->execute(['calendar' => $calendarId, 'weekday' => (int)$weekday, 'start' => self::sqlTime((string)$start), 'end' => self::sqlTime((string)$end)]);
      }
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Reads the opening hours of one or all calendars.
   *
   * @param int|null $calendarId The calendar; null for all
   *
   * @return array<int, array<string, array>> The hours by calendar id: weekday => [[start, end], ...]
   */
  private function hoursOf(?int $calendarId): array
  {
    $stmt = $this->db->prepare('SELECT calendar_id, weekday, start_time, end_time FROM sla_calendar_hours WHERE (:id IS NULL OR calendar_id = :id2) ORDER BY calendar_id, weekday, start_time');
    $stmt->execute(['id' => $calendarId, 'id2' => $calendarId]);

    $hours = [];
    foreach ($stmt->fetchAll() as $row) {
      $hours[(int)$row['calendar_id']][(string)(int)$row['weekday']][] = [self::shortTime((string)$row['start_time']), self::shortTime((string)$row['end_time'])];
    }

    return $hours;
  }

  //---------------------------------------------------------------------------
  /**
   * @param array $row   The calendar row
   * @param array $hours The opening hours of the calendar
   *
   * @return array
   */
  private function format(array $row, array $hours): array
  {
    ksort($hours);

    return [
      'id' => (int)$row['id'],
      'name' => $row['name'],
      'description' => $row['description'],
      'timezone' => $row['timezone'],
      'hours' => (object)$hours,
      'holiday_count' => (int)$row['holiday_count'],
      'policy_count' => (int)$row['policy_count'],
      'created_at' => $row['created_at'],
      'updated_at' => $row['updated_at'],
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * @param array $row The holiday row
   *
   * @return array
   */
  private function formatHoliday(array $row): array
  {
    return [
      'id' => (int)$row['id'],
      'calendar_id' => (int)$row['calendar_id'],
      'name' => $row['name'],
      'from' => $row['date_from'],
      'to' => $row['date_to'],
      'recurring' => (bool)$row['recurring'],
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * @param string $time "HH:MM", "H:MM" or "HH:MM:SS"
   *
   * @return string "HH:MM:00" for the TIME column
   */
  private static function sqlTime(string $time): string
  {
    [$hours, $minutes] = array_map('intval', explode(':', $time));

    return sprintf('%02d:%02d:00', $hours, $minutes);
  }

  //---------------------------------------------------------------------------
  /**
   * @param string $time "HH:MM:SS" of the TIME column
   *
   * @return string "HH:MM"
   */
  private static function shortTime(string $time): string
  {
    return substr($time, 0, 5);
  }
}
