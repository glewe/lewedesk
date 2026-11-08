<?php

declare(strict_types=1);

namespace App\Services;

//---------------------------------------------------------------------------
/**
 * The central visibility rules for issues (see "Permissions" in _docs/TICKET_SPEC.md).
 *
 * Every endpoint that returns issue data (issues, and later comments, attachments, search, history
 * and notifications) gets its access check from this class. No endpoint builds its own access query.
 *
 *   - Nobody sees anything without the permission view_issue, or while the maintenance mode is on
 *     (except Administrators).
 *   - view_all_issues (Agent, Administrator) sees every issue.
 *   - A logged in user sees Public and Registered issues and their own issues.
 *   - An anonymous visitor sees Public issues.
 *   - Soft deleted issues are only visible to users holding delete_issue.
 *
 * The rules exist once, as an SQL condition (scope()). Lists, counts and single issues are all selected
 * with it, so they cannot disagree. Code that needs a yes/no answer for one issue asks the issue model
 * (find()) or adds its own query on top of scope().
 */
class IssueVisibility
{
  private AuthService $auth;
  private bool $maintenance;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param AuthService $auth        The authentication service (who is asking, which permissions)
   * @param bool        $maintenance Whether the maintenance mode is on
   */
  public function __construct(AuthService $auth, bool $maintenance)
  {
    $this->auth = $auth;
    $this->maintenance = $maintenance;
  }

  //---------------------------------------------------------------------------
  /**
   * Returns the logged in user, or null for an anonymous visitor.
   *
   * @return array|null
   */
  public function viewer(): ?array
  {
    return $this->auth->currentUser();
  }

  //---------------------------------------------------------------------------
  /**
   * Returns the id of the logged in user, or null for an anonymous visitor.
   *
   * @return int|null
   */
  public function viewerId(): ?int
  {
    $user = $this->viewer();

    return $user === null ? null : (int)$user['id'];
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the viewer may see issues at all.
   *
   * @return bool
   */
  public function canViewIssues(): bool
  {
    $user = $this->viewer();
    if ($this->maintenance && !($user !== null && $user['is_admin'])) {
      return false;
    }

    return $this->auth->hasPermission($user, 'view_issue');
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the viewer sees all issues regardless of their access (Agents, Administrators).
   *
   * @return bool
   */
  public function canViewAll(): bool
  {
    return $this->canViewIssues() && $this->auth->hasPermission($this->viewer(), 'view_all_issues');
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the viewer may see soft deleted issues.
   *
   * @return bool
   */
  public function canSeeDeleted(): bool
  {
    return $this->canViewIssues() && $this->auth->hasPermission($this->viewer(), 'delete_issue');
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the viewer may see internal comments (and, later, the attachments of internal comments).
   *
   * @return bool
   */
  public function canViewInternalComments(): bool
  {
    return $this->canViewIssues() && $this->auth->hasPermission($this->viewer(), 'view_internal_comments');
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the viewer holds a permission (through the user, role or group; Administrators hold all).
   *
   * @param string $permission The permission name
   *
   * @return bool
   */
  public function hasPermission(string $permission): bool
  {
    return $this->auth->hasPermission($this->viewer(), $permission);
  }

  //---------------------------------------------------------------------------
  /**
   * Builds the SQL condition that selects the issues the viewer may see.
   *
   * The condition uses named parameters starting with "vis_"; merge the returned parameters into the
   * parameters of the query.
   *
   * @param string $alias The alias of the issues table in the query
   *
   * @return array{0: string, 1: array<string, int>} The condition and its parameters
   */
  public function scope(string $alias = 'i'): array
  {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
      throw new \InvalidArgumentException('Invalid table alias');
    }
    if (!$this->canViewIssues()) {
      return ['1 = 0', []];
    }

    $conditions = [];
    $params = [];

    if (!$this->canSeeDeleted()) {
      $conditions[] = "$alias.deleted_at IS NULL";
    }

    if (!$this->canViewAll()) {
      $userId = $this->viewerId();
      if ($userId === null) {
        $conditions[] = "$alias.access = 'public'";
      } else {
        $conditions[] = "($alias.access IN ('public', 'registered') OR $alias.reporter_id = :vis_user)";
        $params['vis_user'] = $userId;
      }
    }

    return [$conditions === [] ? '1 = 1' : '(' . implode(' AND ', $conditions) . ')', $params];
  }
}
