-- LeweDesk sample issues for development (not part of db_init.sql; run with: npm run deploy:local:samples)
--
-- Sample users (password Qwer!1234), groups and role/group assignments, then issues in all access levels and several status, plus one soft deleted
-- issue. A sample issue is only added if no issue with the same summary exists, so the script can be run
-- again. Do not run it on a production database: it also locks the issue key prefix of the system settings.
-- The summaries start with "Sample:" (the Cypress cleanup removes issues starting with "E2E" only).

-- ---------------------------------------------------------------------------
-- Sample users, groups and their assignments (moved here from db_init.sql so a production install does not contain
-- them). All sample users have the password Qwer!1234. Roles and groups are matched by name and the inserts are
-- INSERT IGNORE, so the script can be run again.
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO users (username, password, email, first_name, last_name) VALUES
('inewton', '$2y$12$0awXW/.hfWsSzXSBsw3wW.NmEYKGmDJfb552Iu77OTdCKz/x63nlC', 'inewton@yourserver.com', 'Isaac', 'Newton'),
('athene', '$2y$12$0awXW/.hfWsSzXSBsw3wW.NmEYKGmDJfb552Iu77OTdCKz/x63nlC', 'athene@yourserver.com', 'Athene', 'Wisdom'),
('alupin', '$2y$12$0awXW/.hfWsSzXSBsw3wW.NmEYKGmDJfb552Iu77OTdCKz/x63nlC', 'alupin@yourserver.com', 'Arsene', 'Lupin'),
('einstein', '$2y$12$0awXW/.hfWsSzXSBsw3wW.NmEYKGmDJfb552Iu77OTdCKz/x63nlC', 'einstein@mydomain.com', 'Albert', 'Einstein'),
('jmarple', '$2y$12$0awXW/.hfWsSzXSBsw3wW.NmEYKGmDJfb552Iu77OTdCKz/x63nlC', 'jmarple@yourserver.com', 'Jane', 'Marple'),
('sherlock', '$2y$12$0awXW/.hfWsSzXSBsw3wW.NmEYKGmDJfb552Iu77OTdCKz/x63nlC', 'sherlock@yourserver.com', 'Sherlock', 'Holmes'),
('mcurie', '$2y$12$0awXW/.hfWsSzXSBsw3wW.NmEYKGmDJfb552Iu77OTdCKz/x63nlC', 'mcurie@yourserver.com', 'Marie', 'Curie'),
('zeuss', '$2y$12$0awXW/.hfWsSzXSBsw3wW.NmEYKGmDJfb552Iu77OTdCKz/x63nlC', 'zeuss@yourserver.com', 'Zeuss', 'Lightning'),
('hermes', '$2y$12$0awXW/.hfWsSzXSBsw3wW.NmEYKGmDJfb552Iu77OTdCKz/x63nlC', 'hermes@yourserver.com', 'Hermes', 'Speed'),
('richthofen', '$2y$12$0awXW/.hfWsSzXSBsw3wW.NmEYKGmDJfb552Iu77OTdCKz/x63nlC', 'richthofen@yourserver.com', 'Manfred', 'von Richthofen'),
('aearheart', '$2y$12$0awXW/.hfWsSzXSBsw3wW.NmEYKGmDJfb552Iu77OTdCKz/x63nlC', 'aearhart@yourserver.com', 'Amelia', 'Earhart'),
('cyeager', '$2y$12$0awXW/.hfWsSzXSBsw3wW.NmEYKGmDJfb552Iu77OTdCKz/x63nlC', 'cyeager@yourserver.com', 'Chuck', 'Yeager'),
('galileo', '$2y$12$0awXW/.hfWsSzXSBsw3wW.NmEYKGmDJfb552Iu77OTdCKz/x63nlC', 'galileo@yourserver.com', 'Galileo', 'Galilei');

-- Avatars of the sample users: the images are part of the distribution (uploads/avatar/<username>.png).
-- Only users without an avatar are touched, so a changed avatar survives running the script again.
UPDATE users SET avatar = CONCAT('upload/', username, '.png')
WHERE avatar IS NULL AND username IN ('inewton', 'athene', 'alupin', 'einstein', 'jmarple', 'sherlock', 'mcurie', 'zeuss', 'hermes', 'richthofen', 'aearheart', 'cyeager', 'galileo');

INSERT IGNORE INTO `groups` (name, description, color, icon) VALUES
('Pilots', 'Everything that flies.', '#3399ff', 'bi-airplane'),
('Mythology', 'Gods, heroes and legendary creatures.', '#1b9e3e', 'bi-lightning'),
('Detectives', 'Investigators and crime solvers.', '#6b7785', 'bi-search'),
('Scientists', 'Researchers and inventors.', '#5856d6', 'bi-flask');

-- Sample role assignments
INSERT IGNORE INTO user_role (user_id, role_id)
SELECT u.id, r.id FROM users u JOIN roles r
  ON (1 = 0)
  OR (u.username = 'inewton' AND r.name = 'User')
  OR (u.username = 'athene' AND r.name = 'User')
  OR (u.username = 'alupin' AND r.name = 'User')
  OR (u.username = 'einstein' AND r.name = 'Agent')
  OR (u.username = 'jmarple' AND r.name = 'User')
  OR (u.username = 'sherlock' AND r.name = 'Agent')
  OR (u.username = 'mcurie' AND r.name = 'User')
  OR (u.username = 'zeuss' AND r.name = 'Agent')
  OR (u.username = 'hermes' AND r.name = 'User')
  OR (u.username = 'richthofen' AND r.name = 'Administrator')
  OR (u.username = 'aearheart' AND r.name = 'User')
  OR (u.username = 'cyeager' AND r.name = 'User')
  OR (u.username = 'galileo' AND r.name = 'User');

-- Sample group assignments
INSERT IGNORE INTO user_group (user_id, group_id)
SELECT u.id, g.id FROM users u JOIN `groups` g
  ON (1 = 0)
  OR (u.username = 'inewton' AND g.name = 'Scientists')
  OR (u.username = 'athene' AND g.name = 'Mythology')
  OR (u.username = 'alupin' AND g.name = 'Detectives')
  OR (u.username = 'einstein' AND g.name = 'Scientists')
  OR (u.username = 'jmarple' AND g.name = 'Detectives')
  OR (u.username = 'sherlock' AND g.name = 'Detectives')
  OR (u.username = 'mcurie' AND g.name = 'Scientists')
  OR (u.username = 'zeuss' AND g.name = 'Mythology')
  OR (u.username = 'hermes' AND g.name = 'Mythology')
  OR (u.username = 'richthofen' AND g.name = 'Pilots')
  OR (u.username = 'aearheart' AND g.name = 'Pilots')
  OR (u.username = 'cyeager' AND g.name = 'Pilots');

-- Product
INSERT IGNORE INTO products (name, description, icon, color, background_color) VALUES
('Sample App', 'A product to try out the issues', 'bi-box', '#5856d6', '#5856d6');

-- Lookups
SET @t_bug = (SELECT id FROM issue_types WHERE `key` = 'bug');
SET @t_improvement = (SELECT id FROM issue_types WHERE `key` = 'improvement');
SET @t_feature = (SELECT id FROM issue_types WHERE `key` = 'feature');
SET @t_question = (SELECT id FROM issue_types WHERE `key` = 'question');
SET @t_license = (SELECT id FROM issue_types WHERE `key` = 'license');
SET @t_task = (SELECT id FROM issue_types WHERE `key` = 'task');

SET @s_open = (SELECT id FROM `status` WHERE `key` = 'open');
SET @s_agent = (SELECT id FROM `status` WHERE `key` = 'waiting_for_agent');
SET @s_customer = (SELECT id FROM `status` WHERE `key` = 'waiting_for_customer');
SET @s_pending = (SELECT id FROM `status` WHERE `key` = 'pending');
SET @s_hold = (SELECT id FROM `status` WHERE `key` = 'on_hold');
SET @s_resolved = (SELECT id FROM `status` WHERE `key` = 'resolved');
SET @s_closed = (SELECT id FROM `status` WHERE `key` = 'closed');

