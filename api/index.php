<?php
/**
 * Cleaned up API entry point for Phase 3.1.
 */

declare(strict_types=1);

// Disable error display to avoid corrupting JSON output
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/utils/EnvLoader.php';
require_once __DIR__ . '/utils/SettingsValidator.php';
require_once __DIR__ . '/utils/IssueConfig.php';
require_once __DIR__ . '/utils/IssueKey.php';
require_once __DIR__ . '/utils/GitHubRepo.php';
require_once __DIR__ . '/models/GitHubCommitCacheModel.php';
require_once __DIR__ . '/services/GitHubClient.php';
require_once __DIR__ . '/services/IssueCommits.php';
require_once __DIR__ . '/controllers/IssueCommitController.php';
require_once __DIR__ . '/services/IssueNumbering.php';
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/models/UserModel.php';
require_once __DIR__ . '/services/AuthService.php';
require_once __DIR__ . '/services/AuditLog.php';
require_once __DIR__ . '/services/MailService.php';
require_once __DIR__ . '/models/PasswordResetModel.php';
require_once __DIR__ . '/models/SettingsModel.php';
require_once __DIR__ . '/models/EntityModel.php';
require_once __DIR__ . '/controllers/EntityController.php';
require_once __DIR__ . '/models/PermissionModel.php';
require_once __DIR__ . '/controllers/PermissionController.php';
require_once __DIR__ . '/controllers/UserController.php';
require_once __DIR__ . '/controllers/ProfileController.php';
require_once __DIR__ . '/controllers/TwoFactorController.php';
require_once __DIR__ . '/config/Lookups.php';
require_once __DIR__ . '/models/LookupModel.php';
require_once __DIR__ . '/controllers/LookupController.php';
require_once __DIR__ . '/models/ProductVersionModel.php';
require_once __DIR__ . '/controllers/ProductVersionController.php';
require_once __DIR__ . '/services/IssueVisibility.php';
require_once __DIR__ . '/models/IssueModel.php';
require_once __DIR__ . '/controllers/IssueController.php';
require_once __DIR__ . '/services/CommentPolicy.php';
require_once __DIR__ . '/models/CommentModel.php';
require_once __DIR__ . '/controllers/CommentController.php';
require_once __DIR__ . '/services/IssueHistory.php';
require_once __DIR__ . '/services/IssueSearch.php';
require_once __DIR__ . '/models/HistoryModel.php';
require_once __DIR__ . '/services/IssueConflictException.php';
require_once __DIR__ . '/services/IssueActions.php';
require_once __DIR__ . '/services/RateLimiter.php';
require_once __DIR__ . '/services/AuthThrottle.php';
require_once __DIR__ . '/services/LdapService.php';
require_once __DIR__ . '/services/OidcService.php';
require_once __DIR__ . '/services/AuthPolicy.php';
require_once __DIR__ . '/services/Totp.php';
require_once __DIR__ . '/services/LicenseService.php';
require_once __DIR__ . '/services/LicenseFeatures.php';
require_once __DIR__ . '/controllers/LicenseController.php';
require_once __DIR__ . '/services/SecretVault.php';
require_once __DIR__ . '/services/QrCode.php';
require_once __DIR__ . '/services/TwoFactorService.php';
require_once __DIR__ . '/models/IssueWorkflowModel.php';
require_once __DIR__ . '/controllers/IssueActionController.php';
require_once __DIR__ . '/controllers/HistoryController.php';
require_once __DIR__ . '/services/AttachmentStorage.php';
require_once __DIR__ . '/models/AttachmentModel.php';
require_once __DIR__ . '/controllers/AttachmentController.php';
require_once __DIR__ . '/models/PreferenceModel.php';
require_once __DIR__ . '/controllers/PreferenceController.php';
require_once __DIR__ . '/utils/MailInConfig.php';
require_once __DIR__ . '/services/InboundMailService.php';
require_once __DIR__ . '/controllers/MailInController.php';
require_once __DIR__ . '/services/MailOutbox.php';
require_once __DIR__ . '/services/NotificationMail.php';
require_once __DIR__ . '/models/NotificationPreferenceModel.php';
require_once __DIR__ . '/models/NotificationModel.php';
require_once __DIR__ . '/services/NotificationService.php';
require_once __DIR__ . '/services/Housekeeping.php';
require_once __DIR__ . '/controllers/NotificationController.php';
require_once __DIR__ . '/models/DashboardModel.php';
require_once __DIR__ . '/controllers/DashboardController.php';
require_once __DIR__ . '/models/AuditLogModel.php';
require_once __DIR__ . '/controllers/AuditLogController.php';
require_once __DIR__ . '/models/CustomViewModel.php';
require_once __DIR__ . '/controllers/CustomViewController.php';
require_once __DIR__ . '/services/SlaCalculator.php';
require_once __DIR__ . '/models/SlaCalendarModel.php';
require_once __DIR__ . '/models/SlaPolicyModel.php';
require_once __DIR__ . '/controllers/SlaController.php';
require_once __DIR__ . '/models/SlaReportModel.php';
require_once __DIR__ . '/controllers/SlaReportController.php';
require_once __DIR__ . '/services/SlaService.php';
require_once __DIR__ . '/services/SlaNotifier.php';
require_once __DIR__ . '/controllers/IssueSlaController.php';

use App\Utils\EnvLoader;
use App\Utils\SettingsValidator;
use App\Config\Database;
use App\Models\UserModel;
use App\Services\AuditLog;
use App\Services\AuthService;
use App\Services\MailService;
use App\Models\PasswordResetModel;
use App\Models\SettingsModel;
use App\Services\LicenseService;
use App\Services\LicenseFeatures;
use App\Controllers\LicenseController;
use App\Models\EntityModel;
use App\Controllers\EntityController;
use App\Models\PermissionModel;
use App\Controllers\PermissionController;
use App\Controllers\UserController;
use App\Controllers\ProfileController;
use App\Controllers\TwoFactorController;
use App\Config\Lookups;
use App\Controllers\IssueCommitController;
use App\Controllers\LookupController;
use App\Controllers\ProductVersionController;
use App\Models\LookupModel;
use App\Models\ProductVersionModel;
use App\Models\IssueModel;
use App\Models\IssueWorkflowModel;
use App\Controllers\AttachmentController;
use App\Controllers\AuditLogController;
use App\Controllers\CustomViewController;
use App\Controllers\SlaController;
use App\Controllers\SlaReportController;
use App\Controllers\IssueSlaController;
use App\Services\SlaNotifier;
use App\Services\SlaService;
use App\Controllers\DashboardController;
use App\Controllers\CommentController;
use App\Controllers\HistoryController;
use App\Controllers\IssueActionController;
use App\Controllers\IssueController;
use App\Controllers\NotificationController;
use App\Controllers\PreferenceController;
use App\Models\AttachmentModel;
use App\Models\GitHubCommitCacheModel;
use App\Models\AuditLogModel;
use App\Models\CommentModel;
use App\Models\CustomViewModel;
use App\Models\SlaCalendarModel;
use App\Models\SlaReportModel;
use App\Models\SlaPolicyModel;
use App\Models\DashboardModel;
use App\Models\HistoryModel;
use App\Models\NotificationModel;
use App\Models\NotificationPreferenceModel;
use App\Models\PreferenceModel;
use App\Services\AttachmentStorage;
use App\Services\GitHubClient;
use App\Services\IssueCommits;
use App\Services\CommentPolicy;
use App\Services\Housekeeping;
use App\Services\IssueActions;
use App\Services\IssueVisibility;
use App\Services\AuthPolicy;
use App\Services\LdapService;
use App\Services\OidcService;
use App\Services\MailOutbox;
use App\Controllers\MailInController;
use App\Models\MailInboxModel;
use App\Services\InboundMailService;
use App\Utils\MailInConfig;
use App\Utils\MailInSettings;
use App\Services\NotificationMail;
use App\Services\NotificationService;
use App\Services\QrCode;
use App\Services\AuthThrottle;
use App\Services\RateLimiter;
use App\Services\SecretVault;
use App\Services\Totp;
use App\Services\TwoFactorService;
use App\Utils\IssueConfig;
use App\Utils\IssueKey;

// Load environment variables
EnvLoader::load(dirname(__DIR__));

// Set headers for JSON and CORS
header('Content-Type: application/json; charset=utf-8');
// The pages are served from the same site as the API, so they need no CORS headers. A cross-origin caller is only
// answered with an allowed origin when it is the origin of APP_URL (never "*").
header('Vary: Origin');
$requestOrigin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$appUrlParts = parse_url((string)($_ENV['APP_URL'] ?? ''));
if ($requestOrigin !== '' && !empty($appUrlParts['scheme']) && !empty($appUrlParts['host'])) {
  $appOrigin = strtolower($appUrlParts['scheme'] . '://' . $appUrlParts['host'] . (isset($appUrlParts['port']) ? ':' . $appUrlParts['port'] : ''));
  if (strtolower($requestOrigin) === $appOrigin) {
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
  }
}
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  exit(0);
}

