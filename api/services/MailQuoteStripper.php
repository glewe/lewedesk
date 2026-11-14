<?php

declare(strict_types=1);

namespace App\Services;

require_once __DIR__ . '/NotificationMail.php';

//---------------------------------------------------------------------------
/**
 * Cuts the quoted history and the signature off a reply, so that only what the person wrote ends up as the comment.
 *
 * No list of languages is needed for the main cases, in this order of reliability:
 *   1. The marker line of our own notification mails ("##- Please type your reply above this line -##", any language):
 *      everything from it on is the original mail.
 *   2. A header block of the mail program (From/Sent/To/Subject in the language of the sender, found by the shape of
 *      the lines), also behind "-----Original Message-----" or a line of underscores.
 *   3. An attribution line ("On Mon, 9 Nov 2026 at 10:12, Support <support@example.com> wrote:"): ends with a colon,
 *      holds an address or a time, and is followed by quoted lines (">"). Language independent.
 * Top posting (the answer above the history) is the normal case: everything from the cut on is dropped. If nothing is
 * above the cut (bottom posting: history first, answer below), the quoted lines are removed and the text below them is
 * kept. The signature is cut at a line "-- ". When in doubt the text is kept: if nothing would be left, the whole text
 * is returned.
 */
final class MailQuoteStripper
{
  /** The word "From" in the languages whose mail programs write a header block */
  private const FROM_WORDS = 'from|de|von|da|van|från|fra|od|от|lähettäjä|发件人|寄件者|差出人|보낸\s*사람|feladó|gönderen|expéditeur|absender|remitente|mittente|afzender|avsändare|nadawca|odesílatel|απο|από|מאת|من';

  /** Texts between lines of dashes that announce the original mail */
  private const ORIGINAL_WORDS = 'original message|original-nachricht|ursprüngliche nachricht|urspr\.? nachricht|mensaje original|message d\'origine|message original|messaggio originale|oorspronkelijk bericht|mensagem original|forwarded message|weitergeleitete nachricht|message transféré|mensaje reenviado|messaggio inoltrato';

  //---------------------------------------------------------------------------
  /**
   * Cuts the history and the signature off a text.
   *
   * @param string $text The body of a received mail
   *
   * @return string The reply; the trimmed original text if nothing else would be left
   */
  public static function strip(string $text): string
  {
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $lines = explode("\n", $text);

    [$cut, $kind, $end] = self::findCut($lines);
    $kept = $lines;
    if ($cut !== null) {
      $before = array_slice($lines, 0, $cut);
      if (trim(implode('', $before)) !== '') {
        $kept = $before;
      } elseif ($kind === 'attribution') {
        // Bottom posting: the history comes first, the answer below the quoted block
        $kept = array_slice($lines, $end);
      } else {
        $kept = [];
      }
    }

    $kept = self::withoutSignature($kept);
    $result = trim(implode("\n", $kept));

    return $result !== '' ? $result : trim($text);
  }

