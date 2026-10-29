<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Security;

use Magento\Authorization\Test\Fixture\Role;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\Acl\Builder as AclBuilder;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\State;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Filesystem;
use Magento\TestFramework\Bootstrap as TestBootstrap;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\AbstractBackendController;
use Magento\User\Model\User as AdminUser;
use Magento\User\Test\Fixture\User;
use MageOS\AdvancedReport\Api\Data\SavedReportInterface;
use MageOS\AdvancedReport\Api\Data\SavedReportInterfaceFactory;
use MageOS\AdvancedReport\Api\SavedReportRepositoryInterface;
use MageOS\AdvancedReport\Model\Export\CsvExporter;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;
use MageOS\AdvancedReport\Model\Security\ErrorLogger;
use MageOS\AdvancedReport\ViewModel\Exploration;
use MageOS\AdvancedReport\ViewModel\ReportList;
use Psr\Log\AbstractLogger;
use Throwable;

/**
 * Entry points (data, export, save, delete, view, index list, exploration metadata) with restricted admin roles.
 *
 * Roles (all include Magento_Backend::admin, Magento_Reports::report, MageOS_AdvancedReport::reports):
 *  - "noCore": module ACLs sales, customers, export, saved_manage, saved_share; no core ACL.
 *  - "withCore": the same plus Magento_Sales::sales_order.
 * The default integration admin owns saved reports. Restricted users are logged in inside the test method
 * (fixtures exist by then) and the runtime ACL is reset, otherwise the cached ACL does not know the role.
 *
 * Controllers are exercised through `dispatch()` as the fixture user. A SQL failure is not reachable through the
 * controllers without production code changes, so the sanitized logging is verified on the real ErrorLogger fed
 * with a genuine database exception.
 */
#[DbIsolation(true)]
#[AppArea('adminhtml')]
final class EntryPointAuthorizationTest extends AbstractBackendController
{
    private const string DATA_URI = 'backend/advancedreport/report/data';
    private const string EXPORT_URI = 'backend/advancedreport/report/export';
    private const string SAVE_URI = 'backend/advancedreport/report/save';
    private const string DELETE_URI = 'backend/advancedreport/report/delete';
    private const string VIEW_URI = 'backend/advancedreport/report/view';

    #[DataFixture(Role::class, ['role_name' => 'ar_ep_no_core_%uniqid%', 'resources' => self::NO_CORE], 'role')]
    #[DataFixture(User::class, ['role_id' => '$role.id$'], 'user')]
    #[DataFixture(Role::class, ['role_name' => 'ar_ep_with_core_%uniqid%', 'resources' => self::WITH_CORE], 'role2')]
    #[DataFixture(User::class, ['role_id' => '$role2.id$'], 'user2')]
    public function testItReturns403FromDataWithoutTheDatasetCorePermission(): void
    {
        $this->loginAs('user2');
        $this->post(self::DATA_URI, ['request' => $this->salesRequest()]);
        $this->assertSame(200, $this->response()->getHttpResponseCode(), 'Positive control');

        $this->resetDispatch();
        $this->loginAs('user');
        $this->post(self::DATA_URI, ['request' => $this->salesRequest()]);

        $this->assertSame(403, $this->response()->getHttpResponseCode());
        $this->assertTrue((bool) json_decode($this->response()->getBody(), true)['error']);
    }

    #[DataFixture(Role::class, ['role_name' => 'ar_ep_no_core_%uniqid%', 'resources' => self::NO_CORE], 'role')]
    #[DataFixture(User::class, ['role_id' => '$role.id$'], 'user')]
    #[DataFixture(Role::class, ['role_name' => 'ar_ep_with_core_%uniqid%', 'resources' => self::WITH_CORE], 'role2')]
    #[DataFixture(User::class, ['role_id' => '$role2.id$'], 'user2')]
    public function testItDoesNotExportAReportWithoutTheDatasetCorePermission(): void
    {
        $this->loginAs('user2');
        $objectManager = Bootstrap::getObjectManager();
        $path = $objectManager->get(CsvExporter::class)->exportToFile(
            $objectManager->get(ReportRequestFactory::class)->fromArray(
                json_decode($this->salesRequest(), true)
            ),
            false
        );
        $varDirectory = $objectManager->get(Filesystem::class)->getDirectoryWrite(DirectoryList::VAR_DIR);
        $csv = $varDirectory->readFile($path);
        $varDirectory->delete($path);
        $this->assertNotSame('', $csv, 'Positive control: the role may export the dataset');

        $this->resetDispatch();
        $this->loginAs('user');
        $this->post(self::EXPORT_URI, ['request' => $this->salesRequest()]);

        $this->assertTrue($this->response()->isRedirect());
        $this->assertStringContainsString('advancedreport/report/index', $this->locationHeader());
        $this->assertFalse($this->response()->getHeader('Content-Disposition'));
        $this->assertStringContainsString(
            'The report could not be exported.',
            $this->messagesAsText()
        );
    }

