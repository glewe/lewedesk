<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use Throwable;

require_once __DIR__ . '/ParsedAttachment.php';
require_once __DIR__ . '/ParsedMail.php';
require_once __DIR__ . '/MailHtmlConverter.php';

//---------------------------------------------------------------------------
/**
 * Reads a received mail (RFC 822 / MIME) into a ParsedMail. It works on the raw text and touches neither the database
 * nor the network, so it can be tested with .eml files (scripts/mail-parser-check.php).
 *
 * Handled: folded headers, encoded words (RFC 2047) and parameters (RFC 2231), the charsets mbstring knows (broken or
 * unknown ones fall back to UTF-8 and Windows-1252), quoted-printable, base64, format=flowed, nested multipart,
 * attachments with a Content-ID (inline images). A message that is attached (message/rfc822) stays an attachment and is
 * not read. Bad input never throws: what cannot be read is left out.
 */
final class MailParser
{
  /** Deepest nesting of multipart that is followed */
  private const MAX_DEPTH = 12;

  /** Most parts that are read from one mail */
  private const MAX_PARTS = 200;

  private MailHtmlConverter $converter;
  private int $parts = 0;

  public function __construct(?MailHtmlConverter $converter = null)
  {
    $this->converter = $converter ?? new MailHtmlConverter();
  }

  //---------------------------------------------------------------------------
  /**
   * Parses a mail.
   *
   * @param string $raw The whole message as it came from the server
   *
   * @return ParsedMail
   */
  public function parse(string $raw): ParsedMail
  {
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    [$headerBlock, $body] = $this->split($raw);
    $headers = $this->parseHeaders($headerBlock);

    $this->parts = 0;
    $found = ['plain' => [], 'html' => [], 'attachments' => [], 'bounce' => false];
    $this->walk($headers, $body, $found, 0);

    $html = trim(implode("\n", $found['html']));
    $text = trim(implode("\n\n", array_filter($found['plain'], static fn(string $t): bool => trim($t) !== '')));
    if ($text === '') {
      $text = $this->converter->toText($html);
    }

    $attachments = [];
    foreach ($found['attachments'] as $attachment) {
      $referenced = $attachment->contentId !== null && $html !== ''
        && stripos($html, 'cid:' . $attachment->contentId) !== false;
      $attachments[] = $attachment->withReferenced($referenced);
    }

    [$fromAddress, $fromName] = $this->mailbox($this->first($headers, 'from'));
    $subject = $this->subject($this->first($headers, 'subject'));
    $date = $this->date($this->first($headers, 'date'));
    $inReplyTo = $this->ids($this->first($headers, 'in-reply-to'));
    $references = $this->ids(implode(' ', $headers['references'] ?? []));
    if ($inReplyTo !== [] && !in_array($inReplyTo[0], $references, true)) {
      $references[] = $inReplyTo[0];
    }

    $ownId = $this->ids($this->first($headers, 'message-id'));
    $messageId = $ownId[0] ?? '<noid-' . sha1($fromAddress . '|' . ($date?->format('c') ?? '') . '|' . $subject . '|' . substr($text, 0, 2000)) . '@noid.invalid>';

    return new ParsedMail(
      messageId: $messageId,
      hasOwnMessageId: $ownId !== [],
      fromAddress: $fromAddress,
      fromName: $fromName,
      replyTo: $this->mailbox($this->first($headers, 'reply-to'))[0],
      returnPath: isset($headers['return-path']) ? $this->returnPath($headers['return-path'][0]) : null,
      to: $this->addresses(implode(',', $headers['to'] ?? [])),
      subject: $subject,
      date: $date,
      inReplyTo: $inReplyTo[0] ?? '',
      references: $references,
      text: $text,
      html: $html,
      attachments: $attachments,
      isAutoReply: $this->isAutoReply($headers, $subject),
      isBounce: $found['bounce'] || $this->isBounce($headers, $fromAddress, $subject),
      isList: $this->isList($headers),
      auth: $this->authResults($headers['authentication-results'][0] ?? ''),
      authServer: $this->authServer($headers['authentication-results'][0] ?? '')
    );
  }

