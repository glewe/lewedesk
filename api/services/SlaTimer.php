<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;

//---------------------------------------------------------------------------
/**
 * The clock of one SLA target on one issue (see _docs/SLA.md, section 6). It is a value: every change returns a new
 * timer, nothing is stored or read from the clock, and the time is always given by the caller (Unix timestamps in UTC).
 *
 * What it keeps are facts: when the clock started, the target in working minutes, the periods in which the clock was
 * stopped (a pause for a waiting status, or a cancellation for a deleted issue) and, when the target was reached,
 * the moment it was met. The state is worked out from them, so it cannot get out of step:
 *
 *   met        a response or resolution ended the clock (final: later changes are ignored)
 *   cancelled  the issue is deleted, the clock is stopped
 *   breached   more working time has passed than the target (stays so while paused, until the timer is met)
 *   paused     the clock is stopped by a pause
 *   running    everything else
 *
 * Working time never goes down, so a breach cannot be undone by a pause or by a later change of the due time.
 *
 * The rows of the table (Phase 2) are the facts of toArray().
 */
final class SlaTimer
{
  public const RUNNING = 'running';
  public const PAUSED = 'paused';
  public const MET = 'met';
  public const BREACHED = 'breached';
  public const CANCELLED = 'cancelled';

  public const REASON_PAUSE = 'pause';
  public const REASON_CANCEL = 'cancel';

  /**
   * @param SlaCalculator $calculator The business hours
   * @param int           $start      When the clock started (UTC timestamp)
   * @param int           $minutes    The target in working minutes
   * @param array         $stops      The stopped periods: ['start' => int, 'end' => int|null, 'reason' => 'pause'|'cancel'], in order, only the last one may be open
   * @param int|null      $metAt      When the target was met (UTC timestamp)
   */
  private function __construct(
    private readonly SlaCalculator $calculator,
    private readonly int $start,
    private readonly int $minutes,
    private readonly array $stops = [],
    private readonly ?int $metAt = null
  ) {
  }

  //---------------------------------------------------------------------------
  /**
   * Starts a clock.
   *
   * @param SlaCalculator $calculator The business hours
   * @param int           $start      The start (UTC timestamp)
   * @param int           $minutes    The target in working minutes (not negative)
   *
   * @return self
   */
  public static function start(SlaCalculator $calculator, int $start, int $minutes): self
  {
    if ($minutes < 0) {
      throw new InvalidArgumentException('The target must not be negative.');
    }

    return new self($calculator, $start, $minutes);
  }

