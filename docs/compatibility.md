# Compatibility

Floor: Magento Open Source 2.4.7+ (user decision). Only versions that CI runs are claimed as tested.
The CI matrix is built in `.github/workflows/ci.yml` from the graycore `supported-version` action
(`currently-supported` Magento Open Source releases), filtered to the module PHP constraint and without 2.4.7-p10
(see below). `.github/check-extension.json` turns the graycore-based jobs on or off. The matrix is resolved at run
time, so a new Magento patch release can enter it without a commit here.

Last verified: CI run [38913260333](https://github.com/lucafuser/mage-os-advanced-report/actions/runs/38913260333)
on `master` (commit `c582fd2`, 2026-10-16), all jobs green.

| Component | Supported | CI job enforcing it | Status |
| --- | --- | --- | --- |
| Magento Open Source 2.4.7-p10 | PHP 8.3 | none: temporarily removed from the matrix. Composer refuses its `league/flysystem` 2.x (security advisory `PKSA-w9tt-7782-78jx`, blocked by default) and no fixed 2.x exists (risk R23) | declared, not tested |
| Magento Open Source 2.4.8-p5 | PHP 8.4, MySQL 8.4 | matrix entry: compile-extension, unit-test-extension, integration-test | tested (integration: 76 tests, 420 assertions) |
| Magento Open Source 2.4.9 | PHP 8.5, MySQL 8.4 | matrix entry: same jobs; also verified locally with the integration suite (Magento 2.4.9 / PHP 8.5 / MySQL 8.4) | tested (integration: 76 tests, 420 assertions, CI and local) |
| Mage-OS | 2.4.7+ equivalents | none (the matrix uses the Magento Open Source project only) | declared, not tested |
| PHP 8.3 | `composer.json` `~8.3\|\|~8.4\|\|~8.5` | none while 2.4.7-p10 is out of the matrix; the quality job cannot run 8.3 because PHPUnit 13 needs PHP 8.4+ | declared, not tested |
| PHP 8.4, 8.5 | same | matrix entries (2.4.8-p5, 2.4.9) and the quality job matrix 8.4/8.5 (cs-fixer, phpstan, rector, phpmd, unit, `composer test:xml`, `npm run lint`) | tested (unit: 651 tests on both) |
| MySQL | 8.4 (matrix entries and local). The Guardrails set `MAX_EXECUTION_TIME` | integration-test service per matrix entry | tested on 8.4; 8.0 (the 2.4.7 service) not tested |
| MariaDB | declared. The Guardrails set `max_statement_time` | none | declared, not tested |
| OpenSearch | not required (datasets use the SQL data source) | none | n/a |
| MSI (`Magento_InventoryApi`) | optional (`suggest`). With MSI: `catalog_stock` on `inventory_source_item` (disabled sources excluded) and the `catalog_salable` dataset (stock, reserved, salable quantity). Without MSI: legacy `cataloginventory_stock_item`, no `catalog_salable` (dropped with its presets), no source dimension (risk R27) | integration-test (CI installs include MSI): `Inventory/PhysicalStockTest`, `Inventory/SalableStockTest`, `SmokeTest` | with MSI: tested; without MSI: declared, not tested |
| Adobe Commerce (`row_id`) | unsupported | none | unsupported |

CI jobs: compile-extension, unit-test-extension and integration-test are built from the graycore actions
(not their reusable `check-extension` workflow, whose coding-standard job runs phpcs and cannot be disabled).
The quality job runs PHP-CS-Fixer, PHPStan, the PHPUnit 13 unit suite and the XSD validation on the
standalone module install. phpcs is not run. Integration tests run on the PHPUnit bundled with each Magento
version.

Local verification: the Magento integration framework (`cd dev/tests/integration && ../../../vendor/bin/phpunit -c phpunit.xml ../../../app/code/MageOS/AdvancedReport/Test/Integration`) runs the integration suite (76 tests) on Magento 2.4.9 / PHP 8.5 / MySQL 8.4
with MSI enabled. Metric behaviour per dataset, including the differences without MSI, is documented in
[metric-definitions.md](metric-definitions.md).
