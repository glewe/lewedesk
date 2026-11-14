<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

require_once __DIR__ . '/MailboxClient.php';
require_once __DIR__ . '/MailboxException.php';

//---------------------------------------------------------------------------
/**
 * An IMAP client on plain sockets: no PHP extension needed, so it works the same on every host (the imap extension is
 * gone from PHP core since 8.4). It speaks the little of IMAP4rev1 that Email-to-ticket needs: login, folders, search
 * for unseen mails, fetch, flag and move. Moving uses MOVE when the server offers it, else COPY and EXPUNGE.
 *
 * Folder names are UTF-8 for the caller and modified UTF-7 on the wire (RFC 3501), e.g. "Entw&APw-rfe".
 * Passwords never appear in exception messages.
 */
final class ImapSocketClient implements MailboxClient
{
  /** @var resource|null */
  private $socket = null;

  private int $tag = 0;

  /** @var string[] Upper case capabilities of the server */
  private array $capabilities = [];

  /**
   * Constructor.
   *
   * @param string $host           The IMAP server
   * @param int    $port           The port (993 for implicit TLS, 143 for STARTTLS)
   * @param string $encryption     ssl, tls or none
   * @param string $user           The login
   * @param string $password       The password
   * @param bool   $validateCert   Whether the certificate of the server is verified
   * @param int    $timeout        Seconds to wait for the server
   */
  public function __construct(
    private readonly string $host,
    private readonly int $port,
    private readonly string $encryption,
    private readonly string $user,
    private readonly string $password,
    private readonly bool $validateCert = true,
    private readonly int $timeout = 20
  ) {
  }

  //---------------------------------------------------------------------------
  public function connect(): void
  {
    $context = stream_context_create(['ssl' => [
      'verify_peer' => $this->validateCert,
      'verify_peer_name' => $this->validateCert,
      'allow_self_signed' => !$this->validateCert,
    ]]);
    $scheme = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
    $socket = @stream_socket_client("$scheme://{$this->host}:{$this->port}", $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
    if ($socket === false) {
      throw new MailboxException("Cannot connect to {$this->host}:{$this->port} ($errstr)");
    }
    stream_set_timeout($socket, $this->timeout);
    $this->socket = $socket;

    $greeting = $this->readLine();
    if (!str_starts_with($greeting, '* OK') && !str_starts_with($greeting, '* PREAUTH')) {
      throw new MailboxException('The server did not accept the connection: ' . $greeting);
    }

    if ($this->encryption === 'tls') {
      $this->command('STARTTLS');
      if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        throw new MailboxException('STARTTLS negotiation failed');
      }
    }

    $this->refreshCapabilities();
    $this->login();
    $this->refreshCapabilities();
  }

  //---------------------------------------------------------------------------
  public function folders(): array
  {
    $names = [];
    foreach ($this->command('LIST "" "*"')['untagged'] as $entry) {
      $folder = $this->listEntry($entry);
      if ($folder !== null) {
        $names[] = $folder;
      }
    }

    return $names;
  }

  //---------------------------------------------------------------------------
  public function ensureFolder(string $folder): void
  {
    foreach ($this->command('LIST "" ' . $this->quote(self::encodeFolder($folder)))['untagged'] as $entry) {
      $found = $this->listEntry($entry);
      if ($found !== null && strcasecmp($found, $folder) === 0) {
        return;
      }
    }

    $this->command('CREATE ' . $this->quote(self::encodeFolder($folder)));
    try {
      $this->command('SUBSCRIBE ' . $this->quote(self::encodeFolder($folder)));
    } catch (MailboxException) {
      // A folder that is not subscribed works as well
    }
  }

  //---------------------------------------------------------------------------
  public function selectFolder(string $folder): int
  {
    $exists = 0;
    foreach ($this->command('SELECT ' . $this->quote(self::encodeFolder($folder)))['untagged'] as $entry) {
      if (preg_match('/^\* (\d+) EXISTS/i', $entry['line'], $m) === 1) {
        $exists = (int)$m[1];
      }
    }

    return $exists;
  }

  //---------------------------------------------------------------------------
  public function unseenUids(int $limit): array
  {
    $uids = [];
    foreach ($this->command('UID SEARCH UNSEEN')['untagged'] as $entry) {
      if (preg_match('/^\* SEARCH ?(.*)$/i', $entry['line'], $m) === 1) {
        foreach (preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $uid) {
          $uids[] = (int)$uid;
        }
      }
    }
    sort($uids);

    return array_slice($uids, 0, max(0, $limit));
  }

  //---------------------------------------------------------------------------
  public function size(int $uid): int
  {
    foreach ($this->command("UID FETCH $uid (RFC822.SIZE)")['untagged'] as $entry) {
      if (preg_match('/RFC822\.SIZE (\d+)/i', $entry['line'], $m) === 1) {
        return (int)$m[1];
      }
    }

    return 0;
  }