SET @r_fixed = (SELECT id FROM resolutions WHERE `key` = 'fixed');
SET @r_answered = (SELECT id FROM resolutions WHERE `key` = 'answered');
SET @r_completed = (SELECT id FROM resolutions WHERE `key` = 'completed');
SET @r_duplicate = (SELECT id FROM resolutions WHERE `key` = 'duplicate');

SET @pr_critical = (SELECT id FROM priorities WHERE `key` = 'critical');
SET @pr_high = (SELECT id FROM priorities WHERE `key` = 'high');
SET @pr_normal = (SELECT id FROM priorities WHERE `key` = 'normal');
SET @pr_low = (SELECT id FROM priorities WHERE `key` = 'low');

SET @p_sample = (SELECT id FROM products WHERE name = 'Sample App');

-- Users (reporters are role User, assignees role Agent)
SET @u_inewton = (SELECT id FROM users WHERE username = 'inewton');
SET @u_athene = (SELECT id FROM users WHERE username = 'athene');
SET @u_alupin = (SELECT id FROM users WHERE username = 'alupin');
SET @u_jmarple = (SELECT id FROM users WHERE username = 'jmarple');
SET @u_mcurie = (SELECT id FROM users WHERE username = 'mcurie');
SET @u_hermes = (SELECT id FROM users WHERE username = 'hermes');
SET @u_aearheart = (SELECT id FROM users WHERE username = 'aearheart');
SET @u_cyeager = (SELECT id FROM users WHERE username = 'cyeager');
SET @a_sherlock = (SELECT id FROM users WHERE username = 'sherlock');
SET @a_einstein = (SELECT id FROM users WHERE username = 'einstein');
SET @a_zeuss = (SELECT id FROM users WHERE username = 'zeuss');

-- Public issues (everybody sees them, anonymous visitors included)
INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_bug, @s_open, NULL, @pr_normal, @u_inewton, NULL, @p_sample, '1.2.0', 'public',
  'Sample: Login button does not react on touch devices',
  'Tapping the **Login** button on a phone does nothing.\n\nSteps:\n\n1. Open the login page on a phone\n2. Enter the credentials\n3. Tap *Login*\n\nExpected: the dashboard opens.',
  NOW() - INTERVAL 12 DAY, NOW() - INTERVAL 12 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Login button does not react on touch devices');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_question, @s_agent, NULL, @pr_high, @u_mcurie, @a_sherlock, @p_sample, NULL, 'public',
  'Sample: How do I export the issue list?',
  'Is there a way to export the list of issues to a spreadsheet?',
  NOW() - INTERVAL 9 DAY, NOW() - INTERVAL 8 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: How do I export the issue list?');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_bug, @s_resolved, @r_fixed, @pr_normal, @u_hermes, @a_sherlock, @p_sample, '1.1.4', 'public',
  'Sample: Wrong date format in the German user interface',
  'Dates are shown as 2026-03-04 instead of 04.03.2026 when the language is German.',
  NOW() - INTERVAL 20 DAY, NOW() - INTERVAL 3 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Wrong date format in the German user interface');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_feature, @s_hold, NULL, @pr_low, @u_cyeager, NULL, NULL, NULL, 'public',
  'Sample: Dark mode for printed pages',
  'Printing an issue should use a light theme even when the dark mode is on.',
  NOW() - INTERVAL 30 DAY, NOW() - INTERVAL 15 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Dark mode for printed pages');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_question, @s_open, NULL, @pr_normal, @u_alupin, NULL, NULL, NULL, 'public',
  'Sample: Markdown in descriptions',
  'Which markdown features are supported?\n\n- **bold** and *italic*\n- `code`\n- links like https://lewe.com\n\nAnd does `LD-1` link to the first issue?',
  NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Markdown in descriptions');

-- Registered issues (logged in users)
INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_feature, @s_customer, NULL, @pr_critical, @u_inewton, @a_einstein, @p_sample, NULL, 'registered',
  'Sample: Show the reporter avatar in the issue list',
  'The issue list would be easier to scan with the avatar of the reporter next to the name.',
  NOW() - INTERVAL 14 DAY, NOW() - INTERVAL 5 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Show the reporter avatar in the issue list');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_improvement, @s_pending, NULL, @pr_high, @u_athene, @a_zeuss, @p_sample, '1.2.0', 'registered',
  'Sample: Faster loading of the user list',
  'The user list takes several seconds to load with many users. Waiting for the database vendor to confirm the index behavior.',
  NOW() - INTERVAL 18 DAY, NOW() - INTERVAL 6 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Faster loading of the user list');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_task, @s_closed, @r_completed, @pr_normal, @u_aearheart, @a_sherlock, NULL, NULL, 'registered',
  'Sample: Update the team documentation',
  'The onboarding pages still describe the old login.',
  NOW() - INTERVAL 40 DAY, NOW() - INTERVAL 25 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Update the team documentation');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_question, @s_resolved, @r_answered, @pr_low, @u_inewton, @a_zeuss, NULL, NULL, 'registered',
  'Sample: Which browsers are supported?',
  'Which browsers and versions do you support?',
  NOW() - INTERVAL 11 DAY, NOW() - INTERVAL 10 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Which browsers are supported?');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_license, @s_open, NULL, @pr_normal, @u_mcurie, NULL, NULL, NULL, 'registered',
  'Sample: License request for 25 seats',
  'We would like to run LeweDesk for 25 users. Please send an offer.',
  NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: License request for 25 seats');

-- Reporter issues (only the reporter, Agents and Administrators see them)
INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_license, @s_open, NULL, @pr_high, @u_alupin, NULL, NULL, NULL, 'reporter',
  'Sample: Confidential license key lost',
  'I lost my license key. Please send it again, I do not want this issue to be visible to others.',
  NOW() - INTERVAL 4 DAY, NOW() - INTERVAL 4 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Confidential license key lost');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_bug, @s_agent, NULL, @pr_normal, @u_jmarple, @a_einstein, @p_sample, '1.2.0', 'reporter',
  'Sample: Password reset mail contains my full name and address',
  'The reset mail shows more personal data than needed. Please keep this private until it is fixed.',
  NOW() - INTERVAL 7 DAY, NOW() - INTERVAL 6 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Password reset mail contains my full name and address');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_improvement, @s_closed, @r_duplicate, @pr_low, @u_mcurie, @a_sherlock, NULL, NULL, 'reporter',
  'Sample: Export the issue list (duplicate)',
  'Same request as the question about exporting the issue list.',
  NOW() - INTERVAL 8 DAY, NOW() - INTERVAL 7 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Export the issue list (duplicate)');

-- A soft deleted issue (only visible with the permission delete_issue)
INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, deleted_at, created_at, updated_at)
SELECT @t_task, @s_open, NULL, @pr_normal, @u_athene, NULL, NULL, NULL, 'registered',
  'Sample: Test entry, please ignore',
  'Created by mistake.',
  NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 3 DAY, NOW() - INTERVAL 1 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Test entry, please ignore');

-- Further sample issues (100) in all types, status, products and access levels for judging the dashboard statistics
INSERT IGNORE INTO products (name, description, icon, color, background_color) VALUES
('Sample Portal', 'A second product to try out the issues', 'bi-globe', '#1b9e3e', '#1b9e3e'),
('Sample Mobile', 'A third product to try out the issues', 'bi-phone', '#e55353', '#e55353');
SET @p_portal = (SELECT id FROM products WHERE name = 'Sample Portal');
SET @p_mobile = (SELECT id FROM products WHERE name = 'Sample Mobile');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_improvement, @s_pending, NULL, @pr_high, @u_jmarple, @a_einstein, @p_portal, '1.1.0', 'reporter',
  'Sample: Remember my last choice in login page',
  'Sample issue 1 to fill the dashboard statistics.

Area: Remember my last choice in login page',
  NOW() - INTERVAL 33 DAY, NOW() - INTERVAL 10 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Remember my last choice in login page');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_question, @s_closed, @r_answered, @pr_critical, @u_inewton, @a_einstein, @p_sample, '1.1.4', 'public',
  'Sample: How does comment editor work?',
  'Sample issue 2 to fill the dashboard statistics.

Area: How does comment editor work?',
  NOW() - INTERVAL 58 DAY, NOW() - INTERVAL 32 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: How does comment editor work?');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_question, @s_customer, NULL, @pr_normal, @u_hermes, @a_zeuss, NULL, NULL, 'public',
  'Sample: How does email digest work?',
  'Sample issue 3 to fill the dashboard statistics.