// Not installed yet: no .env file and no database settings in the environment. The pages (static files) cannot know
// that, so they ask the API; the answer makes them go to the installer (src/js/install-check.js).
if (!is_file(dirname(__DIR__) . '/.env') && empty($_ENV['DB_NAME']) && getenv('DB_NAME') === false) {
  http_response_code(503);
  echo json_encode([
    'status' => 'error',
    'code' => 'not_installed',
    'installer' => is_file(dirname(__DIR__) . '/install.php') && !is_file(dirname(__DIR__) . '/install.lock'),
    'message' => 'LeweDesk is not installed yet. Open install.php in the LeweDesk folder.'
  ]);
  exit;
}

try {
  // Initialize Database
  $dbHost = new Database();
  $pdo = $dbHost->connect();

  if (!$pdo) {
    throw new Exception('Database connection failed');
  }

  $userModel = new UserModel($pdo);
  $settingsModel = new SettingsModel($pdo);
  $auth = new AuthService($pdo, (int)($_ENV['SESSION_EXPIRATION'] ?? 7200));
  $audit = new AuditLog($pdo, $auth);
  auditLog($audit);

  // Load all settings once
  $allSettings = $settingsModel->getAll();

  // The license (LeweLic); nothing is sent to the license server until the status is asked for
  $license = new LicenseService($settingsModel, $audit, $_ENV, $allSettings);
  // What the license allows (the limited mode switches features off and caps the number of issues and users)
  $licenseFeatures = new LicenseFeatures($license);
  // The limited mode of the license starts no new SLA timers (the ones that exist keep being updated)
  SlaService::$timersEnabled = $licenseFeatures->allows('sla');

  // Ask browsers and proxies not to cache API responses (disable_caching)
  if (($allSettings['disable_caching'] ?? 'false') === 'true') {
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
  }

  // Apply Global PHP Settings
  if (isset($allSettings['timezone'])) {
    date_default_timezone_set($allSettings['timezone']);
  }

  // Routing Logic
  $requestUri = explode('?', $_SERVER['REQUEST_URI'])[0];
  $scriptName = dirname($_SERVER['SCRIPT_NAME']);
  // Handle the case where dirname returns \ on Windows
  $scriptName = str_replace('\\', '/', $scriptName);
  // Only strip the script directory as a prefix (it is '/' when served from the web root)
  $path = $requestUri;
  if ($scriptName !== '/' && $scriptName !== '' && str_starts_with($path, $scriptName)) {
    $path = substr($path, strlen($scriptName));
  }
  if (empty($path)) $path = '/';

  // Maintenance mode: only administrators may use the API. Login, logout, the session check,
  // the public settings and the avatar images stay reachable.
  $maintenance = ($allSettings['under_maintenance'] ?? 'false') === 'true';
  if ($maintenance && !isMaintenanceExempt($path, $_SERVER['REQUEST_METHOD'])) {
    $current = $auth->currentUser();
    if (!$current || !$current['is_admin']) {
      sendMaintenance();
    }
  }

  // LDAP authentication (LDAP_* in the .env file); only the login uses it, see handleLogin()
  $ldap = new LdapService($_ENV);

  // OIDC single sign-on (OIDC_* in the .env file); it wins over LDAP. The authPolicy tells how a user authenticates.
  $oidc = new OidcService($_ENV, $pdo);
  $authPolicy = new AuthPolicy($ldap, $oidc);

  // Two factor authentication (the key is only needed once somebody sets up or uses 2FA)
  $totp = new Totp();
  // Without a license (limited mode) nobody has to set up 2FA: force_2fa keeps its value but has no effect
  $twoFactor = new TwoFactorService(
    $pdo,
    new SecretVault($_ENV['TWOFA_ENCRYPTION_KEY'] ?? null),
    $totp,
    $licenseFeatures->allows('two_fa_setup') ? $allSettings : ['force_2fa' => 'false'] + $allSettings
  );

  // The issue code asks these for who may see what and for the issue settings
  $issueConfig = new IssueConfig($allSettings);
  $visibility = new IssueVisibility($auth, $maintenance);
  $issueModel = new IssueModel($pdo, $visibility, $issueConfig);
  $commentPolicy = new CommentPolicy($visibility);
  $issueActions = new IssueActions($visibility, $commentPolicy);
  $rateLimiter = new RateLimiter($pdo, $issueConfig, $issueActions);
  $authThrottle = new AuthThrottle($pdo, $_ENV);

  // Notifications: in-app messages are saved with the action, the emails are queued and sent after the response
  // Email-to-ticket (MAILIN_* in .env, license): when answers are read, the mails carry the "reply above this line"
  // marker and the Reply-To of the mailbox
  $mailIn = new MailInConfig($_ENV);
  $mailInActive = $mailIn->isEnabled() && $licenseFeatures->allows('mail_in');
  $mailOutbox = new MailOutbox($pdo, new MailService((string)($allSettings['application_name'] ?? '')), $mailInActive ? $mailIn->replyAddress() : '');
  $notificationModel = new NotificationModel($pdo, $visibility, $issueConfig);
  $notificationPreferences = new NotificationPreferenceModel($pdo);
  $notificationMail = new NotificationMail((string)($allSettings['application_name'] ?? ''), NotificationMail::baseUrl($allSettings), (string)($allSettings['default_language'] ?? 'en'), $mailInActive);
  $notifier = new NotificationService(
    $pdo,
    $auth,
    $issueConfig,
    $notificationModel,
    $notificationPreferences,
    $mailOutbox,
    $notificationMail
  );
  // Reads the support mailbox when it is due (MAILIN_YES in .env, license); does nothing when the feature is off
  $mailInbound = InboundMailService::create($pdo, $allSettings, $_ENV, $notifier, $notificationMail, $mailOutbox, $audit, static fn(): bool => $licenseFeatures->allows('mail_in'), static fn(): bool => $authPolicy->isEnabled(), dirname(__DIR__));
  $attachmentModel = new AttachmentModel($pdo, $visibility, $audit);
  $attachmentStorage = new AttachmentStorage(dirname(__DIR__) . '/uploads/attachments');

  // No cron job needed: after the response the periodic cleanup runs when it is due (mail retry, retention, old uploads)
  $housekeeping = new Housekeeping($mailOutbox, $notifier, $attachmentModel, $attachmentStorage, $issueConfig, $audit, dirname(__DIR__) . '/uploads/.housekeeping', new MailInboxModel($pdo), new SlaNotifier($pdo, $notifier), new GitHubCommitCacheModel($pdo));
  register_shutdown_function(static function () use ($mailOutbox, $housekeeping, $mailInbound): void {
    // Under PHP-FPM the client gets the response now; the mail server is talked to afterwards
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) {
      fastcgi_finish_request();
    }
    try {
      if ($mailOutbox->hasQueued()) {
        $mailOutbox->process();
      }
      $housekeeping->runIfDue();
    } catch (\Throwable $e) {
      error_log('Housekeeping failed: ' . $e->getMessage());
    }
    try {
      $mailInbound->runIfDue();
    } catch (\Throwable $e) {
      error_log('Email to ticket failed: ' . $e->getMessage());
    }
  });

  // The attachment files are served by the attachment endpoint after the access check, never from this folder
  $attachmentController = new AttachmentController($attachmentModel, $attachmentStorage, $issueModel, $issueActions, $visibility, $issueConfig);

  // A type, status, priority, product or group that an SLA policy uses cannot be deleted: the answer names the policies
  $slaPolicies = new SlaPolicyModel($pdo);
  $slaDeleteGuard = static function (string $table, array $ids) use ($slaPolicies): ?string {
    $names = $slaPolicies->usedBy($table, $ids);

    return $names === [] ? null : 'It is used by the SLA policies: ' . implode(', ', $names);
  };

  // The issue key in the path (/issues/LD-12, /issues/LD-12/comments, a former key too): the routes below work with the
  // issue id, so the key is turned into it here. An unknown key becomes the id 0, which no issue has (404 as for any
  // missing issue). Who may see the issue is decided by the visibility rules of the routes, as for an id.
  // POST /issues/12/move is the short form of the action POST /issues/12/actions/move.
  if (preg_match('#^/issues/([A-Za-z]{1,4}-[1-9]\d{0,8})(/.*)?$#', $path, $m)) {
    $path = '/issues/' . ((new IssueKey($pdo))->resolve($m[1]) ?? 0) . ($m[2] ?? '');
  }
  if (preg_match('#^/issues/(\d+)/move/?$#', $path, $m)) {
    $path = '/issues/' . $m[1] . '/actions/move';
  }

  // Roles and groups (REST style: /roles, /roles/12, /roles/delete)
  if (preg_match('#^/(roles|groups)(?:/(\d+|delete))?/?$#', $path, $m)) {
    // Reading the lists is also needed by the user and permission forms (role and group selection)
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
      requireAnyPermission($auth, ['manage_' . $m[1], 'manage_users', 'manage_permissions']);
    } else {
      requirePermission($auth, 'manage_' . $m[1]);
    }
    $controller = $m[1] === 'roles'
      ? new EntityController(new EntityModel($pdo, 'roles', 'user_role', 'role_id'), $audit, 'Role', ['Administrator', 'Agent', 'User', 'Public'])
      : new EntityController(new EntityModel($pdo, 'groups', 'user_group', 'group_id'), $audit, 'Group', [], static fn(array $ids): ?string => $slaDeleteGuard('groups', $ids));
    $controller->handle($_SERVER['REQUEST_METHOD'], $m[2] ?? null);
    exit;
  }

  // The versions of a product (REST style: /products/3/versions, /products/3/versions/7, /products/3/versions/7/issues).
  // Reading is open to everybody who may see issues (the pickers of the issue forms need the versions); changing them
  // needs the product permission. The release notes (the issues with the fixed version) need view_issue and only list
  // the issues the viewer may see.
  if (preg_match('#^/products/(\d+)/versions(?:/(\d+)(?:/(issues))?)?/?$#', $path, $m)) {
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
      $allowed = !empty($m[3]) ? $auth->can('view_issue') : $auth->canAny(['view_issue', 'manage_products']);
      if (!$allowed) {
        $auth->currentUser() ? sendError(403, 'Permission required: view_issue') : sendError(401, 'Authentication required');
      }
    } else {
      requirePermission($auth, 'manage_products');
    }
    (new ProductVersionController(new ProductVersionModel($pdo, $visibility), $audit))->handle($method, (int)$m[1], isset($m[2]) ? (int)$m[2] : null, $m[3] ?? null);
    exit;
  }

  // Issue lookups (REST style: /issue-types, /status, /resolutions, /priorities, /products, /tags, /status-categories)
  if (preg_match('#^/(' . implode('|', Lookups::slugs()) . ')(?:/(\d+|delete))?/?$#', $path, $m)) {
    $definition = Lookups::get($m[1]);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
      // Everybody who may see issues needs the lists to display them, anonymous visitors included
      if (!$auth->canAny(['view_issue', $definition['permission']])) {
        $auth->currentUser() ? sendError(403, 'Permission required: view_issue') : sendError(401, 'Authentication required');
      }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($m[2]) && isset($definition['create_permissions'])) {
      requireAnyPermission($auth, [$definition['permission'], ...$definition['create_permissions']]);
    } else {
      requirePermission($auth, $definition['permission']);
    }
    (new LookupController(new LookupModel($pdo, $definition), $audit, $definition, $slaDeleteGuard, static fn(string $repo): string => (new GitHubClient())->repoStatus($repo)))->handle($_SERVER['REQUEST_METHOD'], $m[2] ?? null);
    exit;
  }

  // Comments of an issue (REST style: /issues/12/comments, /issues/12/comments/5). Reading is open to everybody
  // holding view_issue (the visibility rules decide which issues and comments they get); adding needs a login
  // and comment_issue; changing a comment needs a login (who may change which comment is decided by the
  // CommentPolicy).
  if (preg_match('#^/issues/(\d+)/comments(?:/(\d+))?/?$#', $path, $m)) {
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
      if (!$auth->can('view_issue')) {
        $auth->currentUser() ? sendError(403, 'Permission required: view_issue') : sendError(401, 'Authentication required');
      }
    } elseif ($method === 'POST' && !isset($m[2])) {
      requirePermission($auth, 'comment_issue');
    } else {
      requireUser($auth);
    }
    (new CommentController(new CommentModel($pdo, $visibility), $commentPolicy, $rateLimiter, $notifier))->handle($method, (int)$m[1], isset($m[2]) ? (int)$m[2] : null);
    exit;
  }

  // Attachments of an issue (REST style: /issues/12/attachments, /issues/12/attachments/5). Reading is open to everybody
  // holding view_issue (the visibility rules decide which issues and attachments they get); uploading and deleting need
  // a login (who may do it on which issue is decided by the IssueActions rules).
  if (preg_match('#^/issues/(\d+)/attachments(?:/(\d+))?/?$#', $path, $m)) {
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
      if (!$auth->can('view_issue')) {
        $auth->currentUser() ? sendError(403, 'Permission required: view_issue') : sendError(401, 'Authentication required');
      }
    } else {
      requireUser($auth);
    }
    $attachmentController->handleIssue($method, (int)$m[1], isset($m[2]) ? (int)$m[2] : null);
    exit;
  }

  // The commits of an issue that mention its key, from the GitHub repository of its product (docs/GITHUB.md). Reading
  // is open to everybody holding view_issue (the visibility rules decide which issues they get)
  if (preg_match('#^/issues/(\d+)/commits/?$#', $path, $m)) {
    if (!$auth->can('view_issue')) {
      $auth->currentUser() ? sendError(403, 'Permission required: view_issue') : sendError(401, 'Authentication required');
    }
    requireLicensedFeature($licenseFeatures, 'github');
    (new IssueCommitController($issueModel, new IssueCommits(new GitHubCommitCacheModel($pdo), new GitHubClient()), $pdo))->handle($_SERVER['REQUEST_METHOD'], (int)$m[1]);
    exit;
  }

  // Uploads while a new issue is written (GET, POST /attachments/temp, DELETE /attachments/temp/5)
  if (preg_match('#^/attachments/temp(?:/(\d+))?/?$#', $path, $m)) {
    requirePermission($auth, 'create_issue');
    $attachmentController->handleTemporary($_SERVER['REQUEST_METHOD'], isset($m[1]) ? (int)$m[1] : null);
    exit;
  }

  // The file of an attachment (GET /attachments/5). Everybody who may see the issue may download it
  if (preg_match('#^/attachments/(\d+)/?$#', $path, $m)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
      sendError(405, 'Method not allowed');
    }
    if (!$auth->can('view_issue')) {
      $auth->currentUser() ? sendError(403, 'Permission required: view_issue') : sendError(401, 'Authentication required');
    }
    $attachmentController->download((int)$m[1]);
    exit;
  }

  // History of an issue (GET /issues/12/history). Readable with view_issue like the issue itself
  if (preg_match('#^/issues/(\d+)/history/?$#', $path, $m)) {
    if (!$auth->can('view_issue')) {
      $auth->currentUser() ? sendError(403, 'Permission required: view_issue') : sendError(401, 'Authentication required');
    }
    (new HistoryController(new HistoryModel($pdo, $visibility), $visibility))->handle($_SERVER['REQUEST_METHOD'], (int)$m[1]);
    exit;
  }

  // Notifications of the logged in user (GET /notifications, POST /notifications/read, POST /notifications/12/read,
  // GET, PUT /notifications/preferences). What a user gets to see is decided by the visibility rules inside the model.
  if (preg_match('#^/notifications(?:/(\d+|read|preferences))?(?:/(read))?/?$#', $path, $m)) {
    $current = requireUser($auth);
    (new NotificationController($notificationModel, $notificationPreferences, $issueConfig))->handle($_SERVER['REQUEST_METHOD'], (int)$current['id'], $m[1] ?? null, $m[2] ?? null);
    exit;
  }

  // The dashboard of the logged in user (GET /dashboard). What is counted is decided by the visibility rules inside the model.
  if (preg_match('#^/dashboard/?$#', $path)) {
    $current = requireUser($auth);
    (new DashboardController(new DashboardModel($pdo, $visibility, $issueConfig)))->handle($_SERVER['REQUEST_METHOD'], (int)$current['id']);
    exit;
  }

  // Administration of Email-to-ticket (GET /mailin/status, POST /mailin/test, POST /mailin/run)
  if (preg_match('#^/mailin/(status|test|run)/?$#', $path, $m)) {
    requirePermission($auth, 'manage_settings');
    (new MailInController($mailInbound, $mailIn, new MailInSettings($allSettings), new MailInboxModel($pdo), new IssueKey($pdo)))->handle($_SERVER['REQUEST_METHOD'], $m[1]);
    exit;
  }

  // The license (GET /license/public needs no login, everything else needs manage_settings)
  if (preg_match('#^/license(?:/(public|key|activate|deregister|refresh))?/?$#', $path, $m)) {
    if (!LicenseController::isPublic($m[1] ?? null)) {
      requirePermission($auth, 'manage_settings');
    }
    (new LicenseController($license))->handle($_SERVER['REQUEST_METHOD'], $m[1] ?? null);
    exit;
  }

  // Preferences of the logged in user (GET, PUT /preferences)
  if (preg_match('#^/preferences/?$#', $path)) {
    $current = requireUser($auth);
    (new PreferenceController(new PreferenceModel($pdo)))->handle($_SERVER['REQUEST_METHOD'], (int)$current['id']);
    exit;
  }

  // Runs the housekeeping now (POST /housekeeping; the button on the System tab of the settings). Needs manage_settings. It does what
  // runs after a response every five minutes anyway: sends the mails, tells about SLA timers, deletes what is past its retention.
  if (preg_match('#^/housekeeping/?$#', $path)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      sendError(405, 'Method not allowed');
    }
    requirePermission($auth, 'manage_settings');
    $done = $housekeeping->runNow();
    if ($done === null) {
      sendError(409, 'The housekeeping is running already. Try again in a moment.');
    }
    $audit->log('housekeeping.run', null, null, null, null, null, $done);
    echo json_encode(['status' => 'success', 'message' => 'Housekeeping done', 'data' => $done]);
    exit;
  }

  // The SLA report (GET /sla/reports, also as CSV with format=csv). Needs view_sla (not manage_sla); off in the limited mode of the license.
  if (preg_match('#^/sla/reports/?$#', $path)) {
    requirePermission($auth, 'view_sla');
    requireLicensedFeature($licenseFeatures, 'sla');
    (new SlaReportController(new SlaReportModel($pdo, $visibility, $issueConfig)))->handle($_SERVER['REQUEST_METHOD']);
    exit;
  }

  // SLA configuration (GET, POST /sla/calendars|holidays|policies, GET, PUT, DELETE /sla/calendars|holidays|policies/12).
  // Needs manage_sla; off in the limited mode of the license (the configuration is kept).
  if (preg_match('#^/sla/(calendars|holidays|policies|apply)(?:/(\d+))?/?$#', $path, $m)) {
    requirePermission($auth, 'manage_sla');
    requireLicensedFeature($licenseFeatures, 'sla');
    (new SlaController(new SlaCalendarModel($pdo), $slaPolicies, $audit, new SlaService($pdo)))->handle($_SERVER['REQUEST_METHOD'], $m[1], isset($m[2]) ? (int)$m[2] : null);
    exit;
  }

  // Custom views of the issue list (GET, POST /custom-views, GET, PUT, DELETE /custom-views/12; GET /custom-views?scope=own|all).
  // A login is required; own views need no permission, the list of all views and the views of others need manage_custom_views.
  // Off in the limited mode of the license (the saved views are kept).
  if (preg_match('#^/custom-views(?:/(\d+))?/?$#', $path, $m)) {
    $current = requireUser($auth);
    requireLicensedFeature($licenseFeatures, 'custom_views');
    $scope = isset($_GET['scope']) && is_string($_GET['scope']) ? $_GET['scope'] : null;
    if ($scope === 'all') {
      requirePermission($auth, 'manage_custom_views');
    }
    (new CustomViewController(new CustomViewModel($pdo), $audit))->handle(
      $_SERVER['REQUEST_METHOD'],
      (int)$current['id'],
      $auth->can('manage_custom_views'),
      isset($m[1]) ? (int)$m[1] : null,
      $scope
    );
    exit;
  }

  // The due time of an SLA timer of an issue (PUT /issues/12/sla/first_response). Needs manage_sla, and view_sla as well because
  // the answer is the timer (a role that has only manage_sla must not get SLA data of issues); off in the limited mode of the license.
  if (preg_match('#^/issues/(\d+)/sla/(first_response|resolution)/?$#', $path, $m)) {
    requirePermission($auth, 'view_sla');
    requirePermission($auth, 'manage_sla');
    requireLicensedFeature($licenseFeatures, 'sla');
    (new IssueSlaController($issueModel, $visibility, new SlaService($pdo), $audit, $pdo))->handle($_SERVER['REQUEST_METHOD'], (int)$m[1], $m[2]);
    exit;
  }

  // The workflow actions of an issue (POST /issues/12/actions/resolve). A login is required; who may use which
  // action on which issue is decided by the IssueActions rules (the same ones that list the actions of an issue).
  if (preg_match('#^/issues/(\d+)/actions/([a-z_]+)/?$#', $path, $m)) {
    requireUser($auth);
    $issueWorkflow = new IssueWorkflowModel($pdo, $issueModel, new CommentModel($pdo, $visibility), $audit);
    (new IssueActionController($issueModel, $issueWorkflow, $issueActions, $commentPolicy, $visibility, $notifier, $rateLimiter))->handle($_SERVER['REQUEST_METHOD'], (int)$m[1], $m[2]);
    exit;
  }

  // Issues (REST style: /issues, /issues/12, /issues/counts, /issues/people, /issues/accessible, /issues/agents, /issues/12/tags).
  // Reading is open to everybody holding view_issue, anonymous visitors included (the visibility rules decide which
  // issues they get); creating needs a login, setting the tags and editing need edit_issue. The assignable users are
  // listed for the holders of assign_issue or edit_issue (checked by the controller).
  if (preg_match('#^/issues(?:/(\d+|counts|people|accessible|agents|reporters))?(?:/(tags|watchers))?/?$#', $path, $m) && (empty($m[2]) || (isset($m[1]) && ctype_digit($m[1])))) {
    if (!empty($m[2]) || ($_SERVER['REQUEST_METHOD'] === 'PUT' && isset($m[1]) && ctype_digit($m[1]))) {
      requirePermission($auth, 'edit_issue');
    } elseif (in_array($m[1] ?? '', ['agents', 'reporters'], true)) {
      requireUser($auth);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
      if (!$auth->can('view_issue')) {
        $auth->currentUser() ? sendError(403, 'Permission required: view_issue') : sendError(401, 'Authentication required');
      }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
      requirePermission($auth, 'create_issue');
      // Limited mode of the license: no new issues beyond the limit (editing and deleting stay possible)
      if (empty($m[1]) && $licenseFeatures->reached('issues', $issueModel->countActive())) {
        sendLicenseLimit(LicenseFeatures::limitMessage('issues'));
      }
    }
    (new IssueController($issueModel, $visibility, $issueActions, $attachmentModel, $rateLimiter, $notifier, new IssueKey($pdo)))->handle($_SERVER['REQUEST_METHOD'], $m[1] ?? null, $m[2] ?? null);
    exit;
  }

  // The audit log (GET /audit-log, /audit-log/12, /audit-log/export). Read only. Needs the permission view_audit_log and
  // the Administrator role: granting the permission to another role does not open the log.
  if (preg_match('#^/audit-log(?:/(\d+|export))?/?$#', $path, $m)) {
    requirePermission($auth, 'view_audit_log');
    requireAdmin($auth);
    // Off in the limited mode of the license (the entries are still written)
    requireLicensedFeature($licenseFeatures, 'audit_log');
    (new AuditLogController(new AuditLogModel($pdo, (string)($allSettings['timezone'] ?? 'UTC')), $audit))->handle($_SERVER['REQUEST_METHOD'], $m[1] ?? null);
    exit;
  }

  // Permissions (REST style: /permissions, /permissions/12, /permissions/delete)
  if (preg_match('#^/permissions(?:/(\d+|delete))?/?$#', $path, $m)) {
    requirePermission($auth, 'manage_permissions');
    (new PermissionController(new PermissionModel($pdo), $audit))->handle($_SERVER['REQUEST_METHOD'], $m[1] ?? null);
    exit;
  }

  // Two factor authentication of a user: the user switches it off (own id only, needs the password) or an
  // administrator resets it
  if (preg_match('#^/profile/(\d+)/2fa/disable/?$#', $path, $m)) {
    (new TwoFactorController($twoFactor, $auth, $userModel, $totp, new QrCode(), $audit, $ldap, $oidc))->disable($_SERVER['REQUEST_METHOD'], (int)$m[1]);
    exit;
  }
  if (preg_match('#^/users/(\d+)/2fa/reset/?$#', $path, $m)) {
    $current = requirePermission($auth, 'manage_users');
    (new TwoFactorController($twoFactor, $auth, $userModel, $totp, new QrCode(), $audit, $ldap, $oidc))->reset($_SERVER['REQUEST_METHOD'], (int)$m[1], (int)$current['id']);
    exit;
  }

  // Users (REST style: /users, /users/12, /users/delete)
  if (preg_match('#^/users(?:/(\d+|delete))?/?$#', $path, $m)) {
    requirePermission($auth, 'manage_users');
    // Limited mode of the license: no new users beyond the limit (editing, deactivating and deleting stay possible)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($m[1]) && $licenseFeatures->reached('users', $userModel->countExceptAdmin())) {
      sendLicenseLimit(LicenseFeatures::limitMessage('users'));
    }
    (new UserController($userModel, $audit, ['admin'], (int)$auth->currentUser()['id'], $authPolicy, new CustomViewModel($pdo)))->handle($_SERVER['REQUEST_METHOD'], $m[1] ?? null);
    exit;
  }

  // Profile (self service): /profile/12, /profile/12/avatar; uploaded avatar files: /avatars/<file>
  if (preg_match('#^/profile/(\d+)(?:/(avatar))?/?$#', $path, $m)) {
    // Users may only touch their own profile; administrators may touch every profile
    $current = requireUser($auth);
    if ((int)$m[1] !== (int)$current['id'] && !$current['is_admin']) {
      sendError(403, 'Forbidden');
    }
    (new ProfileController($userModel, $audit, dirname(__DIR__) . '/uploads/avatar', $authPolicy))->handle($_SERVER['REQUEST_METHOD'], (int)$m[1], $m[2] ?? null);
    exit;
  }
  if (preg_match('#^/avatars/([A-Za-z0-9._-]+)$#', $path, $m)) {
    (new ProfileController($userModel, $audit, dirname(__DIR__) . '/uploads/avatar', $authPolicy))->serveAvatar($m[1]);
    exit;
  }

  // Basic Router
  switch ($path) {
    case '/settings':
      if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $current = requirePermission($auth, 'manage_settings');
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || empty($input)) {
          http_response_code(400);
          echo json_encode(['status' => 'error', 'message' => 'No settings given']);
          break;
        }

        // The maintenance mode stays with the Administrator role, whatever permissions are granted
        if (!$current['is_admin'] && isset($input['under_maintenance']) && $input['under_maintenance'] !== ($allSettings['under_maintenance'] ?? 'false') && $input['under_maintenance'] !== (($allSettings['under_maintenance'] ?? 'false') === 'true')) {
          sendError(403, 'Administrator role required to change the maintenance mode');
        }

        [$clean, $errors] = SettingsValidator::validate($input);
        if (!empty($errors)) {
          http_response_code(400);
          echo json_encode(['status' => 'error', 'message' => implode('; ', $errors), 'errors' => $errors]);
          break;
        }

        // Limited mode of the license: forcing 2FA cannot be switched on (a value that is already set is kept)
        if (!$licenseFeatures->allows('two_fa_setup') && isset($clean['force_2fa']) && ($clean['force_2fa'] === true || $clean['force_2fa'] === 'true') && ($allSettings['force_2fa'] ?? 'false') !== 'true') {
          sendLicenseLimit(LicenseFeatures::MESSAGE_FEATURE);
        }

        // The default product must exist (new issues that name no product, e.g. from mails, are created in it)
        if (isset($clean['default_product_id']) && !$issueModel->lookupExists('products', (int)$clean['default_product_id'])) {
          sendError(400, 'default_product_id: product does not exist');
        }

        // The change and one audit entry per changed setting stand or fall together
        $saved = $audit->transaction(function () use ($settingsModel, $audit, $clean, $allSettings): bool {
          if (!$settingsModel->setMultiple($clean)) {
            return false;
          }
          foreach ($clean as $key => $value) {
            $new = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
            $old = $allSettings[$key] ?? null;
            if ($old === $new) {
              continue;
            }
            $audit->log(
              'setting.changed',
              null,
              'setting',
              null,
              (string)$key,
              ['value' => $old === null ? null : AuditLog::sanitizeSetting((string)$key, $old)],
              ['value' => AuditLog::sanitizeSetting((string)$key, $new)]
            );
          }
          return true;
        });

        if ($saved) {
          echo json_encode(['status' => 'success', 'message' => 'Settings updated']);
        } else {
          http_response_code(500);
          echo json_encode(['status' => 'error', 'message' => 'Failed to update settings']);
        }
      } else {
        $settings = $settingsModel->getAll();
        // The license key and the cached license data are internal (lic_*); only the warning period is public
        foreach (array_keys($settings) as $settingKey) {
          if (str_starts_with((string)$settingKey, 'lic_') && $settingKey !== 'lic_expiry_warning') {
            unset($settings[$settingKey]);
          }
        }
        // Not stored: the state of the license (no key) for the banners, the login page and the redirects
        $settings['license'] = $license->publicState() + $licenseFeatures->summary(
          static fn(): int => $issueModel->countActive(),
          static fn(): int => $userModel->countExceptAdmin()
        );
        // Not a stored setting: the pages hide the password features and show the LDAP hint (LDAP_YES in .env)
        $settings['ldap_active'] = $ldap->isEnabled();
        // Not a stored setting: Email to ticket is on (MAILIN_YES in .env and the license); the issue list shows its filter
        $settings['mailin_active'] = $mailInActive;
        // Same for single sign-on (OIDC_YES in .env): the login page shows the SSO button
        $settings['oidc_active'] = $oidc->isEnabled();
        echo json_encode(['status' => 'success', 'data' => $settings]);
      }
      break;

    case '/auth/login':
      // Wrong passwords are counted per client address; a blocked address gets 429 before any password is checked
      throttleAuth($authThrottle, AuthThrottle::LOGIN, false);
      handleLogin($userModel, $auth, $maintenance, $twoFactor, $audit, $ldap, $oidc, $license);
      if (http_response_code() === 401) {
        $authThrottle->record(AuthThrottle::LOGIN, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
      }
      break;

    case '/auth/oidc/login':
      handleOidcLogin($oidc);
      break;

    case '/auth/oidc/callback':
      handleOidcCallback($oidc, $userModel, $auth, $audit, $maintenance, $license);
      break;

    case '/auth/oidc/exchange':
      handleOidcExchange($oidc, $userModel, $auth, $audit, $maintenance, $license);
      break;

    case '/auth/2fa/verify':
    case '/auth/2fa/setup':
    case '/auth/2fa/enable':
    case '/auth/2fa/begin':
      $twoFactorController = new TwoFactorController($twoFactor, $auth, $userModel, $totp, new QrCode(), $audit, $ldap, $oidc);
      $twoFactorController->allowEnrolment($licenseFeatures->allows('two_fa_setup'));
      $twoFactorController->handleAuth($_SERVER['REQUEST_METHOD'], basename($path));
      break;

    case '/auth/logout':
      if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError(405, 'Method not allowed');
      }
      $loggingOut = $auth->currentUser();
      if ($loggingOut !== null) {
        $audit->logQuiet('auth.logout', $loggingOut, 'user', (int)$loggingOut['id'], (string)$loggingOut['username']);
      }
      $auth->revokeCurrentToken();
      echo json_encode(['status' => 'success', 'message' => 'Logged out']);
      break;

    case '/auth/me':
      echo json_encode(['status' => 'success', 'user' => requireUser($auth)]);
      break;

    case '/auth/session':
      // Like /auth/me, but also answers anonymous visitors (no 401): the pages that are open to
      // them need to know the permissions of the role "Public". In maintenance mode anonymous
      // visitors hold no permissions at all.
      $current = $auth->currentUser();
      echo json_encode([
        'status' => 'success',
        'anonymous' => $current === null,
        'user' => $current,
        'permissions' => $current !== null
          ? $current['permissions']
          : ($maintenance ? [] : $auth->publicPermissions())
      ]);
      break;

    case '/auth/register':
      throttleAuth($authThrottle, AuthThrottle::REGISTER, true);
      // Limited mode of the license: no self-registration, whatever the system setting says
      if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$licenseFeatures->allows('self_register')) {
        sendLicenseLimit(LicenseFeatures::MESSAGE_FEATURE);
      }
      handleRegister($userModel, $audit, $authPolicy, ($allSettings['allow_registration'] ?? 'true') !== 'false');
      break;

    case '/auth/reset-password':
      throttleAuth($authThrottle, AuthThrottle::RESET, true);
      handleResetPassword($userModel, new PasswordResetModel($pdo), new MailService((string)($allSettings['application_name'] ?? '')), $allSettings, $audit, $authPolicy);
      break;

    case '/auth/reset-password/confirm':
      handleConfirmResetPassword(new PasswordResetModel($pdo), $userModel, $audit, $authPolicy);
      break;

    case '/test':
      echo json_encode(['status' => 'success', 'message' => 'API is online']);
      break;

    default:
      http_response_code(404);
      echo json_encode(['status' => 'error', 'message' => 'Endpoint not found', 'path' => $path]);
      break;
  }
} catch (\Throwable $e) {
  // The details (SQL, file names) go to the log, never to the client
  error_log(sprintf('API error on %s %s: %s in %s:%d', $_SERVER['REQUEST_METHOD'] ?? '-', $_SERVER['REQUEST_URI'] ?? '-', $e->getMessage(), $e->getFile(), $e->getLine()));
  if (!headers_sent()) {
    http_response_code(500);
  }
  echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
}

