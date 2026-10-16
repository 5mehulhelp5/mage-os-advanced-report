# Compatibility

Floor: Magento Open Source 2.4.7+ (user decision). Only versions that CI runs are claimed as tested.
The CI matrix is built in `.github/workflows/ci.yml` from the graycore `supported-version` action
(`currently-supported` Magento Open Source releases), filtered to the module PHP constraint and without 2.4.7-p10
(see below). `.github/check-extension.json` turns the graycore-based jobs on or off. The first run of this
workflow is pending (see risk R23 in [risk-register.md](risk-register.md)).

| Component | Supported | CI job enforcing it | Status |
| --- | --- | --- | --- |
| Magento Open Source 2.4.7-p10 | PHP 8.3 | none: temporarily removed from the matrix. Composer refuses its `league/flysystem` 2.x (security advisory `PKSA-w9tt-7782-78jx`, blocked by default) and no fixed 2.x exists | declared, not tested |
| Magento Open Source 2.4.8-p5 | PHP 8.4 | matrix entry: compile-extension, unit-test-extension, integration-test | configured, not yet run |
| Magento Open Source 2.4.9 | PHP 8.5 | matrix entry: same jobs; also verified locally with `make integration` (Magento 2.4.9 / PHP 8.5 / MySQL 8.4) | configured, not yet run; local integration tests pass |
| Mage-OS | 2.4.7+ equivalents | none (the matrix uses the Magento Open Source project only) | declared, not tested |
| PHP 8.3 | `composer.json` `~8.3\|\|~8.4\|\|~8.5` | none while 2.4.7-p10 is out of the matrix; the quality job cannot run 8.3 because PHPUnit 13 needs PHP 8.4+ | declared, not tested |
| PHP 8.4, 8.5 | same | matrix entries (2.4.8-p5, 2.4.9) and the quality job matrix 8.4/8.5 (cs-fixer, phpstan, unit, `composer test:xml`) | configured, not yet run |
| MySQL / MariaDB | database versions are those of the matrix entries (integration-test); locally MySQL 8.4. The Guardrails handle both `MAX_EXECUTION_TIME` (MySQL) and `max_statement_time` (MariaDB) | integration-test service per matrix entry | MariaDB declared, not tested; MySQL configured, not yet run |
| OpenSearch | not required (datasets use the SQL data source) | none | n/a |
| MSI (`Magento_InventoryApi`) | optional (`suggest`); legacy `cataloginventory_stock_item` is used otherwise | none | declared, not tested |
| Adobe Commerce (`row_id`) | unsupported | none | unsupported |

CI jobs: compile-extension, unit-test-extension and integration-test are built from the graycore actions
(not their reusable `check-extension` workflow, whose coding-standard job runs phpcs and cannot be disabled).
The quality job runs PHP-CS-Fixer, PHPStan, the PHPUnit 13 unit suite and the XSD validation on the
standalone module install. phpcs is not run.

Local verification: `make integration` runs the integration suite on Magento 2.4.9 / PHP 8.5 / MySQL 8.4.
