=== Lumos Backup ===
Contributors: lumosbackup
Donate link: https://example.com/lumos-backup
Tags: backup, restore, database, standalone, emergency, disaster recovery, s3, sftp, wp-cli, multisite, off-site
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Emergency-resilient WordPress backups paired with an independent standalone restore page that runs even when WordPress will not boot, now with S3 & SFTP remote storage, automated restore drills, WP-CLI commands, and WordPress Multisite support.

== Description ==

**Lumos Backup** is designed for genuine disaster recovery. Unlike standard backup plugins that depend on WordPress itself (WP-Cron, admin-ajax, or REST API routes) to restore your site, Lumos Backup provides an autonomous, single-file emergency restore console (`lumos-restore.php`) deployed directly in your site's document root that runs purely on PHP and MySQL—completely independent of WordPress.

### Key Features

* **WP-CLI Integration**: Full command-line management for terminal and CI/CD operations (`wp lumos backup`, `wp lumos list`, `wp lumos restore`, `wp lumos drill`, `wp lumos status`, `wp lumos token`).
* **Automated Periodic Restore Drills & Health Alerts**: Schedule recurring backup tests (daily, weekly, monthly) using WP-Cron with instant email alerts (`wp_mail()`) if any backup fails verification.
* **Advanced SQL Parser with `DELIMITER` Support**: Accurately imports databases containing stored procedures, functions, and triggers utilizing custom MySQL `DELIMITER //` or `DELIMITER $$` directives.
* **WordPress Multisite (WPMU) Support**: Network Admin management under `manage_network_options`, complete network dumps, subsite registry manifests, and safe domain rewriting across `wp_site`, `wp_blogs`, `wp_sitemeta`, and subsite options tables.
* **Native cURL SFTP Remote Destination**: Direct off-site backup uploads over secure SSH File Transfer Protocol (SFTP) using native PHP cURL without requiring PECL or external libraries.
* **Restore Drill Sandbox**: "Test latest backup" runs isolated verification drills in a sibling sandbox (`wp-content/uploads/lumos-backup/drill/<id>/`) and test database tables without touching your live site or database. Backups with no passing drill are flagged as "Unproven".
* **Dead-Server Kit (`recovery.md`)**: Automatically writes a comprehensive, secret-free recovery runbook next to the config. Provides exact manual commands (download, decrypt, verify SHA-256, import database, restore files) when PHP or the web server cannot run.
* **Zero WordPress Dependency at Restore**: The restore console does not include `wp-load.php`, `wp-config.php`, or any WordPress files.
* **S3-Compatible Remote Storage**: Off-site archive uploading supporting Amazon S3, Backblaze B2, Cloudflare R2, MinIO, and Wasabi via built-in AWS Signature Version 4 signing (zero SDK dependencies).
* **Two-Step Confirmations**: Prevents accidental clicks. The first step presents archive ID, creation timestamp, file size, and the original site URL parsed directly from `manifest.json`. The second click executes the restore.
* **Maintenance File Protection**: Automatically creates `ABSPATH/.lumos-maintenance` during restoration and guarantees its deletion upon success or failure.
* **SQL Failure Safety Boundary**: If database import encounters any SQL error, the process halts immediately. Corrupted database dumps will NEVER overwrite your existing `wp-content` files.
* **Strict Overwrite Guards**: Never overwrites `lumos-restore.php`, `wp-config.php`, `restore-config.json`, or `recovery.md`.
* **Rate-Limited Authentication**: Failed token attempts are strictly throttled (5 misses per IP per 15 minutes yields HTTP 429 Too Many Requests).
* **Audit Logging**: All restore attempts (success or failure) are recorded in `wp-content/uploads/lumos-backup/restore.log` with timestamp, client IP, and archive ID (recovery tokens are never logged).
* **Batched Database Engine**: Memory-efficient database streaming dumps tables in batches without loading entire databases into memory.
* **Cryptographic Token Gate**: Access is locked with a 32-byte cryptographic token. Only the SHA-256 hash of the token is stored on the server (`restore-config.json` mode `0600`).
* **Scheduled WP-Cron & Manual Backups**: Automated daily backups with configurable archive retention (keeps latest 7 by default on both local disk and remote S3/SFTP).

== WP-CLI Commands ==

Manage Lumos Backup directly from the command line:

* `wp lumos backup [--encrypt] [--keep=<n>]`: Run an immediate backup snapshot.
* `wp lumos list [--format=table|json|csv]`: List all local, S3, and SFTP archives with sizes and drill statuses.
* `wp lumos drill [<archive_id>]`: Run an isolated restore drill verifying integrity.
* `wp lumos restore <archive_id> [--new-siteurl=<url>] [--new-home=<url>] [--passphrase=<pass>] [--yes]`: Perform emergency site restoration with optional domain replacement.
* `wp lumos status`: Display current backup health, retention limits, remote destinations, and scheduled tasks.
* `wp lumos token [--rotate]`: Show current standalone restore URL or rotate the security token.