//---------------------------------------------------------------------------
/**
 * Handles the registration request.
 *
 * @param UserModel $userModel The user model
 * @param AuditLog  $audit     The audit log
 * @param AuthPolicy $ldap     Tells whether LDAP or OIDC is active
 * @param bool      $allowed   False when an administrator turned off self-registration (setting allow_registration)
 *
 * @return void
 */
function handleRegister(UserModel $userModel, AuditLog $audit, AuthPolicy $ldap, bool $allowed = true): void
{
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    return;
  }

  // The administrator manages the users manually
  if (!$allowed) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Registration is disabled. Ask an administrator for an account.']);
    return;
  }

  // With LDAP or SSO the accounts are created by an administrator; a self-registered account has no use (and would only
  // reserve the name of somebody else)
  if ($ldap->isEnabled()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Registration is disabled while LDAP or SSO authentication is active. Ask an administrator for an account.']);
    return;
  }

  $input = json_decode(file_get_contents('php://input'), true) ?? [];
  $username = trim((string)($input['username'] ?? ''));
  $email = trim((string)($input['email'] ?? ''));
  $password = (string)($input['password'] ?? '');

  $error = null;
  if ($username === '' || $email === '' || $password === '') {
    $error = 'Username, email and password are required';
  } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
    $error = 'Username must be 3-50 characters (letters, digits, _ . -)';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
    $error = 'Invalid email address';
  } elseif (strlen($password) < 8) {
    $error = 'Password must be at least 8 characters';
  } elseif ($userModel->findByUsername($username)) {
    $error = 'Username is already taken';
  } elseif ($userModel->findByEmail($email)) {
    $error = 'Email is already registered';
  }

  if ($error !== null) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $error]);
    return;
  }

  // The account and its audit entry stand or fall together
  $created = $audit->transaction(function () use ($userModel, $audit, $username, $email, $password): bool {
    $done = $userModel->create([
      'username' => $username,
      'email' => $email,
      'password' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    $new = $done ? $userModel->findByUsername($username) : null;
    if ($new !== null) {
      // Without a role the account could not see or create any issue
      $userModel->assignRole((int)$new['id'], 'User');
      // The new account registered itself: it is the actor
      $audit->log('user.created', ['id' => (int)$new['id'], 'username' => $username], 'user', (int)$new['id'], $username, null, ['email' => $email]);
    }
    return $done;
  });

  if ($created) {
    http_response_code(201);
    echo json_encode(['status' => 'success', 'message' => 'Account created']);
  } else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to create account']);
  }
}

