# Security

How the module authorizes admin access, classifies the data it exposes and keeps user input out of SQL.
The model is enforced server-side; the UI only mirrors it (it hides what the server would refuse).

## Entry points and ACL

Every admin controller extends `Magento\Backend\App\Action`: the framework checks `ADMIN_RESOURCE` before `execute()` and
answers HTTP 403 (`adminhtml_denied` page) when it is missing. The dataset check (module dataset ACL and core entity ACL,
see below) is made by `RequestValidator` through `DatasetAccess::assertAllowed()`, so it applies to every path that runs a
report. `Data`, `Export`, `Save` and `Delete` are POST-only (CSRF form key).

| Entry point | ACL resource | Additional checks | Covering test |
| --- | --- | --- | --- |
| `Controller/Adminhtml/Report/Index` | `MageOS_AdvancedReport::reports` | Presets and saved reports of datasets the admin may not read are hidden (`ViewModel/ReportList`) | `EntryPointAuthorizationTest::testItHidesPresetsAndSavedReportsOfInaccessibleDatasetsFromTheIndexList` |
| `Controller/Adminhtml/Report/View` | `MageOS_AdvancedReport::reports` | Preset or saved report on an inaccessible dataset: error message and redirect to the index (not found); exploration metadata omits inaccessible datasets (`ViewModel/Exploration`, `InitialStateResolver`) | `testItRedirectsWithNotFoundWhenOpeningASharedReportOfAnInaccessibleDataset`, `testItOmitsInaccessibleDatasetsFromTheExplorationMetadata` |
| `Controller/Adminhtml/Report/Data` | `MageOS_AdvancedReport::reports` | Dataset ACL + core ACL (HTTP 403 JSON); request validation (HTTP 400); sanitized logging | `testItReturns403FromDataWithoutTheDatasetCorePermission`, `RunnerAuthorizationTest` |
| `Controller/Adminhtml/Report/Export` | `MageOS_AdvancedReport::export` | Dataset ACL + core ACL: no CSV, redirect to the index with a generic error; without `export` the framework answers 403 | `testItDoesNotExportAReportWithoutTheDatasetCorePermission`, `testItDeniesExportWithA403PageWithoutTheExportPermission` |
| `Controller/Adminhtml/Report/Save` | `MageOS_AdvancedReport::saved_manage` | Sharing needs `MageOS_AdvancedReport::saved_share` (403); dataset ACL + core ACL of the saved definition (403); only the owner can modify | `testItReturns403WhenSavingAReportWithoutTheDatasetCorePermission`, `testItReturns403WhenSharingWithoutTheSharePermission` |
| `Controller/Adminhtml/Report/Delete` | `MageOS_AdvancedReport::saved_manage` | Only the owner can delete (403). An owner who lost the dataset permission no longer sees nor can delete the saved report: accepted | `testItForbidsDeletingASavedReportOwnedByAnotherAdmin` |
| `advanced-report:explain` (`Console/Command/ExplainCommand`) | none (trusted CLI) | Shell access is the trust boundary. A preset query (`LowStockCoverageQuery`) still runs its inner report with ACL checks and no admin session (pre-existing) | unit tests |
| `advanced-report:inventory` (`Console/Command/InventoryCommand`) | none (trusted CLI) | Reads definitions only, no data | unit tests |

Runner level (all entry points): `RunnerAuthorizationTest` covers every dataset gate, preset/dataset mismatch, cache
isolation between roles and the request validation errors.

## Dataset access

A dataset is readable only when **both** gates pass (`Model/Security/DatasetAccess`):

1. the module dataset ACL (`acl` attribute in `etc/report_datasets.xml`: `sales`, `catalog` or `customers`), and
2. the core ACL of the entity the dataset reads, from the `coreResources` argument of `DatasetAccess` in `etc/di.xml`.
   The core gate is skipped when the resource is not declared in the ACL tree (for example a disabled module).

| Dataset | Module ACL | Core ACL | Rationale |
| --- | --- | --- | --- |
| `sales_orders` | `MageOS_AdvancedReport::sales` | `Magento_Sales::sales_order` | Same entity as the core order grid |
| `customer_orders` | `MageOS_AdvancedReport::customers` | `Magento_Sales::sales_order` | Reads orders. The core order view already shows the related customer data (name, email, address, group) to `sales_order`, so it adds no new exposure |
| `sales_items` | `MageOS_AdvancedReport::sales` | `Magento_Sales::sales_order` | Order lines; same entity as the order view |
| `customers` | `MageOS_AdvancedReport::customers` | `Magento_Customer::manage` | Reads customer accounts (birth date, gender, email): needs the core customer permission |
| `catalog_stock` | `MageOS_AdvancedReport::catalog` | `Magento_Catalog::products` | Same data as the product grid (stock, price, cost) |
| `catalog_salable` | `MageOS_AdvancedReport::catalog` | `Magento_InventoryApi::stock` | MSI stock data; skipped without MSI |

