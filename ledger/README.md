# MIZORA Travel Ledger

A private, multi-agency travel ledger with a shared dashboard, account lists, debit/credit drawers, reports, CSV/Excel exports and printable statements.

## Run locally

Requires PHP 8.0+ with PDO SQLite for the default local setup. Enable `zip` for Excel exports. PHP 8.2–8.4 is recommended. MariaDB development uses PDO MySQL and MariaDB 10.2.1 or newer.

```powershell
cd ledger
C:\php\php.exe -S 127.0.0.1:8765 router.php
```

Open http://127.0.0.1:8765. A new installation asks you to create a master administrator. The master creates agencies and owner logins; owners manage their own accounts and entries.

SQLite remains the local default. To run against a disposable MariaDB schema, set `LEDGER_DRIVER=mysql`, `LEDGER_DB_HOST`, `LEDGER_DB_NAME`, `LEDGER_DB_USER`, and `LEDGER_DB_PASSWORD`; set `LEDGER_AUTO_MIGRATE=1` only for local development. Production requires the MariaDB schema to be installed offline and `LEDGER_ENV=production`; web requests never perform MariaDB schema upgrades.

## Using the ledger

- Travel Ledgers keeps its inline New Account form. The table scrolls inside its panel and pagination remains visible.
- Individual ledgers show balances, compact date filters and Debit/Credit drawers. Rows per page are selected beside pagination.
- Payment references load only when needed. Search by PNR, receipt or entry number to find older unpaid references; results are limited to 50 per search.
- CSV and Excel export all transactions in the selected date range. Print/PDF also includes the complete filtered statement, regardless of the current page, in a branded blue A4 portrait layout with the airplane banner, summary cards, transaction history and notes.
- Excel uses the blue MIZORA statement template: agency details and summary side by side, eight transaction columns, category colours, real numeric/date cells, frozen headings and filters. Three native charts show monthly debit/credit movement, category distribution and balance trend (latest 12 months in the selected period).
- Archive accounts and void entries to preserve financial history. Void linked payments before their source charge.
- The chat icon opens a private agency conversation with the master admin. Agents retain Help & Support; the master has an Agency Messages inbox instead. Unread badges refresh while the app is open, messages persist in SQLite, and earlier messages load in batches of 100. Enter sends; Shift+Enter adds a line. Replies appear automatically in an open conversation.
- The master controls chat availability from the inbox header: Available means personal replies, Away means the automated assistant answers. The admin presence expires after 90 seconds of inactivity; a personal reply pauses the assistant in that agency chat for 15 minutes.
- When the admin is away, the assistant replies as "Automated support" in English and Roman Urdu for common topics (greetings, login, entries, payments, exports, date filters, balances, slow pages). It asks for details politely; when an issue is unclear, repeated, or the agent asks for the admin, the chat is flagged "Needs admin" in the master inbox and the agent is told to wait for Abubakar Sidique. Messages stay queued for his personal review.
- Chat uses additive schema version 6. For production upgrades, run the existing offline migration workflow before serving the updated code. Database backups include chat history.

PKR remains the agency reporting/base currency. Each transaction can be entered in PKR, USD, EUR, GBP, AED, SAR, QAR, KWD, BHD, OMR, TRY, CNY, CAD, AUD, SGD, MYR, THB, JPY, INR, BDT or NPR. For a foreign entry, enter the agreed PKR-per-unit exchange rate; the app stores the original currency/amount and fixed-point rate and posts the rounded PKR equivalent to balances, allocations and reports. Rates are manually confirmed and are not fetched live. Existing records migrate as PKR at 1:1 with their stored minor-unit values preserved. Debit increases the Dr balance; credit reduces it. A positive balance is receivable and a negative balance is payable/advance.

## Files and upgrades

`index.php` handles requests and tenant-scoped data; `ui.php` contains shared rendering; `statement.php` shares the statement data and print template; `xlsx.php` builds spreadsheets; `assets/` contains the shared theme and interactions. Operational scripts live in [ops](../ops/README.md).

Local SQLite data is `data/ledger.sqlite`, or the private path configured through `LEDGER_DB`. The shared-hosting deployment uses MariaDB. For a copy, first bring SQLite to schema version 8, stop writers, and run `php ops/migrate-sqlite-to-mariadb.php --source=/private/ledger.sqlite --confirm-empty-target` with a new empty MariaDB target configured through `LEDGER_DB_*`. The importer opens SQLite read-only, refuses a populated target, validates SQLite integrity/foreign keys, currency conversion and financial rules, and checks table counts, per-account base-currency balances, and row hashes before committing. It never deletes or writes the SQLite source. Keep that source offline as rollback evidence; it does not receive new writes after cutover.

Keep the current database private. Replace application code and assets together; never overwrite financial data with an installation copy. The MariaDB schema is versioned independently from SQLite's user_version and production web requests refuse to install or upgrade it implicitly.

Test fixtures, screenshots, temporary files and obsolete release archives are intentionally excluded from this delivery. Generate release packages outside the application directory with `php ops/build-package.php ABSOLUTE_NEW_ZIP_PATH` from the project root.

For production, follow [the hosting and backup instructions](../ops/README.md). This is a basic agency ledger, not a booking engine or a certified double-entry accounting system.
