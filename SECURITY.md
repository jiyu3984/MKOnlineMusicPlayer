# Deployment security

Use the latest stable PHP security release. This deployment uses PHP 8.5.12,
verified against the SHA-256 published by [PHP releases](https://www.php.net/releases/).
Review the [PHP changelog](https://www.php.net/ChangeLog-8.php) when updating.

Copy `config.local.example.php` to `config.local.php` and configure the server
locally. Generate `admin_password_hash` with PHP's `password_hash()` using
`PASSWORD_DEFAULT`; do not put a plain password into source code. The file is
ignored by Git. `netease_cookie` and `kugou_cookie` are optional private provider
credentials. `MUSIC_CONFIG_FILE` can select a configuration file outside the
document root. Only the PHP worker should be able to read this file.

Run PHP under a dedicated account with write access only to `cache/`, `temp/`,
session storage and logs. Keep application source read-only to that account.
Permit PHP execution only for `/api.php` and `/kugou_admin.php`. Deny HTTP access
to `/cache/`, local configuration, hidden files and backups. Serve `/temp/` as
download attachments without PHP execution. Do not expose the PHP-FPM socket
or port to the internet.

Keep login Cookie files when clearing the cache: only remove expired
`cache/*.json` entries. The admin page requires its own password, uses CSRF
tokens for login and state changes, and does not return provider credentials.

The download endpoint accepts public HTTP(S) media destinations only. Private,
loopback and link-local addresses, path traversal and non-HTTP protocols are
rejected. Provider TLS certificates must be verified.