//---------------------------------------------------------------------------
/**
 * Handles the reset password request: emails a single-use reset link (valid for one hour).
 *
 * The response is identical whether or not the email exists to avoid account enumeration.
 *
 * @param UserModel          $userModel  The user model
 * @param PasswordResetModel $resetModel The reset token model
 * @param MailService        $mail       The mail service
 * @param array              $settings   The system settings
 * @param AuditLog           $audit      The audit log
 * @param AuthPolicy         $ldap       Tells who authenticates against LDAP or OIDC
 *
 * @return void
 */
function handleResetPassword(UserModel $userModel, PasswordResetModel $resetModel, MailService $mail, array $settings, AuditLog $audit, AuthPolicy $ldap): void
{
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    return;
  }

  $input = json_decode(file_get_contents('php://input'), true) ?? [];
  $email = trim((string)($input['email'] ?? ''));

  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid email address']);
    return;
  }

  $user = $userModel->findByEmail($email);
  // An LDAP or SSO user has no password in LeweDesk to reset; the answer is the same, so it does not tell who exists
  if ($user && !$ldap->usesExternal((string)$user['username'])) {
    $token = $resetModel->create((int)$user['id'], 3600);
    $baseUrl = mailBaseUrl($settings);
    $link = $baseUrl . '/reset-password.html?token=' . $token;
    $appName = trim((string)($settings['application_name'] ?? '')) ?: 'LeweDesk';
    $name = trim((string)$user['first_name']) !== '' ? (string)$user['first_name'] : (string)$user['username'];
    [$html, $text] = buildResetMail($appName, $baseUrl, $name, $link);
    $mail->send((string)$user['email'], "$appName: Password reset", $html, $text);
    $audit->logQuiet('auth.password_reset_requested', ['id' => (int)$user['id'], 'username' => (string)$user['username']], 'user', (int)$user['id'], (string)$user['username']);
  }

  echo json_encode(['status' => 'success', 'message' => 'If the email is registered, a reset link has been sent.']);
}