Area: How does email digest work?',
  NOW() - INTERVAL 85 DAY, NOW() - INTERVAL 1 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: How does email digest work?');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_feature, @s_hold, NULL, @pr_low, @u_alupin, NULL, @p_sample, '1.0.0', 'public',
  'Sample: Add bulk actions to dark mode',
  'Sample issue 4 to fill the dashboard statistics.

Area: Add bulk actions to dark mode',
  NOW() - INTERVAL 117 DAY, NOW() - INTERVAL 13 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Add bulk actions to dark mode');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_license, @s_agent, NULL, @pr_normal, @u_athene, @a_einstein, @p_sample, NULL, 'public',
  'Sample: Trial extension to evaluate notifications',
  'Sample issue 5 to fill the dashboard statistics.

Area: Trial extension to evaluate notifications',
  NOW() - INTERVAL 93 DAY, NOW() - INTERVAL 5 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Trial extension to evaluate notifications');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_question, @s_closed, @r_answered, @pr_high, @u_cyeager, @a_sherlock, @p_sample, '1.0.0', 'public',
  'Sample: Is settings page available for all roles? #6',
  'Sample issue 6 to fill the dashboard statistics.

Area: Is settings page available for all roles? #6',
  NOW() - INTERVAL 140 DAY, NOW() - INTERVAL 24 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Is settings page available for all roles? #6');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_question, @s_closed, @r_answered, @pr_normal, @u_mcurie, @a_sherlock, @p_mobile, '1.3.0', 'public',
  'Sample: What is the limit of user profile?',
  'Sample issue 7 to fill the dashboard statistics.

Area: What is the limit of user profile?',
  NOW() - INTERVAL 108 DAY, NOW() - INTERVAL 32 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: What is the limit of user profile?');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_license, @s_open, NULL, @pr_high, @u_athene, NULL, @p_mobile, NULL, 'registered',
  'Sample: Trial extension to evaluate PDF export',
  'Sample issue 8 to fill the dashboard statistics.

Area: Trial extension to evaluate PDF export',
  NOW() - INTERVAL 96 DAY, NOW() - INTERVAL 6 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Trial extension to evaluate PDF export');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_improvement, @s_agent, NULL, @pr_normal, @u_athene, @a_zeuss, @p_portal, '1.1.4', 'public',
  'Sample: Clearer labels in mobile layout',
  'Sample issue 9 to fill the dashboard statistics.

Area: Clearer labels in mobile layout',
  NOW() - INTERVAL 149 DAY, NOW() - INTERVAL 7 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Clearer labels in mobile layout');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_task, @s_closed, @r_completed, @pr_low, @u_mcurie, @a_einstein, @p_portal, '1.1.0', 'registered',
  'Sample: Test dashboard on the new server',
  'Sample issue 10 to fill the dashboard statistics.

Area: Test dashboard on the new server',
  NOW() - INTERVAL 104 DAY, NOW() - INTERVAL 65 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Test dashboard on the new server');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_improvement, @s_hold, NULL, @pr_normal, @u_mcurie, NULL, @p_portal, '1.3.0', 'registered',
  'Sample: Remember my last choice in tag management',
  'Sample issue 11 to fill the dashboard statistics.

Area: Remember my last choice in tag management',
  NOW() - INTERVAL 122 DAY, NOW() - INTERVAL 1 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Remember my last choice in tag management');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_feature, @s_pending, NULL, @pr_critical, @u_hermes, @a_sherlock, @p_mobile, '1.0.0', 'registered',
  'Sample: Add bulk actions to attachment upload',
  'Sample issue 12 to fill the dashboard statistics.

Area: Add bulk actions to attachment upload',
  NOW() - INTERVAL 83 DAY, NOW() - INTERVAL 9 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Add bulk actions to attachment upload');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_feature, @s_resolved, @r_completed, @pr_high, @u_aearheart, @a_einstein, @p_portal, '1.3.0', 'public',
  'Sample: Add bulk actions to password reset',
  'Sample issue 13 to fill the dashboard statistics.

Area: Add bulk actions to password reset',
  NOW() - INTERVAL 63 DAY, NOW() - INTERVAL 59 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Add bulk actions to password reset');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_improvement, @s_open, NULL, @pr_normal, @u_hermes, NULL, @p_portal, '1.0.0', 'public',
  'Sample: Remember my last choice in attachment upload',
  'Sample issue 14 to fill the dashboard statistics.

Area: Remember my last choice in attachment upload',
  NOW() - INTERVAL 94 DAY, NOW() - INTERVAL 6 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Remember my last choice in attachment upload');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_feature, @s_agent, NULL, @pr_low, @u_athene, @a_einstein, @p_sample, '1.1.4', 'reporter',
  'Sample: Add a shortcut for PDF export',
  'Sample issue 15 to fill the dashboard statistics.

Area: Add a shortcut for PDF export',
  NOW() - INTERVAL 88 DAY, NOW() - INTERVAL 4 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Add a shortcut for PDF export');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_bug, @s_agent, NULL, @pr_normal, @u_jmarple, NULL, @p_sample, '1.2.0', 'reporter',
  'Sample: status workflow is very slow with many entries',
  'Sample issue 16 to fill the dashboard statistics.

Area: status workflow is very slow with many entries',
  NOW() - INTERVAL 130 DAY, NOW() - INTERVAL 7 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: status workflow is very slow with many entries');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_feature, @s_hold, NULL, @pr_high, @u_cyeager, @a_einstein, @p_mobile, '1.3.0', 'public',
  'Sample: Add bulk actions to user profile',
  'Sample issue 17 to fill the dashboard statistics.

Area: Add bulk actions to user profile',
  NOW() - INTERVAL 23 DAY, NOW() - INTERVAL 3 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Add bulk actions to user profile');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_question, @s_closed, @r_answered, @pr_normal, @u_alupin, NULL, @p_sample, '1.1.4', 'registered',
  'Sample: Can dark mode be customized?',
  'Sample issue 18 to fill the dashboard statistics.

Area: Can dark mode be customized?',
  NOW() - INTERVAL 127 DAY, NOW() - INTERVAL 62 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Can dark mode be customized?');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_question, @s_open, NULL, @pr_low, @u_alupin, NULL, @p_sample, '1.2.0', 'registered',
  'Sample: How does PDF export work?',
  'Sample issue 19 to fill the dashboard statistics.

Area: How does PDF export work?',
  NOW() - INTERVAL 132 DAY, NOW() - INTERVAL 11 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: How does PDF export work?');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_bug, @s_open, NULL, @pr_normal, @u_jmarple, NULL, @p_portal, '1.3.0', 'registered',
  'Sample: product page is very slow with many entries',
  'Sample issue 20 to fill the dashboard statistics.

Area: product page is very slow with many entries',
  NOW() - INTERVAL 75 DAY, NOW() - INTERVAL 5 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: product page is very slow with many entries');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_question, @s_resolved, @r_answered, @pr_high, @u_mcurie, NULL, @p_portal, '1.0.0', 'reporter',
  'Sample: Can mobile layout be customized?',
  'Sample issue 21 to fill the dashboard statistics.

Area: Can mobile layout be customized?',
  NOW() - INTERVAL 40 DAY, NOW() - INTERVAL 32 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Can mobile layout be customized?');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_bug, @s_hold, NULL, @pr_critical, @u_alupin, @a_zeuss, @p_sample, '1.1.0', 'registered',
  'Sample: dashboard is very slow with many entries',
  'Sample issue 22 to fill the dashboard statistics.

Area: dashboard is very slow with many entries',
  NOW() - INTERVAL 121 DAY, NOW() - INTERVAL 11 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: dashboard is very slow with many entries');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_question, @s_pending, NULL, @pr_normal, @u_alupin, @a_zeuss, @p_sample, '1.2.0', 'registered',
  'Sample: How does product page work?',
  'Sample issue 23 to fill the dashboard statistics.

