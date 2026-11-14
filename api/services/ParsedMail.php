<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

//---------------------------------------------------------------------------
/**
 * What MailParser makes of a received mail: plain values, no database, no network. Text is UTF-8.
 *
 * "text" is the body as the sender wrote it, quoted history included; MailQuoteStripper cuts the history off. The flags
 * are what the headers say (a mail can be an auto-reply, a bounce or list mail); what is done about it is decided later.
 * Message ids are kept with their angle brackets, as the outgoing mails store them (mail_outbox.message_id).
 */
final class ParsedMail
{
  /**
   * Constructor.
   *
   * @param string                  $messageId       The Message-ID; if the mail has none, a made-up one (see hasOwnMessageId)
   * @param bool                    $hasOwnMessageId Whether the mail came with a Message-ID
   * @param string                  $fromAddress     The sender address, lower case; empty if there is none or it is invalid
   * @param string                  $fromName        The display name of the sender
   * @param string                  $replyTo         The first Reply-To address, lower case; empty if none
   * @param string|null             $returnPath      The envelope sender; null if the header is missing, '' for <>
   * @param string[]                $to              The addresses of the To header, lower case
   * @param string                  $subject         The subject, encoded words decoded, as sent (with Re: etc.)
   * @param DateTimeImmutable|null  $date            The Date header; null if missing or not readable
   * @param string                  $inReplyTo       The first id of In-Reply-To; empty if none
   * @param string[]                $references      The ids of References, oldest first, plus In-Reply-To
   * @param string                  $text            The text body; made from the HTML if the mail has no text part
   * @param string                  $html            The HTML body as sent; empty if none (never use it for display)
   * @param ParsedAttachment[]      $attachments     Attachments and inline images
   * @param bool                    $isAutoReply     Out-of-office and other automatic answers
   * @param bool                    $isBounce        Delivery reports
   * @param bool                    $isList          Mailing list and bulk mail
   * @param array<string, string>   $auth            spf, dkim, dmarc results of the topmost Authentication-Results header
   * @param string                  $authServer      The authserv-id of that header (the server that wrote it), lower case; empty if there is none
   */
  public function __construct(
    public readonly string $messageId,
    public readonly bool $hasOwnMessageId,
    public readonly string $fromAddress,
    public readonly string $fromName,
    public readonly string $replyTo,
    public readonly ?string $returnPath,
    public readonly array $to,
    public readonly string $subject,
    public readonly ?DateTimeImmutable $date,
    public readonly string $inReplyTo,
    public readonly array $references,
    public readonly string $text,
    public readonly string $html,
    public readonly array $attachments,
    public readonly bool $isAutoReply,
    public readonly bool $isBounce,
    public readonly bool $isList,
    public readonly array $auth,
    public readonly string $authServer = ''
  ) {
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the subject without the reply and forward prefixes of the mail programs (Re:, AW:, WG:, TR:, RV: ...).
   *
   * @return string
   */
  public function cleanSubject(): string
  {
    $subject = trim($this->subject);
    while (preg_match('/^(?:re|aw|fw|fwd|wg|tr|rv|sv|vs|antw|odp|r|enc)(?:\[\d+\])?\s*:\s*/iu', $subject, $m) === 1) {
      $subject = substr($subject, strlen($m[0]));
    }

    return trim($subject);
  }

  //---------------------------------------------------------------------------
  /**
   * Finds the issue numbers named in the subject by their key, e.g. "LD-123" with the prefix LD.
   *
   * @param string $prefix The issue key prefix (issue_key_prefix)
   *
   * @return int[] In the order of appearance, without duplicates
   */
  public function issueIdsInSubject(string $prefix): array
  {
    if (preg_match_all('/(?<![A-Za-z0-9])' . preg_quote($prefix, '/') . '-(\d{1,9})(?!\d)/i', $this->subject, $m) < 1) {
      return [];
    }

    return array_values(array_unique(array_map('intval', $m[1])));
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the mail comes from one of the given addresses.
   *
   * @param string ...$addresses The addresses to compare with
   *
   * @return bool
   */
  public function isFrom(string ...$addresses): bool
  {
    foreach ($addresses as $address) {
      if ($this->fromAddress !== '' && strcasecmp($this->fromAddress, trim($address)) === 0) {
        return true;
      }
    }

    return false;
  }

  //---------------------------------------------------------------------------
  /**
   * Gives one authentication result.
   *
   * @param string $method spf, dkim or dmarc
   *
   * @return string pass, fail, softfail, neutral, none ...; none if the receiving server reported nothing
   */
  public function authResult(string $method): string
  {
    return $this->auth[strtolower($method)] ?? 'none';
  }
}
