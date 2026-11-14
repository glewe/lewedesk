<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Generator;
use InvalidArgumentException;
use RangeException;

//---------------------------------------------------------------------------
/**
 * Calculates with business hours for the SLA (see _docs/SLA.md, sections 5 and 6): when a target is due, how much
 * working time has passed, and when the clock starts. It has no database access and does not read the clock, so it
 * can be tested with fixed dates (scripts/sla-check.php).
 *
 * The calendar is an array:
 *
 *   [
 *     'timezone' => 'Europe/Berlin',                 // IANA time zone of the opening hours
 *     'hours' => [                                   // ISO weekday (1 = Monday ... 7 = Sunday) => ranges
 *       1 => [['09:00', '12:00'], ['13:00', '17:00']],
 *       6 => [],                                     // closed (a missing weekday is closed too)
 *     ],
 *   ]
 *
 * A time is "HH:MM" or "HH:MM:SS" (the MySQL TIME format); "24:00" is allowed as the end of a range and means the
 * end of the day. The ranges of a day must not overlap and must end after they start. A calendar that is open the
 * whole time ("24/7") is SlaCalculator::allDay(); every day is then open from 00:00 to 24:00, which is continuous
 * time, also across the days with a daylight saving change (23 or 25 hours long).
 *
 * The holidays are a list of days on which the clock does not run:
 *
 *   [['from' => '2026-12-24', 'to' => '2026-12-26', 'recurring' => false], ...]
 *
 * With 'recurring' the range repeats every year (a range may reach into the next year, for example 12-30 to 01-02).
 * A recurring 29 February exists only in leap years.
 *
 * Pauses are a list of [start, end] pairs of Unix timestamps; an end of null is a pause that is still going on. The
 * clock does not run during a pause. All instants in and out are UTC (Unix timestamps or DateTimeInterface).
 *
 * Daylight saving time: a time that does not exist (spring forward) moves forward by the length of the gap, a time
 * that exists twice (fall back) is the first one. The hours of a day are real elapsed time, so a range from 00:00 to
 * 24:00 is 23 hours long on the day of the spring change and 25 hours on the day of the fall change.
 */
class SlaCalculator
{
  /** How far ahead (in days) the search for working time goes before it gives up */
  public const MAX_DAYS = 3700;

  private DateTimeZone $zone;

  /** @var array<int, array<int, array{0: int, 1: int}>> Ranges by ISO weekday, as minutes since midnight (end up to 1440) */
  private array $ranges = [];

  /** @var array<int, array{from: string, to: string, recurring: bool}> */
  private array $holidays = [];