    #[DataFixture(Role::class, ['role_name' => 'ar_ep_no_export_%uniqid%', 'resources' => self::NO_EXPORT], 'role')]
    #[DataFixture(User::class, ['role_id' => '$role.id$'], 'user')]
    public function testItDeniesExportWithA403PageWithoutTheExportPermission(): void
    {
        $this->loginAs('user');
        $this->post(self::EXPORT_URI, ['request' => $this->salesRequest()]);

        $this->assertSame(403, $this->response()->getHttpResponseCode());
        $this->assertFalse($this->response()->isRedirect());
        $this->assertFalse($this->response()->getHeader('Content-Disposition'));
    }

    public function testItLogsASanitizedDatabaseErrorInProductionMode(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $connection = $objectManager->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
        try {
            $connection->query("SELECT secret_column FROM ar_missing_table WHERE email = 'secret@example.com'");
            $this->fail('The invalid select must fail.');
        } catch (Throwable $e) {
            $dbException = $e;
        }
        $this->assertStringContainsString('query was', $dbException->getMessage(), 'Precondition');

        $testLogger = new class() extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        $errorLogger = $objectManager->create(ErrorLogger::class, [
            'state' => $objectManager->create(State::class, ['mode' => State::MODE_PRODUCTION]),
            'logger' => $testLogger,
        ]);

        $errorLogger->log($dbException, 'advanced_report_data');

        $this->assertCount(1, $testLogger->records);
        $record = $testLogger->records[0];
        $this->assertStringNotContainsString('query was', $record['message']);
        $this->assertStringNotContainsStringIgnoringCase('SELECT', $record['message']);
        $this->assertStringNotContainsString('secret@example.com', $record['message']);
        $this->assertArrayNotHasKey('exception', $record['context']);
        $this->assertStringNotContainsString('secret@example.com', (string) json_encode($record['context']));
    }

    #[DataFixture(Role::class, ['role_name' => 'ar_ep_no_core_%uniqid%', 'resources' => self::NO_CORE], 'role')]
    #[DataFixture(User::class, ['role_id' => '$role.id$'], 'user')]
    #[DataFixture(Role::class, ['role_name' => 'ar_ep_with_core_%uniqid%', 'resources' => self::WITH_CORE], 'role2')]
    #[DataFixture(User::class, ['role_id' => '$role2.id$'], 'user2')]
    public function testItReturns403WhenSavingAReportWithoutTheDatasetCorePermission(): void
    {
        $params = ['name' => 'ar_ep_saved_' . uniqid(), 'request' => $this->salesRequest()];

        $this->loginAs('user2');
        $this->post(self::SAVE_URI, $params);
        $this->assertSame(200, $this->response()->getHttpResponseCode(), 'Positive control');

        $this->resetDispatch();
        $this->loginAs('user');
        $this->post(self::SAVE_URI, $params);

        $this->assertSame(403, $this->response()->getHttpResponseCode());
    }

    #[DataFixture(Role::class, ['role_name' => 'ar_ep_no_share_%uniqid%', 'resources' => self::NO_SHARE], 'role')]
    #[DataFixture(User::class, ['role_id' => '$role.id$'], 'user')]
    public function testItReturns403WhenSharingWithoutTheSharePermission(): void
    {
        $this->loginAs('user');
        $this->post(self::SAVE_URI, [
            'name' => 'ar_ep_shared_' . uniqid(),
            'request' => $this->salesRequest(),
            'is_shared' => '1',
        ]);
        $this->assertSame(403, $this->response()->getHttpResponseCode());

        $this->resetDispatch();
        $this->post(self::SAVE_URI, [
            'name' => 'ar_ep_private_' . uniqid(),
            'request' => $this->salesRequest(),
        ]);
        $this->assertSame(200, $this->response()->getHttpResponseCode(), 'Positive control: private save');
    }