Area: How does product page work?',
  NOW() - INTERVAL 28 DAY, NOW() - INTERVAL 8 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: How does product page work?');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_bug, @s_agent, NULL, @pr_low, @u_inewton, @a_zeuss, @p_mobile, '1.0.0', 'public',
  'Sample: notifications is very slow with many entries',
  'Sample issue 24 to fill the dashboard statistics.

Area: notifications is very slow with many entries',
  NOW() - INTERVAL 10 DAY, NOW() - INTERVAL 6 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: notifications is very slow with many entries');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_license, @s_resolved, @r_completed, @pr_normal, @u_hermes, @a_einstein, @p_portal, NULL, 'public',
  'Sample: Trial extension to evaluate dashboard',
  'Sample issue 25 to fill the dashboard statistics.

Area: Trial extension to evaluate dashboard',
  NOW() - INTERVAL 119 DAY, NOW() - INTERVAL 103 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Trial extension to evaluate dashboard');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_task, @s_open, NULL, @pr_high, @u_hermes, NULL, NULL, NULL, 'reporter',
  'Sample: Clean up old data of issue list',
  'Sample issue 26 to fill the dashboard statistics.

Area: Clean up old data of issue list',
  NOW() - INTERVAL 69 DAY, NOW() - INTERVAL 0 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Clean up old data of issue list');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_bug, @s_open, NULL, @pr_normal, @u_mcurie, NULL, @p_mobile, '1.3.0', 'registered',
  'Sample: permissions page is very slow with many entries',
  'Sample issue 27 to fill the dashboard statistics.

Area: permissions page is very slow with many entries',
  NOW() - INTERVAL 77 DAY, NOW() - INTERVAL 11 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: permissions page is very slow with many entries');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_feature, @s_resolved, @r_completed, @pr_high, @u_inewton, @a_einstein, @p_mobile, '1.1.4', 'public',
  'Sample: Allow CSV export from password reset',
  'Sample issue 28 to fill the dashboard statistics.

Area: Allow CSV export from password reset',
  NOW() - INTERVAL 11 DAY, NOW() - INTERVAL 5 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Allow CSV export from password reset');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_improvement, @s_open, NULL, @pr_normal, @u_jmarple, NULL, NULL, NULL, 'registered',
  'Sample: Improve the performance of issue list',
  'Sample issue 29 to fill the dashboard statistics.

Area: Improve the performance of issue list',
  NOW() - INTERVAL 28 DAY, NOW() - INTERVAL 4 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Improve the performance of issue list');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_improvement, @s_resolved, @r_completed, @pr_low, @u_mcurie, @a_zeuss, @p_mobile, '1.1.4', 'reporter',
  'Sample: Make issue list easier to use',
  'Sample issue 30 to fill the dashboard statistics.

Area: Make issue list easier to use',
  NOW() - INTERVAL 117 DAY, NOW() - INTERVAL 65 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Make issue list easier to use');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_question, @s_closed, @r_answered, @pr_normal, @u_athene, @a_zeuss, @p_sample, '1.1.0', 'reporter',
  'Sample: What is the limit of permissions page? #31',
  'Sample issue 31 to fill the dashboard statistics.

Area: What is the limit of permissions page? #31',
  NOW() - INTERVAL 13 DAY, NOW() - INTERVAL 0 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: What is the limit of permissions page? #31');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_bug, @s_resolved, @r_duplicate, @pr_critical, @u_aearheart, @a_sherlock, @p_mobile, '1.3.0', 'public',
  'Sample: attachment upload loses my input on reload',
  'Sample issue 32 to fill the dashboard statistics.

Area: attachment upload loses my input on reload',
  NOW() - INTERVAL 91 DAY, NOW() - INTERVAL 70 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: attachment upload loses my input on reload');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_feature, @s_resolved, @r_completed, @pr_high, @u_alupin, @a_einstein, @p_portal, '1.2.0', 'registered',
  'Sample: Add a shortcut for dark mode',
  'Sample issue 33 to fill the dashboard statistics.

Area: Add a shortcut for dark mode',
  NOW() - INTERVAL 40 DAY, NOW() - INTERVAL 32 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Add a shortcut for dark mode');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_improvement, @s_agent, NULL, @pr_normal, @u_mcurie, NULL, @p_portal, '1.3.0', 'public',
  'Sample: Improve the performance of status workflow',
  'Sample issue 34 to fill the dashboard statistics.

Area: Improve the performance of status workflow',
  NOW() - INTERVAL 133 DAY, NOW() - INTERVAL 1 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Improve the performance of status workflow');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_task, @s_open, NULL, @pr_low, @u_aearheart, @a_zeuss, @p_portal, '1.1.4', 'public',
  'Sample: Test attachment upload on the new server',
  'Sample issue 35 to fill the dashboard statistics.

Area: Test attachment upload on the new server',
  NOW() - INTERVAL 117 DAY, NOW() - INTERVAL 7 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Test attachment upload on the new server');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_feature, @s_closed, @r_completed, @pr_normal, @u_inewton, NULL, NULL, NULL, 'reporter',
  'Sample: Allow CSV export from product page',
  'Sample issue 36 to fill the dashboard statistics.

Area: Allow CSV export from product page',
  NOW() - INTERVAL 90 DAY, NOW() - INTERVAL 6 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Allow CSV export from product page');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_improvement, @s_agent, NULL, @pr_high, @u_athene, @a_sherlock, @p_sample, '1.2.0', 'public',
  'Sample: Remember my last choice in email digest',
  'Sample issue 37 to fill the dashboard statistics.

Area: Remember my last choice in email digest',
  NOW() - INTERVAL 45 DAY, NOW() - INTERVAL 9 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Remember my last choice in email digest');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_improvement, @s_resolved, @r_completed, @pr_normal, @u_alupin, @a_einstein, @p_portal, '1.1.0', 'reporter',
  'Sample: Clearer labels in language switch',
  'Sample issue 38 to fill the dashboard statistics.

Area: Clearer labels in language switch',
  NOW() - INTERVAL 62 DAY, NOW() - INTERVAL 33 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Clearer labels in language switch');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_bug, @s_closed, @r_fixed, @pr_low, @u_mcurie, @a_sherlock, @p_sample, '1.1.4', 'reporter',
  'Sample: Wrong text displayed in attachment upload',
  'Sample issue 39 to fill the dashboard statistics.

Area: Wrong text displayed in attachment upload',
  NOW() - INTERVAL 51 DAY, NOW() - INTERVAL 23 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Wrong text displayed in attachment upload');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_feature, @s_resolved, @r_completed, @pr_normal, @u_athene, @a_einstein, @p_sample, '1.0.0', 'public',
  'Sample: Add a shortcut for status workflow',
  'Sample issue 40 to fill the dashboard statistics.

Area: Add a shortcut for status workflow',
  NOW() - INTERVAL 126 DAY, NOW() - INTERVAL 2 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Add a shortcut for status workflow');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_question, @s_open, NULL, @pr_high, @u_alupin, NULL, @p_portal, '1.1.4', 'reporter',
  'Sample: How does login page work?',
  'Sample issue 41 to fill the dashboard statistics.

Area: How does login page work?',
  NOW() - INTERVAL 139 DAY, NOW() - INTERVAL 12 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: How does login page work?');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_bug, @s_resolved, @r_fixed, @pr_critical, @u_inewton, @a_zeuss, @p_portal, '1.1.4', 'registered',
  'Sample: PDF export shows an error after saving',
  'Sample issue 42 to fill the dashboard statistics.

Area: PDF export shows an error after saving',
  NOW() - INTERVAL 36 DAY, NOW() - INTERVAL 27 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: PDF export shows an error after saving');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_feature, @s_agent, NULL, @pr_normal, @u_alupin, @a_zeuss, @p_sample, '1.1.0', 'registered',
  'Sample: Add bulk actions to mobile layout',
  'Sample issue 43 to fill the dashboard statistics.

Area: Add bulk actions to mobile layout',
  NOW() - INTERVAL 71 DAY, NOW() - INTERVAL 13 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Add bulk actions to mobile layout');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_task, @s_closed, @r_completed, @pr_low, @u_athene, @a_zeuss, @p_sample, '1.3.0', 'registered',
  'Sample: Clean up old data of PDF export',
  'Sample issue 44 to fill the dashboard statistics.

