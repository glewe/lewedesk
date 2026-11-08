<?php

declare(strict_types=1);

namespace App\Config;

use PDO;
use PDOException;

/**
 * Database connection handler using PDO.
 */
class Database
{
  private string $host;
  private string $dbName;
  private string $username;
  private string $password;
  private string $port;
  private ?PDO $conn = null;

  public function __construct()
  {
    $this->host = $_ENV['DB_HOST'] ?? '127.0.0.1';
    $this->dbName = $_ENV['DB_NAME'] ?? 'lewedesk';
    $this->username = $_ENV['DB_USER'] ?? 'root';
    $this->password = $_ENV['DB_PASS'] ?? '';
    $this->port = $_ENV['DB_PORT'] ?? '3306';
  }

  //---------------------------------------------------------------------------
  /**
   * Establishes a database connection.
   *
   * @return PDO|null The PDO connection instance
   */
  public function connect(): ?PDO
  {
    if ($this->conn !== null)
    {
      return $this->conn;
    }

    try
    {
      $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->dbName};charset=utf8mb4";
      // Set a short timeout for the connection to avoid hanging
      $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5, // 5 seconds
      ];
      
      $this->conn = new PDO($dsn, $this->username, $this->password, $options);
    }
    catch (PDOException $e)
    {
      error_log("Connection Error: " . $e->getMessage());
    }

    return $this->conn;
  }
}
