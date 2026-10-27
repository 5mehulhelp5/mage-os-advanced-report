# Compatibility

Floor: Magento Open Source 2.4.7+ (user decision). Only versions that a CI run has passed are claimed as tested.
The CI matrix is built in `.github/workflows/ci.yml` from the graycore `supported-version` action
(`currently-supported` Magento Open Source releases), filtered to the module PHP constraint. 2.4.7-p10 is in the
matrix again: the Composer security advisory `PKSA-w9tt-7782-78jx` (its `league/flysystem` 2.x) is ignored only in
each CI Magento install (`composer config audit.ignore`); security blocking stays on and the module `composer.json`
has no audit entries. `.github/check-extension.json` turns the graycore-based jobs on or off. The matrix is resolved
at run time, so a new Magento patch release can enter it without a commit here.

Last verified: CI run [38913260333](https://github.com/lucafuser/mage-os-advanced-report/actions/runs/38913260333)
on `master` (commit `c582fd2`, 2026-10-16). That run covers only the previous matrix (2.4.8-p5 and 2.4.9 on
MySQL 8.4). Rows marked "pending first CI run" are configured in `ci.yml` but have not run yet; the run id will be
recorded here after the first push of the p1g branch.

Integration matrix (`integration-test`, fail-fast off): 2.4.7-p10 / PHP 8.3 / `mysql:8.0`, 2.4.8-p5 / `mysql:8.4`,
2.4.9 / `mysql:8.4`, 2.4.7-p10 / `mariadb:10.6`, 2.4.9 / `mariadb:11.4`. See [testing.md](testing.md).

| Component | Supported | CI job enforcing it | Status |
| --- | --- | --- | --- |
| Magento Open Source 2.4.7-p10 | PHP 8.3 | matrix entry: compile-extension and integration-test (MySQL 8.0 and MariaDB 10.6). `unit-test-extension` excludes 2.4.7: Magento 2.4.7 bundles PHPUnit 9, which ignores the `#[DataProvider]` attributes of `Test/Unit`; the unit suite runs on PHPUnit 13 in the quality job instead. Integration tests keep `@dataProvider` doc-comments for PHPUnit 9 | pending first CI run (unit suite not run on 2.4.7 by design) |
| Magento Open Source 2.4.8-p5 | PHP 8.4, MySQL 8.4 | matrix entry: compile-extension, unit-test-extension, integration-test | tested in run 38913260333 (previous suite: 76 integration tests); the current suite is pending a new CI run |
| Magento Open Source 2.4.9 | PHP 8.5, MySQL 8.4, MariaDB 11.4 | matrix entry: same jobs, plus an integration-test entry on `mariadb:11.4`. Locally verified (Magento 2.4.9 / PHP 8.5 / MySQL 8.4, MSI): unit 930 tests / 3318 assertions, integration 211 tests / 845 assertions / 7 skipped (flavour-specific skips) | MySQL 8.4 tested locally; CI run of the current suite pending; MariaDB 11.4 pending first CI run |
| Mage-OS | 2.4.7+ equivalents | none (the matrix uses the Magento Open Source project only) | declared, not tested |
| PHP 8.3 | `composer.json` `~8.3\|\|~8.4\|\|~8.5` | the 2.4.7-p10 entries (compile-extension, integration-test). The quality job cannot run 8.3 (PHPUnit 13 needs PHP 8.4+) and `unit-test-extension` skips 2.4.7, so the unit suite is not run on PHP 8.3 | pending first CI run (integration only; unit suite not run on 8.3) |
| PHP 8.4, 8.5 | same | matrix entries (2.4.8-p5, 2.4.9) and the quality job matrix 8.4/8.5 (cs-fixer, phpstan, rector, phpmd, unit, `composer test:xml`, `npm run lint`) | tested (CI run 38913260333 for the previous suite; locally unit 930 tests on 8.5) |
| MySQL | 8.0 (2.4.7-p10 entry) and 8.4 (2.4.8-p5, 2.4.9 entries and local). The Guardrails set `MAX_EXECUTION_TIME` | integration-test service per matrix entry | 8.4 tested (local, and CI for the previous suite); 8.0 pending first CI run |
| MariaDB | 10.6 (with 2.4.7-p10) and 11.4 (with 2.4.9). The Guardrails set `max_statement_time` | integration-test entries `mariadb:10.6` and `mariadb:11.4` (flavour-aware tests branch or skip on `ServerFlavourDetector`; the `IndexUsageTest` dead_stock anti-join `possible_keys` expectation for MariaDB is unverified until CI) | pending first CI run |
| OpenSearch | not required (datasets use the SQL data source) | none | n/a |
| MSI (`Magento_InventoryApi`) | optional (`suggest`). With MSI: `catalog_stock` on `inventory_source_item` (disabled sources excluded) and the `catalog_salable` dataset (stock, reserved, salable quantity). Without MSI: legacy `cataloginventory_stock_item`, no `catalog_salable` (dropped with its presets), no source dimension (risk R27). Multi-stock salable quantities are covered only by unit tests (risk R26, accepted) | integration-test (CI installs include MSI): `Inventory/PhysicalStockTest`, `Inventory/SalableStockTest`, `Inventory/StockCountMetricsTest`, `SmokeTest` | with MSI (default stock): tested; multi-stock: unit-test only; without MSI: declared, not tested |
| Adobe Commerce (`row_id`) | unsupported | none | unsupported |

CI jobs: compile-extension, unit-test-extension and integration-test are built from the graycore actions
(not their reusable `check-extension` workflow, whose coding-standard job runs phpcs and cannot be disabled).
The quality job runs PHP-CS-Fixer, PHPStan, the PHPUnit 13 unit suite and the XSD validation on the
standalone module install. phpcs is not run. Integration tests run on the PHPUnit bundled with each Magento
version.

Local verification: the Magento integration framework (`cd dev/tests/integration && ../../../vendor/bin/phpunit -c phpunit.xml ../../../app/code/MageOS/AdvancedReport/Test/Integration`) runs the integration suite (211 tests, 7 skipped as flavour-specific) on Magento 2.4.9 / PHP 8.5 / MySQL 8.4
with MSI enabled. Metric behaviour per dataset, including the differences without MSI, is documented in
[metric-definitions.md](metric-definitions.md).