  /** @var array<string, array<int, array{0: int, 1: int}>> The open intervals of a day by date (cache) */
  private array $dayCache = [];

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param array $calendar The calendar (see the class description)
   * @param array $holidays The holidays (see the class description)
   *
   * @throws InvalidArgumentException If the calendar or a holiday is invalid
   */
  public function __construct(array $calendar, array $holidays = [])
  {
    $errors = self::validate($calendar, $holidays);
    if ($errors !== []) {
      throw new InvalidArgumentException($errors[0]);
    }

    $this->zone = new DateTimeZone((string)$calendar['timezone']);
    foreach ($calendar['hours'] as $weekday => $ranges) {
      $parsed = [];
      foreach ($ranges as $range) {
        $parsed[] = [self::minutes((string)$range[0]), self::minutes((string)$range[1])];
      }
      usort($parsed, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
      $this->ranges[(int)$weekday] = $parsed;
    }
    foreach ($holidays as $holiday) {
      $this->holidays[] = ['from' => (string)$holiday['from'], 'to' => (string)$holiday['to'], 'recurring' => !empty($holiday['recurring'])];
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Gives a calendar that is open all the time.
   *
   * @param string $timezone The time zone (it only decides where a day starts; the time is continuous)
   *
   * @return array
   */
  public static function allDay(string $timezone = 'UTC'): array
  {
    return ['timezone' => $timezone, 'hours' => array_fill(1, 7, [['00:00', '24:00']])];
  }

  //---------------------------------------------------------------------------
  /**
   * Checks a calendar and its holidays. The configuration API uses it, so the messages are meant for the user.
   *
   * @param array $calendar The calendar (see the class description)
   * @param array $holidays The holidays (see the class description)
   *
   * @return string[] The problems, empty if everything is valid
   */
  public static function validate(array $calendar, array $holidays = []): array
  {
    $errors = [];

    $timezone = $calendar['timezone'] ?? null;
    if (!is_string($timezone) || !in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
      $errors[] = 'The time zone is not valid.';
    }

    $open = false;
    $hours = $calendar['hours'] ?? null;
    if (!is_array($hours)) {
      $errors[] = 'The opening hours are missing.';
      $hours = [];
    }
    foreach ($hours as $weekday => $ranges) {
      if (!is_int($weekday) && !(is_string($weekday) && ctype_digit($weekday)) || (int)$weekday < 1 || (int)$weekday > 7) {
        $errors[] = 'The weekday ' . (string)$weekday . ' is not valid (1 = Monday to 7 = Sunday).';
        continue;
      }
      if (!is_array($ranges)) {
        $errors[] = 'The opening hours of weekday ' . $weekday . ' are not valid.';
        continue;
      }

      $parsed = [];
      foreach ($ranges as $range) {
        $start = is_array($range) && count($range) === 2 ? self::minutes((string)($range[0] ?? ''), false) : null;
        $end = is_array($range) && count($range) === 2 ? self::minutes((string)($range[1] ?? ''), true) : null;
        if ($start === null || $end === null) {
          $errors[] = 'A time of weekday ' . $weekday . ' is not valid (use HH:MM).';
        } elseif ($end <= $start) {
          $errors[] = 'A range of weekday ' . $weekday . ' must end after it starts.';
        } else {
          $parsed[] = [$start, $end];
        }
      }
      usort($parsed, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
      for ($i = 1; $i < count($parsed); $i++) {
        if ($parsed[$i][0] < $parsed[$i - 1][1]) {
          $errors[] = 'The ranges of weekday ' . $weekday . ' overlap.';
          break;
        }
      }
      $open = $open || $parsed !== [];
    }
    if (!$open && $errors === []) {
      $errors[] = 'The calendar has no opening hours.';
    }

    foreach ($holidays as $holiday) {
      $from = is_array($holiday) ? ($holiday['from'] ?? null) : null;
      $to = is_array($holiday) ? ($holiday['to'] ?? null) : null;
      if (!self::isDate($from) || !self::isDate($to) || (string)$to < (string)$from) {
        $errors[] = 'A holiday needs a valid start date and an end date that is not before it.';
      }
    }

    return $errors;
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the moment a target is due: the instant at which the given working time has passed since the start.
   *
   * @param DateTimeInterface|int $start   The start of the clock
   * @param int                   $minutes The target in working minutes (0 is due at the start)
   * @param array                 $pauses  The pauses (see the class description)
   *
   * @return DateTimeImmutable|null The due time in UTC; null while a pause is going on (the due time is not known yet)
   *
   * @throws RangeException If there is not enough working time within MAX_DAYS
   */
  public function dueAt(DateTimeInterface|int $start, int $minutes, array $pauses = []): ?DateTimeImmutable
  {
    $from = self::timestamp($start);
    if ($minutes < 0) {
      throw new InvalidArgumentException('The target must not be negative.');
    }
    return $this->dueAtSeconds($from, $minutes * 60, $pauses);
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the moment a working time given in seconds has passed since the start (the warning threshold of a timer is
   * not always a whole number of minutes).
   *
   * @param DateTimeInterface|int $start   The start of the clock
   * @param int                   $seconds The working time in seconds (0 is the start)
   * @param array                 $pauses  The pauses (see the class description)
   *
   * @return DateTimeImmutable|null The moment in UTC; null while a pause is going on (the moment is not known yet)
   *
   * @throws RangeException If there is not enough working time within MAX_DAYS
   */
  public function dueAtSeconds(DateTimeInterface|int $start, int $seconds, array $pauses = []): ?DateTimeImmutable
  {
    $from = self::timestamp($start);
    if ($seconds < 0) {
      throw new InvalidArgumentException('The working time must not be negative.');
    }
    if ($seconds === 0) {
      return self::instant($from);
    }

    $need = $seconds;
    foreach ($this->openIntervals($from, self::normalizePauses($pauses)) as [$begin, $end]) {
      $length = $end - $begin;
      if ($length >= $need) {
        return self::instant($begin + $need);
      }
      $need -= $length;
    }

    // The search ended at a pause that is still going on
    return null;
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the working time between two instants, without the pauses.
   *
   * @param DateTimeInterface|int $start  The start
   * @param DateTimeInterface|int $end    The end (an end before the start gives 0)
   * @param array                 $pauses The pauses (see the class description)
   *
   * @return int Seconds
   *
   * @throws RangeException If the interval is longer than MAX_DAYS
   */
  public function elapsed(DateTimeInterface|int $start, DateTimeInterface|int $end, array $pauses = []): int
  {
    $from = self::timestamp($start);
    $until = self::timestamp($end);
    $seconds = 0;
    if ($until <= $from) {
      return 0;
    }

    foreach ($this->openIntervals($from, self::normalizePauses($pauses), $until) as [$begin, $stop]) {
      if ($begin >= $until) {
        break;
      }
      $seconds += min($stop, $until) - $begin;
    }

    return $seconds;
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the start of the clock for something that happens at an instant: the instant itself during opening hours,
   * else the next opening. A pause is not considered (it is the start of a timer).
   *
   * @param DateTimeInterface|int $instant The instant
   *
   * @return DateTimeImmutable In UTC
   *
   * @throws RangeException If there is no opening within MAX_DAYS (for example, holidays that cover years)
   */
  public function nextOpening(DateTimeInterface|int $instant): DateTimeImmutable
  {
    $from = self::timestamp($instant);
    foreach ($this->openIntervals($from, []) as [$begin]) {
      return self::instant($begin);
    }

    // openIntervals() throws before it ends without an interval, because the pauses are empty
    throw new RangeException('No opening hours found.');
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the clock runs at an instant (opening hours, no holiday), pauses not considered.
   *
   * @param DateTimeInterface|int $instant The instant
   *
   * @return bool
   */
  public function isOpenAt(DateTimeInterface|int $instant): bool
  {
    $at = self::timestamp($instant);
    foreach ($this->openIntervals($at, []) as [$begin]) {
      return $begin <= $at;
    }

    return false;
  }

  //---------------------------------------------------------------------------
  /**
   * Walks through the open intervals from an instant on, in order, without the pauses. An interval that has begun
   * already starts at the instant. It stops at a pause that is not over, and throws when MAX_DAYS pass.
   *
   * @param int      $from   The first instant (Unix timestamp)
   * @param array    $pauses The normalized pauses
   * @param int|null $until  Where the caller stops looking (Unix timestamp): the walk ends at the first day that starts there,
   *                         so a calendar without openings after that does not matter
   *
   * @return Generator<int, array{0: int, 1: int}> [begin, end] as Unix timestamps
   *
   * @throws RangeException If there is nothing open within MAX_DAYS
   */
  private function openIntervals(int $from, array $pauses, ?int $until = null): Generator
  {
    // The first instant at which an open pause begins, after that nothing is known
    $limit = PHP_INT_MAX;
    foreach ($pauses as [$pauseStart, $pauseEnd]) {
      if ($pauseEnd === null) {
        $limit = min($limit, $pauseStart);
      }
    }

    $day = (new DateTimeImmutable('@' . $from))->setTimezone($this->zone)->setTime(0, 0);
    for ($i = 0; $i < self::MAX_DAYS; $i++, $day = $day->modify('+1 day')) {
      if ($until !== null && (int)$day->format('U') >= $until) {
        return;
      }
      $date = $day->format('Y-m-d');
      foreach ($this->dayIntervals($day, $date) as [$begin, $end]) {
        if ($end <= $from) {
          continue;
        }
        $begin = max($begin, $from);
        if ($begin >= $limit) {
          return;
        }
        foreach (self::subtract($begin, min($end, $limit), $pauses) as $piece) {
          yield $piece;
        }
      }
      if ((int)$day->setTime(0, 0)->format('U') >= $limit) {
        return;
      }
    }

    // A pause that is not over ends the search quietly; a calendar that never opens is an error
    if ($limit === PHP_INT_MAX) {
      throw new RangeException('There is no working time within ' . self::MAX_DAYS . ' days.');
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the open intervals of one day (empty on a holiday).
   *
   * @param DateTimeImmutable $day  Midnight of the day in the calendar time zone
   * @param string            $date The date as Y-m-d
   *
   * @return array<int, array{0: int, 1: int}> [begin, end] as Unix timestamps
   */
  private function dayIntervals(DateTimeImmutable $day, string $date): array
  {
    if (isset($this->dayCache[$date])) {
      return $this->dayCache[$date];
    }
    // The cache only has to serve a calculation; it must not grow without limit in a long running process
    if (count($this->dayCache) > 8000) {
      $this->dayCache = [];
    }

    $intervals = [];
    if (!$this->isHoliday($date)) {
      foreach ($this->ranges[(int)$day->format('N')] ?? [] as [$start, $end]) {
        $begin = $this->at($day, $start);
        $stop = $this->at($day, $end);
        if ($stop > $begin) {
          $intervals[] = [$begin, $stop];
        }
      }
    }

    return $this->dayCache[$date] = $intervals;
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the instant of a local time on a day.
   *
   * @param DateTimeImmutable $day     Midnight of the day in the calendar time zone
   * @param int               $minutes Minutes since midnight (1440 is the midnight that ends the day)
   *
   * @return int Unix timestamp
   */
  private function at(DateTimeImmutable $day, int $minutes): int
  {
    if ($minutes >= 1440) {
      return (int)$day->modify('+1 day')->setTime(0, 0)->format('U');
    }

    return (int)$day->setTime(intdiv($minutes, 60), $minutes % 60)->format('U');
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether a day is a holiday. The dates are compared as text (Y-m-d sorts like the dates do).
   *
   * @param string $date The date as Y-m-d
   *
   * @return bool
   */
  private function isHoliday(string $date): bool
  {
    $year = (int)substr($date, 0, 4);
    foreach ($this->holidays as $holiday) {
      if (!$holiday['recurring']) {
        if ($date >= $holiday['from'] && $date <= $holiday['to']) {
          return true;
        }
        continue;
      }

      // A recurring range is moved to the year of the day, or to the year before (a range at the turn of the year)
      $span = (int)substr($holiday['to'], 0, 4) - (int)substr($holiday['from'], 0, 4);
      foreach ([$year - 1, $year] as $start) {
        if ($date >= sprintf('%04d', $start) . substr($holiday['from'], 4) && $date <= sprintf('%04d', $start + $span) . substr($holiday['to'], 4)) {
          return true;
        }
      }
    }

    return false;
  }

  //---------------------------------------------------------------------------
  /**
   * Cuts the pauses out of an interval.
   *
   * @param int   $begin  The start
   * @param int   $end    The end
   * @param array $pauses The normalized pauses (sorted, not overlapping)
   *
   * @return array<int, array{0: int, 1: int}> The pieces that remain, in order
   */
  private static function subtract(int $begin, int $end, array $pauses): array
  {
    $pieces = [];
    $cursor = $begin;
    foreach ($pauses as [$pauseStart, $pauseEnd]) {
      $pauseEnd ??= PHP_INT_MAX;
      if ($pauseEnd <= $cursor) {
        continue;
      }
      if ($pauseStart >= $end) {
        break;
      }
      if ($pauseStart > $cursor) {
        $pieces[] = [$cursor, $pauseStart];
      }
      $cursor = max($cursor, $pauseEnd);
      if ($cursor >= $end) {
        return $pieces;
      }
    }
    if ($cursor < $end) {
      $pieces[] = [$cursor, $end];
    }

    return $pieces;
  }

  //---------------------------------------------------------------------------
  /**
   * Sorts the pauses and merges those that overlap or touch.
   *
   * @param array $pauses [start, end] pairs of Unix timestamps or DateTimeInterface, end null for a pause that is going on
   *
   * @return array<int, array{0: int, 1: int|null}>
   */
  private static function normalizePauses(array $pauses): array
  {
    $list = [];
    foreach ($pauses as $pause) {
      $start = self::timestamp($pause[0]);
      $end = ($pause[1] ?? null) === null ? null : self::timestamp($pause[1]);
      if ($end === null || $end > $start) {
        $list[] = [$start, $end];
      }
    }
    usort($list, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

    $merged = [];
    foreach ($list as [$start, $end]) {
      $last = count($merged) - 1;
      if ($last >= 0 && ($merged[$last][1] === null || $start <= $merged[$last][1])) {
        $merged[$last][1] = $merged[$last][1] === null || $end === null ? null : max($merged[$last][1], $end);
        continue;
      }
      $merged[] = [$start, $end];
    }

    return $merged;
  }

  //---------------------------------------------------------------------------
  /**
   * Reads a time of the day.
   *
   * @param string $time    "HH:MM" or "HH:MM:SS"
   * @param bool   $isEnd   Whether 24:00 is allowed (the end of a range)
   *
   * @return int|null Minutes since midnight; null if the time is not valid
   */
  private static function minutes(string $time, bool $isEnd = true): ?int
  {
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $time, $m)) {
      return null;
    }
    $hours = (int)$m[1];
    $minutes = (int)$m[2];
    $seconds = (int)($m[3] ?? 0);
    if ($minutes > 59 || $seconds > 0 || $hours > 24 || ($hours === 24 && ($minutes > 0 || !$isEnd))) {
      return null;
    }

    return $hours * 60 + $minutes;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether a value is a date as Y-m-d.
   *
   * @param mixed $value The value
   *
   * @return bool
   */
  private static function isDate(mixed $value): bool
  {
    if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
      return false;
    }

    // A recurring 29 February is allowed, so the check is against a leap year
    return checkdate((int)$m[2], (int)$m[3], 2000) || checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
  }

  //---------------------------------------------------------------------------
  /**
   * @param DateTimeInterface|int $value The instant
   *
   * @return int Unix timestamp
   */
  private static function timestamp(DateTimeInterface|int $value): int
  {
    return $value instanceof DateTimeInterface ? $value->getTimestamp() : $value;
  }

  //---------------------------------------------------------------------------
  /**
   * @param int $timestamp Unix timestamp
   *
   * @return DateTimeImmutable In UTC
   */
  private static function instant(int $timestamp): DateTimeImmutable
  {
    return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'));
  }
}