  //---------------------------------------------------------------------------
  /**
   * Splits an entity into the header block and the body.
   *
   * @param string $raw The entity, with LF line breaks
   *
   * @return array{0: string, 1: string}
   */
  private function split(string $raw): array
  {
    if (str_starts_with($raw, "\n")) {
      return ['', substr($raw, 1)];
    }
    $pos = strpos($raw, "\n\n");

    return $pos === false ? [$raw, ''] : [substr($raw, 0, $pos), substr($raw, $pos + 2)];
  }

  //---------------------------------------------------------------------------
  /**
   * Reads a header block. Folded lines are joined.
   *
   * @param string $block The header lines
   *
   * @return array<string, string[]> Lower case name => values in the order of appearance (raw, not decoded)
   */
  private function parseHeaders(string $block): array
  {
    $headers = [];
    $block = preg_replace('/\n[ \t]+/', ' ', $block) ?? $block;
    foreach (explode("\n", $block) as $line) {
      $pos = strpos($line, ':');
      if ($pos === false || $pos === 0) {
        continue;
      }
      $name = strtolower(trim(substr($line, 0, $pos)));
      if (preg_match('/^[a-z0-9!#$%&\'*+.^_`|~-]+$/', $name) === 1) {
        $headers[$name][] = trim(substr($line, $pos + 1));
      }
    }

    return $headers;
  }

  //---------------------------------------------------------------------------
  /**
   * Reads one part and, for multipart, the parts inside it.
   *
   * @param array<string, string[]> $headers The headers of the part
   * @param string                  $body    The body of the part, still encoded
   * @param array                   $found   Collects plain, html, attachments, bounce
   * @param int                     $depth   The nesting depth
   *
   * @return void
   */
  private function walk(array $headers, string $body, array &$found, int $depth): void
  {
    if ($depth > self::MAX_DEPTH || ++$this->parts > self::MAX_PARTS) {
      return;
    }

    [$type, $params] = $this->structured($this->first($headers, 'content-type') ?: 'text/plain; charset=us-ascii');
    [$disposition, $dispositionParams] = $this->structured($this->first($headers, 'content-disposition'));

    if (str_starts_with($type, 'multipart/')) {
      if ($type === 'multipart/report' && strtolower($params['report-type'] ?? '') === 'delivery-status') {
        $found['bounce'] = true;
      }
      $boundary = $params['boundary'] ?? '';
      if ($boundary === '') {
        return;
      }
      foreach ($this->multipart($body, $boundary) as $part) {
        [$partHeaders, $partBody] = $this->split($part);
        $this->walk($this->parseHeaders($partHeaders), $partBody, $found, $depth + 1);
      }

      return;
    }

    if (in_array($type, ['message/delivery-status', 'message/disposition-notification', 'message/feedback-report'], true)) {
      $found['bounce'] = $found['bounce'] || $type === 'message/delivery-status';

      return;
    }

    $filename = $this->filename($dispositionParams['filename'] ?? ($params['name'] ?? ''));
    $isText = $type === 'text/plain' || $type === 'text/html';
    $contentId = trim($this->first($headers, 'content-id'), " \t<>");

    if ($isText && $disposition !== 'attachment' && $filename === '') {
      $text = $this->toUtf8($this->decodeBody($body, $this->first($headers, 'content-transfer-encoding')), $params['charset'] ?? '');
      if ($type === 'text/plain') {
        $found['plain'][] = strtolower($params['format'] ?? '') === 'flowed' ? $this->unflow($text, strtolower($params['delsp'] ?? '') === 'yes') : $text;
      } else {
        $found['html'][] = $text;
      }

      return;
    }

    $data = $this->decodeBody($body, $this->first($headers, 'content-transfer-encoding'));
    if ($filename === '') {
      $filename = $this->defaultName($type, count($found['attachments']) + 1);
    }
    $inline = str_starts_with($type, 'image/') && ($contentId !== '' || $disposition === 'inline');
    $found['attachments'][] = new ParsedAttachment($filename, $type, $data, $contentId !== '' ? $contentId : null, $inline);
  }