    #[DataFixture(Role::class, ['role_name' => 'ar_ep_with_core_%uniqid%', 'resources' => self::WITH_CORE], 'role')]
    #[DataFixture(User::class, ['role_id' => '$role.id$'], 'user')]
    public function testItForbidsDeletingASavedReportOwnedByAnotherAdmin(): void
    {
        $saved = $this->createAdminSavedReport('customer_orders', true);
        $this->loginAs('user');

        $this->post(self::DELETE_URI, ['id' => (string) $saved->getId()]);

        $this->assertSame(403, $this->response()->getHttpResponseCode());
        $repository = Bootstrap::getObjectManager()->get(SavedReportRepositoryInterface::class);
        $this->assertSame($saved->getName(), $repository->getById((int) $saved->getId())->getName());
    }

    // GET secret keys are bound to the session of the first dispatch; a second login in one test would be redirected.
    #[Config('admin/security/use_form_key', 0)]
    #[DataFixture(Role::class, ['role_name' => 'ar_ep_no_core_%uniqid%', 'resources' => self::NO_CORE], 'role')]
    #[DataFixture(User::class, ['role_id' => '$role.id$'], 'user')]
    #[DataFixture(Role::class, ['role_name' => 'ar_ep_with_core_%uniqid%', 'resources' => self::WITH_CORE], 'role2')]
    #[DataFixture(User::class, ['role_id' => '$role2.id$'], 'user2')]
    public function testItRedirectsWithNotFoundWhenOpeningASharedReportOfAnInaccessibleDataset(): void
    {
        $saved = $this->createAdminSavedReport('customer_orders', true);

        $this->loginAs('user2');
        $this->dispatch(self::VIEW_URI . '/id/' . $saved->getId());
        $this->assertFalse($this->response()->isRedirect(), 'Positive control');

        $this->resetDispatch();
        $this->loginAs('user');
        $this->dispatch(self::VIEW_URI . '/id/' . $saved->getId());

        $this->assertTrue($this->response()->isRedirect());
        $this->assertStringContainsString('advancedreport/report/index', $this->locationHeader());
        $this->assertStringContainsString('The requested report does not exist.', $this->messagesAsText());
    }

    #[DataFixture(Role::class, ['role_name' => 'ar_ep_no_core_%uniqid%', 'resources' => self::NO_CORE], 'role')]
    #[DataFixture(User::class, ['role_id' => '$role.id$'], 'user')]
    #[DataFixture(Role::class, ['role_name' => 'ar_ep_with_core_%uniqid%', 'resources' => self::WITH_CORE], 'role2')]
    #[DataFixture(User::class, ['role_id' => '$role2.id$'], 'user2')]
    public function testItHidesPresetsAndSavedReportsOfInaccessibleDatasetsFromTheIndexList(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $saved = $this->createAdminSavedReport('customer_orders', true);

        $this->loginAs('user2');
        $allowed = $this->listedLabels($objectManager->create(ReportList::class)->getAreas());
        $this->assertContains('Customer value', $allowed, 'Positive control: preset');
        $this->assertContains($saved->getName(), $allowed, 'Positive control: saved report');

        $this->loginAs('user');
        $hidden = $this->listedLabels($objectManager->create(ReportList::class)->getAreas());
        $this->assertNotContains('Customer value', $hidden);
        $this->assertNotContains($saved->getName(), $hidden);
    }

    #[DataFixture(Role::class, ['role_name' => 'ar_ep_catalog_%uniqid%', 'resources' => self::CATALOG_ONLY], 'role')]
    #[DataFixture(User::class, ['role_id' => '$role.id$'], 'user')]
    public function testItOmitsInaccessibleDatasetsFromTheExplorationMetadata(): void
    {
        $this->loginAs('user');

        $config = json_decode(
            Bootstrap::getObjectManager()->create(Exploration::class)->getJsConfig(),
            true
        );

        $codes = array_column($config['datasets'], 'code');
        $this->assertContains('catalog_stock', $codes, 'Positive control');
        foreach (['sales_orders', 'customer_orders', 'sales_items', 'customers', 'catalog_salable'] as $code) {
            $this->assertNotContains($code, $codes);
        }
    }

