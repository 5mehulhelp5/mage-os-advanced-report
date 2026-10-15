# Compatibility

Floor: Magento Open Source 2.4.7+ (user decision). Only versions that CI runs are claimed as tested.
The CI matrix is configured in `.github/workflows/ci.yml` and `.github/check-extension.json` but has **not yet run**
(branch not pushed): it is configured, not yet observed green (see risk R23 in [risk-register.md](risk-register.md)).

| Component | Supported | CI job enforcing it | Status |
| --- | --- | --- | --- |
| Magento Open Source 2.4.7-p10 | PHP 8.3 | graycore matrix entry (`kind: custom`): compile-extension, integration_test, phpstan, unit-test-extension | configured, not yet run |
| Magento Open Source 2.4.8-p5 | PHP 8.4 | graycore matrix entry: same jobs | configured, not yet run |
| Magento Open Source 2.4.9 | PHP 8.5 | graycore matrix entry: same jobs; also verified locally with `make integration` (Magento 2.4.9 / PHP 8.5 / MySQL 8.4) | configured, not yet run; local integration tests pass |
| Mage-OS | 2.4.7+ equivalents | none (the graycore action takes one project per call, no Mage-OS entries) | declared, not tested |
| PHP 8.3 | `composer.json` `~8.3\|\|~8.4\|\|~8.5` | only the graycore entry running 8.3 (Magento 2.4.7-p10); the quality job cannot run 8.3 because PHPUnit 13 needs PHP 8.4+ | configured, not yet run |
| PHP 8.4, 8.5 | same | graycore entries (2.4.8-p5, 2.4.9) and the quality job matrix 8.4/8.5 (cs-fixer, unit, `composer test:xml`) | configured, not yet run |
| MySQL / MariaDB | database versions are those of the graycore matrix entries (integration_test); locally MySQL 8.4. The Guardrails handle both `MAX_EXECUTION_TIME` (MySQL) and `max_statement_time` (MariaDB) | graycore integration_test service per matrix entry | MariaDB declared, not tested; MySQL configured, not yet run |
| OpenSearch | not required (datasets use the SQL data source) | none | n/a |
| MSI (`Magento_InventoryApi`) | optional (`suggest`); legacy `cataloginventory_stock_item` is used otherwise | none | declared, not tested |
| Adobe Commerce (`row_id`) | unsupported | none | unsupported |

Graycore jobs enabled: compile-extension, integration_test, phpstan, unit-test-extension. The coding-standard job is
disabled (the project uses PHP-CS-Fixer through the quality job).

Local verification: `make integration` runs the integration suite on Magento 2.4.9 / PHP 8.5 / MySQL 8.4.
