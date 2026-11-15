# IONOS PHP and MySQL setup

The portal backend requires PHP 8.1 or later with PDO MySQL, Fileinfo, mbstring, sessions, and PHP mail enabled. Keep the site on HTTPS. No database password or setup secret belongs in this repository or in chat.

## Configure private server settings

1. In Webspace Explorer, locate the public web root (often named `htdocs`).
2. Copy `lycan-config.example.php` as `lycan-config.php` into the directory immediately above the public web root. Keep it outside `htdocs`.
3. Enter the database host, name, username, and password from IONOS. Set `app_key` and `setup_key` to two different, randomly generated 64-character hexadecimal secrets. Keep both private. Set `storage_dir` to a private directory outside the web root; the example places it beside the config.
4. Confirm the file is at the location expected by `/api/common.php`. The API refuses HTTP when `require_https` is enabled.

## Create the database tables

In the IONOS database's phpMyAdmin, select the database, open **Import**, and import `api/schema.sql`. The schema contains user accounts, portal records, schedules, and login throttling data. It does not create demo users or copy browser-local records.

## Initial owner account

After uploading the site and all files in `api`, visit the secure site and open the employee portal. Use its **First-time setup** form to create the owner account. Enter the `setup_key` from the private config and choose a unique username and a strong password of at least 14 characters. Setup is disabled after an owner has been created. Remove or replace the setup key in the private config after initialization.

The old browser-only demo accounts and localStorage records are intentionally not migrated. Create staff accounts from the authenticated portal; staff members must change their temporary password at first sign-in.

## Verify before using real data

1. Open `/api/db-check.php` over HTTPS; it should return `{"ok":true}`.
2. Sign in with the new owner account, create a staff account, then verify the staff member's first-sign-in password change and role restrictions.
3. Submit test quote, application, time-off, and concern forms. Confirm the records appear only to the appropriate portal roles and notifications arrive at `admin@lycandivision.com` or `l.kornick@lycandivision.com` as configured.
4. Test résumé and gallery uploads, including that résumés are only downloadable by HR and designated leadership roles. Confirm private files are outside the web root.
5. Keep demo warnings and do not enter real records until these checks pass on the actual IONOS host. The local static `serve.ps1` does not run PHP and cannot verify this backend.
