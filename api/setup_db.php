<?php

declare(strict_types=1);

// Command line only: the web server must never run this script
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require_once __DIR__ . '/utils/EnvLoader.php';
require_once __DIR__ . '/config/Database.php';

use App\Utils\EnvLoader;
use App\Config\Database;

// Load environment variables
EnvLoader::load(dirname(__DIR__));

echo "--- LeweDesk Database Setup ---\n";

$dbHost = new Database();
// We need to connect without the database first to create it if it doesn't exist
// But since the user says the DB is up and running and the SQL has CREATE DATABASE IF NOT EXISTS,
// let's try a direct connection first.

try {
    $pdo = $dbHost->connect();
    if (!$pdo) {
        throw new Exception("Connection failed.");
    }

    $sqlFile = dirname(__DIR__) . '/db/db_init.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("SQL file not found at $sqlFile");
    }

    $sql = file_get_contents($sqlFile);
    
    echo "Executing SQL from db_init.sql...\n";
    
    // Execute the SQL
    $pdo->exec($sql);
    
    echo "SUCCESS: Database tables created successfully.\n";
    echo "Initial admin user created: admin / admin\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
