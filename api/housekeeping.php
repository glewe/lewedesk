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
require_once __DIR__ . '/services/SlaService.php';
require_once __DIR__ . '/services/SlaNotifier.php';
require_once __DIR__ . '/services/Housekeeping.php';
require_once __DIR__ . '/services/InboundMailService.php';
require_once __DIR__ . '/services/LdapService.php';
require_once __DIR__ . '/services/OidcService.php';
require_once __DIR__ . '/services/AuthPolicy.php';
require_once __DIR__ . '/services/LicenseService.php';
require_once __DIR__ . '/services/LicenseFeatures.php';

use App\Config\Database;
use App\Models\AttachmentModel;
use App\Models\MailInboxModel;
use App\Models\NotificationModel;
use App\Models\NotificationPreferenceModel;
use App\Models\SettingsModel;
use App\Services\AttachmentStorage;
use App\Services\AuditLog;
use App\Services\AuthPolicy;
use App\Services\AuthService;
use App\Services\Housekeeping;
use App\Services\InboundMailService;
use App\Services\LdapService;
use App\Services\LicenseFeatures;
use App\Services\LicenseService;
use App\Services\IssueVisibility;
use App\Services\MailOutbox;
use App\Services\MailService;
use App\Services\NotificationMail;
use App\Services\NotificationService;
use App\Services\OidcService;
use App\Services\SlaNotifier;
use App\Utils\EnvLoader;
use App\Utils\IssueConfig;
use App\Utils\MailInConfig;

EnvLoader::load(dirname(__DIR__));

try {
  $pdo = (new Database())->connect();
  if (!$pdo) {
    throw new Exception('Database connection failed');
  }

  $settingsModel = new SettingsModel($pdo);
  $settings = $settingsModel->getAll();
  $config = new IssueConfig($settings);
  $auth = new AuthService($pdo, 0);
  $audit = new AuditLog($pdo, $auth);
  $licenseFeatures = new LicenseFeatures(new LicenseService($settingsModel, $audit, $_ENV, $settings));
  // The limited mode of the license starts no new SLA timers (a mail that creates an issue is read by this script too)
  \App\Services\SlaService::$timersEnabled = $licenseFeatures->allows('sla');
  // Email to ticket on (MAILIN_* in .env, license): the mails carry the "reply above this line" marker and the Reply-To
  $mailIn = new MailInConfig($_ENV);
  $mailInActive = $mailIn->isEnabled() && $licenseFeatures->allows('mail_in');
  $outbox = new MailOutbox($pdo, new MailService((string)($settings['application_name'] ?? '')), $mailInActive ? $mailIn->replyAddress() : '');
  $notificationMail = new NotificationMail((string)($settings['application_name'] ?? ''), NotificationMail::baseUrl($settings), (string)($settings['default_language'] ?? 'en'), $mailInActive);
  $service = new NotificationService(
    $pdo,
    $auth,
    $config,
    new NotificationModel($pdo, new IssueVisibility($auth, false), $config),
    new NotificationPreferenceModel($pdo),
    $outbox,
    $notificationMail
  );

  $attachments = new AttachmentModel($pdo, new IssueVisibility($auth, false));
  $storage = new AttachmentStorage(dirname(__DIR__) . '/uploads/attachments');
  $done = (new Housekeeping($outbox, $service, $attachments, $storage, $config, $audit, dirname(__DIR__) . '/uploads/.housekeeping', new MailInboxModel($pdo), new SlaNotifier($pdo, $service)))->run();

  // Email to ticket: reads the support mailbox now (nothing happens when MAILIN_YES is not 1 or the license does not allow it)
  $inbound = InboundMailService::create($pdo, $settings, $_ENV, $service, $notificationMail, $outbox, $audit, static fn(): bool => $licenseFeatures->allows('mail_in'), static fn(): bool => (new AuthPolicy(new LdapService($_ENV), new OidcService($_ENV, $pdo)))->isEnabled(), dirname(__DIR__));
  if ($inbound->isActive()) {
    $read = $inbound->run();
    echo "Email to ticket: {$read['issues']} issue(s), {$read['comments']} comment(s), {$read['rejected']} rejected, {$read['errors']} failed" . ($read['error'] !== null ? ', mailbox error: ' . $read['error'] : '') . ".\n";
  }

  echo "SLA: {$done['sla']['breached']} breached and {$done['sla']['at_risk']} at risk timer(s) announced" . ($done['sla']['skipped'] > 0 ? ", {$done['sla']['skipped']} old one(s) only marked" : '') . ".\n";
  echo "Sent {$done['sent']} email(s), deleted {$done['notifications']} notification(s), {$done['mails']} old email(s) and {$done['uploads']} temporary upload(s) and {$done['audit']} audit log entries.\n";
} catch (Exception $e) {
  echo 'ERROR: ' . $e->getMessage() . "\n";
  exit(1);
}