  //---------------------------------------------------------------------------
  public function fetchRaw(int $uid): string
  {
    foreach ($this->command("UID FETCH $uid (BODY.PEEK[])")['untagged'] as $entry) {
      if ($entry['literals'] !== [] && stripos($entry['line'], 'FETCH') !== false) {
        return $entry['literals'][0];
      }
    }

    throw new MailboxException("The mail $uid could not be loaded");
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the UIDs of the newest mails of the selected folder, seen or not (for the diagnostic scripts).
   *
   * @param int $limit The most UIDs to return
   *
   * @return int[] Newest first
   */
  public function newestUids(int $limit): array
  {
    $uids = [];
    foreach ($this->command('UID SEARCH ALL')['untagged'] as $entry) {
      if (preg_match('/^\* SEARCH ?(.*)$/i', $entry['line'], $m) === 1) {
        foreach (preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $uid) {
          $uids[] = (int)$uid;
        }
      }
    }
    rsort($uids);

    return array_slice($uids, 0, max(0, $limit));
  }

  //---------------------------------------------------------------------------
  /**
   * Loads some header fields of a mail without marking it as seen (for the diagnostic scripts).
   *
   * @param int      $uid    The UID
   * @param string[] $fields Header field names, e.g. ['Authentication-Results']
   *
   * @return string The header lines as sent
   */
  public function fetchHeaderFields(int $uid, array $fields): string
  {
    $names = implode(' ', array_map(static fn(string $f): string => preg_replace('/[^A-Za-z0-9-]/', '', $f) ?? '', $fields));
    foreach ($this->command("UID FETCH $uid (BODY.PEEK[HEADER.FIELDS ($names)])")['untagged'] as $entry) {
      if ($entry['literals'] !== []) {
        return $entry['literals'][0];
      }
    }

    return '';
  }

  //---------------------------------------------------------------------------
  public function markSeen(int $uid): void
  {
    $this->command("UID STORE $uid +FLAGS.SILENT (\\Seen)");
  }

  //---------------------------------------------------------------------------
  public function moveTo(int $uid, string $folder): void
  {
    $target = $this->quote(self::encodeFolder($folder));
    if (in_array('MOVE', $this->capabilities, true)) {
      $this->command("UID MOVE $uid $target");

      return;
    }

    $this->command("UID COPY $uid $target");
    $this->command("UID STORE $uid +FLAGS.SILENT (\\Deleted)");
    $this->command(in_array('UIDPLUS', $this->capabilities, true) ? "UID EXPUNGE $uid" : 'EXPUNGE');
  }

  //---------------------------------------------------------------------------
  public function close(): void
  {
    if ($this->socket === null) {
      return;
    }
    try {
      $this->command('LOGOUT');
    } catch (\Throwable) {
      // Closing must work whatever the state of the connection is
    }
    @fclose($this->socket);
    $this->socket = null;
  }

  //---------------------------------------------------------------------------
  /**
   * Encodes a folder name as modified UTF-7 (RFC 3501, 5.1.3).
   *
   * @param string $name The name, UTF-8
   *
   * @return string
   */
  public static function encodeFolder(string $name): string
  {
    $out = '';
    $run = '';
    $flush = static function () use (&$out, &$run): void {
      if ($run !== '') {
        $out .= '&' . strtr(rtrim(base64_encode((string)mb_convert_encoding($run, 'UTF-16BE', 'UTF-8')), '='), '/', ',') . '-';
        $run = '';
      }
    };

    foreach (mb_str_split($name, 1, 'UTF-8') as $char) {
      $code = strlen($char) === 1 ? ord($char) : 0;
      if ($code >= 0x20 && $code <= 0x7E) {
        $flush();
        $out .= $char === '&' ? '&-' : $char;
      } else {
        $run .= $char;
      }
    }
    $flush();

    return $out;
  }

  //---------------------------------------------------------------------------
  /**
   * Decodes a modified UTF-7 folder name.
   *
   * @param string $name The name as the server sends it
   *
   * @return string UTF-8
   */
  public static function decodeFolder(string $name): string
  {
    return preg_replace_callback('/&([^-]*)-/', static function (array $m): string {
      if ($m[1] === '') {
        return '&';
      }
      $binary = base64_decode(strtr($m[1], ',', '/'), false);

      return $binary === false ? $m[0] : (string)mb_convert_encoding($binary, 'UTF-8', 'UTF-16BE');
    }, $name) ?? $name;
  }

  //---------------------------------------------------------------------------
  /**
   * Logs in with LOGIN, or with AUTHENTICATE PLAIN when the credentials do not fit into a quoted string.
   *
   * @return void
   */
  private function login(): void
  {
    $plain = static fn(string $s): bool => preg_match('/^[\x20-\x7E]*$/', $s) === 1;
    if ($plain($this->user) && $plain($this->password)) {
      $this->command('LOGIN ' . $this->quote($this->user) . ' ' . $this->quote($this->password), 'LOGIN');

      return;
    }

    $this->command('AUTHENTICATE PLAIN ' . base64_encode("\0" . $this->user . "\0" . $this->password), 'AUTHENTICATE');
  }

  //---------------------------------------------------------------------------
  /**
   * Asks the server what it can do.
   *
   * @return void
   */
  private function refreshCapabilities(): void
  {
    $this->capabilities = [];
    foreach ($this->command('CAPABILITY')['untagged'] as $entry) {
      if (preg_match('/^\* CAPABILITY (.*)$/i', $entry['line'], $m) === 1) {
        $this->capabilities = array_merge($this->capabilities, preg_split('/\s+/', strtoupper(trim($m[1])), -1, PREG_SPLIT_NO_EMPTY) ?: []);
      }
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Reads the folder name from a LIST answer.
   *
   * @param array{line: string, literals: string[]} $entry An untagged answer
   *
   * @return string|null The name (UTF-8); null if the answer is not a folder or the folder cannot be selected
   */
  private function listEntry(array $entry): ?string
  {
    if (preg_match('/^\* LIST \(([^)]*)\) (?:NIL|"(?:[^"\\\\]|\\\\.)*") (.*)$/i', $entry['line'], $m) !== 1) {
      return null;
    }
    if (stripos($m[1], '\\Noselect') !== false) {
      return null;
    }

    $name = trim($m[2]);
    if ($name !== '' && $name[0] === '"') {
      $name = preg_replace('/\\\\(.)/', '$1', substr($name, 1, -1)) ?? '';
    } elseif (preg_match('/^\{\d+\}$/', $name) === 1) {
      $name = $entry['literals'][0] ?? '';
    }

    return self::decodeFolder($name);
  }

  //---------------------------------------------------------------------------
  /**
   * Quotes a string for a command.
   *
   * @param string $value The text; no line breaks
   *
   * @return string
   */
  private function quote(string $value): string
  {
    if (preg_match('/[\r\n\0]/', $value) === 1) {
      throw new MailboxException('A value with a line break cannot be sent to the server');
    }

    return '"' . addcslashes($value, '"\\') . '"';
  }

  //---------------------------------------------------------------------------
  /**
   * Sends a command and reads the answer up to its completion.
   *
   * @param string      $command The command line
   * @param string|null $name    What to call the command in an error message (default: its first word)
   *
   * @return array{status: string, text: string, untagged: array<int, array{line: string, literals: string[]}>}
   *
   * @throws MailboxException If the server answers NO or BAD, or the connection fails
   */
  private function command(string $command, ?string $name = null): array
  {
    if ($this->socket === null) {
      throw new MailboxException('Not connected');
    }

    $tag = 'A' . ++$this->tag;
    if (@fwrite($this->socket, "$tag $command\r\n") === false) {
      throw new MailboxException('Connection lost while writing');
    }

    $untagged = [];
    while (true) {
      $line = $this->readLine();
      $literals = [];
      while (preg_match('/\{(\d+)\+?\}$/', $line, $m) === 1) {
        $literals[] = $this->readBytes((int)$m[1]);
        $line .= $this->readLine();
      }

      if (str_starts_with($line, "$tag ")) {
        [$status, $text] = array_pad(explode(' ', substr($line, strlen($tag) + 1), 2), 2, '');
        $status = strtoupper($status);
        if ($status !== 'OK') {
          $verb = $name ?? strtoupper((string)strtok($command, ' '));
          throw new MailboxException("$verb failed: $status " . trim($text));
        }

        return ['status' => $status, 'text' => trim($text), 'untagged' => $untagged];
      }
      if (str_starts_with($line, '* ')) {
        $untagged[] = ['line' => $line, 'literals' => $literals];
      }
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Reads one line without its line break.
   *
   * @return string
   */
  private function readLine(): string
  {
    $line = fgets($this->socket);
    if ($line === false) {
      $info = stream_get_meta_data($this->socket);
      throw new MailboxException(!empty($info['timed_out']) ? 'The server did not answer in time' : 'The connection was closed by the server');
    }

    return rtrim($line, "\r\n");
  }

  //---------------------------------------------------------------------------
  /**
   * Reads an exact number of bytes (a literal).
   *
   * @param int $length The number of bytes
   *
   * @return string
   */
  private function readBytes(int $length): string
  {
    $data = '';
    while (strlen($data) < $length) {
      $chunk = fread($this->socket, min(65536, $length - strlen($data)));
      if ($chunk === false || $chunk === '') {
        throw new MailboxException('The connection was lost while a mail was loaded');
      }
      $data .= $chunk;
    }

    return $data;
  }
}
