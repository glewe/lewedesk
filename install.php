<?php

declare(strict_types=1);

/**
 * LeweDesk web installer
 *
 * Run it once in the browser after you have created an empty database and copied the files to the web server:
 * checks the server, tests the database connection, writes the .env file, creates the tables (db/db_init.sql),
 * optionally loads the sample data and locks itself with the file install.lock.
 *
 * Delete this file after the installation (the result page has a button for it). See INSTALL.md.
 *
 * Self-contained on purpose: no Composer, no libraries, and no dependency on the application code, so it also runs
 * when the application cannot start yet (for example because there is no .env file).
 */

const MIN_PHP = '8.1.0';

$root = __DIR__;
$lockFile = $root . '/install.lock';
$envFile = $root . '/.env';
$initSql = $root . '/db/db_init.sql';
$sampleSql = [$root . '/db/db_sample.sql', $root . '/db/db_sample_audit.sql'];

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; form-action 'self'; base-uri 'none'");
header('Referrer-Policy: no-referrer');

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

//---------------------------------------------------------------------------
/**
 * Escapes text for HTML.
 *
 * @param mixed $text The text
 *
 * @return string
 */
function h(mixed $text): string
{
  return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

//---------------------------------------------------------------------------
/**
 * Checks the server. Every check is [label, ok, detail, blocking]; a failed blocking check stops the installation.
 *
 * @param string $root The installation folder
 *
 * @return array<int, array{0: string, 1: bool, 2: string, 3: bool}>
 */
function checkServer(string $root): array
{
  $checks = [];
  $checks[] = ['PHP ' . MIN_PHP . ' or newer', version_compare(PHP_VERSION, MIN_PHP, '>='), 'Found ' . PHP_VERSION, true];
  foreach (['pdo_mysql' => 'database access', 'mbstring' => 'text handling', 'openssl' => 'encryption of 2FA secrets', 'curl' => 'license server, OIDC', 'json' => 'API', 'ctype' => 'input checks'] as $ext => $why) {
    $checks[] = ['PHP extension ' . $ext, extension_loaded($ext), $why, true];
  }
  $checks[] = ['PHP extension ldap (optional)', extension_loaded('ldap'), 'Only needed for LDAP login', false];

  $checks[] = ['File db/db_init.sql', is_readable($root . '/db/db_init.sql'), 'The database structure', true];
  $checks[] = ['File api/index.php', is_readable($root . '/api/index.php'), 'The API', true];
  $checks[] = ['File index.html', is_readable($root . '/index.html'), 'The application pages', true];

  $envWritable = file_exists($root . '/.env') ? is_writable($root . '/.env') : is_writable($root);
  $checks[] = ['Can write .env', $envWritable, 'The folder (or an existing .env) must be writable by the web server user during the installation', true];
  $checks[] = ['Can write install.lock', is_writable($root), 'Locks the installer after a successful installation', true];

  $uploads = $root . '/uploads';
  if (!is_dir($uploads)) {
    @mkdir($uploads, 0775, true);
  }
  $checks[] = ['Can write uploads/', is_dir($uploads) && is_writable($uploads), 'Avatars and attachments are stored here', true];

  if (function_exists('apache_get_modules')) {
    $checks[] = ['Apache mod_rewrite', in_array('mod_rewrite', apache_get_modules(), true), 'The API needs the rewrite rule in api/.htaccess', false];
  }

  return $checks;
}

//---------------------------------------------------------------------------
/**
 * Guesses the address of the application from the request (the folder of this script).
 *
 * @return string No trailing slash
 */
function guessUrl(): string
{
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
  $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
  $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');

  return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

//---------------------------------------------------------------------------
/**
 * Runs an SQL file with several statements and reports the first error.
 *
 * @param PDO    $pdo  The connection (exceptions on)
 * @param string $file The file
 *
 * @return void
 */
function runSqlFile(PDO $pdo, string $file): void
{
  $sql = file_get_contents($file);
  if ($sql === false || trim($sql) === '') {
    throw new RuntimeException('Cannot read ' . basename($file));
  }
  // The statements are sent in one go; nextRowset() makes PHP raise the error of a later statement
  $stmt = $pdo->query($sql);
  do {
    // nothing to fetch, only to advance
  } while ($stmt->nextRowset());
  $stmt->closeCursor();
}

//---------------------------------------------------------------------------
/**
 * Reads the values of an existing .env file (the same rules as the application: NAME=value, quotes at both ends are
 * removed, lines starting with # are skipped).
 *
 * @param string $file The .env file
 *
 * @return array<string, string>
 */
function readEnvFile(string $file): array
{
  $values = [];
  if (!is_readable($file)) {
    return $values;
  }
  foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
      continue;
    }
    [$name, $value] = explode('=', $line, 2);
    $values[trim($name)] = trim($value, " \t\n\r\0\x0B\"'");
  }

  return $values;
}