Area: Clean up old data of PDF export',
  NOW() - INTERVAL 81 DAY, NOW() - INTERVAL 30 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Clean up old data of PDF export');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_feature, @s_customer, NULL, @pr_normal, @u_hermes, @a_zeuss, @p_sample, '1.1.0', 'registered',
  'Sample: Support drag and drop in mobile layout',
  'Sample issue 45 to fill the dashboard statistics.

Area: Support drag and drop in mobile layout',
  NOW() - INTERVAL 18 DAY, NOW() - INTERVAL 7 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Support drag and drop in mobile layout');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_task, @s_hold, NULL, @pr_high, @u_inewton, @a_einstein, @p_sample, '1.1.4', 'public',
  'Sample: Test comment editor on the new server',
  'Sample issue 46 to fill the dashboard statistics.

Area: Test comment editor on the new server',
  NOW() - INTERVAL 59 DAY, NOW() - INTERVAL 4 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Test comment editor on the new server');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_bug, @s_closed, @r_duplicate, @pr_normal, @u_jmarple, @a_zeuss, @p_portal, '1.2.0', 'registered',
  'Sample: PDF export loses my input on reload',
  'Sample issue 47 to fill the dashboard statistics.

Area: PDF export loses my input on reload',
  NOW() - INTERVAL 28 DAY, NOW() - INTERVAL 16 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: PDF export loses my input on reload');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_feature, @s_closed, @r_completed, @pr_high, @u_inewton, NULL, NULL, NULL, 'reporter',
  'Sample: Allow CSV export from language switch',
  'Sample issue 48 to fill the dashboard statistics.

Area: Allow CSV export from language switch',
  NOW() - INTERVAL 109 DAY, NOW() - INTERVAL 10 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Allow CSV export from language switch');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_task, @s_resolved, @r_completed, @pr_normal, @u_aearheart, @a_zeuss, @p_mobile, '1.0.0', 'public',
  'Sample: Test tag management on the new server',
  'Sample issue 49 to fill the dashboard statistics.

Area: Test tag management on the new server',
  NOW() - INTERVAL 144 DAY, NOW() - INTERVAL 12 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Test tag management on the new server');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_improvement, @s_resolved, @r_completed, @pr_low, @u_jmarple, @a_sherlock, @p_sample, '1.2.0', 'registered',
  'Sample: Improve the performance of mobile layout',
  'Sample issue 50 to fill the dashboard statistics.

Area: Improve the performance of mobile layout',
  NOW() - INTERVAL 151 DAY, NOW() - INTERVAL 10 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Improve the performance of mobile layout');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_improvement, @s_closed, @r_completed, @pr_normal, @u_inewton, @a_zeuss, @p_sample, '1.2.0', 'public',
  'Sample: Remember my last choice in settings page',
  'Sample issue 51 to fill the dashboard statistics.

Area: Remember my last choice in settings page',
  NOW() - INTERVAL 96 DAY, NOW() - INTERVAL 87 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Remember my last choice in settings page');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_feature, @s_pending, NULL, @pr_critical, @u_inewton, @a_einstein, @p_portal, '1.3.0', 'registered',
  'Sample: Support drag and drop in attachment upload',
  'Sample issue 52 to fill the dashboard statistics.

Area: Support drag and drop in attachment upload',
  NOW() - INTERVAL 84 DAY, NOW() - INTERVAL 13 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Support drag and drop in attachment upload');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_feature, @s_agent, NULL, @pr_high, @u_alupin, @a_zeuss, @p_portal, '1.1.4', 'reporter',
  'Sample: Support drag and drop in password reset',
  'Sample issue 53 to fill the dashboard statistics.

Area: Support drag and drop in password reset',
  NOW() - INTERVAL 31 DAY, NOW() - INTERVAL 8 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Support drag and drop in password reset');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_bug, @s_closed, @r_fixed, @pr_normal, @u_alupin, @a_sherlock, @p_portal, '1.1.0', 'registered',
  'Sample: dark mode shows an error after saving',
  'Sample issue 54 to fill the dashboard statistics.

Area: dark mode shows an error after saving',
  NOW() - INTERVAL 101 DAY, NOW() - INTERVAL 47 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: dark mode shows an error after saving');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_task, @s_customer, NULL, @pr_low, @u_mcurie, @a_einstein, @p_sample, '1.1.4', 'reporter',
  'Sample: Clean up old data of status workflow',
  'Sample issue 55 to fill the dashboard statistics.

Area: Clean up old data of status workflow',
  NOW() - INTERVAL 106 DAY, NOW() - INTERVAL 8 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Clean up old data of status workflow');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_license, @s_agent, NULL, @pr_normal, @u_athene, @a_zeuss, @p_sample, NULL, 'public',
  'Sample: Invoice and license key for password reset',
  'Sample issue 56 to fill the dashboard statistics.

Area: Invoice and license key for password reset',
  NOW() - INTERVAL 42 DAY, NOW() - INTERVAL 12 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Invoice and license key for password reset');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_feature, @s_open, NULL, @pr_high, @u_inewton, NULL, @p_portal, '1.2.0', 'public',
  'Sample: Allow CSV export from user profile',
  'Sample issue 57 to fill the dashboard statistics.

Area: Allow CSV export from user profile',
  NOW() - INTERVAL 44 DAY, NOW() - INTERVAL 3 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Allow CSV export from user profile');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_bug, @s_open, NULL, @pr_normal, @u_jmarple, NULL, @p_sample, '1.3.0', 'registered',
  'Sample: user profile loses my input on reload',
  'Sample issue 58 to fill the dashboard statistics.

Area: user profile loses my input on reload',
  NOW() - INTERVAL 60 DAY, NOW() - INTERVAL 11 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: user profile loses my input on reload');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_feature, @s_pending, NULL, @pr_low, @u_athene, @a_sherlock, @p_mobile, '1.1.0', 'registered',
  'Sample: Allow CSV export from email digest',
  'Sample issue 59 to fill the dashboard statistics.

Area: Allow CSV export from email digest',
  NOW() - INTERVAL 92 DAY, NOW() - INTERVAL 5 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Allow CSV export from email digest');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_bug, @s_agent, NULL, @pr_normal, @u_aearheart, @a_einstein, @p_portal, '1.1.0', 'reporter',
  'Sample: Layout of email digest is broken on small screens',
  'Sample issue 60 to fill the dashboard statistics.

Area: Layout of email digest is broken on small screens',
  NOW() - INTERVAL 32 DAY, NOW() - INTERVAL 8 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Layout of email digest is broken on small screens');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_task, @s_pending, NULL, @pr_high, @u_jmarple, @a_sherlock, @p_sample, '1.3.0', 'reporter',
  'Sample: Update the documentation of password reset',
  'Sample issue 61 to fill the dashboard statistics.

Area: Update the documentation of password reset',
  NOW() - INTERVAL 39 DAY, NOW() - INTERVAL 1 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Update the documentation of password reset');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_bug, @s_hold, NULL, @pr_critical, @u_mcurie, @a_zeuss, @p_sample, '1.3.0', 'public',
  'Sample: permissions page loses my input on reload',
  'Sample issue 62 to fill the dashboard statistics.

Area: permissions page loses my input on reload',
  NOW() - INTERVAL 93 DAY, NOW() - INTERVAL 0 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: permissions page loses my input on reload');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_improvement, @s_open, NULL, @pr_normal, @u_aearheart, NULL, @p_mobile, '1.3.0', 'registered',
  'Sample: Make search easier to use',
  'Sample issue 63 to fill the dashboard statistics.

Area: Make search easier to use',
  NOW() - INTERVAL 116 DAY, NOW() - INTERVAL 8 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Make search easier to use');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_improvement, @s_closed, @r_completed, @pr_low, @u_mcurie, @a_sherlock, @p_sample, '1.3.0', 'registered',
  'Sample: Make settings page easier to use',
  'Sample issue 64 to fill the dashboard statistics.

Area: Make settings page easier to use',
  NOW() - INTERVAL 39 DAY, NOW() - INTERVAL 4 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Make settings page easier to use');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_bug, @s_open, NULL, @pr_normal, @u_jmarple, NULL, NULL, NULL, 'public',
  'Sample: settings page is very slow with many entries',
  'Sample issue 65 to fill the dashboard statistics.

