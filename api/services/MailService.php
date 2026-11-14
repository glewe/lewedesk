<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Minimal SMTP mail sender configured through the MAIL_* environment variables.
 *
 * MAIL_ENCRYPTION is optional (ssl, tls or none). Without it port 465 uses implicit SSL,
 * port 587 uses STARTTLS and any other port is unencrypted (e.g. a local MailHog).
 */
class MailService
{
  private string $host;
  private int $port;
  private string $user;
  private string $pass;
  private string $from;
  private string $fromName;
  private string $encryption;

  /** @var resource|null */
  private $socket = null;

  private string $lastError = '';

  //---------------------------------------------------------------------------
  /**
   * Reads the mail configuration from the environment.
   *
   * @param string $fromName The sender name (the application name); falls back to APP_NAME
   */
  public function __construct(string $fromName = '')
  {
    $this->host = (string)($_ENV['MAIL_HOST'] ?? '');
    $this->port = (int)($_ENV['MAIL_PORT'] ?? 587);
    $this->user = (string)($_ENV['MAIL_USER'] ?? '');
    $this->pass = (string)($_ENV['MAIL_PASS'] ?? '');
    $this->from = (string)($_ENV['MAIL_FROM'] ?? '');
    $this->fromName = trim($fromName) !== '' ? trim($fromName) : (string)($_ENV['APP_NAME'] ?? 'LeweDesk');

    $encryption = strtolower((string)($_ENV['MAIL_ENCRYPTION'] ?? ''));
    if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
      $encryption = match ($this->port) {
        465 => 'ssl',
        587 => 'tls',
        default => 'none',
      };
    }
    $this->encryption = $encryption;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the minimum configuration (host and sender) is present.
   *
   * @return bool
   */
  public function isConfigured(): bool
  {
    return $this->host !== '' && filter_var($this->from, FILTER_VALIDATE_EMAIL) !== false;
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the reason why the last send() failed.
   *
   * @return string Empty if the last mail was accepted
   */
  public function lastError(): string
  {
    return $this->lastError;
  }

  //---------------------------------------------------------------------------
  /**
   * Sends a multipart (text and HTML) email. Failures are logged, never thrown.
   *
   * @param string $to      Recipient address
   * @param string $subject Subject line
   * @param string $html    HTML body
   * @param string $text    Plain text body
   * @param array  $options Optional headers: message_id (a value from newMessageId()), in_reply_to (a message id),
   *                        references (message ids, oldest first) and reply_to (an address), so that mail programs
   *                        thread the mails of an issue and answers reach the support mailbox
   *
   * @return bool True if the server accepted the message
   */
  public function send(string $to, string $subject, string $html, string $text, array $options = []): bool
  {
    $this->lastError = '';
    try {
      if (!$this->isConfigured()) {
        throw new RuntimeException('Mail is not configured (MAIL_HOST / MAIL_FROM)');
      }
      if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('Invalid recipient address');
      }
      // Sample users use @yourserver.com addresses, which would only cause delivery failures
      if (self::isSampleAddress($to)) {
        return true;
      }

      $this->connect();
      $this->command('MAIL FROM:<' . $this->from . '>', [250]);
      $this->command('RCPT TO:<' . $to . '>', [250, 251]);
      $this->command('DATA', [354]);
      $message = $this->buildMessage($to, $subject, $html, $text, $options);
      // Dot-stuff lines starting with "." and terminate the data section
      $message = preg_replace('/^\./m', '..', $message);
      $this->write($message . "\r\n.");
      $this->expect([250]);
      $this->command('QUIT', [221]);

      return true;
    } catch (RuntimeException $e) {
      $this->lastError = $e->getMessage();
      error_log('Mail delivery failed: ' . $e->getMessage());

      return false;
    } finally {
      $this->close();
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Creates a Message-ID for a mail that will be sent, so that it can be stored before the mail is sent.
   *
   * @return string With the angle brackets, e.g. <3f9a...@example.com>
   */
  public function newMessageId(): string
  {
    return '<' . bin2hex(random_bytes(12)) . '@' . $this->domain() . '>';
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the domain of the sender address.
   *
   * @return string
   */
  private function domain(): string
  {
    return substr(strrchr($this->from, '@') ?: '@localhost', 1);
  }

  //---------------------------------------------------------------------------
  /**
   * Makes a message id safe for a header: one id in angle brackets, no line breaks or spaces.
   *
   * @param string $id The id, with or without angle brackets
   *
   * @return string Empty if nothing usable is left
   */
  private static function headerId(string $id): string
  {
    $id = preg_replace('/[^\x21-\x7e]|[<>]/', '', $id) ?? '';

    return $id === '' ? '' : '<' . $id . '>';
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether an address belongs to the sample data domain (@yourserver.com).
   *
   * @param string $address Email address
   *
   * @return bool
   */
  private static function isSampleAddress(string $address): bool
  {
    $address = strtolower(trim($address));

    // The domain .invalid is reserved for addresses that do not exist (RFC 6761): the account "Email requester" has one
    return str_ends_with($address, '@yourserver.com') || str_ends_with($address, '.invalid');
  }

  //---------------------------------------------------------------------------
  /**
   * Opens the connection, negotiates encryption and authenticates.
   *
   * @return void
   */
  private function connect(): void
  {
    $scheme = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
    $socket = @stream_socket_client("$scheme://{$this->host}:{$this->port}", $errno, $errstr, 15);
    if ($socket === false) {
      throw new RuntimeException("Cannot connect to {$this->host}:{$this->port} ($errstr)");
    }
    stream_set_timeout($socket, 15);
    $this->socket = $socket;

    $this->expect([220]);
    $this->command('EHLO ' . $this->clientName(), [250]);

    if ($this->encryption === 'tls') {
      $this->command('STARTTLS', [220]);
      if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        throw new RuntimeException('STARTTLS negotiation failed');
      }
      $this->command('EHLO ' . $this->clientName(), [250]);
    }

    if ($this->user !== '') {
      $this->command('AUTH LOGIN', [334]);
      $this->command(base64_encode($this->user), [334]);
      $this->command(base64_encode($this->pass), [235]);
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Builds the RFC 5322 message with a text and an HTML alternative.
   *
   * @param string $to      Recipient address
   * @param string $subject Subject line
   * @param string $html    HTML body
   * @param string $text    Plain text body
   * @param array  $options Optional headers (see send())
   *
   * @return string The message with CRLF line endings
   */
  private function buildMessage(string $to, string $subject, string $html, string $text, array $options = []): string
  {
    $boundary = 'b_' . bin2hex(random_bytes(12));
    $fromName = '=?UTF-8?B?' . base64_encode($this->fromName) . '?=';

    $headers = [
      "From: $fromName <{$this->from}>",
      "To: <$to>",
      'Subject: =?UTF-8?B?' . base64_encode(str_replace(["\r", "\n"], ' ', $subject)) . '?=',
      'Date: ' . date('r'),
      'Message-ID: ' . (self::headerId((string)($options['message_id'] ?? '')) ?: $this->newMessageId()),
      // Tells other mail systems not to answer with out-of-office replies and the like (no mail loops)
      'Auto-Submitted: auto-generated',
      'X-Auto-Response-Suppress: All',
      'MIME-Version: 1.0',
      "Content-Type: multipart/alternative; boundary=\"$boundary\"",
    ];

    $replyTo = trim((string)($options['reply_to'] ?? ''));
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) !== false && strcasecmp($replyTo, $this->from) !== 0) {
      $headers[] = "Reply-To: <$replyTo>";
    }
    $inReplyTo = self::headerId((string)($options['in_reply_to'] ?? ''));
    if ($inReplyTo !== '') {
      $headers[] = "In-Reply-To: $inReplyTo";
    }
    $references = array_values(array_filter(array_map(
      static fn($id): string => self::headerId((string)$id),
      (array)($options['references'] ?? [])
    )));
    if ($references !== []) {
      $headers[] = 'References: ' . implode(' ', $references);
    }

    $part = static fn (string $type, string $body): string =>
      "--$boundary\r\nContent-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
      . chunk_split(base64_encode($body), 76, "\r\n");

    return implode("\r\n", $headers) . "\r\n\r\n"
      . $part('text/plain', $text)
      . $part('text/html', $html)
      . "--$boundary--\r\n";
  }

  //---------------------------------------------------------------------------
  /**
   * Sends a command and checks the reply code.
   *
   * @param string $command  The command line
   * @param int[]  $expected Accepted reply codes
   *
   * @return string The reply
   */
  private function command(string $command, array $expected): string
  {
    $this->write($command);

    return $this->expect($expected);
  }

  //---------------------------------------------------------------------------
  /**
   * Writes a line to the server.
   *
   * @param string $data Data without the trailing CRLF
   *
   * @return void
   */
  private function write(string $data): void
  {
    if (@fwrite($this->socket, $data . "\r\n") === false) {
      throw new RuntimeException('Connection lost while writing');
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Reads a (possibly multi-line) reply and checks its code.
   *
   * @param int[] $expected Accepted reply codes
   *
   * @return string The reply
   */
  private function expect(array $expected): string
  {
    $reply = '';
    while (($line = fgets($this->socket, 1024)) !== false) {
      $reply .= $line;
      // A space after the code marks the last line ("250-" continues)
      if (strlen($line) < 4 || $line[3] === ' ') {
        break;
      }
    }

    if (!in_array((int)substr($reply, 0, 3), $expected, true)) {
      throw new RuntimeException('Unexpected SMTP reply: ' . trim($reply));
    }

    return $reply;
  }

  //---------------------------------------------------------------------------
  /**
   * Returns the name announced in EHLO.
   *
   * @return string
   */
  private function clientName(): string
  {
    return preg_replace('/[^A-Za-z0-9.-]/', '', (string)gethostname()) ?: 'localhost';
  }

  //---------------------------------------------------------------------------
  /**
   * Closes the connection.
   *
   * @return void
   */
  private function close(): void
  {
    if (is_resource($this->socket)) {
      fclose($this->socket);
    }
    $this->socket = null;
  }
}