    #[DataFixture(Role::class, ['role_name' => 'ar_ep_cart_no_%uniqid%', 'resources' => self::CARTS_NO_CORE], 'role')]
    #[DataFixture(User::class, ['role_id' => '$role.id$'], 'user')]
    #[DataFixture(Role::class, ['role_name' => 'ar_ep_cart_ok_%uniqid%', 'resources' => self::CARTS_FULL], 'role2')]
    #[DataFixture(User::class, ['role_id' => '$role2.id$'], 'user2')]
    public function testItDeniesExportingCartDataWithoutCartPermissions(): void
    {
        $this->loginAs('user2');
        $objectManager = Bootstrap::getObjectManager();
        $path = $objectManager->get(CsvExporter::class)->exportToFile(
            $objectManager->get(ReportRequestFactory::class)->fromArray(
                json_decode($this->cartsRequest(), true)
            ),
            false
        );
        $varDirectory = $objectManager->get(Filesystem::class)->getDirectoryWrite(DirectoryList::VAR_DIR);
        $csv = $varDirectory->readFile($path);
        $varDirectory->delete($path);
        $this->assertNotSame('', $csv, 'Positive control: the role may export the carts dataset');

        $this->resetDispatch();
        $this->loginAs('user');
        $this->post(self::EXPORT_URI, ['request' => $this->cartsRequest()]);

        $this->assertTrue($this->response()->isRedirect());
        $this->assertFalse($this->response()->getHeader('Content-Disposition'));
        $this->assertStringContainsString('The report could not be exported.', $this->messagesAsText());
    }

    #[DataFixture(Role::class, ['role_name' => 'ar_ep_cart_tab_no_%uniqid%', 'resources' => self::CARTS_NO_CORE], 'role')]
    #[DataFixture(User::class, ['role_id' => '$role.id$'], 'user')]
    #[DataFixture(Role::class, ['role_name' => 'ar_ep_cart_tab_ok_%uniqid%', 'resources' => self::CARTS_FULL], 'role2')]
    #[DataFixture(User::class, ['role_id' => '$role2.id$'], 'user2')]
    public function testItHidesTheCartsTabFromAdminsWithoutCartPermissions(): void
    {
        $objectManager = Bootstrap::getObjectManager();

        $this->loginAs('user2');
        $this->assertArrayHasKey(
            'carts',
            $objectManager->create(ReportList::class)->getAreas(),
            'Positive control'
        );

        $this->loginAs('user');
        $this->assertArrayNotHasKey('carts', $objectManager->create(ReportList::class)->getAreas());
    }

    private const array BASE = [
        'Magento_Backend::admin',
        'Magento_Reports::report',
        'MageOS_AdvancedReport::reports',
    ];
    private const array NO_CORE = [
        ...self::BASE,
        'MageOS_AdvancedReport::sales',
        'MageOS_AdvancedReport::customers',
        'MageOS_AdvancedReport::export',
        'MageOS_AdvancedReport::saved_manage',
        'MageOS_AdvancedReport::saved_share',
    ];
    private const array WITH_CORE = [...self::NO_CORE, 'Magento_Sales::sales_order'];
    private const array CARTS_FULL = [
        ...self::BASE,
        'MageOS_AdvancedReport::carts',
        'MageOS_AdvancedReport::export',
        'Magento_Reports::abandoned',
        'Magento_Sales::sales_order',
    ];
    private const array CARTS_NO_CORE = [
        ...self::BASE,
        'MageOS_AdvancedReport::carts',
        'MageOS_AdvancedReport::export',
    ];
    private const array NO_EXPORT = [
        ...self::BASE,
        'MageOS_AdvancedReport::sales',
        'MageOS_AdvancedReport::saved_manage',
        'Magento_Sales::sales_order',
    ];
    private const array NO_SHARE = [
        ...self::BASE,
        'MageOS_AdvancedReport::sales',
        'MageOS_AdvancedReport::saved_manage',
        'Magento_Sales::sales_order',
    ];
    private const array CATALOG_ONLY = [
        ...self::BASE,
        'MageOS_AdvancedReport::sales',
        'MageOS_AdvancedReport::customers',
        'MageOS_AdvancedReport::catalog',
        'Magento_Catalog::products',
    ];