//---------------------------------------------------------------------------
/**
 * Handles setting a new password with a reset token.
 *
 * @param PasswordResetModel $resetModel The reset token model
 * @param UserModel          $userModel  The user model
 * @param AuditLog           $audit      The audit log
 * @param AuthPolicy         $ldap       Tells who authenticates against LDAP or OIDC
 *
 * @return void
 */
function handleConfirmResetPassword(PasswordResetModel $resetModel, UserModel $userModel, AuditLog $audit, AuthPolicy $ldap): void
{
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    return;
  }

  $input = json_decode(file_get_contents('php://input'), true) ?? [];
  $token = (string)($input['token'] ?? '');
  $password = (string)($input['password'] ?? '');

  $userId = preg_match('/^[a-f0-9]{64}$/', $token) ? $resetModel->findUserId($token) : null;

  if ($userId === null) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'This reset link is invalid or has expired.']);
    return;
  }
  if (strlen($password) < 8) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Password must be at least 8 characters']);
    return;
  }

  $username = (string)($userModel->getProfile($userId)['username'] ?? '');
  // A link issued before LDAP or SSO was switched on must not set a local password that is never used
  if ($ldap->usesExternal($username)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'This reset link is invalid or has expired.']);
    return;
  }
  $audit->transaction(function () use ($resetModel, $audit, $userId, $username, $password): void {
    $resetModel->complete($userId, password_hash($password, PASSWORD_DEFAULT));
    // The user proved the ownership of the account with the link: the user is the actor
    $audit->log('auth.password_reset_completed', ['id' => $userId, 'username' => $username], 'user', $userId, $username, ['password' => AuditLog::MASK], ['password' => AuditLog::MASK]);
  });
  echo json_encode(['status' => 'success', 'message' => 'Your password has been changed. You can now log in.']);
}

