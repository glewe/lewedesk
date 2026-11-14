<?php

declare(strict_types=1);

namespace App\Utils;

//---------------------------------------------------------------------------
/**
 * The MAILIN_* part of the environment (.env): the connection to the mailbox that Email-to-ticket reads (see
 * _docs/EMAIL_TO_TICKET.md). The credentials stay in .env and are never stored in the database or sent to the browser.
 * The other options of the feature are system settings (mailin_*).
 *
 * The user and the password default to MAIL_USER and MAIL_PASS: the mailbox that sends the notifications is normally
 * the one that receives the answers.
 */
class MailInConfig
{
  /** @var array<string, mixed> */
  private array $env;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param array<string, mixed> $env The environment ($_ENV)
   */
  public function __construct(array $env)
  {
    $this->env = $env;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the administrator switched the feature on (MAILIN_YES=1) and the mailbox is described. The license
   * is checked separately (LicenseFeatures).
   *
   * @return bool
   */
  public function isEnabled(): bool
  {
    return $this->value('MAILIN_YES') === '1' && $this->host() !== '' && $this->user() !== '' && $this->password() !== '';
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the IMAP server.
   *
   * @return string
   */
  public function host(): string
  {
    return $this->value('MAILIN_HOST');
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the IMAP port.
   *
   * @return int Default 993
   */
  public function port(): int
  {
    $port = (int)$this->value('MAILIN_PORT');

    return $port >= 1 && $port <= 65535 ? $port : 993;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the kind of encryption.
   *
   * @return string ssl (implicit TLS), tls (STARTTLS) or none; without MAILIN_ENCRYPTION it follows the port
   */
  public function encryption(): string
  {
    $encryption = strtolower($this->value('MAILIN_ENCRYPTION'));
    if (in_array($encryption, ['ssl', 'tls', 'none'], true)) {
      return $encryption;
    }

    return match ($this->port()) {
      993 => 'ssl',
      143 => 'tls',
      default => 'none',
    };
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the login name of the mailbox.
   *
   * @return string MAILIN_USER, else MAIL_USER
   */
  public function user(): string
  {
    return $this->value('MAILIN_USER') !== '' ? $this->value('MAILIN_USER') : $this->value('MAIL_USER');
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the password of the mailbox.
   *
   * @return string MAILIN_PASS, else MAIL_PASS
   */
  public function password(): string
  {
    return $this->value('MAILIN_PASS') !== '' ? $this->value('MAILIN_PASS') : $this->value('MAIL_PASS');
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the folder that is read.
   *
   * @return string Default INBOX
   */
  public function folder(): string
  {
    return $this->value('MAILIN_FOLDER') !== '' ? $this->value('MAILIN_FOLDER') : 'INBOX';
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the folder for the mails that became an issue or a comment.
   *
   * @return string Default Processed
   */
  public function processedFolder(): string
  {
    return $this->value('MAILIN_PROCESSED_FOLDER') !== '' ? $this->value('MAILIN_PROCESSED_FOLDER') : 'Processed';
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the folder for the mails that were refused.
   *
   * @return string Default Rejected
   */
  public function rejectedFolder(): string
  {
    return $this->value('MAILIN_REJECTED_FOLDER') !== '' ? $this->value('MAILIN_REJECTED_FOLDER') : 'Rejected';
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the certificate of the server is verified.
   *
   * @return bool Default true; MAILIN_VALIDATE_CERT=0 switches it off (self-signed test servers only)
   */
  public function validateCertificate(): bool
  {
    return $this->value('MAILIN_VALIDATE_CERT') !== '0';
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the servers whose Authentication-Results header is believed (MAILIN_AUTH_SERVER, comma separated).
   *
   * Every mail can carry an Authentication-Results header that the sender wrote; only the header of the server that
   * received the mail means anything. So the result is trusted only if the authserv-id in it is one of these names.
   * With none configured no result is trusted, and every sender depends on the setting mailin_trust_sender.
   *
   * @return string[] Lower case
   */
  public function authServers(): array
  {
    $parts = preg_split('/[\s,;]+/', strtolower($this->value('MAILIN_AUTH_SERVER')), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    return array_values(array_unique($parts));
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the authserv-id of an Authentication-Results header is one of the trusted servers. A configured name
   * matches itself and the names below it: "kundenserver.de" matches "mx.kundenserver.de".
   *
   * @param string $authServer The authserv-id from the header
   *
   * @return bool
   */
  public function trustsAuthServer(string $authServer): bool
  {
    $authServer = strtolower(trim($authServer));
    if ($authServer === '') {
      return false;
    }
    foreach ($this->authServers() as $trusted) {
      if ($authServer === $trusted || str_ends_with($authServer, '.' . $trusted)) {
        return true;
      }
    }

    return false;
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the address that answers should go to: the login of the mailbox, if it is an email address.
   *
   * @return string Empty if the login is not an address
   */
  public function replyAddress(): string
  {
    $user = $this->user();

    return filter_var($user, FILTER_VALIDATE_EMAIL) !== false ? $user : '';
  }

  //---------------------------------------------------------------------------
  /**
   * Reads one value.
   *
   * @param string $key The name
   *
   * @return string Trimmed; empty if it is not set
   */
  private function value(string $key): string
  {
    return trim((string)($this->env[$key] ?? ''));
  }
}
