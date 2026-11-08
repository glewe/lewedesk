<?php

declare(strict_types=1);

namespace App\Services;

//---------------------------------------------------------------------------
/**
 * Tells how a user authenticates: against the LeweDesk database ("local"), LDAP ("ldap") or single sign-on ("oidc").
 *
 * The user named "admin" is always local. OIDC wins over LDAP (see _docs/OIDC.md): while OIDC is on, LdapService is
 * switched off, so at most one of the two is ever active. The controllers ask this class instead of the two services
 * whenever the answer is only "does this user have a password in LeweDesk?".
 */
class AuthPolicy
{
  public const LOCAL = 'local';
  public const LDAP = 'ldap';
  public const OIDC = 'oidc';

  private LdapService $ldap;
  private OidcService $oidc;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param LdapService $ldap The LDAP service
   * @param OidcService $oidc The OIDC service
   */
  public function __construct(LdapService $ldap, OidcService $oidc)
  {
    $this->ldap = $ldap;
    $this->oidc = $oidc;
  }

  //---------------------------------------------------------------------------
  /**
   * Whether LDAP or OIDC is switched on (accounts are then created by an administrator and have no password of their own).
   *
   * @return bool
   */
  public function isEnabled(): bool
  {
    return $this->ldap->isEnabled() || $this->oidc->isEnabled();
  }

  //---------------------------------------------------------------------------
  /**
   * How a user authenticates.
   *
   * @param string $username The username as entered or stored
   *
   * @return string local, ldap or oidc
   */
  public function method(string $username): string
  {
    if ($this->oidc->usesOidc($username)) {
      return self::OIDC;
    }

    return $this->ldap->usesLdap($username) ? self::LDAP : self::LOCAL;
  }

  //---------------------------------------------------------------------------
  /**
   * Whether the password of a user is managed outside of LeweDesk (LDAP or OIDC).
   *
   * @param string $username The username as entered or stored
   *
   * @return bool
   */
  public function usesExternal(string $username): bool
  {
    return $this->method($username) !== self::LOCAL;
  }
}