  //---------------------------------------------------------------------------
  /**
   * Splits the body of a multipart entity at its boundary lines. A missing closing boundary is tolerated.
   *
   * @param string $body     The body
   * @param string $boundary The boundary parameter
   *
   * @return string[] The parts, headers included
   */
  private function multipart(string $body, string $boundary): array
  {
    $pattern = '/^--' . preg_quote($boundary, '/') . '(--)?[ \t]*$/m';
    if (preg_match_all($pattern, $body, $matches, PREG_OFFSET_CAPTURE) < 1) {
      return [];
    }

    $parts = [];
    $starts = $matches[0];
    foreach ($starts as $index => [$line, $offset]) {
      if (($matches[1][$index][0] ?? '') === '--') {
        break;
      }
      $from = $offset + strlen($line) + 1;
      $to = isset($starts[$index + 1]) ? $starts[$index + 1][1] - 1 : strlen($body);
      if ($to >= $from) {
        $parts[] = substr($body, $from, $to - $from);
      }
    }

    return $parts;
  }

  //---------------------------------------------------------------------------
  /**
   * Reads a structured header value: "text/plain; charset=utf-8" or "attachment; filename*=UTF-8''a%20b.pdf".
   *
   * @param string $value The header value
   *
   * @return array{0: string, 1: array<string, string>} The main value in lower case, and the parameters (names in lower
   *                                                    case, RFC 2231 continuations and encodings resolved)
   */
  private function structured(string $value): array
  {
    $value = trim($value);
    $pos = strpos($value, ';');
    $main = strtolower(trim($pos === false ? $value : substr($value, 0, $pos)));
    if ($pos === false) {
      return [$main, []];
    }

    $raw = [];
    preg_match_all('/;\s*([^\s=;]+)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|[^;]*)/', substr($value, $pos), $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
      $name = strtolower($m[1]);
      $val = trim($m[2]);
      if ($val !== '' && $val[0] === '"') {
        $val = preg_replace('/\\\\(.)/', '$1', substr($val, 1, -1)) ?? '';
      }
      $raw[$name] = $val;
    }

    return [$main, $this->resolveParameters($raw)];
  }

  //---------------------------------------------------------------------------
  /**
   * Resolves RFC 2231 parameters: name*=charset'lang'%xx, and the pieces name*0, name*1*, ...
   *
   * @param array<string, string> $raw The parameters as written
   *
   * @return array<string, string>
   */
  private function resolveParameters(array $raw): array
  {
    $result = [];
    $pieces = [];
    foreach ($raw as $name => $value) {
      if (preg_match('/^([^*]+)\*(\d+)?(\*)?$/', $name, $m) !== 1) {
        $result[$name] = $value;
        continue;
      }
      $pieces[$m[1]][(int)($m[2] ?? 0)] = [$value, ($m[3] ?? '') === '*' || ($m[2] ?? '') === ''];
    }

    foreach ($pieces as $base => $list) {
      ksort($list);
      $charset = '';
      $data = '';
      foreach ($list as $index => [$value, $extended]) {
        if ($extended) {
          if ($index === array_key_first($list) && preg_match("/^([^']*)'[^']*'(.*)$/s", $value, $m) === 1) {
            $charset = $m[1];
            $value = $m[2];
          }
          $value = rawurldecode($value);
        }
        $data .= $value;
      }
      $result[$base] = $this->toUtf8($data, $charset);
    }

    return $result;
  }

  //---------------------------------------------------------------------------
  /**
   * Decodes a transfer encoding.
   *
   * @param string $body     The body
   * @param string $encoding The Content-Transfer-Encoding value
   *
   * @return string
   */
  private function decodeBody(string $body, string $encoding): string
  {
    return match (strtolower(trim($encoding))) {
      'base64' => (string)base64_decode(preg_replace('/[^A-Za-z0-9+\/=_-]/', '', $body) ?? '', false),
      'quoted-printable' => quoted_printable_decode($body),
      default => $body,
    };
  }