//---------------------------------------------------------------------------
/**
 * Returns the application URL for links in emails: the application_url setting,
 * falling back to APP_URL from the environment. No trailing slash.
 *
 * @param array $settings The system settings
 *
 * @return string
 */
function mailBaseUrl(array $settings): string
{
  $url = trim((string)($settings['application_url'] ?? ''));
  if ($url === '') {
    $url = (string)($_ENV['APP_URL'] ?? '');
  }
  return rtrim($url, '/');
}

//---------------------------------------------------------------------------
/**
 * Builds the password reset email.
 *
 * @param string $appName The application name
 * @param string $baseUrl The application URL
 * @param string $name    The recipient's name
 * @param string $link    The reset link
 *
 * @return array{0: string, 1: string} HTML and plain text body
 */
function buildResetMail(string $appName, string $baseUrl, string $name, string $link): array
{
  $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

  $title = $appName;
  $icon = $baseUrl . '/assets/img/icons/app-icon-128.png';
  $font = "font-family:'Poppins','Segoe UI',Helvetica,Arial,sans-serif;";

  $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
    . '<meta name="color-scheme" content="dark"><meta name="supported-color-schemes" content="dark">'
    . '<title>' . $e($title) . '</title></head>'
    . '<body style="margin:0;padding:0;background-color:#121212;' . $font . 'color:#dee2e6;">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#121212;"><tr><td align="center" style="padding:32px 12px;">'
    // Card
    . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background-color:#1e1e1e;border:1px solid #333333;border-radius:8px;">'
    // Header
    . '<tr><td style="background-color:#212631;border-bottom:1px solid #323a49;border-radius:8px 8px 0 0;padding:20px 32px;">'
    . '<table role="presentation" cellpadding="0" cellspacing="0"><tr>'
    . '<td style="padding-right:14px;"><img src="' . $e($icon) . '" width="40" height="40" alt="' . $e($appName) . '" style="display:block;border:0;"></td>'
    . '<td style="' . $font . 'font-size:20px;font-weight:600;color:#ffffff;">' . $e($title) . '</td>'
    . '</tr></table></td></tr>'
    // Body
    . '<tr><td style="padding:36px 32px 12px;' . $font . 'font-size:15px;line-height:1.6;color:#dee2e6;">'
    . '<h1 style="margin:0 0 20px;font-size:22px;font-weight:600;color:#ffffff;">Reset your password</h1>'
    . '<p style="margin:0 0 16px;">Hello ' . $e($name) . ',</p>'
    . '<p style="margin:0 0 28px;">We received a request to reset the password of your account. Click the button below to choose a new one.</p>'
    . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 28px;"><tr>'
    . '<td align="center" style="background-color:#0d6efd;border-radius:6px;">'
    . '<a href="' . $e($link) . '" style="display:inline-block;padding:13px 30px;' . $font . 'font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">Reset password</a>'
    . '</td></tr></table>'
    . '<p style="margin:0 0 8px;font-size:13px;color:#aaaaaa;">This link is valid for one hour and can be used once.</p>'
    . '</td></tr>'
    // Fallback link and notice
    . '<tr><td style="padding:12px 32px 32px;' . $font . 'font-size:13px;line-height:1.6;color:#aaaaaa;">'
    . '<div style="border-top:1px solid #333333;padding-top:20px;">'
    . '<p style="margin:0 0 8px;">If the button does not work, copy this address into your browser:</p>'
    . '<p style="margin:0 0 20px;word-break:break-all;"><a href="' . $e($link) . '" style="color:#0f90fb;text-decoration:none;">' . $e($link) . '</a></p>'
    . '<p style="margin:0;">If you did not request this, you can safely ignore this email. Your password will not change.</p>'
    . '</div></td></tr>'
    // Footer
    . '<tr><td align="center" style="background-color:#1a1a1a;border-top:1px solid #333333;border-radius:0 0 8px 8px;padding:18px 32px;' . $font . 'font-size:12px;color:#999999;">'
    . '&copy; ' . date('Y') . ' by <a href="https://lewe.com" style="color:#0f90fb;text-decoration:none;">Lewe Labs</a>'
    . '</td></tr>'
    . '</table></td></tr></table></body></html>';

  $text = "$title\n\n"
    . "Hello $name,\n\n"
    . "We received a request to reset the password of your account. Open this link to choose a new one (valid for one hour, usable once):\n\n"
    . "$link\n\n"
    . "If you did not request this, you can safely ignore this email. Your password will not change.\n";

  return [$html, $text];
}

