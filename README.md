# ECO Tracker

## Project structure

```text
eco/
|-- src/
|   `-- ECOProcessor.php       # ECO processing and business logic
|-- config/
|   `-- db.php                 # Shared database connection
|-- database/
|   |-- schema.sql            # Initial database schema
|   `-- migration.sql         # Upgrade an existing database
|-- public/                   # Web server document root
|   |-- index.php             # Main dashboard
|   |-- dashboard.php         # Additional dashboard
|   |-- api.php               # API endpoint
|   |-- add.php
|   |-- edit.php
|   |-- delete.php
|   |-- upload_tracker.php
|   |-- upload_signoff.php
|   |-- export.php
|   `-- assets/
|       |-- css/style.css
|       `-- js/app.js
|-- prototypes/               # Standalone HTML design versions
|-- tests/                    # Business logic and import regression tests
|-- vendor/                   # Composer dependencies
|-- composer.json
`-- composer.lock
```

## Setup

Run commands from the project root.

1. Install PHP and the extensions required by the locked Composer dependencies, then run `composer install`.
2. For a new database, import `database/schema.sql` into MySQL. To upgrade an existing database, review and apply `database/migration.sql` as needed.
3. Configure `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASS` in the server environment. Connection defaults are in `config/db.php`.
4. Set the web server document root to this project's `public/` directory. PHP pages and assets are served from there; application code, database scripts, dependencies, tests, and prototypes remain outside the document root.

For local development:

```sh
php -S localhost:8000 -t public
```

Open http://localhost:8000/ in your browser. Page and API filenames are unchanged under the new document root. Update any existing server configuration or launch command that previously served the project root.

Prototypes can be opened directly from `prototypes/` for design review.

## Validation

Run the existing suites from the project root:

```sh
php tests/test_logic.php
php tests/test_searchresult.php
```

The test suites use temporary files. The import regression suite uses a test database double and does not need a production database connection.
