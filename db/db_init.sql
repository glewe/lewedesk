-- LeweDesk Database Initialization

-- The database must exist already (character set utf8mb4) and be selected by the connection: install.php,
-- api/setup_db.php and scripts/deploy.js do that. The name is not fixed, so it can be set in .env (DB_NAME).

-- Users Table
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  email VARCHAR(100) NOT NULL UNIQUE,
  first_name VARCHAR(50),
  last_name VARCHAR(50),
  avatar VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Add the avatar column to databases created before Phase 4.2 (works on MySQL and MariaDB)
SET @add_avatar = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE users ADD COLUMN avatar VARCHAR(255) NULL AFTER last_name', 'DO 0')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'avatar'
);
PREPARE add_avatar_stmt FROM @add_avatar;
EXECUTE add_avatar_stmt;
DEALLOCATE PREPARE add_avatar_stmt;

-- Two factor authentication (docs: _docs/2FA.md). twofa_secret is the TOTP secret, encrypted with TWOFA_ENCRYPTION_KEY
-- (never plain text); twofa_enabled_at is set once the user confirmed a code; twofa_last_step is the last accepted
-- 30 second time step (a code is only accepted once). A user has 2FA when twofa_enabled_at is not NULL.
SET @add_twofa_secret = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE users ADD COLUMN twofa_secret VARCHAR(255) NULL AFTER avatar', 'DO 0')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'twofa_secret'
);
PREPARE add_twofa_secret_stmt FROM @add_twofa_secret;
EXECUTE add_twofa_secret_stmt;
DEALLOCATE PREPARE add_twofa_secret_stmt;

SET @add_twofa_enabled_at = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE users ADD COLUMN twofa_enabled_at DATETIME NULL AFTER twofa_secret', 'DO 0')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'twofa_enabled_at'
);
PREPARE add_twofa_enabled_at_stmt FROM @add_twofa_enabled_at;
EXECUTE add_twofa_enabled_at_stmt;
DEALLOCATE PREPARE add_twofa_enabled_at_stmt;

SET @add_twofa_last_step = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE users ADD COLUMN twofa_last_step BIGINT NULL AFTER twofa_enabled_at', 'DO 0')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'twofa_last_step'
);
PREPARE add_twofa_last_step_stmt FROM @add_twofa_last_step;
EXECUTE add_twofa_last_step_stmt;
DEALLOCATE PREPARE add_twofa_last_step_stmt;

-- Initial admin user (password = username). Change the password after the first login.
-- The hash is generated using PHP's password_hash. The sample users are in db_sample.sql.
INSERT IGNORE INTO users (username, password, email, first_name, last_name) VALUES
('admin', '$2y$12$CJLuD1tFHz46BKNWYaiPT.NLXkN7SZHs3.7Yb7PaW/f18nBV/l7Ta', 'admin@example.com', 'System', 'Admin');
 