//---------------------------------------------------------------------------
/**
 * Formats a value for the .env file. The loader of the application strips quotes at both ends, so a value that
 * starts or ends with a quote cannot be stored (checked beforehand).
 *
 * @param string $value The value
 *
 * @return string
 */
function envValue(string $value): string
{
  return preg_match('/^[A-Za-z0-9_.:\/@+=-]*$/', $value) ? $value : '"' . $value . '"';
}

//---------------------------------------------------------------------------
/**
 * Builds the content of the .env file.
 *
 * @param array<string, string> $v The values
 *
 * @return string
 */
function buildEnv(array $v): string
{
  $mail = $v['mail_host'] !== ''
    ? "MAIL_HOST=" . envValue($v['mail_host']) . "\nMAIL_PORT=" . envValue($v['mail_port']) . "\nMAIL_USER=" . envValue($v['mail_user']) . "\nMAIL_PASS=" . envValue($v['mail_pass']) . "\nMAIL_FROM=" . envValue($v['mail_from']) . "\n"
    : "# MAIL_HOST=smtp.example.com\n# MAIL_PORT=587\n# MAIL_USER=notifications@example.com\n# MAIL_PASS=password\n# MAIL_FROM=notifications@example.com\n";

  return "# LeweDesk configuration, written by install.php on " . date('Y-m-d H:i') . "\n"
    . "# Keep this file private. Optional settings (LDAP, OIDC, ...) are described in .env.example.\n"
    . "# Do not put comments on the same line as a value.\n\n"
    . "# APP\nAPP_NAME=LeweDesk\nAPP_ENV=production\nAPP_URL=" . envValue($v['app_url']) . "\n\n"
    . "# DATABASE\nDB_HOST=" . envValue($v['db_host']) . "\nDB_PORT=" . envValue($v['db_port']) . "\nDB_NAME=" . envValue($v['db_name']) . "\nDB_USER=" . envValue($v['db_user']) . "\nDB_PASS=" . envValue($v['db_pass']) . "\n\n"
    . "# SECURITY\nSESSION_EXPIRATION=7200\n\n"
    . "# MAIL (SMTP; without MAIL_HOST no emails are sent)\n" . $mail . "\n"
    . "# TWO FACTOR AUTHENTICATION\n# Encrypts the TOTP secrets in the database. Do NOT change it once users have set up 2FA, and keep a backup of it.\n"
    . "TWOFA_ENCRYPTION_KEY=" . $v['twofa_key'] . "\nDISABLE_ADMIN_2FA=false\n\n"
    . "# LOGIN THROTTLE (events per address within the window; 0 = off)\nAUTH_THROTTLE_MAX=10\nAUTH_THROTTLE_WINDOW=900\n\n"
    . "# LICENSE\nLICENSE_SERVER=https://lewelic.lewe.com\nLICENSE_ITEM=LeweDesk\n";
}

//---------------------------------------------------------------------------
/**
 * Renders a page.
 *
 * @param string $title The heading
 * @param string $body  The HTML of the content
 *
 * @return void
 */
