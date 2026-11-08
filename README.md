# LeweDesk

LeweDesk is a web based issue tracker (ticket system) for customer support. Customers report problems, ask questions and request improvements in one central place; your support team works through them with a configurable status workflow.

## Features

* Issues with types, status, resolutions, products and tags
* Workflow actions: Respond, Resolve, Close, Reopen, Assign, Watch and more
* Markdown comments with inline images and internal notes for the support team
* Attachments with size and count limits, full history of every change
* Search and quick views such as *My issues* and *Assigned to me*
* Roles, groups and permissions; each issue is *Public*, *Registered* or *Reporter only*
* Notifications in the application and by email, per user and event
* Dashboard with statistics and charts
* Two factor authentication (TOTP), LDAP and single sign-on (OpenID Connect)
* Audit log, spam protection with hourly limits, login throttle
* Dark and light mode, English, German, French and Spanish

## What is in this repository

This repository contains the **ready to run application** (the built frontend, the PHP API and the database scripts), not the source code of the frontend. Copy the files to your web server and run the installer in the browser.

## Installation

See **[INSTALL.md](INSTALL.md)**: requirements, creating the database, copying the files, the installer, cron jobs and upgrades.

## Documentation

The user and administrator manual is on GitBook: https://lewe.gitbook.io/lewedesk

## Support and issues

Please report bugs and ideas at https://github.com/glewe/lewedesk/issues

## License

See [LICENSE](LICENSE).