-- Login tokens (only the SHA-256 hash of the token is stored)
CREATE TABLE IF NOT EXISTS auth_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  INDEX idx_auth_tokens_expires (expires_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Password reset tokens (only the SHA-256 hash of the token is stored)
CREATE TABLE IF NOT EXISTS password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  INDEX idx_password_resets_expires (expires_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- OIDC authentication (docs: _docs/OIDC.md). oidc_sub is the permanent subject identifier of the Identity Provider; it
-- is bound to the account on the first SSO login. NULL (not '') for users without one, so UNIQUE allows many of them.
SET @add_oidc_sub = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE users ADD COLUMN oidc_sub VARCHAR(255) NULL UNIQUE AFTER avatar', 'DO 0')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'oidc_sub'
);
PREPARE add_oidc_sub_stmt FROM @add_oidc_sub;
EXECUTE add_oidc_sub_stmt;
DEALLOCATE PREPARE add_oidc_sub_stmt;

-- State of a login in progress at the Identity Provider (only the SHA-256 hash of state is stored). Valid 10 minutes,
-- used once. code_verifier is the PKCE secret.
CREATE TABLE IF NOT EXISTS oidc_states (
  state_hash CHAR(64) PRIMARY KEY,
  nonce VARCHAR(64) NOT NULL,
  code_verifier VARCHAR(128) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_oidc_states_created (created_at)
) ENGINE=InnoDB;

-- One-time codes the login page swaps for a login response, so no token travels in a URL (only the hash is stored).
-- Valid 60 seconds, used once.
CREATE TABLE IF NOT EXISTS oidc_codes (
  code_hash CHAR(64) PRIMARY KEY,
  user_id INT NOT NULL,
  expires_at DATETIME NOT NULL,
  INDEX idx_oidc_codes_expires (expires_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Two factor authentication, pending state: after the password step the client only gets a short-lived token (only its
-- SHA-256 hash is stored) instead of a login token. purpose 'challenge' = enter a code, 'setup' = enroll first.
-- setup_secret is the not yet confirmed TOTP secret (encrypted); it moves to users.twofa_secret once confirmed.
CREATE TABLE IF NOT EXISTS twofa_pending (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  purpose ENUM('challenge', 'setup') NOT NULL,
  setup_secret VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  INDEX idx_twofa_pending_expires (expires_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Two factor authentication, failed code attempts (brute force protection): one row per wrong or replayed code.
-- 5 failures per user in 5 minutes or 20 per IP in 15 minutes lock further attempts. Cleared after a success.
CREATE TABLE IF NOT EXISTS twofa_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  ip VARCHAR(45) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_twofa_attempts_user (user_id, created_at),
  INDEX idx_twofa_attempts_ip (ip, created_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Throttle of the anonymous auth endpoints (App\Services\AuthThrottle): one row per wrong password at /auth/login and
-- per request to /auth/register and /auth/reset-password, by client address. AUTH_THROTTLE_MAX (default 10) events within
-- AUTH_THROTTLE_WINDOW seconds (default 900) block the address; 0 switches the limit off. Old rows are deleted on insert.
CREATE TABLE IF NOT EXISTS auth_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  action VARCHAR(20) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_auth_attempts_lookup (action, ip, created_at),
  INDEX idx_auth_attempts_created (created_at)
) ENGINE=InnoDB;

-- Settings Table
CREATE TABLE IF NOT EXISTS settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(100) NOT NULL UNIQUE,
  `value` TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Remove dummy settings from earlier development phases
DELETE FROM settings WHERE `key` IN ('system_name', 'technical_email', 'default_theme', 'allow_registration', 'items_per_page');

-- Default values of the system settings (existing values are kept; keep in sync with src/js/defaults.js)
INSERT IGNORE INTO settings (`key`, `value`) VALUES
('application_url', 'https://leti.lewe.com'),
('application_name', 'LeweDesk'),
('application_manual', 'https://lewe.gitbook.io/leti'),
('html_description', 'LeweDesk - The web based Lewe Ticket Management application'),
('html_keywords', 'LeweDesk, Lewe Ticket Management, ticket, issue, support, bug, feature, improvement, Lewe'),
('default_language', 'en'),
('alert_show_info', 'true'),
('alert_show_success', 'true'),
('alert_show_warning', 'true'),
('alert_show_error', 'true'),
('alert_autoclose_info', 'true'),
('alert_autoclose_success', 'true'),
('alert_autoclose_warning', 'false'),
('alert_autoclose_error', 'false'),
('alert_autoclose_delay', '3000'),
('timezone', 'Europe/Berlin'),
('matomo_analytics', 'false'),
('matomo_url', ''),
('matomo_siteid', '1'),
('disable_search_engines', 'true'),
('disable_caching', 'false'),
('debug_mode', 'false'),
('under_maintenance', 'false'),
('color_mode', 'dark'),
('font', 'Poppins'),
('issue_key_prefix', 'LD'),
('support_email', 'support@lewe.com'),
('attachment_max_size', '2'),
('attachment_max_count', '10'),
('attachment_max_total_size', '10'),
('rate_limit_issues', '10'),
('rate_limit_comments', '30'),
('notification_retention_days', '90'),
('notification_poll_interval', '60'),
('temp_upload_cleanup_hours', '24'),
('force_2fa', 'false'),
('lic_expiry_warning', '30'),
('audit_retention_days', '365');

-- Roles Table
CREATE TABLE IF NOT EXISTS roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE,
  description VARCHAR(255),
  color VARCHAR(7) NOT NULL DEFAULT '#6b7785',
  icon VARCHAR(50),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO roles (name, description, color, icon) VALUES
('Administrator', 'Full access to all areas and settings of the application.', '#e55353', 'bi-shield-lock'),
('Agent', 'Handles tickets and supports users.', '#f9b115', 'bi-headset'),
('User', 'Registered user who can create and follow tickets.', '#5856d6', 'bi-person'),
('Public', 'Anonymous visitor without an account.', '#6b7785', 'bi-globe');

-- Groups Table
CREATE TABLE IF NOT EXISTS `groups` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE,
  description VARCHAR(255),
  color VARCHAR(7) NOT NULL DEFAULT '#6b7785',
  icon VARCHAR(50),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- No groups are created by default (sample groups are in db_sample.sql)

-- User/Role assignments
CREATE TABLE IF NOT EXISTS user_role (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  role_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_role (user_id, role_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- User/Group assignments
CREATE TABLE IF NOT EXISTS user_group (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  group_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_group (user_id, group_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Permissions Table
CREATE TABLE IF NOT EXISTS permissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE,
  description VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Permission/Role assignments
CREATE TABLE IF NOT EXISTS permission_role (
  id INT AUTO_INCREMENT PRIMARY KEY,
  permission_id INT NOT NULL,
  role_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_permission_role (permission_id, role_id),
  FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Permission/Group assignments
CREATE TABLE IF NOT EXISTS permission_group (
  id INT AUTO_INCREMENT PRIMARY KEY,
  permission_id INT NOT NULL,
  group_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_permission_group (permission_id, group_id),
  FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
  FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Test permissions (existing entries are kept)
INSERT IGNORE INTO permissions (name, description) VALUES
('manage_groups', 'Open the groups and group-edit pages and manage groups.'),
('manage_permissions', 'Open the permissions and permission-edit pages and manage permissions.'),
('manage_roles', 'Open the roles and role-edit pages and manage roles.'),
('manage_settings', 'Open the settings page and change the system settings.'),
('manage_users', 'Open the users and user-edit pages and manage users.'),
('edit_technical_email', 'See and edit the Technical Email field on the Sample Edit page.');

INSERT IGNORE INTO permission_role (permission_id, role_id)
SELECT p.id, r.id FROM permissions p JOIN roles r
  ON (1 = 0)
  OR (p.name IN ('manage_groups', 'manage_permissions', 'manage_roles', 'manage_settings', 'manage_users', 'edit_technical_email') AND r.name = 'Administrator')
  OR (p.name = 'edit_technical_email' AND r.name = 'Agent');

-- Issue permissions (existing entries are kept)
INSERT IGNORE INTO permissions (name, description) VALUES
('create_issue', 'Create issues.'),
('comment_issue', 'Add comments to issues. Users may comment on their own issues and on Public and Registered issues; Agents and Administrators on all issues.'),
('view_issue', 'See issues, their comments and attachments within the scope of the issue access: Public issues for anonymous visitors, additionally Registered issues and own issues for logged in users.'),
('view_all_issues', 'See all issues regardless of their access, including the Reporter-level issues of other users.'),
('view_internal_comments', 'See internal comments and the attachments of internal comments.'),
('edit_issue', 'Open the issue edit page and change all fields of an issue.'),
('assign_issue', 'Assign issues to agents.'),
('change_issue_status', 'Change the status and the resolution of issues (Resolve action and edit page).'),
('delete_issue', 'Delete and restore issues and see deleted issues.'),
('edit_any_comment', 'Edit and delete the comments of other users.'),
('add_attachment', 'Add attachments to issues of other users. Reporters can always add attachments to their own issues.'),
('manage_issue_types', 'Open the issue types pages and manage issue types.'),
('manage_status', 'Open the status pages and manage issue status.'),
('manage_status_categories', 'Open the status categories page and edit the status categories.'),
('manage_resolutions', 'Open the resolutions pages and manage resolutions.'),
('manage_products', 'Open the products pages and manage products.'),
('manage_tags', 'Open the tags pages and edit or delete tags.');

INSERT IGNORE INTO permission_role (permission_id, role_id)
SELECT p.id, r.id FROM permissions p JOIN roles r
  ON (1 = 0)
  OR (p.name IN ('create_issue', 'comment_issue', 'view_issue') AND r.name IN ('User', 'Agent', 'Administrator'))
  OR (p.name = 'view_issue' AND r.name = 'Public')
  OR (p.name IN ('view_all_issues', 'view_internal_comments', 'edit_issue', 'assign_issue', 'change_issue_status', 'delete_issue', 'edit_any_comment', 'add_attachment') AND r.name IN ('Agent', 'Administrator'))
  OR (p.name IN ('manage_issue_types', 'manage_status', 'manage_status_categories', 'manage_resolutions', 'manage_products', 'manage_tags') AND r.name = 'Administrator');

-- Initial role assignments
INSERT IGNORE INTO user_role (user_id, role_id)
SELECT u.id, r.id FROM users u JOIN roles r
  ON (u.username = 'admin' AND r.name = 'Administrator');


-- ---------------------------------------------------------------------------
-- Issue lookup tables (see _docs/TICKET_SPEC.md)
--
-- Records with a `key` are protected system records: they cannot be deleted, deactivated or renamed
-- (the name is translated through the language files by the key). Description, icon and colors stay
-- editable. `color` colors icons and texts, `background_color` is applied with transparency to rows
-- and badges. Existing records are never overwritten by the seed (INSERT IGNORE).
-- ---------------------------------------------------------------------------

-- Issue types
CREATE TABLE IF NOT EXISTS issue_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(50) NULL UNIQUE,
  name VARCHAR(50) NOT NULL UNIQUE,
  description VARCHAR(255),
  icon VARCHAR(50),
  color VARCHAR(7) NOT NULL DEFAULT '#6b7785',
  background_color VARCHAR(7) NOT NULL DEFAULT '#6b7785',
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO issue_types (`key`, name, description, icon, color, background_color, sort_order) VALUES
('bug', 'Bug', 'An incident or problem of the product', 'bi-bug', '#e55353', '#e55353', 10),
('improvement', 'Improvement', 'An improvement request for an existing feature', 'bi-arrow-up-circle', '#3399ff', '#3399ff', 20),
('feature', 'Feature', 'A request for a new feature', 'bi-stars', '#1b9e3e', '#1b9e3e', 30),
('question', 'Question', 'An inquiry or question about a product or service', 'bi-question-circle', '#5856d6', '#5856d6', 40),
('license', 'License', 'An inquiry or request for a license', 'bi-key', '#f9b115', '#f9b115', 50),
('task', 'Task', 'A general task', 'bi-check2-square', '#6b7785', '#6b7785', 60);

-- Status categories (seed only: only description, icon and colors can be edited)
CREATE TABLE IF NOT EXISTS status_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(50) NOT NULL UNIQUE,
  name VARCHAR(50) NOT NULL UNIQUE,
  description VARCHAR(255),
  icon VARCHAR(50),
  color VARCHAR(7) NOT NULL DEFAULT '#6b7785',
  background_color VARCHAR(7) NOT NULL DEFAULT '#6b7785',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO status_categories (`key`, name, description, icon, color, background_color) VALUES
('open', 'Open', 'All issues that are not being worked on yet', 'bi-circle', '#ff8080', '#ff8080'),
('waiting', 'Waiting', 'All issues that are pending or on hold', 'bi-hourglass-split', '#f9b115', '#f9b115'),
('in_progress', 'In progress', 'All issues that are being worked on', 'bi-play-circle', '#0080ff', '#0080ff'),
('done', 'Done', 'All issues that are resolved or closed', 'bi-check-circle', '#1b9e3e', '#1b9e3e');

-- Status
CREATE TABLE IF NOT EXISTS status (
  id INT AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(50) NULL UNIQUE,
  category_id INT NOT NULL,
  name VARCHAR(50) NOT NULL UNIQUE,
  description VARCHAR(255),
  icon VARCHAR(50),
  color VARCHAR(7) NOT NULL DEFAULT '#6b7785',
  background_color VARCHAR(7) NOT NULL DEFAULT '#6b7785',
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES status_categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

INSERT IGNORE INTO status (`key`, category_id, name, description, icon, color, background_color, sort_order) VALUES
('open', (SELECT id FROM status_categories WHERE `key` = 'open'), 'Open', 'Submitted issues that are not being worked on yet', 'bi-circle', '#ff8080', '#ff8080', 10),
('waiting_for_agent', (SELECT id FROM status_categories WHERE `key` = 'in_progress'), 'Waiting for agent', 'The issue waits for a response from an agent', 'bi-headset', '#ffff00', '#ffff00', 20),
('waiting_for_customer', (SELECT id FROM status_categories WHERE `key` = 'in_progress'), 'Waiting for customer', 'The issue waits for a response from the customer', 'bi-person-exclamation', '#0080ff', '#0080ff', 30),
('pending', (SELECT id FROM status_categories WHERE `key` = 'waiting'), 'Pending', 'The issue waits for 3rd party input', 'bi-hourglass-split', '#f9b115', '#f9b115', 40),
('on_hold', (SELECT id FROM status_categories WHERE `key` = 'waiting'), 'On hold', 'The issue is paused or postponed', 'bi-pause-circle', '#8080ff', '#8080ff', 50),
('resolved', (SELECT id FROM status_categories WHERE `key` = 'done'), 'Resolved', 'The issue has been resolved', 'bi-check-circle', '#1b9e3e', '#1b9e3e', 60),
('closed', (SELECT id FROM status_categories WHERE `key` = 'done'), 'Closed', 'The resolution of the issue has been accepted', 'bi-lock', '#4f5d73', '#4f5d73', 70);

-- Resolutions
CREATE TABLE IF NOT EXISTS resolutions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(50) NULL UNIQUE,
  name VARCHAR(50) NOT NULL UNIQUE,
  description VARCHAR(255),
  icon VARCHAR(50),
  color VARCHAR(7) NOT NULL DEFAULT '#6b7785',
  background_color VARCHAR(7) NOT NULL DEFAULT '#6b7785',
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO resolutions (`key`, name, description, icon, color, background_color, sort_order) VALUES
('completed', 'Completed', 'Work on the issue has been completed', 'bi-check2-all', '#1b9e3e', '#1b9e3e', 10),
('fixed', 'Fixed', 'The issue has been fixed', 'bi-wrench', '#1b9e3e', '#1b9e3e', 20),
('answered', 'Answered', 'The issue has been answered', 'bi-chat-dots', '#3399ff', '#3399ff', 30),
('expired', 'Expired', 'The issue has expired', 'bi-clock-history', '#6b7785', '#6b7785', 40),
('duplicate', 'Duplicate', 'The issue is a duplicate of an existing issue', 'bi-files', '#f9b115', '#f9b115', 50),
('wont_do', 'Won''t do', 'The issue won''t be actioned', 'bi-slash-circle', '#e55353', '#e55353', 60),
('not_reproducible', 'Not reproducible', 'The issue can''t be reproduced', 'bi-question-octagon', '#6b7785', '#6b7785', 70);

-- Products (user defined, no seed records)
CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE,
  description VARCHAR(255),
  icon VARCHAR(50),
  color VARCHAR(7) NOT NULL DEFAULT '#6b7785',
  background_color VARCHAR(7) NOT NULL DEFAULT '#6b7785',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Tags (user defined, no seed records)
CREATE TABLE IF NOT EXISTS tags (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE,
  color VARCHAR(7) NOT NULL DEFAULT '#ffffff',
  background_color VARCHAR(7) NOT NULL DEFAULT '#6b7785',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Issues (see _docs/TICKET_SPEC.md)
--
-- `id` is the issue number, displayed as <issue_key_prefix>-<id>. Lookup values use ON DELETE RESTRICT:
-- the API reassigns the issues first (a replacement is required), so an in-use value can never be
-- removed silently. Users use ON DELETE SET NULL: issues keep existing when an account is deleted (a
-- missing reporter is shown as "Deleted user"; a missing assignee means "unassigned"). `updated_at` has
-- no ON UPDATE clause on purpose: the application sets it for the changes that count as an update
-- (watcher changes and automatic reassignments do not). `deleted_at` is the soft delete marker.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS issues (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type_id INT NOT NULL,
  status_id INT NOT NULL,
  resolution_id INT NULL,
  reporter_id INT NULL,
  assignee_id INT NULL,
  product_id INT NULL,
  version VARCHAR(50) NULL,
  access ENUM('public', 'registered', 'reporter') NOT NULL DEFAULT 'registered',
  summary VARCHAR(200) NOT NULL,
  description MEDIUMTEXT NOT NULL,
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_issues_status (status_id),
  INDEX idx_issues_type (type_id),
  INDEX idx_issues_assignee (assignee_id),
  INDEX idx_issues_reporter (reporter_id),
  INDEX idx_issues_product (product_id),
  INDEX idx_issues_access (access),
  INDEX idx_issues_updated (updated_at),
  INDEX idx_issues_deleted (deleted_at),
  FULLTEXT KEY ft_issues_text (summary, description),
  FOREIGN KEY (type_id) REFERENCES issue_types(id) ON DELETE RESTRICT,
  FOREIGN KEY (status_id) REFERENCES `status`(id) ON DELETE RESTRICT,
  FOREIGN KEY (resolution_id) REFERENCES resolutions(id) ON DELETE RESTRICT,
  FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (assignee_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Tags of an issue (deleting a tag or an issue removes the links)
CREATE TABLE IF NOT EXISTS issue_tag (
  issue_id INT NOT NULL,
  tag_id INT NOT NULL,
  PRIMARY KEY (issue_id, tag_id),
  INDEX idx_issue_tag_tag (tag_id),
  FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE,
  FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Watchers of an issue (a watch has no content of its own, so it goes away with the account)
CREATE TABLE IF NOT EXISTS issue_watcher (
  issue_id INT NOT NULL,
  user_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (issue_id, user_id),
  INDEX idx_issue_watcher_user (user_id),
  FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Comments of an issue (see _docs/TICKET_SPEC.md)
--
-- `author_id` uses ON DELETE SET NULL: the comments stay when the account is deleted and are shown as
-- written by "Deleted user". `edited_at` is the "edited" marker (NULL: never edited). A deleted comment
-- keeps its row, so the timeline can show the placeholder "Comment deleted"; its text is cleared when it
-- is deleted. `is_internal` comments are only for users holding view_internal_comments. `updated_at` has
-- no ON UPDATE clause on purpose: the application sets it.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  issue_id INT NOT NULL,
  author_id INT NULL,
  body MEDIUMTEXT NOT NULL,
  is_internal TINYINT(1) NOT NULL DEFAULT 0,
  edited_at DATETIME NULL,
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_comments_issue (issue_id, created_at),
  INDEX idx_comments_author (author_id),
  FULLTEXT KEY ft_comments_body (body),
  FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE,
  FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Per-user preferences (key/value). The allowed keys and values are defined in api/models/PreferenceModel.php;
-- the first one is `comment_order`. The preferences go away with the account.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_preferences (
  user_id INT NOT NULL,
  pref_key VARCHAR(50) NOT NULL,
  pref_value VARCHAR(255) NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, pref_key),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
-- ---------------------------------------------------------------------------
-- Activity history of an issue (see _docs/TICKET_SPEC.md). Written by App\Services\IssueHistory inside the
-- transaction of the change. `event` is issue_created, issue_deleted, issue_restored, comment_added, comment_edited, comment_deleted or
-- field_changed (then `field`, `old_value` and `new_value` tell what changed). The history never holds the
-- text of a comment. `is_internal` marks entries about internal comments (users without
-- view_internal_comments see "internal comment added" only). `actor_id` uses ON DELETE SET NULL: the entries
-- stay when the account is deleted. `comment_id` has no foreign key: the comment row outlives its entries
-- anyway (a deleted comment keeps its row).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS issue_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  issue_id INT NOT NULL,
  actor_id INT NULL,
  event VARCHAR(40) NOT NULL,
  field VARCHAR(40) NULL,
  old_value TEXT NULL,
  new_value TEXT NULL,
  comment_id INT NULL,
  is_internal TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_issue_history_issue (issue_id, created_at, id),
  FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE,
  FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Entries for issues and comments that exist without history (created before this table, or by a SQL script).
-- Edits of the past cannot be reconstructed beyond the "edited" marker. Safe to run again.
INSERT INTO issue_history (issue_id, actor_id, event, created_at)
SELECT i.id, i.reporter_id, 'issue_created', i.created_at FROM issues i
WHERE NOT EXISTS (SELECT 1 FROM issue_history h WHERE h.issue_id = i.id AND h.event = 'issue_created');

INSERT INTO issue_history (issue_id, actor_id, event, comment_id, is_internal, created_at)
SELECT c.issue_id, c.author_id, 'comment_added', c.id, c.is_internal, c.created_at FROM comments c
WHERE NOT EXISTS (SELECT 1 FROM issue_history h WHERE h.comment_id = c.id AND h.event = 'comment_added');

INSERT INTO issue_history (issue_id, actor_id, event, comment_id, is_internal, created_at)
SELECT c.issue_id, c.author_id, 'comment_edited', c.id, c.is_internal, c.edited_at FROM comments c
WHERE c.edited_at IS NOT NULL AND c.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM issue_history h WHERE h.comment_id = c.id AND h.event = 'comment_edited');

INSERT INTO issue_history (issue_id, actor_id, event, comment_id, is_internal, created_at)
SELECT c.issue_id, c.author_id, 'comment_deleted', c.id, c.is_internal, c.deleted_at FROM comments c
WHERE c.deleted_at IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM issue_history h WHERE h.comment_id = c.id AND h.event = 'comment_deleted');

-- ---------------------------------------------------------------------------
-- Attachments of an issue (see _docs/TICKET_SPEC.md). The files live below uploads/attachments under a random
-- name (`stored_name`) and are only handed out by the API after the access check. `original_name` is the
-- cleaned name of the uploaded file, `mime_type` follows from the validated extension. `is_inline` marks the
-- images that are pasted or dropped into a text; `is_internal` marks the images of internal comments (only for users
-- holding view_internal_comments); `comment_id` is the comment that shows an inline image (no foreign key: a deleted
-- comment keeps its row). A file that is uploaded while a new issue is written has no issue yet (`issue_id` is NULL)
-- and carries the `temp_token` the browser chose; it is attached when the issue is saved and deleted by the
-- housekeeping after temp_upload_cleanup_hours otherwise. `uploader_id` uses ON DELETE SET NULL: the attachments stay
-- when the account is deleted.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS attachments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  issue_id INT NULL,
  comment_id INT NULL,
  uploader_id INT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name CHAR(32) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  size INT UNSIGNED NOT NULL,
  is_inline TINYINT(1) NOT NULL DEFAULT 0,
  is_internal TINYINT(1) NOT NULL DEFAULT 0,
  temp_token CHAR(32) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_attachments_stored (stored_name),
  INDEX idx_attachments_issue (issue_id, created_at),
  INDEX idx_attachments_temp (issue_id, temp_token),
  INDEX idx_attachments_uploader (uploader_id),
  FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE,
  FOREIGN KEY (uploader_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Rate limiting (App\Services\RateLimiter): one row per issue or comment a user created, counted over the last hour
-- (rate_limit_issues, rate_limit_comments). `action` is issue or comment. Rows older than an hour are deleted
-- whenever the limiter runs; they go away with the account.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rate_limits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  action VARCHAR(20) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_rate_limits_user (user_id, action, created_at),
  INDEX idx_rate_limits_created (created_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Notifications (see "Email and notification events" in _docs/TICKET_SPEC.md; App\Services\NotificationService)
--
-- `notifications` are the in-app messages of the top bar: one row per recipient and action (an action that
-- changes several things, e.g. Resolve, is one row). `event` is the main event, `data` (JSON) holds what the
-- message says (all events of the action, the new status, the assignee). `is_internal` marks messages about an
-- internal comment (only shown to users holding view_internal_comments). Rows older than
-- notification_retention_days are deleted by the housekeeping. They go away with the issue or the account;
-- `actor_id` uses ON DELETE SET NULL.
--
-- `notification_preferences` hold what a user switched off per event (a missing row means email and in-app are on).
--
-- `mail_outbox` queues the emails. `status` is pending, sending, sent or failed; a mail is tried at most twice
-- (`attempts`), the second failure is final and written to the PHP error log.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  issue_id INT NOT NULL,
  actor_id INT NULL,
  event VARCHAR(30) NOT NULL,
  data TEXT NULL,
  is_internal TINYINT(1) NOT NULL DEFAULT 0,
  read_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notifications_user (user_id, read_at, created_at),
  INDEX idx_notifications_created (created_at),
  INDEX idx_notifications_issue (issue_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE,
  FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notification_preferences (
  user_id INT NOT NULL,
  event VARCHAR(30) NOT NULL,
  email_enabled TINYINT(1) NOT NULL DEFAULT 1,
  inapp_enabled TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (user_id, event),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mail_outbox (
  id INT AUTO_INCREMENT PRIMARY KEY,
  recipient VARCHAR(100) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  body_html MEDIUMTEXT NOT NULL,
  body_text MEDIUMTEXT NOT NULL,
  status ENUM('pending', 'sending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  attempted_at DATETIME NULL,
  INDEX idx_mail_outbox_status (status, attempted_at),
  INDEX idx_mail_outbox_created (created_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Audit log (see _docs/AUDITLOG.md; App\Services\AuditLog)
--
-- System wide log of who did what and when: logins, account and configuration changes, security events. Regular issue
-- activity is not part of it (that stays in issue_history). There are no foreign keys: the entries stay when a user or
-- the target is deleted, which is why the actor and the target are also kept as text (`actor_username`,
-- `target_label`). `category` (auth, user, config, data, security, audit) is the first part of `event`, kept for the
-- filter. `outcome` is failure for attempts that did not happen (wrong password, denied access). `old_value` and
-- `new_value` hold JSON with the sensitive values masked. `ip` is stored with the last octet masked (IPv4) or
-- anonymized (IPv6), never in full. Entries are never edited; only the housekeeping deletes them after
-- audit_retention_days (0 = keep forever).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actor_id INT NULL,
  actor_username VARCHAR(50) NULL,
  event VARCHAR(50) NOT NULL,
  category VARCHAR(20) NOT NULL,
  outcome ENUM('success', 'failure') NOT NULL DEFAULT 'success',
  target_type VARCHAR(30) NULL,
  target_id INT NULL,
  target_label VARCHAR(255) NULL,
  old_value TEXT NULL,
  new_value TEXT NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  request_id CHAR(16) NULL,
  INDEX idx_audit_log_created (created_at),
  INDEX idx_audit_log_actor (actor_id, created_at),
  INDEX idx_audit_log_event (event, created_at),
  INDEX idx_audit_log_category (category, created_at),
  INDEX idx_audit_log_target (target_type, target_id)
) ENGINE=InnoDB;

-- Permission to open the audit log. Only the Administrator role holds it (the API also requires the Administrator role)
INSERT IGNORE INTO permissions (name, description) VALUES
('view_audit_log', 'Open the audit log and export it.');

INSERT IGNORE INTO permission_role (permission_id, role_id)
SELECT p.id, r.id FROM permissions p JOIN roles r
  ON p.name = 'view_audit_log' AND r.name = 'Administrator';

-- Entries for what exists without an audit trail (created before the audit log, or by a SQL script), taken from the
-- creation dates of the records. The actor is unknown. They are marked {"backfilled":true}. Safe to run again: a record
-- that already has its entry is skipped. The protected system records of the issue lookups (with a key) are left out.
INSERT INTO audit_log (created_at, event, category, target_type, target_id, target_label, new_value)
SELECT u.created_at, 'user.created', 'user', 'user', u.id, u.username, '{"backfilled":true}' FROM users u
WHERE NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.event = 'user.created' AND a.target_type = 'user' AND a.target_id = u.id);

INSERT INTO audit_log (created_at, event, category, target_type, target_id, target_label, new_value)
SELECT u.twofa_enabled_at, 'auth.2fa_enabled', 'auth', 'user', u.id, u.username, '{"backfilled":true}' FROM users u
WHERE u.twofa_enabled_at IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.event = 'auth.2fa_enabled' AND a.target_type = 'user' AND a.target_id = u.id);

INSERT INTO audit_log (created_at, event, category, target_type, target_id, target_label, new_value)
SELECT r.created_at, 'role.created', 'config', 'role', r.id, r.name, '{"backfilled":true}' FROM roles r
WHERE NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.event = 'role.created' AND a.target_type = 'role' AND a.target_id = r.id);

INSERT INTO audit_log (created_at, event, category, target_type, target_id, target_label, new_value)
SELECT g.created_at, 'group.created', 'config', 'group', g.id, g.name, '{"backfilled":true}' FROM `groups` g
WHERE NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.event = 'group.created' AND a.target_type = 'group' AND a.target_id = g.id);

INSERT INTO audit_log (created_at, event, category, target_type, target_id, target_label, new_value)
SELECT p.created_at, 'permission.created', 'config', 'permission', p.id, p.name, '{"backfilled":true}' FROM permissions p
WHERE NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.event = 'permission.created' AND a.target_type = 'permission' AND a.target_id = p.id);

INSERT INTO audit_log (created_at, event, category, target_type, target_id, target_label, new_value)
SELECT t.created_at, 'issue_type.created', 'config', 'issue_type', t.id, t.name, '{"backfilled":true}' FROM issue_types t
WHERE t.`key` IS NULL
  AND NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.event = 'issue_type.created' AND a.target_type = 'issue_type' AND a.target_id = t.id);

INSERT INTO audit_log (created_at, event, category, target_type, target_id, target_label, new_value)
SELECT s.created_at, 'status.created', 'config', 'status', s.id, s.name, '{"backfilled":true}' FROM `status` s
WHERE s.`key` IS NULL
  AND NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.event = 'status.created' AND a.target_type = 'status' AND a.target_id = s.id);

INSERT INTO audit_log (created_at, event, category, target_type, target_id, target_label, new_value)
SELECT r.created_at, 'resolution.created', 'config', 'resolution', r.id, r.name, '{"backfilled":true}' FROM resolutions r
WHERE r.`key` IS NULL
  AND NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.event = 'resolution.created' AND a.target_type = 'resolution' AND a.target_id = r.id);

INSERT INTO audit_log (created_at, event, category, target_type, target_id, target_label, new_value)
SELECT p.created_at, 'product.created', 'config', 'product', p.id, p.name, '{"backfilled":true}' FROM products p
WHERE NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.event = 'product.created' AND a.target_type = 'product' AND a.target_id = p.id);

INSERT INTO audit_log (created_at, event, category, target_type, target_id, target_label, new_value)
SELECT t.created_at, 'tag.created', 'config', 'tag', t.id, t.name, '{"backfilled":true}' FROM tags t
WHERE NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.event = 'tag.created' AND a.target_type = 'tag' AND a.target_id = t.id);

INSERT INTO audit_log (created_at, event, category, target_type, target_id, target_label, new_value)
SELECT i.deleted_at, 'issue.deleted', 'data', 'issue', i.id, LEFT(i.summary, 255), '{"backfilled":true}' FROM issues i
WHERE i.deleted_at IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.event = 'issue.deleted' AND a.target_type = 'issue' AND a.target_id = i.id);