function page(string $title, string $body): void
{
  echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<meta name="robots" content="noindex, nofollow"><title>' . h($title) . ' - LeweDesk</title><link rel="icon" href="favicon.svg">'
    . '<style>
:root{--bg:#f4f5f8;--card:#fff;--text:#1f2430;--muted:#667085;--line:#dfe2ea;--accent:#5856d6;--accent-text:#fff;--ok:#1b9e3e;--warn:#b7791f;--bad:#d92d20;--field:#fff}
@media (prefers-color-scheme:dark){:root{--bg:#14161c;--card:#1d2028;--text:#e8eaf0;--muted:#9aa3b2;--line:#2e3340;--accent:#7d7bf0;--accent-text:#10111a;--ok:#4cc46a;--warn:#e0b04a;--bad:#ff7a70;--field:#14161c}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
main{max-width:720px;margin:0 auto;padding:24px 16px 48px}
header{display:flex;align-items:center;gap:12px;margin-bottom:16px}header img{width:40px;height:40px}h1{font-size:1.4rem;margin:0}h2{font-size:1.05rem;margin:0 0 12px}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:18px;margin-bottom:16px}
.muted{color:var(--muted);font-size:.9rem}
table{width:100%;border-collapse:collapse}td{padding:6px 4px;border-top:1px solid var(--line);vertical-align:top}tr:first-child td{border-top:0}td:first-child{width:1.6em;font-weight:700}
.ok{color:var(--ok)}.warn{color:var(--warn)}.bad{color:var(--bad)}
label{display:block;font-weight:600;margin:12px 0 4px}input[type=text],input[type=password],input[type=email],input[type=number]{width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:6px;background:var(--field);color:var(--text);font:inherit}
.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media (max-width:560px){.row{grid-template-columns:1fr}}
.check{display:flex;gap:8px;align-items:flex-start;font-weight:400;margin:10px 0}.check input{margin-top:5px}
button{background:var(--accent);color:var(--accent-text);border:0;border-radius:6px;padding:10px 18px;font:inherit;font-weight:600;cursor:pointer}button[disabled]{opacity:.5;cursor:not-allowed}
.alert{border:1px solid;border-radius:8px;padding:10px 12px;margin-bottom:16px}.alert.bad{border-color:var(--bad)}.alert.warn{border-color:var(--warn)}.alert.good{border-color:var(--ok)}
details summary{cursor:pointer;font-weight:600;margin-top:12px}code{background:var(--bg);padding:1px 5px;border-radius:4px}
</style></head><body><main><header><img src="favicon.svg" alt=""><h1>' . h($title) . '</h1></header>' . $body . '</main></body></html>';
}

//---------------------------------------------------------------------------
// Installed already: nothing but the button that removes this file
if (file_exists($lockFile)) {
  http_response_code(403);
  $msg = '';
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? '')) && ($_POST['action'] ?? '') === 'delete_installer') {
    $msg = @unlink(__FILE__)
      ? '<div class="alert good">install.php was deleted.</div>'
      : '<div class="alert bad">install.php could not be deleted. Delete it by hand.</div>';
  }
  page('Already installed', $msg . '<div class="card"><p>LeweDesk is installed already, the installer is locked. To run it again, delete the file <code>install.lock</code> first.</p>'
    . (file_exists(__FILE__) ? '<form method="post"><input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '"><input type="hidden" name="action" value="delete_installer"><button>Delete install.php</button></form>' : '')
    . '<p><a href="./">Open LeweDesk</a></p></div>');
  exit;
}

$checks = checkServer($root);
$blocked = false;
foreach ($checks as $c) {
  if (!$c[1] && $c[3]) {
    $blocked = true;
  }
}