    private function response(): HttpResponse
    {
        $response = $this->getResponse();
        $this->assertInstanceOf(HttpResponse::class, $response);

        return $response;
    }

    private function httpRequest(): HttpRequest
    {
        $request = $this->getRequest();
        $this->assertInstanceOf(HttpRequest::class, $request);

        return $request;
    }

    private function session(): AuthSession
    {
        $session = $this->_auth->getAuthStorage();
        $this->assertInstanceOf(AuthSession::class, $session);

        return $session;
    }

    private function loginAs(string $userAlias): void
    {
        $user = DataFixtureStorageManager::getStorage()->get($userAlias);
        $this->_auth->login($user->getUserName(), TestBootstrap::ADMIN_PASSWORD);
        // The backend FileFactory redirects to the startup page on the first page after login.
        $this->session()->setIsFirstPageAfterLogin(false);
        Bootstrap::getObjectManager()->get(AclBuilder::class)->resetRuntimeAcl();
    }

    /**
     * Starts the next dispatch with clean parameters and response (both are shared across dispatches).
     */
    private function resetDispatch(): void
    {
        // The request instance is kept: the backend URL model holds it to compute the secret key.
        $this->httpRequest()->setMethod('GET')->setPostValue([])->setParams([]);
        $response = $this->response();
        $response->clearHeaders();
        $response->setHttpResponseCode(200);
        $response->setBody('');
    }

    /**
     * @param array<string,mixed> $params
     */
    private function post(string $uri, array $params): void
    {
        $formKey = Bootstrap::getObjectManager()->get(FormKey::class)->getFormKey();
        $this->httpRequest()->setMethod('POST')->setPostValue($params + ['form_key' => $formKey]);
        $this->dispatch($uri);
    }

    private function salesRequest(): string
    {
        return (string) json_encode([
            'dataset' => 'sales_orders',
            'metrics' => ['orders'],
            'dimensions' => [],
            'filters' => [],
            'dateRange' => ['type' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31'],
            'storeIds' => [],
            'bypassCache' => true,
        ]);
    }

    private function cartsRequest(): string
    {
        return (string) json_encode([
            'dataset' => 'carts',
            'metrics' => ['carts'],
            'dimensions' => [],
            'filters' => [],
            'dateRange' => ['type' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31'],
            'storeIds' => [],
            'bypassCache' => true,
        ]);
    }

    private function locationHeader(): string
    {
        $header = $this->response()->getHeader('Location');

        return $header ? (string) $header->getFieldValue() : '';
    }

    private function messagesAsText(): string
    {
        return implode(' ', array_map('strval', $this->getMessages()));
    }

    /**
     * Saved report owned by the default integration admin.
     */
    private function createAdminSavedReport(string $dataset, bool $shared): SavedReportInterface
    {
        $objectManager = Bootstrap::getObjectManager();
        $admin = $objectManager->create(AdminUser::class)->loadByUsername(TestBootstrap::ADMIN_NAME);
        $report = $objectManager->get(SavedReportInterfaceFactory::class)->create()
            ->setName('ar_ep_owned_' . uniqid())
            ->setDatasetCode($dataset)
            ->setDefinition([
                'dataset' => $dataset,
                'metrics' => ['orders'],
                'dimensions' => [],
                'filters' => [],
                'dateRange' => ['type' => 'last_30_days'],
                'storeIds' => [],
            ])
            ->setAdminUserId((int) $admin->getId())
            ->setIsShared($shared);

        return $objectManager->get(SavedReportRepositoryInterface::class)->save($report);
    }

    /**
     * @param array<string,array<string,mixed>> $areas
     * @return string[]
     */
    private function listedLabels(array $areas): array
    {
        $labels = [];
        foreach ($areas as $area) {
            foreach (array_merge($area['presets'], $area['saved']) as $item) {
                $labels[] = $item['label'];
            }
        }

        return $labels;
    }
}