  //---------------------------------------------------------------------------
  /**
   * Finds the first place where the history starts.
   *
   * @param string[] $lines The lines of the text
   *
   * @return array{0: int|null, 1: string, 2: int} Index of the first line of the history (null if there is none), the
   *                                              kind (marker, header, attribution) and the index of the first line
   *                                              behind a quoted block that follows an attribution
   */
  private static function findCut(array $lines): array
  {
    $count = count($lines);
    for ($i = 0; $i < $count; $i++) {
      $line = $lines[$i];
      if (trim($line) === '') {
        continue;
      }

      if (preg_match(NotificationMail::REPLY_MARKER_PATTERN, $line) === 1) {
        return [$i, 'marker', $count];
      }
      if (self::isSeparator($line) && self::headerBlockAt($lines, self::nextFilled($lines, $i + 1)) ) {
        return [$i, 'header', $count];
      }
      if (preg_match('/^\s*-{2,}\s*(?:' . self::ORIGINAL_WORDS . ')\s*-{2,}\s*$/iu', $line) === 1) {
        return [$i, 'header', $count];
      }
      if (self::headerBlockAt($lines, $i)) {
        return [$i, 'header', $count];
      }
      $used = self::attributionLength($lines, $i);
      if ($used > 0) {
        $end = $i + $used;
        while ($end < $count && (trim($lines[$end]) === '' || str_starts_with(ltrim($lines[$end]), '>'))) {
          $end++;
        }

        return [$i, 'attribution', $end];
      }
    }

    return [null, '', $count];
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the index of the next line that is not empty.
   *
   * @param string[] $lines The lines
   * @param int      $from  Where to start
   *
   * @return int The count of lines if there is none
   */
  private static function nextFilled(array $lines, int $from): int
  {
    $count = count($lines);
    while ($from < $count && trim($lines[$from]) === '') {
      $from++;
    }

    return $from;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether a line separates the answer from the original: a line of underscores, dashes or equal signs.
   *
   * @param string $line The line
   *
   * @return bool
   */
  private static function isSeparator(string $line): bool
  {
    return preg_match('/^\s*(?:[_=*-]{10,}|-{2,}[^-\n]{0,40}-{2,})\s*$/u', $line) === 1;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the header block of a mail program (From/Sent/To/Subject) starts at a line.
   *
   * @param string[] $lines The lines
   * @param int      $i     The index of the first line of the block
   *
   * @return bool
   */
  private static function headerBlockAt(array $lines, int $i): bool
  {
    if (!isset($lines[$i]) || preg_match('/^\s*\*?\s*(?:' . self::FROM_WORDS . ')\s*\*?\s*:\s*\S/iu', $lines[$i]) !== 1) {
      return false;
    }

    // At least two more lines of the shape "Label: value" follow directly
    $headers = 0;
    for ($j = $i + 1; $j <= $i + 6 && isset($lines[$j]); $j++) {
      if (trim($lines[$j]) === '') {
        if ($headers > 0) {
          break;
        }
        continue;
      }
      if (preg_match('/^\s*\*?\s*[\p{L}][\p{L} .\-]{0,24}\*?\s*:\s*\S/u', $lines[$j]) === 1) {
        $headers++;
      } else {
        break;
      }
    }

    return $headers >= 2;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells how many lines an attribution line takes ("On ... wrote:" can be wrapped over up to three lines).
   *
   * @param string[] $lines The lines
   * @param int      $i     The index of the first line
   *
   * @return int 0 if there is no attribution at this line
   */
  private static function attributionLength(array $lines, int $i): int
  {
    if (str_starts_with(ltrim($lines[$i]), '>')) {
      return 0;
    }

    $joined = '';
    for ($k = 1; $k <= 3 && isset($lines[$i + $k - 1]); $k++) {
      $line = trim($lines[$i + $k - 1]);
      if ($line === '' || ($k > 1 && str_starts_with($line, '>'))) {
        return 0;
      }
      $joined = trim($joined . ' ' . $line);
      if (strlen($joined) > 300) {
        return 0;
      }
      if (preg_match('/[:：]$/u', $joined) !== 1) {
        continue;
      }

      $hasAddress = preg_match('/[\w.+-]+@[\w-]+\.[\w.-]+/u', $joined) === 1;
      $hasTime = preg_match('/(?<!\d)\d{1,2}[:.h]\d{2}(?!\d)|(?<!\d)(?:19|20)\d{2}(?!\d)/u', $joined) === 1;
      $next = self::nextFilled($lines, $i + $k);
      $quoted = isset($lines[$next]) && str_starts_with(ltrim($lines[$next]), '>');
      if (($hasAddress || $hasTime) && ($quoted || ($hasAddress && $hasTime))) {
        return $k;
      }
    }

    return 0;
  }

  //---------------------------------------------------------------------------
  /**
   * Cuts the signature off: everything from a line "-- " (two dashes and a space, as the standard says).
   *
   * @param string[] $lines The lines of the reply
   *
   * @return string[]
   */
  private static function withoutSignature(array $lines): array
  {
    foreach ($lines as $i => $line) {
      if ($i > 0 && preg_match('/^--[ \t]?$/', $line) === 1) {
        return array_slice($lines, 0, $i);
      }
    }

    return $lines;
  }
}