  //---------------------------------------------------------------------------
  /**
   * Rebuilds a clock from the facts of toArray().
   *
   * @param SlaCalculator $calculator The business hours
   * @param array         $data       start, minutes, stops, met_at
   *
   * @return self
   */
  public static function fromArray(SlaCalculator $calculator, array $data): self
  {
    $stops = [];
    foreach ($data['stops'] ?? [] as $stop) {
      $stops[] = [
        'start' => (int)$stop['start'],
        'end' => ($stop['end'] ?? null) === null ? null : (int)$stop['end'],
        'reason' => $stop['reason'] === self::REASON_CANCEL ? self::REASON_CANCEL : self::REASON_PAUSE,
      ];
    }

    return new self($calculator, (int)$data['start'], (int)$data['minutes'], $stops, ($data['met_at'] ?? null) === null ? null : (int)$data['met_at']);
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the facts of the clock.
   *
   * @return array{start: int, minutes: int, stops: array, met_at: int|null}
   */
  public function toArray(): array
  {
    return ['start' => $this->start, 'minutes' => $this->minutes, 'stops' => $this->stops, 'met_at' => $this->metAt];
  }

  //---------------------------------------------------------------------------
  /**
   * Stops the clock for a waiting status. Nothing changes if the clock is stopped already (a pause does not turn a
   * cancellation into a pause) or the target was met.
   *
   * @param int $at The moment (UTC timestamp)
   *
   * @return self
   */
  public function pause(int $at): self
  {
    if ($this->metAt !== null || $this->open() !== null) {
      return $this;
    }

    return $this->withStop($at, self::REASON_PAUSE);
  }

  //---------------------------------------------------------------------------
  /**
   * Starts the clock again after a pause. Nothing changes if there is no pause going on.
   *
   * @param int $at The moment (UTC timestamp)
   *
   * @return self
   */
  public function resume(int $at): self
  {
    return $this->closeStop($at, self::REASON_PAUSE);
  }

  //---------------------------------------------------------------------------
  /**
   * Cancels the clock because the issue was deleted. A pause that is going on becomes the cancellation. Nothing
   * changes if the target was met or the clock is cancelled already.
   *
   * @param int $at The moment (UTC timestamp)
   *
   * @return self
   */
  public function cancel(int $at): self
  {
    if ($this->metAt !== null) {
      return $this;
    }
    $open = $this->open();
    if ($open !== null && $open['reason'] === self::REASON_CANCEL) {
      return $this;
    }

    $timer = $open === null ? $this : $this->closeStop($at, self::REASON_PAUSE);

    return $timer->withStop($at, self::REASON_CANCEL);
  }

  //---------------------------------------------------------------------------
  /**
   * Takes back a cancellation (the issue was restored): the clock runs again, the time of the cancellation does not
   * count. A waiting status that applies again is a new pause() of the caller. Nothing changes if the clock is not
   * cancelled.
   *
   * @param int $at The moment (UTC timestamp)
   *
   * @return self
   */
  public function restore(int $at): self
  {
    return $this->closeStop($at, self::REASON_CANCEL);
  }

  //---------------------------------------------------------------------------
  /**
   * Ends the clock because the target was met (first response given, issue resolved). The first call wins: a clock
   * that is met stays as it is. A pause or cancellation that is going on does not prevent it.
   *
   * @param int $at The moment (UTC timestamp)
   *
   * @return self
   */
  public function stop(int $at): self
  {
    if ($this->metAt !== null) {
      return $this;
    }
    if ($at < $this->start) {
      throw new InvalidArgumentException('The target cannot be met before the clock started.');
    }

    // The pause is over at that moment: the time up to it counts, nothing after it
    return new self($this->calculator, $this->start, $this->minutes, $this->stops, $at);
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the state at a moment.
   *
   * @param int $now The moment (UTC timestamp)
   *
   * @return string RUNNING, PAUSED, MET, BREACHED or CANCELLED
   */
  public function state(int $now): string
  {
    if ($this->metAt !== null) {
      return self::MET;
    }
    $open = $this->open();
    if ($open !== null && $open['reason'] === self::REASON_CANCEL) {
      return self::CANCELLED;
    }
    if ($this->elapsedSeconds($now) > $this->minutes * 60) {
      return self::BREACHED;
    }

    return $open !== null ? self::PAUSED : self::RUNNING;
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the working time that has passed (up to the moment the target was met, if it was).
   *
   * @param int $now The moment (UTC timestamp)
   *
   * @return int Seconds
   */
  public function elapsedSeconds(int $now): int
  {
    return $this->calculator->elapsed($this->start, $this->metAt ?? $now, $this->pauses());
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the working time that is left; negative once the target is passed.
   *
   * @param int $now The moment (UTC timestamp)
   *
   * @return int Seconds
   */
  public function remainingSeconds(int $now): int
  {
    return $this->minutes * 60 - $this->elapsedSeconds($now);
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the moment the target is due.
   *
   * @return DateTimeImmutable|null In UTC; null while the clock is stopped (the due time is not known), or after the
   *                                target was met
   */
  public function dueAt(): ?DateTimeImmutable
  {
    return $this->metAt !== null ? null : $this->calculator->dueAt($this->start, $this->minutes, $this->pauses());
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the moment the warning threshold is reached: the instant at which isAtRisk() turns true while the clock runs.
   *
   * @param int $warningPercent The threshold in percent (1 to 100)
   *
   * @return DateTimeImmutable|null In UTC; null while the clock is stopped, or after the target was met
   */
  public function warnAt(int $warningPercent): ?DateTimeImmutable
  {
    if ($this->metAt !== null) {
      return null;
    }
    // The smallest working time with elapsed * 100 >= percent * target (whole numbers, like isAtRisk())
    $seconds = intdiv($warningPercent * $this->minutes * 60 + 99, 100);

    return $this->calculator->dueAtSeconds($this->start, $seconds, $this->pauses());
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the target is in danger: the share of the target that is used has reached the threshold, and the
   * target is not breached, met or cancelled.
   *
   * @param int $now            The moment (UTC timestamp)
   * @param int $warningPercent The threshold in percent (1 to 100)
   *
   * @return bool
   */
  public function isAtRisk(int $now, int $warningPercent): bool
  {
    $state = $this->state($now);
    if ($state !== self::RUNNING && $state !== self::PAUSED) {
      return false;
    }

    // Whole numbers only: no rounding at the border
    return $this->elapsedSeconds($now) * 100 >= $warningPercent * $this->minutes * 60;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether a met target was met in time.
   *
   * @return bool|null Null if the target is not met yet
   */
  public function metInTime(): ?bool
  {
    return $this->metAt === null ? null : $this->elapsedSeconds($this->metAt) <= $this->minutes * 60;
  }

  //---------------------------------------------------------------------------
  /**
   * Gives how long the target was exceeded, for the reports.
   *
   * @param int $now The moment (UTC timestamp)
   *
   * @return int Seconds of working time over the target; 0 if it is not exceeded
   */
  public function overrunSeconds(int $now): int
  {
    return max(0, -$this->remainingSeconds($now));
  }

  //---------------------------------------------------------------------------
  /**
   * The stopped periods as pairs for the calculator. A period that is going on while the target was met ends there.
   *
   * @return array<int, array{0: int, 1: int|null}>
   */
  private function pauses(): array
  {
    $pauses = [];
    foreach ($this->stops as $stop) {
      $pauses[] = [$stop['start'], $stop['end']];
    }

    return $pauses;
  }

  //---------------------------------------------------------------------------
  /**
   * @return array{start: int, end: int|null, reason: string}|null The stopped period that is going on
   */
  private function open(): ?array
  {
    $last = $this->stops === [] ? null : $this->stops[count($this->stops) - 1];

    return $last !== null && $last['end'] === null ? $last : null;
  }

  //---------------------------------------------------------------------------
  /**
   * @param int    $at     The start of the period
   * @param string $reason REASON_PAUSE or REASON_CANCEL
   *
   * @return self
   */
  private function withStop(int $at, string $reason): self
  {
    $last = $this->stops === [] ? null : $this->stops[count($this->stops) - 1];
    if ($at < $this->start || ($last !== null && $at < (int)$last['end'])) {
      throw new InvalidArgumentException('The moment is before the clock started or before the last change.');
    }

    return new self($this->calculator, $this->start, $this->minutes, [...$this->stops, ['start' => $at, 'end' => null, 'reason' => $reason]], $this->metAt);
  }

  //---------------------------------------------------------------------------
  /**
   * Ends the period that is going on, if it has the reason.
   *
   * @param int    $at     The end of the period
   * @param string $reason REASON_PAUSE or REASON_CANCEL
   *
   * @return self
   */
  private function closeStop(int $at, string $reason): self
  {
    $open = $this->open();
    if ($this->metAt !== null || $open === null || $open['reason'] !== $reason) {
      return $this;
    }
    if ($at < $open['start']) {
      throw new InvalidArgumentException('The moment is before the start of the period.');
    }

    $stops = $this->stops;
    $stops[count($stops) - 1]['end'] = $at;

    return new self($this->calculator, $this->start, $this->minutes, $stops, $this->metAt);
  }
}
