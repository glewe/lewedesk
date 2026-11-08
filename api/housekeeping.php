<?php

declare(strict_types=1);

// Command line only: the web server must never run this script
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

// Housekeeping for the notifications: sends the emails that are waiting in the queue (new ones and the retry of the
// ones that failed once), deletes the in-app notifications older than notification_retention_days and the sent and
// failed emails older than two weeks (see _docs/SYSTEM_SETTINGS.md). The API does the same whenever it queues a mail
// or creates a notification; run this script from cron (for example every five minutes) so that a failed mail is
// retried also when nothing else happens. Optional: the API runs the same cleanup by itself (Housekeeping service), so
// cron is only for sites that want a fixed schedule:
//
//   php api/housekeeping.php

require_once __DIR__ . '/utils/EnvLoader.php';
require_once __DIR__ . '/utils/IssueConfig.php';
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/models/SettingsModel.php';
require_once __DIR__ . '/models/IssueModel.php';
require_once __DIR__ . '/models/NotificationModel.php';
require_once __DIR__ . '/models/NotificationPreferenceModel.php';
require_once __DIR__ . '/services/IssueHistory.php';
require_once __DIR__ . '/services/IssueVisibility.php';
require_once __DIR__ . '/services/AuthService.php';
require_once __DIR__ . '/services/AuditLog.php';
require_once __DIR__ . '/services/MailService.php';
require_once __DIR__ . '/services/MailOutbox.php';
require_once __DIR__ . '/services/NotificationMail.php';
require_once __DIR__ . '/models/AttachmentModel.php';
require_once __DIR__ . '/services/AttachmentStorage.php';
require_once __DIR__ . '/services/NotificationService.php';
require_once __DIR__ . '/services/Housekeeping.php';

use App\Config\Database;
use App\Models\AttachmentModel;
use App\Models\NotificationModel;
use App\Models\NotificationPreferenceModel;
use App\Models\SettingsModel;
use App\Services\AttachmentStorage;
use App\Services\AuditLog;
use App\Services\AuthService;
use App\Services\Housekeeping;
use App\Services\IssueVisibility;
use App\Services\MailOutbox;
use App\Services\MailService;
use App\Services\NotificationMail;
use App\Services\NotificationService;
use App\Utils\EnvLoader;
use App\Utils\IssueConfig;

EnvLoader::load(dirname(__DIR__));

try {
  $pdo = (new Database())->connect();
  if (!$pdo) {
    throw new Exception('Database connection failed');
  }

  $settings = (new SettingsModel($pdo))->getAll();
  $config = new IssueConfig($settings);
  $auth = new AuthService($pdo, 0);
  $outbox = new MailOutbox($pdo, new MailService((string)($settings['application_name'] ?? '')));
  $service = new NotificationService(
    $pdo,
    $auth,
    $config,
    new NotificationModel($pdo, new IssueVisibility($auth, false), $config),
    new NotificationPreferenceModel($pdo),
    $outbox,
    new NotificationMail((string)($settings['application_name'] ?? ''), NotificationMail::baseUrl($settings), (string)($settings['default_language'] ?? 'en'))
  );

  $attachments = new AttachmentModel($pdo, new IssueVisibility($auth, false));
  $storage = new AttachmentStorage(dirname(__DIR__) . '/uploads/attachments');
  $done = (new Housekeeping($outbox, $service, $attachments, $storage, $config, new AuditLog($pdo, $auth), dirname(__DIR__) . '/uploads/.housekeeping'))->run();

  echo "Sent {$done['sent']} email(s), deleted {$done['notifications']} notification(s), {$done['mails']} old email(s) and {$done['uploads']} temporary upload(s) and {$done['audit']} audit log entries.\n";
} catch (Exception $e) {
  echo 'ERROR: ' . $e->getMessage() . "\n";
  exit(1);
}