$existingEnv = readEnvFile($envFile);
$defaults = [
  'db_host' => $existingEnv['DB_HOST'] ?? 'localhost', 'db_port' => $existingEnv['DB_PORT'] ?? '3306', 'db_name' => $existingEnv['DB_NAME'] ?? 'lewedesk',
  'db_user' => $existingEnv['DB_USER'] ?? '', 'db_pass' => $existingEnv['DB_PASS'] ?? '',
  'app_url' => $existingEnv['APP_URL'] ?? guessUrl(),
  'admin_email' => '', 'admin_pass' => '', 'admin_pass2' => '',
  'mail_host' => '', 'mail_port' => '587', 'mail_user' => '', 'mail_pass' => '', 'mail_from' => '',
];
$in = $defaults;
$errors = [];
$done = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  foreach ($defaults as $k => $_) {
    $in[$k] = trim((string)($_POST[$k] ?? ''));
  }
  $in['db_pass'] = (string)($_POST['db_pass'] ?? '');
  $in['admin_pass'] = (string)($_POST['admin_pass'] ?? '');
  $in['admin_pass2'] = (string)($_POST['admin_pass2'] ?? '');
  $in['mail_pass'] = (string)($_POST['mail_pass'] ?? '');
  $withSamples = !empty($_POST['samples']);
  $overwrite = !empty($_POST['overwrite']);
  $replaceEnv = !empty($_POST['replace_env']);

  if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
    $errors[] = 'The form has expired. Please try again.';
  }
  if ($blocked) {
    $errors[] = 'The server does not meet the requirements above.';
  }

  // Input checks
  if ($in['db_host'] === '' || $in['db_name'] === '' || $in['db_user'] === '') {
    $errors[] = 'Database host, name and user are required.';
  }
  if (!preg_match('/^\d{1,5}$/', $in['db_port'])) {
    $errors[] = 'The database port must be a number.';
  }
  if (!preg_match('#^https?://[^\s"\']+$#i', $in['app_url'])) {
    $errors[] = 'The application address must start with http:// or https://.';
  }
  $in['app_url'] = rtrim($in['app_url'], '/');
  foreach (['db_host', 'db_name', 'db_user', 'db_pass', 'app_url', 'mail_host', 'mail_port', 'mail_user', 'mail_pass', 'mail_from'] as $k) {
    if (preg_match('/[\r\n]/', $in[$k])) {
      $errors[] = 'Line breaks are not allowed in the settings.';
      break;
    }
  }
  foreach (['db_pass', 'mail_pass', 'mail_user'] as $k) {
    if ($in[$k] !== '' && (str_contains('"\'', $in[$k][0]) || str_contains('"\'', substr($in[$k], -1)))) {
      $errors[] = 'A password or user name must not start or end with a quote character (the .env file cannot store it).';
      break;
    }
  }
  if ($in['mail_host'] !== '' && ($in['mail_from'] === '' || !filter_var($in['mail_from'], FILTER_VALIDATE_EMAIL))) {
    $errors[] = 'Enter a valid sender address for the email settings, or leave the SMTP host empty.';
  }
  if ($in['mail_host'] !== '' && !preg_match('/^\d{1,5}$/', $in['mail_port'])) {
    $errors[] = 'The SMTP port must be a number.';
  }
  if ($in['admin_email'] !== '' && !filter_var($in['admin_email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'The administrator email address is not valid.';
  }
  if ($in['admin_pass'] !== '' && strlen($in['admin_pass']) < 8) {
    $errors[] = 'The administrator password must have at least 8 characters.';
  }
  if ($in['admin_pass'] !== $in['admin_pass2']) {
    $errors[] = 'The two administrator passwords differ.';
  }

  // Connect
  $pdo = null;
  if (!$errors) {
    try {
      $pdo = new PDO(
        'mysql:host=' . $in['db_host'] . ';port=' . $in['db_port'] . ';dbname=' . $in['db_name'] . ';charset=utf8mb4',
        $in['db_user'],
        $in['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]
      );
    } catch (PDOException $e) {
      $errors[] = 'The database connection failed: ' . $e->getMessage() . ' (Is the database created and does the user have all rights on it?)';
    }
  }

  // Existing LeweDesk tables
  $fresh = true;
  if ($pdo && !$errors) {
    $existing = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'")->fetchColumn();
    if ($existing > 0) {
      $fresh = false;
      if (!$overwrite) {
        $errors[] = 'The database contains LeweDesk tables already. To add missing tables and upgrade the structure (existing data is kept), tick "Use the existing database".';
      }
    }
  }

  // Install
  if ($pdo && !$errors) {
    set_time_limit(300);
    try {
      runSqlFile($pdo, $initSql);
      $sampleDone = false;
      if ($withSamples) {
        foreach ($sampleSql as $file) {
          if (is_readable($file)) {
            runSqlFile($pdo, $file);
            $sampleDone = true;
          }
        }
      }

      // Administrator: only set when the account still has the initial password
      $adminNote = 'The administrator account is <code>admin</code> with the password <code>admin</code>.';
      $adminDefault = false;
      $row = $pdo->query("SELECT id, password FROM users WHERE username = 'admin'")->fetch(PDO::FETCH_ASSOC);
      if ($row && password_verify('admin', (string)$row['password'])) {
        if ($in['admin_pass'] !== '') {
          $pdo->prepare("UPDATE users SET password = :p WHERE id = :id")->execute(['p' => password_hash($in['admin_pass'], PASSWORD_DEFAULT), 'id' => $row['id']]);
          $adminNote = 'Log in as <code>admin</code> with the password you entered.';
        } else {
          $adminDefault = true;
        }
      }
      if ($row && $in['admin_email'] !== '') {
        try {
          $pdo->prepare("UPDATE users SET email = :e WHERE id = :id")->execute(['e' => $in['admin_email'], 'id' => $row['id']]);
        } catch (PDOException $e) {
          // The address is used by another account: keep the old one
        }
      }

      // A new installation gets its own address (links in emails) and support address; an existing one keeps its settings
      if ($fresh) {
        $set = $pdo->prepare("UPDATE settings SET `value` = :v WHERE `key` = :k");
        $set->execute(['v' => $in['app_url'], 'k' => 'application_url']);
        if ($in['admin_email'] !== '') {
          $set->execute(['v' => $in['admin_email'], 'k' => 'support_email']);
        }
      }

      // .env: an existing file is kept unless it is to be replaced (then a backup stays, and the 2FA key is reused:
      // a new one would make the 2FA secrets in the database unreadable)
      $envKept = file_exists($envFile) && !$replaceEnv;
      if (!$envKept) {
        $twofaKey = (string)(readEnvFile($envFile)['TWOFA_ENCRYPTION_KEY'] ?? '');
        if (strlen((string)base64_decode($twofaKey, true)) < 32) {
          $twofaKey = base64_encode(random_bytes(32));
        }
        if (file_exists($envFile)) {
          @copy($envFile, $root . '/.env.backup-' . date('Ymd-His'));
        }
        if (file_put_contents($envFile, buildEnv($in + ['twofa_key' => $twofaKey]), LOCK_EX) === false) {
          throw new RuntimeException('The .env file could not be written. The database is ready; create the file by hand (see .env.example) or make the folder writable and run the installer again with "Use the existing database".');
        }
        @chmod($envFile, 0640);
      }

      file_put_contents($lockFile, "Installed " . date('c') . "\n");
      $done = ['admin' => $adminNote, 'adminDefault' => $adminDefault, 'samples' => $sampleDone, 'mail' => $in['mail_host'] !== '' || $envKept, 'envKept' => $envKept];
    } catch (Throwable $e) {
      $errors[] = 'The installation stopped: ' . $e->getMessage();
    }
  }
}

//---------------------------------------------------------------------------
// Result
if ($done !== null) {
  $body = '<div class="alert good"><strong>LeweDesk is installed.</strong></div><div class="card"><h2>Next steps</h2><ol>'
    . '<li>' . $done['admin'] . '</li>'
    . ($done['adminDefault'] ? '<li class="bad"><strong>Change the administrator password right after your first login</strong> (profile page). The initial password is public knowledge.</li>' : '')
    . '<li><strong>Delete <code>install.php</code></strong> from the server (button below). It is locked by <code>install.lock</code>, but it does not belong on a production server.</li>'
    . '<li>Back up your <code>.env</code> file, especially <code>TWOFA_ENCRYPTION_KEY</code>: without it two factor secrets cannot be read any more.</li>'
    . ($done['envKept'] ? '<li>The existing <code>.env</code> file was kept as it is.</li>' : '')
    . ($done['mail'] ? '' : '<li>No email settings were entered: LeweDesk sends no emails (notifications, password reset) until you add <code>MAIL_*</code> to <code>.env</code>.</li>')
    . ($done['samples'] ? '<li class="warn">The sample data was loaded (sample users have the password <code>Qwer!1234</code>). Do not use it on a production system.</li>' : '')
    . '<li>Optional: LDAP, single sign-on and a cron job are described in <code>INSTALL.md</code>.</li></ol>'
    . '<form method="post" action="install.php"><input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '"><input type="hidden" name="action" value="delete_installer"><button>Delete install.php</button></form>'
    . '<p><a href="./">Open LeweDesk</a></p></div>';
  page('Installation complete', $body);
  exit;
}

//---------------------------------------------------------------------------
// Form
$body = '';
if ($errors) {
  $body .= '<div class="alert bad"><strong>Not installed yet.</strong><ul>';
  foreach ($errors as $e) {
    $body .= '<li>' . h($e) . '</li>';
  }
  $body .= '</ul></div>';
}

$body .= '<div class="card"><h2>1. Server check</h2><table>';
foreach ($checks as [$label, $ok, $detail, $blocking]) {
  $mark = $ok ? '<span class="ok">&#10003;</span>' : ($blocking ? '<span class="bad">&#10007;</span>' : '<span class="warn">!</span>');
  $body .= '<tr><td>' . $mark . '</td><td>' . h($label) . '<div class="muted">' . h($detail) . '</div></td></tr>';
}
$body .= '</table>' . ($blocked ? '<p class="bad"><strong>Fix the failed checks first, then reload this page.</strong></p>' : '') . '</div>';

$f = static fn(string $k, string $type = 'text', string $extra = ''): string => '<input type="' . $type . '" name="' . $k . '" id="' . $k . '" value="' . ($type === 'password' && $k !== 'db_pass' ? '' : h($in[$k])) . '" ' . $extra . '>';

$body .= '<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">'
  . '<div class="card"><h2>2. Database</h2><p class="muted">Create an empty database with the character set utf8mb4 and a user with all rights on it before you continue (see INSTALL.md).</p>'
  . '<div class="row"><div><label for="db_host">Host</label>' . $f('db_host', 'text', 'required') . '</div><div><label for="db_port">Port</label>' . $f('db_port', 'text', 'required inputmode="numeric"') . '</div></div>'
  . '<label for="db_name">Database name</label>' . $f('db_name', 'text', 'required')
  . '<div class="row"><div><label for="db_user">User</label>' . $f('db_user', 'text', 'required') . '</div><div><label for="db_pass">Password</label>' . $f('db_pass', 'password', 'autocomplete="new-password"') . '</div></div>'
  . '<label class="check"><input type="checkbox" name="overwrite" value="1"' . (!empty($_POST['overwrite']) ? ' checked' : '') . '><span>Use the existing database if it already contains LeweDesk tables (missing tables are added, existing data is kept).</span></label>'
  . '</div>'
  . '<div class="card"><h2>3. Application</h2>'
  . '<label for="app_url">Address of LeweDesk</label>' . $f('app_url', 'text', 'required') . '<div class="muted">Without a trailing slash. Used for the links in emails.</div>'
  . '<div class="row"><div><label for="admin_email">Administrator email (optional)</label>' . $f('admin_email', 'email') . '</div><div></div></div>'
  . '<div class="row"><div><label for="admin_pass">Administrator password (optional)</label>' . $f('admin_pass', 'password', 'autocomplete="new-password"') . '</div><div><label for="admin_pass2">Repeat password</label>' . $f('admin_pass2', 'password', 'autocomplete="new-password"') . '</div></div>'
  . '<div class="muted">At least 8 characters. If you leave it empty, the account <code>admin</code> keeps the password <code>admin</code> and you must change it after the first login.</div>'
  . '<details' . ($in['mail_host'] !== '' ? ' open' : '') . '><summary>Email settings (optional, can be added to .env later)</summary>'
  . '<div class="row"><div><label for="mail_host">SMTP host</label>' . $f('mail_host') . '</div><div><label for="mail_port">SMTP port</label>' . $f('mail_port', 'text', 'inputmode="numeric"') . '</div></div>'
  . '<div class="row"><div><label for="mail_user">SMTP user</label>' . $f('mail_user') . '</div><div><label for="mail_pass">SMTP password</label>' . $f('mail_pass', 'password', 'autocomplete="new-password"') . '</div></div>'
  . '<label for="mail_from">Sender address</label>' . $f('mail_from', 'email') . '</details>'
  . '</div>'
  . '<div class="card"><h2>4. Options</h2>'
  . (is_readable($sampleSql[0]) ? '<label class="check"><input type="checkbox" name="samples" value="1"' . (!empty($_POST['samples']) ? ' checked' : '') . '><span>Load the sample data (users, issues, audit log). For trying LeweDesk out only, not for production.</span></label>' : '')
  . (file_exists($envFile) ? '<label class="check"><input type="checkbox" name="replace_env" value="1"' . (!empty($_POST['replace_env']) ? ' checked' : '') . '><span>A <code>.env</code> file exists already and is kept. Tick to replace it with the values above instead (a backup copy and the 2FA key are kept).</span></label>' : '')
  . '<button type="submit"' . ($blocked ? ' disabled' : '') . '>Install LeweDesk</button></div></form>';

page('LeweDesk installation', $body);
