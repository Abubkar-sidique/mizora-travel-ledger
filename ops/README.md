# Shared-hosting deployment

The required production target is Hostinger Web/Cloud shared hosting with MariaDB. Do not use the earlier VPS/SQLite workflow for this deployment.

Start with [hostinger-shared.md](hostinger-shared.md). The current app supports SQLite for local development and the MariaDB adapter for shared hosting. A production migration must use a version 7 SQLite source and a new, empty MariaDB database. The importer is [migrate-sqlite-to-mariadb.php](migrate-sqlite-to-mariadb.php); it runs offline, writes only to the configured MariaDB destination, and leaves SQLite unchanged. Review its preflight report and verify the target before any owner-approved cutover.

## Release package

Run `php ops/build-package.php ABSOLUTE_NEW_ZIP_PATH` from the project root. The package is an allowlist of application files and shared-hosting instructions; it excludes database files, credentials, migration fixtures, tests, and local-only migration tooling. Verify `SHA256SUMS` before upload.

The package builder does not connect to Hostinger or provision the database, change a live database, deploy files, enable cron, or switch production traffic. Production readiness remains NO-GO until the actual account configuration, MariaDB integration tests, restore test, HTTPS, file access, and application acceptance checks pass.
