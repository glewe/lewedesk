<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

//---------------------------------------------------------------------------
/**
 * What Email-to-ticket needs from a mailbox. The implementation is ImapSocketClient (plain sockets, no PHP extension);
 * the tests use a fake one. Mails are addressed by their UID, which stays valid while they are moved.
 */
interface MailboxClient
{
  /**
   * Opens the connection and logs in.
   *
   * @return void
   *
   * @throws MailboxException
   */
  public function connect(): void;

  /**
   * Gives the names of all folders of the mailbox (UTF-8).
   *
   * @return string[]
   *
   * @throws MailboxException
   */
  public function folders(): array;

  /**
   * Makes sure a folder exists; creates it if not.
   *
   * @param string $folder The folder name (UTF-8)
   *
   * @return void
   *
   * @throws MailboxException
   */
  public function ensureFolder(string $folder): void;

  /**
   * Opens a folder for reading and moving.
   *
   * @param string $folder The folder name (UTF-8)
   *
   * @return int The number of mails in it
   *
   * @throws MailboxException
   */
  public function selectFolder(string $folder): int;

  /**
   * Gives the UIDs of the unseen mails of the selected folder, oldest first.
   *
   * @param int $limit The most UIDs to return
   *
   * @return int[]
   *
   * @throws MailboxException
   */
  public function unseenUids(int $limit): array;

  /**
   * Gives the size of a mail without loading it.
   *
   * @param int $uid The UID
   *
   * @return int Bytes
   *
   * @throws MailboxException
   */
  public function size(int $uid): int;

  /**
   * Loads a mail as it is, without marking it as seen.
   *
   * @param int $uid The UID
   *
   * @return string The raw message
   *
   * @throws MailboxException
   */
  public function fetchRaw(int $uid): string;

  /**
   * Marks a mail as seen.
   *
   * @param int $uid The UID
   *
   * @return void
   *
   * @throws MailboxException
   */
  public function markSeen(int $uid): void;

  /**
   * Moves a mail from the selected folder into another one.
   *
   * @param int    $uid    The UID
   * @param string $folder The target folder (UTF-8); it must exist
   *
   * @return void
   *
   * @throws MailboxException
   */
  public function moveTo(int $uid, string $folder): void;

  /**
   * Logs out and closes the connection. Never throws.
   *
   * @return void
   */
  public function close(): void;
}
