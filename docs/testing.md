# Testing

How to run every check of this module locally and what each CI job enforces. All commands are real
targets: module `composer.json` scripts, `package.json` scripts, the project `Makefile` and
`.github/workflows/ci.yml`.

## Standalone module checks

Run from the module root (`app/code/MageOS/AdvancedReport`) after `composer install` and `npm install`.
PHPUnit 13 needs PHP 8.4 or newer.

```bash
composer install --no-interaction --prefer-dist
composer test:unit        # phpunit -c phpunit.xml.dist --testsuite unit
composer test:phpstan     # phpstan analyse --memory-limit=1G
composer test:cs-fixer    # php-cs-fixer dry run (--allow-risky=yes); phpcs is not used
composer test:rector      # rector process --dry-run
composer test:phpmd       # phpmd with phpmd.xml
composer test:xml         # XSD validation (php dev/validate-xml.php)
composer test             # all of the above in sequence

npm install --no-audit --no-fund
npm run lint              # ESLint on view/ (npm run lint:fix to autofix)
```

There are no JavaScript unit tests; ESLint and manual checks only.

## Local unit and integration tests (Docker project)

From the Magento project root, everything runs in Docker:

```bash
make ar-check                          # cs-fixer, phpstan, rector, phpmd, eslint, unit, full integration suite, xml
make integration-db                    # create the magento2_integration database if missing (idempotent)
make integration                       # whole MageOS_AdvancedReport integration suite
make integration filter=RefundsTest    # only matching tests (--filter)
make integration-reset                 # clear the sandbox; the next run reinstalls Magento
```

Notes:

- `make ar-check` runs `php-cs-fixer fix` (it rewrites files), installs the module `vendor/` and
  `node_modules/` if missing and removes `vendor/` at the end. Do not run two `ar-check` runs at once.
- `make integration` and `make integration-reset` serialize on `var/.integration.lock`. Each run clears the
  sandbox `var/cache`, `var/page_cache` and `generated/code/MageOS` first.
- The first integration run installs Magento into `magento2_integration` (about 30 s). Run
  `make integration-reset` after changing `etc/db_schema.xml` or the module set.
- Latest local result: unit 930 tests / 3318 assertions; integration 211 tests / 845 assertions / 7 skipped
  (flavour-specific tests), on Magento 2.4.9, PHP 8.5, MySQL 8.4.
- After editing `composer.json` (for example the `version` key), refresh the lock hash inside Docker:
  `docker compose run --rm --workdir=/var/www/app/code/MageOS/AdvancedReport workspace composer update --lock`.
  `composer validate` then reports only the known warnings for the unbound `magento/*: "*"` constraints
  (a compatibility decision, see [compatibility](compatibility.md)).

## Integration test conventions

- Method-level `#[Config]` and `#[DataFixture]` only (risk R25).
- Integration tests that use `#[DataProvider]` / `#[TestWith]` keep a matching `@dataProvider` doc-comment
  (PHPUnit 9, bundled with Magento 2.4.7, ignores PHP attributes). 2.4.7 is not in the integration matrix, so
  this is not enforced by CI.
- Tests whose behaviour depends on the database server (EXPLAIN output, the statement-timeout variable,
  timezone tables) branch or skip on `ServerFlavourDetector`; on the other flavour they are skipped, never
  failed.
- Every metric has a hand-computed value assertion; the expected values are in a docblock table per class.

## CI jobs

Defined in `.github/workflows/ci.yml` (push and pull request on `master`/`main`, plus manual
`workflow_dispatch`). The Magento matrix comes from the graycore `supported-version` action, filtered to PHP
8.3 to 8.5 by `compute-matrix`.

| Job | What it enforces |
| --- | --- |
| `compute-matrix` | Resolves the matrix. Drops Magento 2.4.7 from `unit-test-extension` and `integration-test`; appends the MariaDB 11.4 entry to the integration matrix. |
| `quality` (PHP 8.4, 8.5) | `composer test:cs-fixer`, `test:phpstan`, `test:rector`, `test:phpmd`, `test:unit` (PHPUnit 13), `test:xml`, then `npm run lint`. |
| `compile-extension` | `setup:di:compile` in a Magento install for 2.4.7-p10, 2.4.8-p5 and 2.4.9. |
| `unit-test-extension` | `Test/Unit` with the PHPUnit bundled in Magento 2.4.8-p5 and 2.4.9. 2.4.7-p10 is skipped: it bundles PHPUnit 9, which ignores the `#[DataProvider]` attributes `Test/Unit` relies on. The unit suite still runs on PHPUnit 13 in `quality`. |
| `integration-test` | `Test/Integration` in a Magento install against the database service of each matrix entry (below). `fail-fast` is off. 2.4.7-p10 is skipped: its test framework (`TestCaseProperties::endTestSuite`) sets every test property to null after each class, which fails on the non-nullable typed properties of `Test/Integration`. |

2.4.7-p10 is in the `compile-extension` matrix even though Composer blocks its `league/flysystem` 2.x (advisory
`PKSA-w9tt-7782-78jx`, risk R23): each job that builds a Magento install runs
`composer config --json audit.ignore '["PKSA-w9tt-7782-78jx"]'` in the CI install only. Security blocking stays
on and the module `composer.json` has no audit entries.

### Database matrix of `integration-test`

| Magento | PHP | Database |
| --- | --- | --- |
| 2.4.8-p5 | 8.4 | `mysql:8.4` |
| 2.4.9 | 8.5 | `mysql:8.4` |
| 2.4.9 | 8.5 | `mariadb:11.4` |

The MariaDB entry is a copy of the resolved 2.4.9 entry with only the `mysql` service image and health check
replaced (`healthcheck.sh --connect --innodb_initialized`); the service name and the `MYSQL_*` credentials stay
unchanged so the install-config seds apply as is. It runs on `ubuntu-24.04`: the `ubuntu-latest` (26.04) MySQL 8.4
client makes `mysqldump` query `information_schema.COLUMN_STATISTICS`, which MariaDB lacks, and the Magento test
framework adds `--column-statistics=0` only for 8.0 clients. Local runs cover MySQL 8.4 only; CI covers MariaDB.

## Benchmarks

Benchmark seed, procedure, budgets and results are in [performance](performance.md).
