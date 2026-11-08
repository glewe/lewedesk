<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Handles password reset tokens. Only the SHA-256 hash of a token is stored.
 */
class PasswordResetModel
{
  private PDO $db;

  //---------------------------------------------------------------------------
  /**
   * @param PDO $db The database connection
   */
  public function __construct(PDO $db)
  {
    $this->db = $db;
  }

  //---------------------------------------------------------------------------
  /**
   * Creates a reset token for a user and invalidates the user's earlier ones.
   *
   * @param int $userId   The user id
   * @param int $lifetime Lifetime in seconds
   *
   * @return string The token (not stored in clear text)
   */
  public function create(int $userId, int $lifetime): string
  {
    $this->db->exec("DELETE FROM password_resets WHERE expires_at < NOW()");
    $this->db->prepare("DELETE FROM password_resets WHERE user_id = :user_id")->execute(['user_id' => $userId]);

    $token = bin2hex(random_bytes(32));
    $this->db->prepare(
      "INSERT INTO password_resets (user_id, token_hash, expires_at)
       VALUES (:user_id, :hash, DATE_ADD(NOW(), INTERVAL :ttl SECOND))"
    )->execute(['user_id' => $userId, 'hash' => hash('sha256', $token), 'ttl' => $lifetime]);

    return $token;
  }

  //---------------------------------------------------------------------------
  /**
   * Returns the user id of a valid (existing and not expired) token.
   *
   * @param string $token The token from the reset link
   *
   * @return int|null The user id or null
   */
  public function findUserId(string $token): ?int
  {
    $stmt = $this->db->prepare("SELECT user_id FROM password_resets WHERE token_hash = :hash AND expires_at > NOW()");
    $stmt->execute(['hash' => hash('sha256', $token)]);
    $id = $stmt->fetchColumn();

    return $id === false ? null : (int)$id;
  }

  //---------------------------------------------------------------------------
  /**
   * Sets a new password, consumes the reset tokens and ends all login sessions of the user.
   *
   * @param int    $userId       The user id
   * @param string $passwordHash The new password hash
   *
   * @return void
   */
  public function complete(int $userId, string $passwordHash): void
  {
    $own = !$this->db->inTransaction();
    if ($own) {
      $this->db->beginTransaction();
    }
    try {
      $this->db->prepare("UPDATE users SET password = :password WHERE id = :id")
        ->execute(['password' => $passwordHash, 'id' => $userId]);
      $this->db->prepare("DELETE FROM password_resets WHERE user_id = :id")->execute(['id' => $userId]);
      $this->db->prepare("DELETE FROM auth_tokens WHERE user_id = :id")->execute(['id' => $userId]);
      if ($own) {
        $this->db->commit();
      }
    } catch (\Throwable $e) {
      if ($own && $this->db->inTransaction()) {
        $this->db->rollBack();
      }
      throw $e;
    }
  }
}