  //---------------------------------------------------------------------------
  /**
   * Joins the lines of a format=flowed text (a line that ends with a space continues in the next one).
   *
   * @param string $text  The text
   * @param bool   $delsp Whether the space at the end of a flowed line is removed
   *
   * @return string
   */
  private function unflow(string $text, bool $delsp): string
  {
    $out = [];
    $buffer = null;
    foreach (explode("\n", $text) as $line) {
      $depth = 0;
      $content = $line;
      while (str_starts_with($content, '>')) {
        $content = substr($content, 1);
        $depth++;
      }
      $content = ltrim($content, ' ');
      if ($depth > 0) {
        $line = str_repeat('>', $depth) . ' ' . $content;
      } elseif (str_starts_with($line, ' ')) {
        $line = substr($line, 1);
      }

      $flowed = $line !== '-- ' && str_ends_with($line, ' ');
      if ($buffer !== null) {
        $line = $buffer . ($depth > 0 ? $content : $line);
        $buffer = null;
      }
      if ($flowed) {
        $buffer = $delsp ? substr($line, 0, -1) : $line;
        continue;
      }
      $out[] = $line;
    }
    if ($buffer !== null) {
      $out[] = rtrim($buffer);
    }

    return implode("\n", $out);
  }

  //---------------------------------------------------------------------------
  /**
   * Converts text to UTF-8. An unknown or wrong charset does not stop the mail: the text is taken as UTF-8 if it is
   * valid, else as Windows-1252.
   *
   * @param string $data    The bytes
   * @param string $charset The declared charset; may be empty
   *
   * @return string Valid UTF-8 without control characters (except tab and line breaks)
   */
  private function toUtf8(string $data, string $charset): string
  {
    $charset = strtolower(trim($charset, " \t\"'"));
    $charset = preg_replace('/\*.*$/', '', $charset) ?? '';
    $charset = match (true) {
      $charset === '' || $charset === 'us-ascii' || $charset === 'ascii' || $charset === 'utf8' || $charset === 'x-unknown' || $charset === 'unknown-8bit' => 'utf-8',
      $charset === 'iso-8859-8-i' => 'iso-8859-8',
      $charset === 'gb2312' || $charset === 'gb_2312-80' => 'gbk',
      $charset === 'ks_c_5601-1987' => 'euc-kr',
      $charset === 'ansi_x3.4-1968' => 'utf-8',
      default => $charset,
    };

    $result = null;
    if ($charset !== 'utf-8') {
      try {
        $result = mb_convert_encoding($data, 'UTF-8', $charset);
      } catch (Throwable) {
        $result = @iconv($charset, 'UTF-8//IGNORE', $data);
        $result = $result === false ? null : $result;
      }
    }
    if ($result === null) {
      $result = $data;
    }
    if (!mb_check_encoding($result, 'UTF-8')) {
      $result = mb_convert_encoding($result, 'UTF-8', 'Windows-1252');
    }

    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $result) ?? $result;
  }

  //---------------------------------------------------------------------------
  /**
   * Decodes the encoded words (=?charset?B?...?=) of a header. Neighbouring words of one charset are decoded together,
   * so a character that is split between two words stays whole.
   *
   * @param string $value The header value
   *
   * @return string UTF-8
   */
  private function decodeWords(string $value): string
  {
    if (!str_contains($value, '=?')) {
      return $this->toUtf8($value, '');
    }

    $word = '=\?[^?\s]+\?[bBqQ]\?[^?\s]*\?=';

    return $this->toUtf8(preg_replace_callback('/' . $word . '(?:\s+' . $word . ')*/', function (array $run): string {
      preg_match_all('/=\?([^?\s]+)\?([bBqQ])\?([^?\s]*)\?=/', $run[0], $words, PREG_SET_ORDER);
      $out = '';
      $charset = null;
      $bytes = '';
      foreach ($words as $w) {
        $cs = strtolower(preg_replace('/\*.*$/', '', $w[1]) ?? $w[1]);
        $data = strtolower($w[2]) === 'b'
          ? (string)base64_decode($w[3], false)
          : quoted_printable_decode(str_replace('_', ' ', $w[3]));
        if ($charset !== null && $cs !== $charset) {
          $out .= $this->toUtf8($bytes, $charset);
          $bytes = '';
        }
        $charset = $cs;
        $bytes .= $data;
      }

      return $out . ($charset === null ? '' : $this->toUtf8($bytes, $charset));
    }, $value) ?? $value, '');
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the first value of a header.
   *
   * @param array<string, string[]> $headers The headers
   * @param string                  $name    The lower case name
   *
   * @return string Empty if the header is missing
   */
  private function first(array $headers, string $name): string
  {
    return $headers[$name][0] ?? '';
  }

  //---------------------------------------------------------------------------
  /**
   * Gives a decoded subject: encoded words resolved, white space folded.
   *
   * @param string $value The header value
   *
   * @return string
   */
  private function subject(string $value): string
  {
    return trim(preg_replace('/\s+/u', ' ', $this->decodeWords($value)) ?? '');
  }

  //---------------------------------------------------------------------------
  /**
   * Reads the first mailbox of an address header.
   *
   * @param string $value The header value
   *
   * @return array{0: string, 1: string} The address in lower case (empty if there is none or it is invalid) and the name
   */
  private function mailbox(string $value): array
  {
    $name = '';
    $address = '';
    if (preg_match('/^\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^<",]*?))\s*<([^<>\s]+@[^<>\s]+)>/', $value, $m) === 1) {
      $name = $m[1] !== '' ? (preg_replace('/\\\\(.)/', '$1', $m[1]) ?? '') : trim(preg_replace('/\s*\([^)]*\)/', '', $m[2]) ?? '');
      $address = $m[3];
    } elseif (preg_match('/([^\s<>(),;"]+@[^\s<>(),;"]+)/', $value, $m) === 1) {
      $address = $m[1];
      if (preg_match('/\(([^)]*)\)/', $value, $n) === 1) {
        $name = trim($n[1]);
      }
    }

    $address = strtolower(rtrim(trim($address), '.'));

    return [filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : '', trim($this->decodeWords($name))];
  }

  //---------------------------------------------------------------------------
  /**
   * Reads all addresses of an address header.
   *
   * @param string $value The header value
   *
   * @return string[] Valid addresses in lower case
   */
  private function addresses(string $value): array
  {
    $list = [];
    if (preg_match_all('/<([^<>\s]+@[^<>\s]+)>|([^\s<>(),;":]+@[^\s<>(),;"]+)/', $value, $matches, PREG_SET_ORDER) > 0) {
      foreach ($matches as $m) {
        $address = strtolower(rtrim(trim($m[1] !== '' ? $m[1] : ($m[2] ?? '')), '.'));
        if (filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
          $list[$address] = true;
        }
      }
    }

    return array_keys($list);
  }

  //---------------------------------------------------------------------------
  /**
   * Reads the ids (<...>) of a Message-ID, In-Reply-To or References header.
   *
   * @param string $value The header value
   *
   * @return string[] With angle brackets, in the order of appearance
   */
  private function ids(string $value): array
  {
    if (preg_match_all('/<[^<>\s]+>/', $value, $m) < 1) {
      return [];
    }

    return array_values(array_unique($m[0]));
  }

  //---------------------------------------------------------------------------
  /**
   * Reads the envelope sender.
   *
   * @param string $value The Return-Path value
   *
   * @return string The address in lower case; '' for the empty sender <>
   */
  private function returnPath(string $value): string
  {
    return trim(strtolower($value), " <>\t");
  }

  //---------------------------------------------------------------------------
  /**
   * Reads the Date header.
   *
   * @param string $value The header value
   *
   * @return DateTimeImmutable|null
   */
  private function date(string $value): ?DateTimeImmutable
  {
    $value = trim(preg_replace('/\s*\([^)]*\)\s*$/', '', $value) ?? '');
    if ($value === '') {
      return null;
    }
    try {
      return new DateTimeImmutable($value);
    } catch (Throwable) {
      return null;
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Makes a safe file name from an attachment name: no path, no control characters, at most 255 bytes.
   *
   * @param string $name The name as sent (UTF-8 after parameter decoding, or an encoded word)
   *
   * @return string Empty if the part has no name
   */
  private function filename(string $name): string
  {
    $name = trim($this->decodeWords($name));
    $name = str_replace('\\', '/', $name);
    $name = basename($name);
    $name = trim(preg_replace('/[\x00-\x1F\x7F<>:"|?*]/u', '', $name) ?? '', " .\t");

    return mb_strcut($name, 0, 255, 'UTF-8');
  }

  //---------------------------------------------------------------------------
  /**
   * Gives a name to an attachment that has none.
   *
   * @param string $type   The MIME type
   * @param int    $number The number of the attachment in the mail
   *
   * @return string
   */
  private function defaultName(string $type, int $number): string
  {
    $extension = match ($type) {
      'image/jpeg' => 'jpg',
      'image/png' => 'png',
      'image/gif' => 'gif',
      'application/pdf' => 'pdf',
      'text/plain' => 'txt',
      'text/html' => 'html',
      'message/rfc822' => 'eml',
      default => 'bin',
    };

    return 'attachment-' . $number . '.' . $extension;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether a mail is an automatic answer (out of office, auto-reply).
   *
   * @param array<string, string[]> $headers The headers
   * @param string                  $subject The decoded subject
   *
   * @return bool
   */
  private function isAutoReply(array $headers, string $subject): bool
  {
    $auto = strtolower($this->first($headers, 'auto-submitted'));
    if ($auto !== '' && $auto !== 'no') {
      return true;
    }
    if (in_array(strtolower($this->first($headers, 'precedence')), ['auto_reply', 'auto-reply'], true)) {
      return true;
    }
    foreach (['x-autoreply', 'x-autorespond', 'x-auto-response-suppress'] as $name) {
      if (isset($headers[$name])) {
        return true;
      }
    }

    return preg_match('/^\s*(?:automatic reply|auto(?:matic)?[- ]?reply|autoreply|out of office|abwesenheit|automatische antwort|r[ée]ponse automatique|respuesta autom[aá]tica|risposta automatica|automatisch antwoord)\b/iu', $subject) === 1;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether a mail is a delivery report.
   *
   * @param array<string, string[]> $headers  The headers
   * @param string                  $from     The sender address
   * @param string                  $subject  The decoded subject
   *
   * @return bool
   */
  private function isBounce(array $headers, string $from, string $subject): bool
  {
    if (isset($headers['return-path']) && $this->returnPath($headers['return-path'][0]) === '') {
      return true;
    }
    $local = strtolower(strstr($from, '@', true) ?: '');
    if (in_array($local, ['mailer-daemon', 'postmaster'], true)) {
      return true;
    }

    return preg_match('/^\s*(?:undeliverable|undelivered mail|delivery status notification|mail delivery failed|returned mail|unzustellbar|nicht zustellbar)\b/iu', $subject) === 1;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether a mail is list or bulk mail.
   *
   * @param array<string, string[]> $headers The headers
   *
   * @return bool
   */
  private function isList(array $headers): bool
  {
    if (isset($headers['list-id']) || isset($headers['list-unsubscribe']) || isset($headers['list-post'])) {
      return true;
    }

    return in_array(strtolower($this->first($headers, 'precedence')), ['bulk', 'junk', 'list'], true);
  }

  //---------------------------------------------------------------------------
  /**
   * Reads the authserv-id of an Authentication-Results header: the name of the server that checked the mail, the first
   * word of the value ("mx.example.net; spf=pass ..." or "mx.example.net 1; ...").
   *
   * @param string $value The header value
   *
   * @return string Lower case; empty if there is none
   */
  private function authServer(string $value): string
  {
    $first = trim(explode(';', $value, 2)[0]);

    return preg_match('/^[A-Za-z0-9._-]+/', $first, $m) === 1 ? strtolower($m[0]) : '';
  }

  //---------------------------------------------------------------------------
  /**
   * Reads the results of the topmost Authentication-Results header. The server that received the mail adds its own on
   * top; the lines below could be forged by the sender, so only the first one counts.
   *
   * @param string $value The value of the first Authentication-Results header
   *
   * @return array<string, string> spf, dkim, dmarc => result (lower case)
   */
  private function authResults(string $value): array
  {
    $results = [];
    foreach (['spf', 'dkim', 'dmarc'] as $method) {
      if (preg_match_all('/(?:^|[;\s])' . $method . '\s*=\s*([a-z]+)/i', $value, $m) > 0) {
        // Several signatures: one passing result is enough
        $values = array_map('strtolower', $m[1]);
        $results[$method] = in_array('pass', $values, true) ? 'pass' : $values[0];
      }
    }

    return $results;
  }
}