//---------------------------------------------------------------------------
/**
 * Handles the login request.
 *
 * @param UserModel   $userModel The user model
 * @param AuthService $auth      The authentication service
 * @param bool        $maintenance Whether the maintenance mode is on (only administrators may log in)
 * @param TwoFactorService $twoFactor The two factor authentication rules
 * @param LicenseService $license The license (a locked license lets only administrators log in)
 *
 * @return void
 */
function handleLogin(UserModel $userModel, AuthService $auth, bool $maintenance, TwoFactorService $twoFactor, AuditLog $audit, LdapService $ldap, OidcService $oidc, LicenseService $license): void
{
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    return;
  }

  $input = json_decode(file_get_contents('php://input'), true);
  $username = $input['username'] ?? '';
  $password = $input['password'] ?? '';

  if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Username and password are required']);
    return;
  }

  $user = $userModel->findByUsername($username);

  // With SSO on, only "admin" may use this form; everybody else signs in with the SSO button. The stored username
  // decides (like for LDAP), and the answer is the same for unknown names.
  if ($oidc->usesOidc($user ? (string)$user['username'] : $username)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Local login is disabled while SSO is active. Please use the Login with SSO button. Only the administrator account can use local login.']);
    return;
  }

  // With LDAP on, everybody except "admin" is checked against LDAP and never against the local password (which stays
  // in the database, unchanged, for the day LDAP is switched off). The stored username decides, so "ADMIN" or
  // "admin " cannot be used to get around the rule. LDAP is asked first: somebody who is not an LDAP user must not
  // learn which accounts LeweDesk has.
  $method = 'local';
  $verified = false;
  if ($ldap->usesLdap($user ? (string)$user['username'] : $username)) {
    $method = 'ldap';
    $result = $ldap->verify($username, $password);
    if ($result === LdapService::OK) {
      if (!$user) {
        // LDAP only authenticates; accounts are never created from it
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unknown LeweDesk user.']);
        return;
      }
      $verified = true;
    } else {
      $failure = LdapService::failure($result);
      if ($failure['log'] && $result === LdapService::EXTENSION_MISSING) {
        // The other server problems are written to the error log by the service
        error_log('LDAP: ' . LdapService::describe($result) . ' (LDAP_YES is on)');
      }
      if ($failure['status'] === 401 && $user) {
        $audit->logFailure('auth.login_failed', ['id' => (int)$user['id'], 'username' => (string)$user['username']], 'user', (int)$user['id'], (string)$user['username'], null, ['method' => $method]);
      }
      http_response_code($failure['status']);
      echo json_encode(['status' => 'error', 'message' => $failure['message']]);
      return;
    }
  } else {
    $verified = $user && password_verify($password, $user['password']);
  }

  if ($verified) {
    $account = $auth->userData((int)$user['id']);
    if ($maintenance && !$account['is_admin']) {
      sendMaintenance('The application is under maintenance. Only administrators can log in.');
    }
    if (!$account['is_admin'] && $license->isLocked()) {
      sendLicenseExpired($audit, $user, 'password');
    }

    // Second factor: no login token yet, only a short-lived pending token. A user who has set up 2FA enters a
    // code; if 2FA is forced and the user has not set it up yet, the user sets it up first.
    $userId = (int)$user['id'];
    if ($twoFactor->isRequired($userId)) {
      if ($twoFactor->isBypassed($account)) {
        error_log("DISABLE_ADMIN_2FA is on: administrator {$user['username']} logged in without two factor authentication");
        $audit->logQuiet('auth.2fa_bypass', ['id' => $userId, 'username' => (string)$user['username']], 'user', $userId, (string)$user['username'], null, ['reason' => 'DISABLE_ADMIN_2FA']);
      } else {
        $enrolled = $twoFactor->isEnrolled($userId);
        echo json_encode([
          'status' => $enrolled ? '2fa_required' : '2fa_setup_required',
          'pending_token' => $twoFactor->createPending($userId, $enrolled ? TwoFactorService::CHALLENGE : TwoFactorService::SETUP),
          'expires_in' => 300
        ]);
        return;
      }
    }

    $audit->logQuiet('auth.login', ['id' => $userId, 'username' => (string)$user['username']], 'user', $userId, (string)$user['username'], null, ['method' => $method]);
    echo json_encode([
      'status' => 'success',
      'token' => $auth->issueToken((int)$user['id']),
      'expires_in' => $auth->lifetime(),
      'user' => $auth->userData((int)$user['id'])
    ]);
  } else {
    // A wrong password for an existing account is logged; unknown usernames are not (see _docs/AUDITLOG.md)
    if ($user) {
      $audit->logFailure('auth.login_failed', ['id' => (int)$user['id'], 'username' => (string)$user['username']], 'user', (int)$user['id'], (string)$user['username'], null, ['method' => $method]);
    }
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid credentials']);
  }
}

//---------------------------------------------------------------------------
/**
 * Ends the request with 429 while the client address is blocked for an action (see AuthThrottle).
 *
 * @param AuthThrottle $throttle The throttle
 * @param string       $action   AuthThrottle::LOGIN, REGISTER or RESET
 * @param bool         $count    True to count this request (registration, reset); false when the caller counts
 *                               the failure itself (login)
 *
 * @return void
 */
function throttleAuth(AuthThrottle $throttle, string $action, bool $count): void
{
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return;
  }
  $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
  $wait = $throttle->lockedFor($action, $ip);
  if ($wait > 0) {
    header('Retry-After: ' . $wait);
    http_response_code(429);
    echo json_encode(['status' => 'error', 'message' => 'Too many attempts. Please try again later.', 'retry_after' => $wait]);
    exit;
  }
  if ($count) {
    $throttle->record($action, $ip);
  }
}

//---------------------------------------------------------------------------
/**
 * Sends the browser to the login page of the application with an SSO error code (shown by the page).
 *
 * @param OidcService $oidc  The OIDC service (knows where the login page is)
 * @param string      $error The error code (disabled, config, unavailable, invalid_token, expired, provider_error,
 *                           unknown_user, admin_local_only, maintenance)
 *
 * @return never
 */
function redirectOidcError(OidcService $oidc, string $error): never
{
  header('Location: ' . $oidc->loginPageUrl() . '?oidc_error=' . rawurlencode($error));
  exit;
}

//---------------------------------------------------------------------------
/**
 * Starts the SSO login: sends the browser to the Identity Provider.
 *
 * @param OidcService $oidc The OIDC service
 *
 * @return void
 */
function handleOidcLogin(OidcService $oidc): void
{
  if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    return;
  }

  $result = $oidc->beginLogin();
  if ($result['error'] !== null) {
    // Without SSO there is no login page to send the visitor to
    if ($result['error'] === OidcService::DISABLED) {
      http_response_code(404);
      echo json_encode(['status' => 'error', 'message' => 'SSO is not active']);
      return;
    }
    redirectOidcError($oidc, $result['error']);
  }

  header('Location: ' . $result['url']);
}

//---------------------------------------------------------------------------
/**
 * Receives the Identity Provider after the login: checks the answer, finds the LeweDesk account and sends the browser
 * back to the login page with a one-time code (or an error code). The code is swapped for the login by
 * handleOidcExchange(), so no token travels in a URL.
 *
 * The account is found by the permanent subject ("sub"), or by the username (preferred_username) on the first login,
 * which binds the subject to the account. Accounts are never created from OIDC, and the email is never used.
 *
 * @param OidcService $oidc        The OIDC service
 * @param UserModel   $userModel   The user model
 * @param AuthService $auth        The authentication service
 * @param AuditLog    $audit       The audit log
 * @param bool        $maintenance Whether the maintenance mode is on (only administrators may log in)
 * @param LicenseService $license  The license (a locked license lets only administrators log in)
 *
 * @return void
 */
