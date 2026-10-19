# ECO Tracker

PHP application for importing Agile PLM ECO reports, tracking PMC and QA completion, and exporting filtered records to CSV.

## Project structure

```text
eco/
|-- config/
|   `-- db.php                    # Shared PDO database connection
|-- database/
|   |-- schema.sql               # Initial eco_db schema
|   `-- migration.sql            # Upgrade SQL for an existing database
|-- prototypes/
|   |-- prototype.html           # Standalone UI design versions
|   |-- prototypev2.html
|   |-- prototypev3.html
|   |-- prototypev4.html
|   `-- prototypev5.html
|-- public/                      # Web server document root
|   |-- assets/
|   |   |-- css/style.css        # Dashboard styles
|   |   `-- js/app.js            # Dashboard interactions and API requests
|   |-- index.php                # Main dashboard and import interface
|   |-- api.php                  # Validation, import, tracking, and summary API
|   |-- export.php               # Filtered CSV export
|   |-- upload_tracker.php       # Standalone tracker CSV import
|   |-- upload_signoff.php       # Standalone signoff Excel import
|   |-- add.php                  # Legacy eco_records create form
|   `-- edit.php                 # Legacy eco_records edit form
|-- src/
|   `-- ECOProcessor.php         # Parsing, validation, import, and tracking rules
|-- tests/
|   |-- test_logic.php           # Business logic checks
|   `-- test_searchresult.php    # CSV/XLS/XLSX import regression checks
|-- vendor/                      # Composer-managed dependencies
|-- composer.json               # Dependency requirements
|-- composer.lock               # Locked dependency versions
`-- README.md
```

## How the files work together

The main dashboard is `public/index.php`. It loads `config/db.php` and `src/ECOProcessor.php`, and serves its styles and JavaScript from `public/assets/`. Browser actions in `app.js` call `public/api.php`; exports use `public/export.php`. `ECOProcessor.php` loads the Composer autoloader from `vendor/autoload.php`.

The main application stores data in three tables defined in `database/schema.sql`: `eco_master` for ECO data, `eco_signoff` for signoff status, and `eco_tracking` for manual tracking and completion fields.

`public/add.php` and `public/edit.php` are legacy forms that target `eco_records`. That table is not created by the current schema, so these forms need an existing legacy table to work. The standalone upload pages use the current ECO tables.

The HTML files in `prototypes/` are design references and can be opened directly in a browser. They are separate from the PHP application.

## Requirements

- 64-bit PHP 8.3 or later within PHP 8.x, as required by the current `composer.lock` dependencies.
- Composer to install the locked packages, including PhpSpreadsheet.
- MySQL access through PHP's PDO MySQL driver (`pdo_mysql`).
- PHP extensions required by the locked packages: `ctype`, `dom`, `fileinfo`, `filter`, `gd`, `iconv`, `libxml`, `mbstring`, `simplexml`, `xml`, `xmlreader`, `xmlwriter`, `zip`, and `zlib`.

## Setup

Run commands from the project root.

1. Install and check the locked dependencies:

   ```sh
   composer install
   composer check-platform-reqs
   ```

2. For a new database, import `database/schema.sql`. It creates and selects `eco_db`. To upgrade an existing database, review `database/migration.sql` and apply the statements supported by your database server. Both scripts explicitly select `eco_db`; adjust them if you use a different database name.

3. Set the database environment variables in your PHP server environment:

   | Variable | Purpose |
   |---|---|
   | `DB_HOST` | Database host; defaults to `localhost` |
   | `DB_PORT` | Database port; defaults to `3306` |
   | `DB_NAME` | Database name; defaults to `eco_db` |
   | `DB_USER` | Database username |
   | `DB_PASS` | Database password |

   Connection defaults are defined in `config/db.php`. Environment variables override those defaults. There is no automatic `.env` file loader.

4. Set the web server document root to this project's `public/` directory. Keep `config/`, `database/`, `src/`, `tests/`, `prototypes/`, and `vendor/` outside the document root. The application entry point is `public/index.php`.

For local development:

```sh
php -S localhost:8000 -t public
```

Open http://localhost:8000/ in your browser. Page links, assets, and API requests resolve relative to `public/`. Existing server configurations that served the project root need to use `public/` instead.

## Validation

Run the existing suites from the project root:

```sh
php tests/test_logic.php
php tests/test_searchresult.php
```

Both suites load `src/ECOProcessor.php`, require the Composer dependencies, and create temporary files. Neither requires a live database: the business logic suite uses a null connection, and the SearchResult suite uses a PDO test double and reads `database/schema.sql`.

`test_logic.php` prints individual `[PASS]` or `[FAIL]` results; inspect those results because its final completion message and exit status do not indicate whether every assertion passed. `test_searchresult.php` throws an exception when a check fails.

## Missing signoff review

Click **Review Open ECOs without signoff** to compare master ECOs with the latest uploaded user_signoff snapshot. Open uses the dashboard rule: statuses other than Complete are Open. Upload a full signoff report before reviewing; existing signoff history is treated as current until the next signoff upload.

PMC and QA checkboxes save tracking progress only. The Status dropdown starts with the saved database value and allows Controller, Reviewer 1, Reviewer 2, or Complete. Saving updates only the master `status` column; `status_in_agile` and PMC/QA tracking stay unchanged. Complete removes the record from this review. Closing the popup refreshes the dashboard after edits. A later SearchResult import can replace the master status with the source report's status.

Signoff uploads refresh snapshot membership transactionally while retaining previous signoff history. SearchResult-only imports preserve the last signoff snapshot. The new `in_latest_signoff` column is added automatically by the existing schema upgrade routine.
## Imported status values

Uploads support `eco_master.status` defined as `ENUM('Controller','Reviewer 1','Reviewer 2','Complete')`. The importer preserves the existing column definition. It removes `# No Controller` first and skips rows whose cleaned status is blank. For remaining rows it normalizes capitalization and whitespace, and accepts `Reviewer1` / `Reviewer2` as `Reviewer 1` / `Reviewer 2`. Unsupported nonblank statuses are rejected with the ECO and source row for SearchResult uploads; signoff-only master creation identifies the ECO. Values such as `Open` or `Closed` cannot identify a reviewer stage and must be corrected in the source report. An unsupported value prevents the upload from saving any imported records.

## Separate PMC and QA contact columns

`eco_master` now includes nullable TEXT columns `pmc_name`, `pmc_email`, `qa_name`, and `qa_email`. SearchResult uploads populate them automatically. Original `pmc_site` and `qa_site` values remain for the existing interface, filters, and CSV exports.

For an existing database, run from the project root with the application's DB environment variables:

```sh
php database/migrate_contacts.php
```

This adds missing columns and backfills existing `NAME (email)` contacts. It leaves populated contact groups and original site values untouched. Multiple contacts use semicolon-separated names and emails in matching order; a missing name/email uses an empty position, or NULL when that entire field is absent. Name-only values are preserved. The migration preserves `updated_at` and the status ENUM. No SQL dump needs to be re-imported.
