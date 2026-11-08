<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

//---------------------------------------------------------------------------
/**
 * Encrypts the TOTP secrets for the database (AES-256-GCM).
 *
 * The key comes from TWOFA_ENCRYPTION_KEY in the .env file (at least 32 random bytes, Base64 encoded). The
 * key is only read when a secret is encrypted or decrypted, so the application runs without it as long as
 * nobody uses 2FA. Without a valid key nothing is ever stored or read in plain text.
 *
 * A stored value is Base64 of: 12 byte IV, 16 byte authentication tag, ciphertext. The context (e.g. the user)
 * is authenticated with it, so a secret copied to another user's row does not decrypt.
 */
class SecretVault
{
  private const CIPHER = 'aes-256-gcm';
  private const IV_LENGTH = 12;
  private const TAG_LENGTH = 16;

  private ?string $encoded;
  private ?string $key = null;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param string|null $encodedKey The Base64 encoded key (TWOFA_ENCRYPTION_KEY)
   */
  public function __construct(?string $encodedKey)
  {
    $this->encoded = $encodedKey;
  }

  //---------------------------------------------------------------------------
  /**
   * Encrypts a secret.
   *
   * @param string $plain   The secret
   * @param string $context What the secret belongs to, e.g. "user:12"; the same value is needed to decrypt
   *
   * @return string Base64 text for the database
   *
   * @throws RuntimeException If the key is missing or invalid
   */
  public function encrypt(string $plain, string $context): string
  {
    $iv = random_bytes(self::IV_LENGTH);
    $tag = '';
    $cipher = openssl_encrypt($plain, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag, $context, self::TAG_LENGTH);
    if ($cipher === false) {
      throw new RuntimeException('The two factor secret could not be encrypted.');
    }

    return base64_encode($iv . $tag . $cipher);
  }

  //---------------------------------------------------------------------------
  /**
   * Decrypts a secret.
   *
   * @param string $stored  The value from the database
   * @param string $context What the secret belongs to (see encrypt())
   *
   * @return string The secret
   *
   * @throws RuntimeException If the key is missing or invalid, or the value is damaged or belongs elsewhere
   */
  public function decrypt(string $stored, string $context): string
  {
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) <= self::IV_LENGTH + self::TAG_LENGTH) {
      throw new RuntimeException('The stored two factor secret is damaged.');
    }

    $iv = substr($raw, 0, self::IV_LENGTH);
    $tag = substr($raw, self::IV_LENGTH, self::TAG_LENGTH);
    $plain = openssl_decrypt(substr($raw, self::IV_LENGTH + self::TAG_LENGTH), self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag, $context);
    if ($plain === false) {
      throw new RuntimeException('The stored two factor secret cannot be decrypted (wrong TWOFA_ENCRYPTION_KEY?).');
    }

    return $plain;
  }

  //---------------------------------------------------------------------------
  /**
   * Derives the encryption key from the configured one.
   *
   * @return string 32 bytes
   *
   * @throws RuntimeException If the key is missing or too short
   */
  private function key(): string
  {
    if ($this->key === null) {
      $decoded = $this->encoded !== null ? base64_decode(trim($this->encoded), true) : false;
      if ($decoded === false || strlen($decoded) < 32) {
        throw new RuntimeException('TWOFA_ENCRYPTION_KEY is missing or invalid in the .env file (needs at least 32 random bytes, Base64 encoded).');
      }
      $this->key = hash_hkdf('sha256', $decoded, 32, 'lewedesk-2fa-secrets');
    }

    return $this->key;
  }
}