== Limitations & Security Disclosures ==

Please take note of the operational boundaries and technical limits:

1. **The Recovery Split: WordPress Down vs. Server Down**:
   - **WordPress Down (PHP & Web Server Operational)**: `lumos-restore.php` covers this scenario. Even if WordPress core, theme code, plugin code, or database options are broken or throwing fatal errors, you can open `lumos-restore.php?k=<token>` in your browser to restore backups with automated serialized-safe URL replacement.
   - **Server Down (PHP & Web Server Unresponsive / Dead Hardware)**: Neither WordPress nor `lumos-restore.php` can run if the server hardware is dead, PHP is not executing, or the operating system is unreachable. The Dead-Server Kit (`recovery.md` / **Download recovery sheet**) covers this scenario: it documents the off-site archive details and gives you the exact shell commands to download, decrypt, extract, and restore your site on a fresh server instance without needing PHP or the old server.

2. **Remote Credentials Storage Risk**:
   S3 and SFTP credentials are saved in the WordPress options table and mirrored into `wp-content/uploads/lumos-backup/restore-config.json` with mode `0600` alongside database credentials. This is required so the standalone emergency restore script can list and download remote archives when WordPress core and database options are inaccessible. Server file permissions must strictly prevent unauthorized system users from reading this configuration file.

3. **SQL Parser Boundaries**:
   The database importer parses SQL files with full support for single/double quotes, backticks, escaped characters, block comments, and custom `DELIMITER` directives (for stored procedures and triggers). Individual statements must fit within the server's configured MySQL `max_allowed_packet` size.

== Installation ==

1. Upload the `lumos-backup` directory to your `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress (or Network Activate on Multisite).
3. Upon activation, Lumos Backup automatically:
   - Sets up the secure archives storage directory.
   - Generates a unique 32-byte random recovery token.
   - Deploys `lumos-restore.php` to your WordPress root directory (`ABSPATH`).
   - Writes `wp-content/uploads/lumos-backup/restore-config.json` with mode `0600`.
   - Schedules the daily backup WP-Cron task.
4. Navigate to **Tools → Lumos Backup** (or Network Admin → Lumos Backup) to configure your remote destinations, set automated restore drill schedules, and copy your private recovery link.

== Frequently Asked Questions ==

= How do I configure Backblaze B2 or Cloudflare R2? =
Enter your bucket name, access key, secret key, and paste your S3 endpoint URL in the **Custom Endpoint URL** field (e.g. `https://s3.us-west-004.backblazeb2.com` or `https://<account_id>.r2.cloudflarestorage.com`). Leave the endpoint blank for standard AWS S3.

= How does SFTP remote storage work? =
Lumos Backup leverages native PHP cURL SFTP to securely stream archives over SSH/SFTP directly to remote servers without needing any external PECL extensions or composer packages.

= How does the two-step restore work? =
When you click **Restore**, Lumos Backup opens the archive (downloading from S3/SFTP if necessary), inspects `manifest.json`, and displays the backup ID, creation date, original site URL, and file size. You must click the red **Confirm & Perform Restore Now** button to start the restore.

= What happens if database restoration fails midway? =
Lumos Backup enforces a strict failure boundary: if the SQL import fails or encounters errors, the process halts immediately. Your existing `wp-content` files will NOT be overwritten.

= Are recovery tokens logged? =
No. Tokens are never written to `restore.log` or any server logs.

== Changelog ==

= 1.3.0 =
* Full WP-CLI Integration: `wp lumos backup`, `list`, `restore`, `drill`, `status`, `token`.
* Automated Periodic Restore Drills & Failure Notification Alerts (`wp_mail`).
* Advanced SQL Parser supporting custom MySQL `DELIMITER` directives for stored procedures and triggers.
* WordPress Multisite (WPMU) Network Admin support and multi-domain database replacements.
* Native cURL SFTP Remote Destination Driver with zero PECL dependencies.

= 1.1.0 =
* S3-compatible remote storage destination with native AWS SigV4 signing (AWS S3, Backblaze B2, Cloudflare R2, MinIO).
* Automatic remote backup upload and remote archive pruning.
* Standalone restore console lists and restores both local and remote S3 archives.
* Two-step restore confirmation displaying site URL, creation time, and file size.
* Maintenance mode file (`.lumos-maintenance`) during restore.
* Strict failure boundary: halts before file copy on SQL failure.
* Failed token rate-limiting (5 misses per IP per 15 minutes -> HTTP 429).
* Dedicated audit log in `restore.log`.

= 1.0.0 =
* Initial release of Lumos Backup.
