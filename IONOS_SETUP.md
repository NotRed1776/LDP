# IONOS PHP and MySQL setup

The database connection values are kept in a private PHP file, not in the public site or Git. Get the host, database name, username, and password from your IONOS database details.

## Configure the private connection file

1. In Webspace Explorer, locate the public web root (often named `htdocs`).
2. Make a private copy of `lycan-config.example.php` named `lycan-config.php` in the directory immediately above the public web root.
3. Edit the private copy and fill in the host, database name, username, and password from IONOS. Do not upload the password to GitHub or send it in chat.
4. Confirm the private file is next to, not inside, the public web root. The API expects it at `../lycan-config.php` relative to the web root.

## Check the connection

After uploading the site and the `api` directory, open `/api/db-check.php` on the website. A working connection responds with `{"ok":true}`. Any failure returns a generic error; the specific diagnostic is written to the PHP server error log.

This only verifies that PHP can connect to MySQL. It does not yet move portal data or make the demo portal production-safe. Keep the demo warnings in place until the authenticated API and database-backed portal are implemented and tested.
