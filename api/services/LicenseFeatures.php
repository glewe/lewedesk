<?php

declare(strict_types=1);

namespace App\Services;

//---------------------------------------------------------------------------
/**
 * What the installation may do with its license (see _docs/LICENSE.md, Phase 9).
 *
 * In the full mode and during the trial and grace periods everything is allowed. In the limited mode (no license after
 * the grace period, or a blocked license) these features are off; nothing is deleted or changed, everything is back as
 * soon as a valid license is activated:
 *
 *   - audit_log     the Audit Log cannot be viewed (entries are still written)
 *   - custom_views  the custom views cannot be used (the saved views are kept)
 *   - sla           the SLA pages and API cannot be used and no SLA timer is started (the configuration and the timers are kept)
 *   - self_register the registration page creates no accounts (the system setting allow_registration is kept)
 *   - two_fa_setup  nobody can set up 2FA, and force_2fa has no effect (users who set it up keep it)
 *   - mail_in       Email-to-ticket reads no mail (the mails stay in the mailbox; see _docs/EMAIL_TO_TICKET.md)
 *
 * and these new records cannot be created beyond the limit (existing ones can still be edited and deleted):
 *
 *   - issues        50 (all issues that are not deleted)
 *   - users         20 (all users except the built-in administrator "admin")
 *
 * This is the only place that knows the limits; everything else asks this class.
 */
class LicenseFeatures
{
  /** The most issues in the limited mode */
  public const MAX_ISSUES = 50;

  /** The most users (not counting the built-in administrator) in the limited mode */
  public const MAX_USERS = 20;

  /** The features that are switched off in the limited mode */
  public const SWITCHED = ['audit_log', 'custom_views', 'sla', 'self_register', 'two_fa_setup', 'mail_in'];

  /** The message of an API answer (HTTP 402, code license_limit) for a switched off feature */
  public const MESSAGE_FEATURE = 'This feature is currently disabled due to an invalid license.';

  /**
   * Constructor.
   *
   * @param LicenseService $license The license
   */
  public function __construct(private LicenseService $license)
  {
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the installation runs in the limited mode.
   *
   * @return bool
   */
  public function isLimited(): bool
  {
    return $this->license->mode() === 'limited';
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether a feature is available.
   *
   * @param string $feature One of SWITCHED
   *
   * @return bool
   */
  public function allows(string $feature): bool
  {
    return !in_array($feature, self::SWITCHED, true) || !$this->isLimited();
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the limit for the number of records of a kind.
   *
   * @param string $kind issues or users
   *
   * @return int|null null when there is none (full mode, trial and grace period)
   */
  public function limit(string $kind): ?int
  {
    if (!$this->isLimited()) {
      return null;
    }

    return match ($kind) {
      'issues' => self::MAX_ISSUES,
      'users' => self::MAX_USERS,
      default => null,
    };
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the limit for a kind of record is reached, so no new one may be created.
   *
   * @param string $kind  issues or users
   * @param int    $count How many exist
   *
   * @return bool
   */
  public function reached(string $kind, int $count): bool
  {
    $limit = $this->limit($kind);

    return $limit !== null && $count >= $limit;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the message for a refused creation.
   *
   * @param string $kind issues or users
   *
   * @return string
   */
  public static function limitMessage(string $kind): string
  {
    $max = $kind === 'issues' ? self::MAX_ISSUES : self::MAX_USERS;

    return sprintf('The license limit of %d %s is reached. Activate a valid license to create more.', $max, $kind);
  }

  //---------------------------------------------------------------------------
  /**
   * Gets what the pages need to know (part of GET /settings, which anybody may call: the counts are not given, only
   * whether the limits are reached).
   *
   * @param callable $countIssues Returns the number of issues (only called in the limited mode)
   * @param callable $countUsers  Returns the number of users except "admin" (only called in the limited mode)
   *
   * @return array{features: array<string, bool>, limits: array<string, array{max: int, reached: bool}>|null}
   */
  public function summary(callable $countIssues, callable $countUsers): array
  {
    $features = [];
    foreach (self::SWITCHED as $feature) {
      $features[$feature] = $this->allows($feature);
    }
    if (!$this->isLimited()) {
      return ['features' => $features, 'limits' => null];
    }

    return [
      'features' => $features,
      'limits' => [
        'issues' => ['max' => self::MAX_ISSUES, 'reached' => $this->reached('issues', (int)$countIssues())],
        'users' => ['max' => self::MAX_USERS, 'reached' => $this->reached('users', (int)$countUsers())],
      ],
    ];
  }
}