Area: settings page is very slow with many entries',
  NOW() - INTERVAL 52 DAY, NOW() - INTERVAL 11 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: settings page is very slow with many entries');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_bug, @s_closed, @r_fixed, @pr_high, @u_inewton, @a_zeuss, @p_portal, '1.3.0', 'reporter',
  'Sample: tag management shows an error after saving',
  'Sample issue 66 to fill the dashboard statistics.

Area: tag management shows an error after saving',
  NOW() - INTERVAL 151 DAY, NOW() - INTERVAL 44 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: tag management shows an error after saving');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_task, @s_hold, NULL, @pr_normal, @u_alupin, @a_einstein, @p_portal, '1.1.4', 'registered',
  'Sample: Update the documentation of email digest',
  'Sample issue 67 to fill the dashboard statistics.

Area: Update the documentation of email digest',
  NOW() - INTERVAL 55 DAY, NOW() - INTERVAL 7 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Update the documentation of email digest');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_task, @s_agent, NULL, @pr_high, @u_hermes, @a_einstein, @p_sample, '1.2.0', 'reporter',
  'Sample: Test email digest on the new server #68',
  'Sample issue 68 to fill the dashboard statistics.

Area: Test email digest on the new server #68',
  NOW() - INTERVAL 51 DAY, NOW() - INTERVAL 11 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Test email digest on the new server #68');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_bug, @s_hold, NULL, @pr_normal, @u_jmarple, @a_einstein, @p_mobile, '1.1.0', 'registered',
  'Sample: dashboard loses my input on reload',
  'Sample issue 69 to fill the dashboard statistics.

Area: dashboard loses my input on reload',
  NOW() - INTERVAL 63 DAY, NOW() - INTERVAL 1 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: dashboard loses my input on reload');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_bug, @s_resolved, @r_fixed, @pr_low, @u_alupin, @a_einstein, @p_portal, '1.1.4', 'public',
  'Sample: password reset shows an error after saving #70',
  'Sample issue 70 to fill the dashboard statistics.

Area: password reset shows an error after saving #70',
  NOW() - INTERVAL 44 DAY, NOW() - INTERVAL 13 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: password reset shows an error after saving #70');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_bug, @s_resolved, @r_duplicate, @pr_normal, @u_mcurie, @a_einstein, @p_mobile, '1.2.0', 'reporter',
  'Sample: mobile layout shows an error after saving #71',
  'Sample issue 71 to fill the dashboard statistics.

Area: mobile layout shows an error after saving #71',
  NOW() - INTERVAL 17 DAY, NOW() - INTERVAL 3 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: mobile layout shows an error after saving #71');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_bug, @s_open, NULL, @pr_critical, @u_athene, NULL, @p_sample, '1.0.0', 'registered',
  'Sample: Wrong text displayed in language switch',
  'Sample issue 72 to fill the dashboard statistics.

Area: Wrong text displayed in language switch',
  NOW() - INTERVAL 126 DAY, NOW() - INTERVAL 7 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Wrong text displayed in language switch');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_question, @s_agent, NULL, @pr_high, @u_alupin, @a_sherlock, @p_sample, '1.2.0', 'public',
  'Sample: Can issue list be customized?',
  'Sample issue 73 to fill the dashboard statistics.

Area: Can issue list be customized?',
  NOW() - INTERVAL 116 DAY, NOW() - INTERVAL 10 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Can issue list be customized?');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_improvement, @s_customer, NULL, @pr_normal, @u_hermes, @a_einstein, @p_sample, '1.1.0', 'registered',
  'Sample: Make user profile easier to use',
  'Sample issue 74 to fill the dashboard statistics.

Area: Make user profile easier to use',
  NOW() - INTERVAL 46 DAY, NOW() - INTERVAL 4 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Make user profile easier to use');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_feature, @s_open, NULL, @pr_low, @u_athene, NULL, @p_mobile, '1.0.0', 'registered',
  'Sample: Add a shortcut for REST API',
  'Sample issue 75 to fill the dashboard statistics.

Area: Add a shortcut for REST API',
  NOW() - INTERVAL 63 DAY, NOW() - INTERVAL 4 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Add a shortcut for REST API');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_feature, @s_pending, NULL, @pr_normal, @u_inewton, @a_einstein, @p_sample, '1.1.0', 'public',
  'Sample: Support drag and drop in permissions page #76',
  'Sample issue 76 to fill the dashboard statistics.

Area: Support drag and drop in permissions page #76',
  NOW() - INTERVAL 9 DAY, NOW() - INTERVAL 3 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Support drag and drop in permissions page #76');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_task, @s_closed, @r_completed, @pr_high, @u_aearheart, @a_einstein, @p_sample, '1.1.4', 'public',
  'Sample: Clean up old data of attachment upload',
  'Sample issue 77 to fill the dashboard statistics.

Area: Clean up old data of attachment upload',
  NOW() - INTERVAL 101 DAY, NOW() - INTERVAL 16 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Clean up old data of attachment upload');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_bug, @s_pending, NULL, @pr_normal, @u_inewton, @a_einstein, @p_portal, '1.0.0', 'registered',
  'Sample: Wrong text displayed in user profile #78',
  'Sample issue 78 to fill the dashboard statistics.

Area: Wrong text displayed in user profile #78',
  NOW() - INTERVAL 82 DAY, NOW() - INTERVAL 3 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Wrong text displayed in user profile #78');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_improvement, @s_open, NULL, @pr_low, @u_athene, NULL, NULL, NULL, 'reporter',
  'Sample: Make PDF export easier to use',
  'Sample issue 79 to fill the dashboard statistics.

Area: Make PDF export easier to use',
  NOW() - INTERVAL 32 DAY, NOW() - INTERVAL 12 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Make PDF export easier to use');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_question, @s_closed, @r_answered, @pr_normal, @u_aearheart, @a_zeuss, @p_mobile, '1.2.0', 'registered',
  'Sample: Where can I configure language switch?',
  'Sample issue 80 to fill the dashboard statistics.

Area: Where can I configure language switch?',
  NOW() - INTERVAL 148 DAY, NOW() - INTERVAL 101 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Where can I configure language switch?');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_license, @s_agent, NULL, @pr_high, @u_mcurie, @a_einstein, @p_mobile, NULL, 'registered',
  'Sample: Renewal of the license, question about dashboard',
  'Sample issue 81 to fill the dashboard statistics.

Area: Renewal of the license, question about dashboard',
  NOW() - INTERVAL 32 DAY, NOW() - INTERVAL 3 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Renewal of the license, question about dashboard');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_improvement, @s_resolved, @r_completed, @pr_critical, @u_inewton, @a_einstein, @p_portal, '1.2.0', 'public',
  'Sample: Better keyboard support in settings page',
  'Sample issue 82 to fill the dashboard statistics.

Area: Better keyboard support in settings page',
  NOW() - INTERVAL 69 DAY, NOW() - INTERVAL 9 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Better keyboard support in settings page');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_task, @s_open, NULL, @pr_normal, @u_hermes, NULL, @p_portal, '1.3.0', 'public',
  'Sample: Update the documentation of settings page',
  'Sample issue 83 to fill the dashboard statistics.

Area: Update the documentation of settings page',
  NOW() - INTERVAL 81 DAY, NOW() - INTERVAL 3 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Update the documentation of settings page');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_task, @s_agent, NULL, @pr_low, @u_athene, @a_sherlock, @p_mobile, '1.1.0', 'public',
  'Sample: Review the texts of product page #84',
  'Sample issue 84 to fill the dashboard statistics.

Area: Review the texts of product page #84',
  NOW() - INTERVAL 67 DAY, NOW() - INTERVAL 8 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Review the texts of product page #84');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_question, @s_agent, NULL, @pr_normal, @u_athene, @a_zeuss, NULL, NULL, 'public',
  'Sample: What is the limit of settings page?',
  'Sample issue 85 to fill the dashboard statistics.

Area: What is the limit of settings page?',
  NOW() - INTERVAL 137 DAY, NOW() - INTERVAL 4 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: What is the limit of settings page?');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_bug, @s_closed, @r_fixed, @pr_high, @u_mcurie, @a_einstein, @p_sample, '1.1.0', 'registered',
  'Sample: Layout of comment editor is broken on small screens',
  'Sample issue 86 to fill the dashboard statistics.