Consequences:

- Presets and saved reports of an inaccessible dataset are hidden from the list and metadata, and opening them is a not
  found redirect; running them is refused (403).
- `LowStockCoverageQuery` runs an inner sales query gated by `sales_items`; without that permission it runs without sales data.
- A dataset without a `coreResources` mapping (for example added by an extender) is gated by its module ACL only.
  Extenders add a mapping in their `di.xml`:

```xml
<type name="MageOS\AdvancedReport\Model\Security\DatasetAccess">
    <arguments>
        <argument name="coreResources" xsi:type="array">
            <item name="acme_returns" xsi:type="string">Magento_Sales::sales_creditmemo</item>
        </argument>
    </arguments>
</type>
```

## Data classification

| Field (dimension) | Datasets | Sensitivity | Business purpose |
| --- | --- | --- | --- |
| `birth_year`, `birth_month`, `birth_weekday` | `sales_orders`, `customer_orders`, `customers` | Personal data (date of birth, bucketed) | Age and birthday-month cohort analysis |
| `gender` | same | Personal data | Gender segmentation |
| `customer` (customer key, derived from customer id or email) and the underlying customer id/email columns | `customer_orders` (dimension); `customers` rows come from customer accounts | Personal data (identifying) | Customer ranking and lifetime value |
| `billing_postcode` | `sales_orders`, `customer_orders`, `customers` | Personal data (location) | Regional analysis |
| `billing_country`, `shipping_country` | `sales_orders`, `customer_orders`, `customers` | Low (coarse location) | Sales by country |
| `customer_group_id` | `sales_orders`, `customer_orders`, `customers`, `sales_items` | Low (commercial segment) | B2B/B2C and pricing group analysis |
| `coupon_code` (dimension), `has_coupon` (filter field) | `sales_orders` | Commercial (promotion code, may identify a campaign or partner) | Promotion effectiveness |
| `payment_method` | `sales_orders` | Commercial | Payment mix |

Order-level datasets (`sales_orders`, `customer_orders`, `sales_items`) follow `Magento_Sales::sales_order`, as the core
order view does; `customers` requires `Magento_Customer::manage`. Exports go through the same checks and need `export` too.

## User values to SQL

Request JSON -> `ReportRequestFactory` (typed parsing, only `InvalidRequestException` on malformed shapes) ->
`RequestValidator` (dataset, metric, dimension and operator identifiers against the definitions; sort target and direction;
`limit >= 1`, `offset >= 0`; store ids against the store manager; preset existence and dataset match) ->
`UserFilterBuilder` (values bound with `quoteInto`) -> SQL.

Identifiers never come from the request as SQL text. The SQL expressions in `report_datasets.xml` are trusted module
configuration (written by developers), never user input.

## Cache

`ResultCache::buildKey()` includes `AclContext::getFingerprint()`: a sha256 over the allow/deny state of every dataset
module ACL, every declared core ACL (`DatasetAccess::getResources()`) and `MageOS_AdvancedReport::config`. It is recomputed
on each call (no memoization), so admins with different permissions never share a cached result, and a permission change
takes effect immediately. Authorization is checked before the cache lookup.

## Logging

`Model/Security/ErrorLogger` replaces direct `LoggerInterface` use in controllers and the saved report repository. In
production mode it logs `Class: sanitized message` with only a `context` (`advanced_report_data`, `advanced_report_export`,
`advanced_report_saved_save`, `advanced_report_saved_delete`) and the `previous` exception classes: no SQL text, bound
values or stack trace. Outside production the full exception is logged. Saved report errors shown to the user are fixed
messages.

## Upgrade notes

Roles that only have a module ACL (for example `MageOS_AdvancedReport::sales`) lose the datasets until the matching core
ACL is granted. After deploying, regenerate `generated/code/MageOS/AdvancedReport` (controller constructors changed):
`setup:di:compile` in production, or clear `generated/` in developer mode.

## Trusted CLI authorization (benchmark)

Reports need an authorization decision, and a CLI process has no backend session. `Model/Security/TrustedCliAuthorization`
decorates the core `Magento\Framework\Authorization` and is injected (in `etc/di.xml`) as the `authorization` argument of
`DatasetAccess`, `AclContext` and `RequestValidator` only; it is not a global preference, so no other consumer is affected.
It allows every resource only while `Model/Security/TrustedCliContext` is enabled and otherwise delegates unchanged. The
context is disabled by default and only the benchmark command enables it, after the area code is set. The CLI is trusted
(shell access is the trust boundary, like `advanced-report:explain` and `advanced-report:inventory`), so benchmark runs skip
the dataset ACL gates; HTTP entry points never enable the context.
