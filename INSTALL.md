# Installing LeweDesk

This guide takes you from the downloaded files to a running LeweDesk. The user and administrator manual is on GitBook: https://lewe.gitbook.io/leti

## Requirements

| What | Needed |
|---|---|
| Web server | Apache 2.4 with `mod_rewrite` and `AllowOverride All` for the LeweDesk folder (the `.htaccess` files do the work). nginx works too, see [nginx](#nginx). |
| PHP | 8.1 or newer with the extensions `pdo_mysql`, `mbstring`, `openssl`, `curl`, `json` and `ctype`. The extension `ldap` is only needed for LDAP login. |
| Database | MySQL 8 or MariaDB 10.5+ (a database with the character set `utf8mb4`) |
| Mail (optional) | An SMTP server for notifications and password reset emails |

LeweDesk can live in the root of a site (`https://desk.example.com/`) or in a sub folder (`https://example.com/desk/`).

## 1. Create the database

Create an empty database and a user that has all rights on it, for example with the `mysql` client:

```sql
CREATE DATABASE lewedesk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'lewedesk'@'localhost' IDENTIFIED BY 'a-strong-password';
GRANT ALL PRIVILEGES ON lewedesk.* TO 'lewedesk'@'localhost';
```

On shared hosting you do this in the control panel (phpMyAdmin, Plesk, ...). Note the host, port, database name, user and password.

## 2. Copy the files

Copy the **content** of this repository (not the folder itself) to the web server folder, so that `index.html`, `install.php`, `api/` and `db/` are next to each other:

```
index.html  install.php  .htaccess  assets/  api/  db/  uploads/ ...
```

The web server user must be able to write to

* the folder itself (only until the installation is done, for the `.env` file and `install.lock`), and
* the folder `uploads/` (avatars and attachments), for example `chmod 775 uploads`, or `chmod 777 uploads` if the PHP user is not in the group of the folder owner.

## 3. Run the installer

Open `https://your-server/your-folder/install.php` in the browser and follow the page:

1. **Server check.** Fix everything marked with a red cross, then reload.
2. **Database.** Enter host, port, database name, user and password. The installer tests the connection.
3. **Application.** Check the address of LeweDesk. Optionally enter the email address and the password for the administrator account (at least 8 characters) and your SMTP settings (they can be added to `.env` later).
4. **Sample data** is only for trying LeweDesk out. Do not load it on a production system.

The installer creates the tables, writes the `.env` file with a freshly generated key for the two factor authentication and creates `install.lock`, which locks the installer.

Without a password in the form, the administrator is **`admin` / `admin`**. Log in and change the password in your profile **immediately**.

## 4. Clean up

* Delete `install.php` (the result page has a button for it, or delete the file by hand).
* Remove write access from the application folder if you opened it for the installation. `uploads/` stays writable.
* Back up `.env`. Never publish it. The key `TWOFA_ENCRYPTION_KEY` in it encrypts the 2FA secrets of your users: if it is lost or changed, they cannot be read any more.

## 5. First steps

1. Log in as `admin` and open the system settings: application name, address, time zone, language, support email, attachment limits.
2. Create roles, groups and users (or let users register themselves), see the manual.
3. If you entered SMTP settings, create a test issue and check that the notification mail arrives.

## Background jobs

Nothing has to be scheduled. LeweDesk retries failed mails and deletes old notifications, mails and forgotten uploads by itself, at most every five minutes, after a response. It stores the time of the last run in `uploads/.housekeeping`, so `uploads/` must be writable.

If you prefer a fixed schedule, run this from cron, for example every five minutes (command line only; the web server refuses these scripts):

```
*/5 * * * * php /path/to/lewedesk/api/housekeeping.php
0 3 * * * php /path/to/lewedesk/api/cleanup_uploads.php
```

## Configuration in `.env`

The installer writes the essential settings. `.env.example` lists every key with a comment, including these optional ones:

* **LDAP** (`LDAP_*`) and **single sign-on** with OpenID Connect (`OIDC_*`). Both are described in the manual.
* **Login throttle** (`AUTH_THROTTLE_MAX`, `AUTH_THROTTLE_WINDOW`): by default 10 wrong passwords (or registration and reset requests) per address within 15 minutes, then HTTP 429. Behind a reverse proxy the web server must pass the real client address on as `REMOTE_ADDR`, otherwise all visitors share one address.
* **Two factor authentication** (`TWOFA_ENCRYPTION_KEY`, `DISABLE_ADMIN_2FA`).

Changes in `.env` take effect at once.

## Web server notes

### Apache

The folder needs `AllowOverride All` (or at least `FileInfo`, `Limit` and `Options`). The `.htaccess` files in the root, in `api/`, `db/` and `uploads/` do the following. If your host ignores them, you must do it in the server configuration:

* `api/.htaccess` hands every request below `/api/` to `api/index.php` and passes the `Authorization` header on to PHP.
* The root `.htaccess` blocks `.env`, `install.lock` and `*.sql`. `db/` and `uploads/` are blocked as a whole.

On some hosts the rewrite rule in `api/.htaccess` needs a `RewriteBase` with the URL path of the `api` folder, for example `RewriteBase /desk/api/`. Add it as the first line.

### nginx

Not tested by the authors; a starting point for a site in the web root:

```nginx
location /api/ {
    try_files $uri /api/index.php$is_args$args;
}
location ~ ^/(db|uploads)/ { deny all; }
location ~ /(\.env.*|install\.lock)$ { deny all; }
location ~ \.sql$ { deny all; }
location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php-fpm.sock;
}
```

Serve the site over HTTPS.

## Upgrading

1. Back up the database and the folder (`.env` and `uploads/` are the parts you cannot get back).
2. Copy the new files over the old ones. Keep `.env`, `uploads/` and `install.lock` (they are not part of the download).
3. Update the database structure. Either on the command line: `php api/setup_db.php`, or in the browser: delete `install.lock`, open `install.php`, enter the database settings (they are prefilled from `.env`), tick **Use the existing database** and run it. Your `.env` and your data are kept. Delete `install.php` afterwards.
4. Read `CHANGELOG.md` for changes that need your attention.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| The pages load but every action fails, `/api/...` gives 404 | `mod_rewrite` is off or `.htaccess` is ignored (`AllowOverride`); see Web server notes |
| Login works but you are logged out at once, or API calls answer 401 | The web server does not pass the `Authorization` header to PHP |
| "Too many attempts" (429) | The login throttle: wait 15 minutes, or raise `AUTH_THROTTLE_MAX` in `.env` |
| Avatars or attachments cannot be saved | `uploads/` is not writable for the PHP user |
| No emails | `MAIL_*` is missing in `.env`, or the SMTP server refused (see the PHP error log) |
| The installer says "Already installed" | `install.lock` exists; delete it only to repeat or upgrade the installation |

Report problems at https://github.com/glewe/lewedesk/issues
