<?php

declare(strict_types=1);

namespace App\Services;

//---------------------------------------------------------------------------
/**
 * Time based one-time passwords (TOTP, RFC 6238) as used by Google and Microsoft Authenticator:
 * HMAC-SHA1, 6 digits, a time step of 30 seconds. The secrets are Base32 encoded (RFC 4648, no padding).
 * A code is accepted for the current time step and the one before and after it (clock drift).
 */
class Totp
{
  public const DIGITS = 6;
  public const PERIOD = 30;
  public const ISSUER = 'LeweDesk';

  /** Time steps before and after the current one that are accepted */
  private const WINDOW = 1;

  private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

  //---------------------------------------------------------------------------
  /**
   * Creates a new random secret (160 bits, as recommended by RFC 4226).
   *
   * @return string The secret, Base32 encoded (32 characters)
   */
  public function generateSecret(): string
  {
    return self::base32Encode(random_bytes(20));
  }

  //---------------------------------------------------------------------------
  /**
   * Gets the time step of a point in time.
   *
   * @param int|null $time Unix timestamp, null for now
   *
   * @return int
   */
  public function step(?int $time = null): int
  {
    return intdiv($time ?? time(), self::PERIOD);
  }

  //---------------------------------------------------------------------------
  /**
   * Calculates the code of a time step.
   *
   * @param string $secret The Base32 secret
   * @param int    $step   The time step
   * @param int    $digits The number of digits (6 for the application; RFC 6238 lists 8 digit test values)
   *
   * @return string The code with leading zeros
   */
  public function code(string $secret, int $step, int $digits = self::DIGITS): string
  {
    $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
    $offset = ord($hash[19]) & 0x0F;
    $binary = ((ord($hash[$offset]) & 0x7F) << 24)
      | ((ord($hash[$offset + 1]) & 0xFF) << 16)
      | ((ord($hash[$offset + 2]) & 0xFF) << 8)
      | (ord($hash[$offset + 3]) & 0xFF);

    return str_pad((string)($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
  }

  //---------------------------------------------------------------------------
  /**
   * Checks a code against the time steps around now.
   *
   * @param string   $secret      The Base32 secret
   * @param string   $code        The code entered by the user (spaces are ignored)
   * @param int|null $matchedStep Receives the time step the code belongs to
   * @param int|null $afterStep   Only steps after this one count (the last accepted step; a code is used only once)
   * @param int|null $time        Unix timestamp, null for now
   *
   * @return bool
   */
  public function verify(string $secret, string $code, ?int &$matchedStep = null, ?int $afterStep = null, ?int $time = null): bool
  {
    $matchedStep = null;
    $code = preg_replace('/\s+/', '', $code) ?? '';
    if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
      return false;
    }

    $current = $this->step($time);
    for ($step = $current - self::WINDOW; $step <= $current + self::WINDOW; $step++) {
      if ($afterStep !== null && $step <= $afterStep) {
        continue;
      }
      // Every step is compared, so the time taken does not tell which one came close
      if (hash_equals($this->code($secret, $step), $code) && $matchedStep === null) {
        $matchedStep = $step;
      }
    }

    return $matchedStep !== null;
  }

  //---------------------------------------------------------------------------
  /**
   * Builds the otpauth URI that authenticator apps read from the QR code.
   *
   * @param string $secret The Base32 secret
   * @param string $label  The account name shown in the app (the email address)
   *
   * @return string
   */
  public function uri(string $secret, string $label): string
  {
    return 'otpauth://totp/' . rawurlencode(self::ISSUER . ':' . $label)
      . '?secret=' . $secret
      . '&issuer=' . rawurlencode(self::ISSUER)
      . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
  }

  //---------------------------------------------------------------------------
  /**
   * Encodes binary data as Base32 (no padding).
   *
   * @param string $data The binary data
   *
   * @return string
   */
  public static function base32Encode(string $data): string
  {
    $bits = '';
    foreach (str_split($data) as $char) {
      $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }

    $result = '';
    foreach (str_split($bits, 5) as $chunk) {
      $result .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
    }

    return $result;
  }

  //---------------------------------------------------------------------------
  /**
   * Decodes Base32 data. Padding, spaces and lower case letters are tolerated.
   *
   * @param string $text The Base32 text
   *
   * @return string The binary data
   */
  public static function base32Decode(string $text): string
  {
    $text = strtoupper(str_replace([' ', '='], '', $text));

    $bits = '';
    foreach (str_split($text) as $char) {
      $value = strpos(self::ALPHABET, $char);
      if ($value === false) {
        throw new \InvalidArgumentException('Invalid Base32 character');
      }
      $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
    }

    $result = '';
    foreach (str_split($bits, 8) as $chunk) {
      if (strlen($chunk) === 8) {
        $result .= chr(bindec($chunk));
      }
    }

    return $result;
  }
}
