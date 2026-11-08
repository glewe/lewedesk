-- LeweDesk sample audit log entries for development (not part of db_init.sql; run with: npm run deploy:local:samples)
--
-- About 300 entries spread over the last 90 days (all categories, the sample users of db_init.sql as actors, successes
-- and failures, masked IP addresses), plus 6 entries older than a year to try out the retention. All entries carry
-- {"sample":true} in new_value. The script does nothing when such entries exist already, so it can be run again.
-- To start over: DELETE FROM audit_log WHERE new_value LIKE '%"sample":true%';
-- Do not run it on a production database.

SET @has_audit_samples = (SELECT COUNT(*) FROM audit_log WHERE new_value LIKE '%"sample":true%');

-- Numbers 1 to 306
DROP TEMPORARY TABLE IF EXISTS sample_seq;
CREATE TEMPORARY TABLE sample_seq (n INT PRIMARY KEY);
INSERT INTO sample_seq (n)
SELECT a.d * 100 + b.d * 10 + c.d + 1
FROM (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a,
     (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b,
     (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) c
WHERE a.d * 100 + b.d * 10 + c.d + 1 <= 306;

-- The users that act (and that are acted on)
DROP TEMPORARY TABLE IF EXISTS sample_users;
CREATE TEMPORARY TABLE sample_users (rn INT AUTO_INCREMENT PRIMARY KEY, id INT NOT NULL, username VARCHAR(50) NOT NULL);
INSERT INTO sample_users (id, username) SELECT id, username FROM users ORDER BY id;
-- A second copy: MySQL cannot join a temporary table to itself
DROP TEMPORARY TABLE IF EXISTS sample_targets;
CREATE TEMPORARY TABLE sample_targets (rn INT PRIMARY KEY, id INT NOT NULL, username VARCHAR(50) NOT NULL);
INSERT INTO sample_targets (rn, id, username) SELECT rn, id, username FROM sample_users;
SET @sample_user_count = (SELECT COUNT(*) FROM sample_users);

-- The kinds of events: target 'user' = another user, otherwise the fixed label. The old and new value are JSON.
DROP TEMPORARY TABLE IF EXISTS sample_events;
CREATE TEMPORARY TABLE sample_events (
  rn INT AUTO_INCREMENT PRIMARY KEY,
  event VARCHAR(50) NOT NULL,
  category VARCHAR(20) NOT NULL,
  outcome VARCHAR(10) NOT NULL,
  target_type VARCHAR(30) NULL,
  target_label VARCHAR(255) NULL,
  old_value TEXT NULL,
  new_value TEXT NULL
);
INSERT INTO sample_events (event, category, outcome, target_type, target_label, old_value, new_value) VALUES
('auth.login', 'auth', 'success', NULL, NULL, NULL, NULL),
('auth.login', 'auth', 'success', NULL, NULL, NULL, NULL),
('auth.login', 'auth', 'success', NULL, NULL, NULL, NULL),
('auth.logout', 'auth', 'success', NULL, NULL, NULL, NULL),
('auth.login_failed', 'auth', 'failure', 'user', 'user', NULL, NULL),
('auth.password_changed', 'auth', 'success', 'user', 'user', '{"password":"***"}', '{"password":"***"}'),
('auth.password_reset_requested', 'auth', 'success', 'user', 'user', NULL, NULL),
('auth.password_reset_completed', 'auth', 'success', 'user', 'user', NULL, NULL),
('auth.2fa_enabled', 'auth', 'success', 'user', 'user', NULL, NULL),
('auth.2fa_disabled', 'auth', 'success', 'user', 'user', NULL, NULL),
('auth.2fa_reset', 'auth', 'success', 'user', 'user', NULL, NULL),
('auth.2fa_failed', 'auth', 'failure', 'user', 'user', NULL, NULL),
('auth.2fa_locked', 'auth', 'failure', 'user', 'user', NULL, NULL),
('user.created', 'user', 'success', 'user', 'user', NULL, NULL),
('user.updated', 'user', 'success', 'user', 'user', '{"email":"old@example.com"}', '{"email":"new@example.com"}'),
('user.roles_changed', 'user', 'success', 'user', 'user', '{"roles":["User"]}', '{"roles":["Agent"]}'),
('user.groups_changed', 'user', 'success', 'user', 'user', '{"groups":["Scientists"]}', '{"groups":["Scientists","Pilots"]}'),
('user.deleted', 'user', 'success', 'user', 'former_user', NULL, NULL),
('setting.changed', 'config', 'success', 'setting', 'notification_retention_days', '{"value":"90"}', '{"value":"60"}'),
('setting.changed', 'config', 'success', 'setting', 'support_email', '{"value":"support@lewe.com"}', '{"value":"help@lewe.com"}'),
('setting.changed', 'config', 'success', 'setting', 'smtp_password', '{"value":"***"}', '{"value":"***"}'),
('role.updated', 'config', 'success', 'role', 'Agent', '{"description":"Handles tickets."}', '{"description":"Handles tickets and supports users."}'),
('group.created', 'config', 'success', 'group', 'Sample Group', NULL, '{"name":"Sample Group"}'),
('permission.updated', 'config', 'success', 'permission', 'edit_issue', '{"roles":["Administrator"]}', '{"roles":["Administrator","Agent"]}'),
('product.created', 'config', 'success', 'product', 'Sample App', NULL, '{"name":"Sample App"}'),
('tag.deleted', 'config', 'success', 'tag', 'obsolete', '{"name":"obsolete"}', NULL),
('status.updated', 'config', 'success', 'status', 'In Progress', '{"color":"#3399ff"}', '{"color":"#f9b115"}'),
('issue.deleted', 'data', 'success', 'issue', 'Sample: Login page shows an error', NULL, NULL),
('issue.restored', 'data', 'success', 'issue', 'Sample: Login page shows an error', NULL, NULL),
('attachment.deleted', 'data', 'success', 'attachment', 'screenshot.png', NULL, NULL),
('security.permission_denied', 'security', 'failure', 'request', 'GET /users', NULL, '{"permission":"manage_users"}'),
('security.permission_denied', 'security', 'failure', 'request', 'POST /settings', NULL, '{"permission":"manage_settings"}'),
('security.2fa_pending_invalid', 'security', 'failure', NULL, NULL, NULL, NULL),
('audit.exported', 'audit', 'success', NULL, NULL, NULL, '{"format":"csv"}');
SET @sample_event_count = (SELECT COUNT(*) FROM sample_events);

-- The 300 entries of the last 90 days
INSERT INTO audit_log (created_at, actor_id, actor_username, event, category, outcome, target_type, target_id, target_label, old_value, new_value, ip, user_agent, request_id)
SELECT
  NOW() - INTERVAL (s.n * 432 + (s.n * 37) % 400) MINUTE,
  a.id,
  a.username,
  e.event,
  e.category,
  e.outcome,
  e.target_type,
  IF(e.target_label = 'user', t.id, NULL),
  IF(e.target_label = 'user', t.username, e.target_label),
  e.old_value,
  CONCAT(IFNULL(CONCAT(LEFT(e.new_value, CHAR_LENGTH(e.new_value) - 1), ','), '{'), '"sample":true}'),
  CONCAT('10.', s.n % 20, '.', (s.n * 7) % 250, '.0'),
  ELT(s.n % 3 + 1, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Firefox/131.0', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15', 'Mozilla/5.0 (X11; Linux x86_64) Chrome/130.0 Safari/537.36'),
  LEFT(MD5(s.n), 16)
FROM sample_seq s
JOIN sample_events e ON e.rn = (s.n * 5) % @sample_event_count + 1
JOIN sample_users a ON a.rn = (s.n * 3) % @sample_user_count + 1
JOIN sample_targets t ON t.rn = (s.n * 7 + 1) % @sample_user_count + 1
WHERE @has_audit_samples = 0 AND s.n <= 300;

-- 6 entries older than a year (deleted by the default retention of 365 days)
INSERT INTO audit_log (created_at, actor_id, actor_username, event, category, outcome, target_type, target_id, target_label, new_value, ip, user_agent, request_id)
SELECT
  NOW() - INTERVAL (370 + s.n * 20) DAY,
  a.id,
  a.username,
  'auth.login',
  'auth',
  'success',
  NULL,
  NULL,
  NULL,
  '{"sample":true}',
  CONCAT('10.99.', s.n, '.0'),
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Firefox/131.0',
  LEFT(MD5(CONCAT('old', s.n)), 16)
FROM sample_seq s
JOIN sample_users a ON a.rn = s.n % @sample_user_count + 1
WHERE @has_audit_samples = 0 AND s.n > 300;

DROP TEMPORARY TABLE IF EXISTS sample_events;
DROP TEMPORARY TABLE IF EXISTS sample_targets;
DROP TEMPORARY TABLE IF EXISTS sample_users;
DROP TEMPORARY TABLE IF EXISTS sample_seq;