Area: Layout of comment editor is broken on small screens',
  NOW() - INTERVAL 6 DAY, NOW() - INTERVAL 4 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Layout of comment editor is broken on small screens');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_bug, @s_resolved, @r_fixed, @pr_normal, @u_alupin, @a_sherlock, @p_portal, '1.2.0', 'public',
  'Sample: Wrong text displayed in mobile layout',
  'Sample issue 87 to fill the dashboard statistics.

Area: Wrong text displayed in mobile layout',
  NOW() - INTERVAL 83 DAY, NOW() - INTERVAL 1 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Wrong text displayed in mobile layout');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_bug, @s_resolved, @r_fixed, @pr_high, @u_inewton, @a_zeuss, @p_sample, '1.2.0', 'reporter',
  'Sample: dark mode is very slow with many entries',
  'Sample issue 88 to fill the dashboard statistics.

Area: dark mode is very slow with many entries',
  NOW() - INTERVAL 36 DAY, NOW() - INTERVAL 9 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: dark mode is very slow with many entries');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_license, @s_closed, @r_completed, @pr_normal, @u_jmarple, @a_einstein, @p_portal, NULL, 'registered',
  'Sample: Invoice and license key for PDF export',
  'Sample issue 89 to fill the dashboard statistics.

Area: Invoice and license key for PDF export',
  NOW() - INTERVAL 134 DAY, NOW() - INTERVAL 76 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Invoice and license key for PDF export');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_task, @s_resolved, @r_completed, @pr_low, @u_cyeager, @a_einstein, NULL, NULL, 'registered',
  'Sample: Review the texts of product page',
  'Sample issue 90 to fill the dashboard statistics.

Area: Review the texts of product page',
  NOW() - INTERVAL 126 DAY, NOW() - INTERVAL 44 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Review the texts of product page');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_feature, @s_agent, NULL, @pr_normal, @u_inewton, @a_sherlock, @p_sample, '1.2.0', 'public',
  'Sample: Add a shortcut for mobile layout',
  'Sample issue 91 to fill the dashboard statistics.

Area: Add a shortcut for mobile layout',
  NOW() - INTERVAL 6 DAY, NOW() - INTERVAL 0 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Add a shortcut for mobile layout');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_bug, @s_closed, @r_duplicate, @pr_critical, @u_inewton, @a_sherlock, NULL, NULL, 'public',
  'Sample: issue list loses my input on reload',
  'Sample issue 92 to fill the dashboard statistics.

Area: issue list loses my input on reload',
  NOW() - INTERVAL 137 DAY, NOW() - INTERVAL 41 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: issue list loses my input on reload');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_question, @s_closed, @r_answered, @pr_high, @u_hermes, @a_sherlock, @p_sample, '1.1.0', 'public',
  'Sample: Where can I configure email digest?',
  'Sample issue 93 to fill the dashboard statistics.

Area: Where can I configure email digest?',
  NOW() - INTERVAL 41 DAY, NOW() - INTERVAL 9 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Where can I configure email digest?');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_task, @s_closed, @r_completed, @pr_normal, @u_cyeager, @a_sherlock, @p_mobile, '1.3.0', 'public',
  'Sample: Prepare release notes for product page',
  'Sample issue 94 to fill the dashboard statistics.

Area: Prepare release notes for product page',
  NOW() - INTERVAL 108 DAY, NOW() - INTERVAL 79 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Prepare release notes for product page');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_improvement, @s_customer, NULL, @pr_low, @u_cyeager, @a_zeuss, @p_sample, '1.1.0', 'registered',
  'Sample: Better keyboard support in search',
  'Sample issue 95 to fill the dashboard statistics.

Area: Better keyboard support in search',
  NOW() - INTERVAL 118 DAY, NOW() - INTERVAL 0 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Better keyboard support in search');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_improvement, @s_closed, @r_completed, @pr_normal, @u_athene, @a_einstein, @p_sample, '1.1.0', 'reporter',
  'Sample: Improve the performance of notifications',
  'Sample issue 96 to fill the dashboard statistics.

Area: Improve the performance of notifications',
  NOW() - INTERVAL 45 DAY, NOW() - INTERVAL 8 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Improve the performance of notifications');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_question, @s_hold, NULL, @pr_high, @u_alupin, @a_sherlock, @p_sample, '1.2.0', 'public',
  'Sample: Can dashboard be customized?',
  'Sample issue 97 to fill the dashboard statistics.

Area: Can dashboard be customized?',
  NOW() - INTERVAL 151 DAY, NOW() - INTERVAL 10 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Can dashboard be customized?');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_bug, @s_open, NULL, @pr_normal, @u_aearheart, NULL, @p_sample, '1.1.0', 'public',
  'Sample: search is very slow with many entries',
  'Sample issue 98 to fill the dashboard statistics.

Area: search is very slow with many entries',
  NOW() - INTERVAL 101 DAY, NOW() - INTERVAL 5 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: search is very slow with many entries');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_feature, @s_customer, NULL, @pr_low, @u_hermes, @a_zeuss, @p_portal, '1.1.0', 'registered',
  'Sample: Add bulk actions to permissions page',
  'Sample issue 99 to fill the dashboard statistics.

Area: Add bulk actions to permissions page',
  NOW() - INTERVAL 104 DAY, NOW() - INTERVAL 3 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Add bulk actions to permissions page');

INSERT INTO issues (type_id, status_id, resolution_id, priority_id, reporter_id, assignee_id, product_id, version, access, summary, description, created_at, updated_at)
SELECT @t_feature, @s_closed, @r_completed, @pr_normal, @u_inewton, @a_einstein, @p_portal, '1.1.4', 'reporter',
  'Sample: Add filters to tag management',
  'Sample issue 100 to fill the dashboard statistics.

Area: Add filters to tag management',
  NOW() - INTERVAL 85 DAY, NOW() - INTERVAL 13 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM issues WHERE summary = 'Sample: Add filters to tag management');

-- Comments (a comment is only added if the issue has no comment with the same text yet, so the script can
-- be run again). The deleted comment is matched by its author, because a deleted comment has no text.
INSERT INTO comments (issue_id, author_id, body, is_internal, edited_at, deleted_at, created_at, updated_at)
SELECT i.id, @a_sherlock, 'Hello! There is no export yet. As a workaround you can copy the table from the list page.', 0, NULL, NULL, NOW() - INTERVAL 8 DAY, NOW() - INTERVAL 8 DAY
FROM issues i WHERE i.summary = 'Sample: How do I export the issue list?'
  AND NOT EXISTS (SELECT 1 FROM comments c WHERE c.issue_id = i.id AND c.body = 'Hello! There is no export yet. As a workaround you can copy the table from the list page.');

INSERT INTO comments (issue_id, author_id, body, is_internal, edited_at, deleted_at, created_at, updated_at)
SELECT i.id, @u_mcurie, 'Thank you. A **CSV export** would be great, I will add this to my wish list.', 0, NOW() - INTERVAL 7 DAY, NULL, NOW() - INTERVAL 8 DAY, NOW() - INTERVAL 7 DAY
FROM issues i WHERE i.summary = 'Sample: How do I export the issue list?'
  AND NOT EXISTS (SELECT 1 FROM comments c WHERE c.issue_id = i.id AND c.body = 'Thank you. A **CSV export** would be great, I will add this to my wish list.');

INSERT INTO comments (issue_id, author_id, body, is_internal, edited_at, deleted_at, created_at, updated_at)
SELECT i.id, @a_sherlock, 'Internal: the customer has a support contract, handle with priority.', 1, NULL, NULL, NOW() - INTERVAL 8 DAY, NOW() - INTERVAL 8 DAY
FROM issues i WHERE i.summary = 'Sample: How do I export the issue list?'
  AND NOT EXISTS (SELECT 1 FROM comments c WHERE c.issue_id = i.id AND c.body = 'Internal: the customer has a support contract, handle with priority.');

