<?php

declare(strict_types=1);

// Command line only: the web server must never run this script
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

// Housekeeping for the attachments: deletes the temporary uploads that were never attached to an issue within
// temp_upload_cleanup_hours (see _docs/SYSTEM_SETTINGS.md). The API does the same whenever somebody uploads a
// temporary file; run this script from cron (for example hourly) on a site where uploads are rare:
//
//   php api/cleanup_uploads.php

require_once __DIR__ . '/utils/EnvLoader.php';
require_once __DIR__ . '/utils/IssueConfig.php';
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/models/SettingsModel.php';
require_once __DIR__ . '/models/IssueModel.php';
require_once __DIR__ . '/models/AttachmentModel.php';
require_once __DIR__ . '/services/AttachmentStorage.php';
require_once __DIR__ . '/services/IssueHistory.php';
require_once __DIR__ . '/services/IssueVisibility.php';
require_once __DIR__ . '/services/AuthService.php';

use App\Config\Database;
use App\Models\AttachmentModel;
use App\Models\SettingsModel;
use App\Services\AttachmentStorage;
use App\Services\AuthService;
use App\Services\IssueVisibility;
use App\Utils\EnvLoader;
use App\Utils\IssueConfig;

EnvLoader::load(dirname(__DIR__));

try {
  $pdo = (new Database())->connect();
  if (!$pdo) {
    throw new Exception('Database connection failed');
  }

  $config = new IssueConfig((new SettingsModel($pdo))->getAll());
  $model = new AttachmentModel($pdo, new IssueVisibility(new AuthService($pdo, 0), false));
  $storage = new AttachmentStorage(dirname(__DIR__) . '/uploads/attachments');

  $stored = $model->purgeExpired($config->tempUploadCleanupHours());
  foreach ($stored as $name) {
    $storage->remove($name);
  }

  echo 'Deleted ' . count($stored) . " temporary upload(s).\n";
} catch (Exception $e) {
  echo 'ERROR: ' . $e->getMessage() . "\n";
  exit(1);
}