function handleOidcCallback(OidcService $oidc, UserModel $userModel, AuthService $auth, AuditLog $audit, bool $maintenance, LicenseService $license): void
{
  if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    return;
  }
  if (!$oidc->isEnabled()) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'SSO is not active']);
    return;
  }

  // The provider reports an error (the user cancelled, access denied, ...)
  if (isset($_GET['error'])) {
    error_log('OIDC: the provider returned an error: ' . substr((string)preg_replace('/[^\x20-\x7e]/', '?', (string)$_GET['error']), 0, 100));
    redirectOidcError($oidc, OidcService::PROVIDER_ERROR);
  }

  $result = $oidc->finishLogin((string)($_GET['code'] ?? ''), (string)($_GET['state'] ?? ''));
  if ($result['error'] !== null) {
    redirectOidcError($oidc, $result['error']);
  }
  $sub = $result['sub'];

  // The permanent subject first, then the username (case-insensitive: the database collation decides, as for LDAP)
  $user = $userModel->findByOidcSub($sub);
  $bind = false;
  if (!$user) {
    $user = $result['username'] !== '' ? $userModel->findByUsername($result['username']) : null;
    // An account that is bound to another subject is not taken over by name
    if ($user && ($user['oidc_sub'] ?? null) !== null && $user['oidc_sub'] !== '' && $user['oidc_sub'] !== $sub) {
      $user = null;
    }
    $bind = $user !== null;
  }
  if (!$user) {
    redirectOidcError($oidc, 'unknown_user');
  }

  $userId = (int)$user['id'];
  $actor = ['id' => $userId, 'username' => (string)$user['username']];

  // "admin" is local only. This check comes before the subject is bound, so no subject is ever written to its record.
  if (!$oidc->usesOidc((string)$user['username'])) {
    $audit->logFailure('auth.login_failed', $actor, 'user', $userId, (string)$user['username'], null, ['method' => 'oidc', 'reason' => 'admin_local_only']);
    redirectOidcError($oidc, 'admin_local_only');
  }

  if ($bind) {
    if (!$userModel->saveOidcSub($userId, $sub)) {
      redirectOidcError($oidc, 'unknown_user');
    }
    $audit->logQuiet('auth.oidc_bound', $actor, 'user', $userId, (string)$user['username']);
  }

  $account = $auth->userData($userId);
  if ($maintenance && !$account['is_admin']) {
    redirectOidcError($oidc, 'maintenance');
  }
  if (!$account['is_admin'] && $license->isLocked()) {
    $audit->logFailure('auth.login_failed', $actor, 'user', $userId, (string)$user['username'], null, ['method' => 'oidc', 'reason' => 'license_expired']);
    redirectOidcError($oidc, 'license_expired');
  }

  header('Location: ' . $oidc->loginPageUrl() . '#oidc=' . $oidc->createCode($userId));
}

//---------------------------------------------------------------------------
/**
 * Swaps the one-time code from the login page for the login (token and user), like a successful local login. There is
 * no second factor: the Identity Provider takes care of it (see _docs/OIDC.md).
 *
 * @param OidcService $oidc        The OIDC service
 * @param UserModel   $userModel   The user model
 * @param AuthService $auth        The authentication service
 * @param AuditLog    $audit       The audit log
 * @param bool        $maintenance Whether the maintenance mode is on (only administrators may log in)
 * @param LicenseService $license  The license (a locked license lets only administrators log in)
 *
 * @return void
 */
function handleOidcExchange(OidcService $oidc, UserModel $userModel, AuthService $auth, AuditLog $audit, bool $maintenance, LicenseService $license): void
{
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    return;
  }
  if (!$oidc->isEnabled()) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'SSO is not active']);
    return;
  }

  $input = json_decode(file_get_contents('php://input'), true);
  $userId = $oidc->redeemCode(is_array($input) ? (string)($input['code'] ?? '') : '');
  $profile = $userId !== null ? $userModel->getProfile($userId) : null;
  // A code only exists for an SSO user; if that changed in the meantime (OIDC switched off, "admin") it is refused
  if ($userId === null || !$profile || !$oidc->usesOidc((string)$profile['username'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'The SSO login has expired. Please try again.']);
    return;
  }

  $account = $auth->userData($userId);
  if ($maintenance && !$account['is_admin']) {
    sendMaintenance('The application is under maintenance. Only administrators can log in.');
  }
  if (!$account['is_admin'] && $license->isLocked()) {
    sendLicenseExpired($audit, $profile, 'oidc');
  }

  $audit->logQuiet('auth.login', ['id' => $userId, 'username' => (string)$profile['username']], 'user', $userId, (string)$profile['username'], null, ['method' => 'oidc']);
  echo json_encode([
    'status' => 'success',
    'token' => $auth->issueToken($userId),
    'expires_in' => $auth->lifetime(),
    'user' => $account
  ]);
}

//---------------------------------------------------------------------------
/**
 * Holds the audit log of the request for the helper functions below (they have no other way to reach it).
 *
 * @param AuditLog|null $set The audit log to remember; null to only read it
 *
 * @return AuditLog|null
 */
function auditLog(?AuditLog $set = null): ?AuditLog
{
  static $audit = null;
  if ($set !== null) {
    $audit = $set;
  }

  return $audit;
}

//---------------------------------------------------------------------------
/**
 * Sends a JSON error response and ends the request.
 *
 * @param int    $status  HTTP status code
 * @param string $message The message
 *
 * @return never
 */
function sendError(int $status, string $message): never
{
  // A denied request is a security event (401 is not: that is a missing or expired login)
  if ($status === 403) {
    auditLog()?->logFailure(
      'security.permission_denied',
      null,
      'request',
      null,
      ($_SERVER['REQUEST_METHOD'] ?? '-') . ' ' . explode('?', (string)($_SERVER['REQUEST_URI'] ?? '-'))[0],
      null,
      ['message' => $message]
    );
  }
  http_response_code($status);
  echo json_encode(['status' => 'error', 'message' => $message]);
  exit;
}

//---------------------------------------------------------------------------
/**
 * Ends the request with 402 and the code license_limit: the license does not allow it (limited mode).
 *
 * @param string $message The message
 *
 * @return never
 */
function sendLicenseLimit(string $message): never
{
  http_response_code(402);
  echo json_encode(['status' => 'error', 'code' => 'license_limit', 'message' => $message]);
  exit;
}

//---------------------------------------------------------------------------
/**
 * Ends the request with 402 unless the license allows the feature.
 *
 * @param LicenseFeatures $features The license features
 * @param string          $feature  One of LicenseFeatures::SWITCHED
 *
 * @return void
 */
function requireLicensedFeature(LicenseFeatures $features, string $feature): void
{
  if (!$features->allows($feature)) {
    sendLicenseLimit(LicenseFeatures::MESSAGE_FEATURE);
  }
}

//---------------------------------------------------------------------------
/**
 * Returns the logged-in user or ends the request with 401.
 *
 * @param AuthService $auth The authentication service
 *
 * @return array
 */
function requireUser(AuthService $auth): array
{
  return $auth->currentUser() ?? sendError(401, 'Authentication required');
}

//---------------------------------------------------------------------------
/**
 * Returns the logged-in user holding a permission or ends the request with 401 or 403.
 * Administrators hold every permission.
 *
 * @param AuthService $auth       The authentication service
 * @param string      $permission The permission name
 *
 * @return array
 */
function requirePermission(AuthService $auth, string $permission): array
{
  $user = requireUser($auth);
  return $auth->can($permission) ? $user : sendError(403, 'Permission required: ' . $permission);
}

//---------------------------------------------------------------------------
/**
 * Returns the logged-in user holding at least one of the permissions or ends the request with 401 or 403.
 *
 * @param AuthService $auth        The authentication service
 * @param string[]    $permissions The permission names
 *
 * @return array
 */
function requireAnyPermission(AuthService $auth, array $permissions): array
{
  $user = requireUser($auth);
  return $auth->canAny($permissions) ? $user : sendError(403, 'Permission required: ' . implode(' or ', $permissions));
}

//---------------------------------------------------------------------------
/**
 * Returns the logged-in administrator or ends the request with 401 or 403.
 *
 * @param AuthService $auth The authentication service
 *
 * @return array
 */
function requireAdmin(AuthService $auth): array
{
  $user = requireUser($auth);
  return $user['is_admin'] ? $user : sendError(403, 'Administrator role required');
}

//---------------------------------------------------------------------------
/**
 * Sends the maintenance response (503) and ends the request.
 *
 * @param string $message The message
 *
 * @return never
 */
function sendMaintenance(string $message = 'The application is under maintenance'): never
{
  http_response_code(503);
  header('Retry-After: 300');
  echo json_encode(['status' => 'error', 'message' => $message, 'maintenance' => true]);
  exit;
}

//---------------------------------------------------------------------------
/**
 * Sends the license-expired response (403) to a user who is not an administrator and ends the request.
 *
 * @param AuditLog $audit  The audit log
 * @param array    $user   The user who logged in (id, username)
 * @param string   $method How the user logged in
 *
 * @return never
 */
function sendLicenseExpired(AuditLog $audit, array $user, string $method): never
{
  $audit->logFailure('auth.login_failed', ['id' => (int)$user['id'], 'username' => (string)$user['username']], 'user', (int)$user['id'], (string)$user['username'], null, ['method' => $method, 'reason' => 'license_expired']);
  http_response_code(403);
  echo json_encode(['status' => 'error', 'message' => 'The license has expired. Only administrators can log in.', 'reason' => 'license_expired']);
  exit;
}

//---------------------------------------------------------------------------
/**
 * Tells whether a request may pass while the maintenance mode is on.
 *
 * @param string $path   The request path
 * @param string $method The HTTP method
 *
 * @return bool
 */
function isMaintenanceExempt(string $path, string $method): bool
{
  // The 2FA steps of a login (only administrators can get this far in maintenance mode; "begin" needs a session)
  if (in_array($path, ['/auth/login', '/auth/oidc/login', '/auth/oidc/callback', '/auth/oidc/exchange', '/auth/logout', '/auth/me', '/auth/session', '/auth/2fa/verify', '/auth/2fa/setup', '/auth/2fa/enable', '/test'], true)) {
    return true;
  }

  return $method === 'GET' && ($path === '/settings' || $path === '/license/public' || str_starts_with($path, '/avatars/'));
}
