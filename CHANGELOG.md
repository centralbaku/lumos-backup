# Changelog

All notable changes to the Lumos Backup plugin will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.1.0] - 2026-11-16

### Added
- **GitHub Self-Hosted Auto-Updater**: Native WordPress update notifications directly from the GitHub repository (`centralbaku/lumos-backup`) and 1-click update installation.
- **WP-CLI Integration**: Full command-line tool suite (`wp lumos backup`, `list`, `restore`, `drill`, `status`, `token`).
- **Automated Restore Drills & Health Alerting**: Scheduled WP-Cron background verification runs (daily, weekly, monthly) with immediate admin email alerts (`wp_mail`) on any verification failure.
- **Advanced SQL Parser with `DELIMITER` Support**: Accurately imports databases containing stored procedures, functions, and triggers utilizing custom MySQL `DELIMITER //` or `DELIMITER $$` directives.
- **WordPress Multisite (WPMU) Support**: Network Admin management under `manage_network_options`, complete network dumps, subsite registry in manifests, and multi-domain rewriting across `wp_site`, `wp_blogs`, `wp_sitemeta`, and subsite options tables.
- **Native cURL SFTP Remote Destination**: Direct off-site backup uploads over secure SSH File Transfer Protocol (SFTP) using native PHP cURL without external PECL extensions.
- **Automated Release Workflow**: GitHub Actions workflow (`.github/workflows/release.yml`) and local script (`scripts/build-release.sh`) to automatically package installable `lumos-backup-vX.Y.Z.zip` archives.

---

## [1.0.0] - 2026-10-27

### Added
- **Emergency Standalone Restore Console**: Autonomous single-file restore page (`lumos-restore.php`) that runs when WordPress core will not boot.
- **Two-Step Restore Safeguards**: Required confirmation step displaying manifest site URL, archive timestamp, and size before execution.
- **Maintenance File Protection**: Automatic `.lumos-maintenance` creation during restore with guaranteed cleanup.
- **SQL Failure Boundary**: Process halts immediately on database import errors to prevent overwriting `wp-content` with a broken restore.
- **Rate-Limited Authentication**: 5 incorrect key attempts per IP per 15 minutes triggers HTTP 429 Too Many Requests.
- **S3-Compatible Remote Storage**: Native AWS Signature Version 4 off-site uploads supporting AWS S3, Backblaze B2, Cloudflare R2, MinIO, and Wasabi.
- **SHA-256 Checksum Sidecars**: Verifies archive integrity before extraction; rejects tampered files.
- **AES-256-CBC Encryption**: Optional PBKDF2-derived passphrase encryption for archives (`.zip.enc`).
- **Domain Replacement Engine**: Serialized-safe recursive search and replace for migrating domains during restoration.
- **Dead-Server Kit (`recovery.md`)**: Runbook containing exact manual shell instructions to recover when PHP and the web server are dead.
- **Batched Database Engine**: Memory-efficient streaming database dumper without loading whole tables into memory.