INSERT INTO comments (issue_id, author_id, body, is_internal, edited_at, deleted_at, created_at, updated_at)
SELECT i.id, @a_sherlock, 'Fixed in `1.1.5`, see also LD-1.', 0, NULL, NULL, NOW() - INTERVAL 3 DAY, NOW() - INTERVAL 3 DAY
FROM issues i WHERE i.summary = 'Sample: Wrong date format in the German user interface'
  AND NOT EXISTS (SELECT 1 FROM comments c WHERE c.issue_id = i.id AND c.body = 'Fixed in `1.1.5`, see also LD-1.');

INSERT INTO comments (issue_id, author_id, body, is_internal, edited_at, deleted_at, created_at, updated_at)
SELECT i.id, @u_hermes, '', 0, NULL, NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 4 DAY, NOW() - INTERVAL 2 DAY
FROM issues i WHERE i.summary = 'Sample: Wrong date format in the German user interface'
  AND NOT EXISTS (SELECT 1 FROM comments c WHERE c.issue_id = i.id AND c.author_id = @u_hermes AND c.deleted_at IS NOT NULL);

INSERT INTO comments (issue_id, author_id, body, is_internal, edited_at, deleted_at, created_at, updated_at)
SELECT i.id, @a_einstein, 'Markdown works in comments too:\n\n- **bold** and *italic*\n- `code`\n\n> and quotes', 0, NULL, NULL, NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY
FROM issues i WHERE i.summary = 'Sample: Markdown in descriptions'
  AND NOT EXISTS (SELECT 1 FROM comments c WHERE c.issue_id = i.id AND c.author_id = @a_einstein);

-- History entries for the sample issues and comments (the SQL above bypasses the application)
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

-- Status change into a status of the category "done" for the resolved and closed sample issues (feeds the
-- "created vs resolved" chart of the dashboard, which counts these entries); it happens at the last update
INSERT INTO issue_history (issue_id, actor_id, event, field, old_value, new_value, created_at)
SELECT i.id, COALESCE(i.assignee_id, i.reporter_id), 'field_changed', 'status', 'open', s.`key`, i.updated_at
FROM issues i JOIN `status` s ON s.id = i.status_id
WHERE s.`key` IN ('resolved', 'closed') AND i.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM issue_history h WHERE h.issue_id = i.id AND h.event = 'field_changed' AND h.field = 'status');

-- ---------------------------------------------------------------------------
-- Sample custom views (see _docs/VIEWS.md): a private and a shared view of two users and a shared view without owner
-- (the owner was deleted). A view is only added if the owner has no view with the same name, so the script can be run again.
-- ---------------------------------------------------------------------------
INSERT INTO custom_views (owner_id, name, shared, filters)
SELECT u.id, 'Open issues', 0, CONCAT('{"status_id":["', s.id, '"]}')
FROM users u JOIN `status` s ON s.`key` = 'open'
WHERE u.username = 'inewton'
  AND NOT EXISTS (SELECT 1 FROM custom_views v WHERE v.owner_id = u.id AND v.name = 'Open issues');

INSERT INTO custom_views (owner_id, name, shared, filters)
SELECT u.id, 'Resolved or closed', 1, CONCAT('{"status_id":["', s1.id, '","', s2.id, '"]}')
FROM users u JOIN `status` s1 ON s1.`key` = 'resolved' JOIN `status` s2 ON s2.`key` = 'closed'
WHERE u.username = 'inewton'
  AND NOT EXISTS (SELECT 1 FROM custom_views v WHERE v.owner_id = u.id AND v.name = 'Resolved or closed');

INSERT INTO custom_views (owner_id, name, shared, filters)
SELECT u.id, 'Unassigned', 1, '{"assignee_id":["none"]}'
FROM users u
WHERE u.username = 'athene'
  AND NOT EXISTS (SELECT 1 FROM custom_views v WHERE v.owner_id = u.id AND v.name = 'Unassigned');

INSERT INTO custom_views (owner_id, name, shared, filters)
SELECT NULL, 'Everything (orphaned)', 1, '{}'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM custom_views v WHERE v.owner_id IS NULL AND v.name = 'Everything (orphaned)');

-- ---------------------------------------------------------------------------
-- Sample SLA configuration (see _docs/SLA.md): the calendar "Business hours" with holidays, the default policy
-- "Standard support" and the policy "Critical issues" that runs around the clock. Every part is only added if it does
-- not exist, so the script can be run again.
-- ---------------------------------------------------------------------------
INSERT INTO sla_calendars (name, description, timezone)
SELECT 'Business hours', 'Monday to Friday, 09:00-12:00 and 13:00-17:00', 'Europe/Berlin'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sla_calendars WHERE name = 'Business hours');

INSERT INTO sla_calendar_hours (calendar_id, weekday, start_time, end_time)
SELECT c.id, d.weekday, r.start_time, r.end_time
FROM sla_calendars c
JOIN (SELECT 1 AS weekday UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5) d
JOIN (SELECT '09:00:00' AS start_time, '12:00:00' AS end_time UNION ALL SELECT '13:00:00', '17:00:00') r
WHERE c.name = 'Business hours'
  AND NOT EXISTS (SELECT 1 FROM sla_calendar_hours h WHERE h.calendar_id = c.id);

INSERT INTO sla_holidays (calendar_id, name, date_from, date_to, recurring)
SELECT c.id, 'New Year', '2026-01-01', '2026-01-01', 1
FROM sla_calendars c WHERE c.name = 'Business hours'
  AND NOT EXISTS (SELECT 1 FROM sla_holidays h WHERE h.calendar_id = c.id AND h.name = 'New Year');

INSERT INTO sla_holidays (calendar_id, name, date_from, date_to, recurring)
SELECT c.id, 'Christmas', '2025-12-24', '2025-12-26', 1
FROM sla_calendars c WHERE c.name = 'Business hours'
  AND NOT EXISTS (SELECT 1 FROM sla_holidays h WHERE h.calendar_id = c.id AND h.name = 'Christmas');

INSERT INTO sla_policies (name, description, active, sort_order, calendar_id, warning_percent, priority_id)
SELECT 'Critical issues', 'Critical issues are handled around the clock', 1, 10, NULL, 80, (SELECT id FROM priorities WHERE `key` = 'critical')
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sla_policies WHERE name = 'Critical issues');

INSERT INTO sla_policies (name, description, active, sort_order, calendar_id, warning_percent)
SELECT 'Standard support', 'The default policy for all other issues', 1, 100, (SELECT id FROM sla_calendars WHERE name = 'Business hours'), 80
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sla_policies WHERE name = 'Standard support');

INSERT INTO sla_policy_targets (policy_id, priority_id, metric, minutes)
SELECT p.id, NULL, v.metric, v.minutes
FROM sla_policies p
JOIN (SELECT 'first_response' AS metric, 15 AS minutes UNION ALL SELECT 'resolution', 240) v
WHERE p.name = 'Critical issues'
  AND NOT EXISTS (SELECT 1 FROM sla_policy_targets t WHERE t.policy_id = p.id);

INSERT INTO sla_policy_targets (policy_id, priority_id, metric, minutes)
SELECT p.id, (SELECT id FROM priorities WHERE `key` = v.priority_key), v.metric, v.minutes
FROM sla_policies p
JOIN (
  SELECT 'critical' AS priority_key, 'first_response' AS metric, 30 AS minutes
  UNION ALL SELECT 'critical', 'resolution', 240
  UNION ALL SELECT 'high', 'first_response', 120
  UNION ALL SELECT 'high', 'resolution', 480
  UNION ALL SELECT 'normal', 'first_response', 480
  UNION ALL SELECT 'normal', 'resolution', 2400
  UNION ALL SELECT 'low', 'first_response', 960
  UNION ALL SELECT 'low', 'resolution', 4800
) v
WHERE p.name = 'Standard support'
  AND NOT EXISTS (SELECT 1 FROM sla_policy_targets t WHERE t.policy_id = p.id);

-- The clock stops while the issue waits for the customer, for a third party or is on hold
INSERT INTO sla_policy_pause_statuses (policy_id, status_id)
SELECT p.id, s.id
FROM sla_policies p
JOIN `status` s ON s.`key` IN ('waiting_for_customer', 'pending', 'on_hold')
WHERE p.name IN ('Critical issues', 'Standard support')
  AND NOT EXISTS (SELECT 1 FROM sla_policy_pause_statuses x WHERE x.policy_id = p.id);
