<?php
/**
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2015 Adyen BV (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Test\Unit\Helper;

use Adyen\AdyenException;
use Adyen\Client;
use Adyen\Config as AdyenConfig;
use Adyen\Model\Checkout\ApplicationInfo;
use Adyen\Model\Checkout\CommonField;
use Adyen\Payment\Gateway\Request\Header\HeaderDataBuilderInterface;
use Adyen\Payment\Helper\Config as ConfigHelper;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Helper\Locale;
use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Model\Config\Source\RenderMode;
use Adyen\Payment\Model\RecurringType;
use Adyen\Payment\Model\ResourceModel\Notification\CollectionFactory as NotificationCollectionFactory;
use Adyen\Payment\Observer\AdyenPaymentMethodDataAssignObserver;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Adyen\Service\Checkout\ModificationsApi;
use Adyen\Service\Checkout\OrdersApi;
use Adyen\Service\Checkout\PaymentLinksApi;
use Adyen\Service\Checkout\PaymentsApi;
use Adyen\Service\RecurringApi;
use Magento\Backend\Helper\Data as BackendHelper;
use Magento\Customer\Helper\Address;
use Magento\Directory\Model\Config\Source\Country;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ProductMetadata;
use Magento\Framework\App\State;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Config\DataInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository;
use Magento\Framework\View\Asset\Source;
use Magento\Payment\Model\InfoInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManager;
use Magento\Tax\Model\Calculation;
use Magento\Tax\Model\Config;
use Magento\Sales\Model\Order;
use ReflectionClass;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\App\Request\Http;

class DataTest extends AbstractAdyenTestCase
{
    /**
     * @var Data
     */
    private $dataHelper;

    private $clientMock;
    private $adyenLogger;
    private $ccTypesAltData;
    private $configHelper;
    private $objectManager;
    private $store;
    private $encryptor;
    private $dataStorage;
    private $assetRepo;
    private $assetSource;
    private $taxConfig;
    private $taxCalculation;
    private $backendHelper;
    private $storeManager;
    private $cache;
    private $localeResolver;
    private $config;
    private $componentRegistrar;
    private $localeHelper;
    private $orderManagement;
    private $orderStatusHistoryFactory;
    private $request;

    public function setUp(): void
    {
        $this->clientMock = $this->createConfiguredMock(Client::class, [
                'getConfig' => new AdyenConfig([
                    'environment' => 'test',
                    'externalPlatform' => ['name' => 'test platform', 'version' => '1.2.3', 'integrator' => 'test integrator'],
                    'merchantApplication' => ['name' => 'test merchant', 'version' => '1.2.3'],
                    'adyenPaymentSource' => ['name' => 'test source', 'version' => '1.2.3']
                ]),
                'getLibraryName' => 'test library',
                'getLibraryVersion' => '1.2.3',
            ]
        );

        // Prepare mock data for ccTypesAltData
        $this->ccTypesAltData = [
            'VI' => ['code_alt' => 'VI', 'code' => 'VI'],
        ];

        $this->configHelper = $this->createConfiguredMock(ConfigHelper::class, [
            'getMotoMerchantAccountProperties' => [
                'apikey' => 'wellProtectedEncryptedApiKey',
                'demo_mode' => '1'
            ]
        ]);

        $this->objectManager = new ObjectManager($this);
        $context = $this->createMock(Context::class);
        $this->store = $this->createMock(Store::class);
        $this->encryptor = $this->createMock(EncryptorInterface::class);
        $this->dataStorage = $this->createMock(DataInterface::class);
        $country = $this->createMock(Country::class);
        $moduleList = $this->createMock(ModuleListInterface::class);
        $this->assetRepo = $this->createMock(Repository::class);
        $this->assetSource = $this->createMock(Source::class);
        $notificationFactory = $this->createGeneratedMock(NotificationCollectionFactory::class);
        $this->taxConfig = $this->createMock(Config::class);
        $this->taxCalculation = $this->createMock(Calculation::class);
        $this->backendHelper = $this->createMock(BackendHelper::class);
        $productMetadata = $this->createConfiguredMock(ProductMetadata::class, [
            'getName' => 'magento',
            'getVersion' => '2.x.x',
            'getEdition' => 'Community'
        ]);
        $this->adyenLogger = $this->createMock(AdyenLogger::class);
        $this->storeManager = $this->createMock(StoreManager::class);
        $this->storeManager->method('getStore')->willReturn($this->store);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->localeResolver = $this->createMock(ResolverInterface::class);
        $this->config = $this->createMock(ScopeConfigInterface::class);
        $this->componentRegistrar = $this->createConfiguredMock(ComponentRegistrarInterface::class, [
            'getPath' => 'vendor/adyen/module-payment'
        ]);
        $this->localeHelper = $this->createMock(Locale::class);
        $this->orderManagement = $this->createMock(OrderManagementInterface::class);
        $this->orderStatusHistoryFactory = $this->createGeneratedMock(HistoryFactory::class);

        $this->dataStorage->method('get')
            ->with('adyen_credit_cards')
            ->willReturn($this->ccTypesAltData);

        $this->request = $this->createMock(Http::class);

        $this->paymentMock = $this->getMockBuilder(InfoInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(null)
            ->getMock();

        // Partial mock builder is being used for mocking the methods in the class being tested.
        $this->dataHelper = $this->getMockBuilder(Data::class)
            ->setMethods(['getModuleVersion'])
            ->setConstructorArgs([
                $context,
                $this->encryptor,
                $this->dataStorage,
                $country,
                $moduleList,
                $this->assetRepo,
                $this->assetSource,
                $notificationFactory,
                $this->taxConfig,
                $this->taxCalculation,
                $this->backendHelper,
                $productMetadata,
                $this->adyenLogger,
                $this->storeManager,
                $this->cache,
                $this->localeResolver,
                $this->config,
                $this->componentRegistrar,
                $this->localeHelper,
                $this->orderManagement,
                $this->orderStatusHistoryFactory,
                $this->configHelper,
                $this->request
            ])
            ->getMock();

        $this->dataHelper->expects($this->any())
            ->method('getModuleVersion')
            ->willReturn('1.2.3');
    }

    public function testGetRecurringTypes()
    {
        $expectedResult = [
            RecurringType::ONECLICK => 'ONECLICK',
            RecurringType::ONECLICK_RECURRING => 'ONECLICK,RECURRING',
            RecurringType::RECURRING => 'RECURRING'
        ];

        $actualResult = $this->dataHelper->getRecurringTypes();

        // Assert that the actual result matches the expected result
        $this->assertEquals($expectedResult, $actualResult);
    }

    public function testGetCheckoutFrontendRegions()
    {
        $expectedResult = [
            'eu' => 'Default (EU - Europe)',
            'au' => 'AU - Australasia',
            'us' => 'US - United States',
            'in' => 'IN - India',
            'apse' => 'APSE - Asia Pacific Southeast'
        ];

        $actualResult = $this->dataHelper->getCheckoutFrontendRegions();

        // Assert that the actual result matches the expected result
        $this->assertEquals($expectedResult, $actualResult);
    }

    public function testGetCaptureModes()
    {
        $expectedResult = [
            'auto' => 'Immediate',
            'manual' => 'Manual'
        ];

        $actualResult = $this->dataHelper->getCaptureModes();

        // Assert that the actual result matches the expected result
        $this->assertEquals($expectedResult, $actualResult);
    }

    /**
     * Test getOpenInvoiceCaptureModes method
     */
    public function testGetOpenInvoiceCaptureModes()
    {
        $expectedResult = [
            'auto' => 'Immediate',
            'manual' => 'Manual',
            'onshipment' => 'On shipment'
        ];

        $actualResult = $this->dataHelper->getOpenInvoiceCaptureModes();

        // Assert that the actual result matches the expected result
        $this->assertEquals($expectedResult, $actualResult);
    }

    public function testGetPaymentRoutines()
    {
        // Define the expected result
        $expectedResult = [
            'single' => 'Single Page Payment Routine',
            'multi' => 'Multi-page Payment Routine'
        ];

        // Call the method to get the actual result
        $actualResult = $this->dataHelper->getPaymentRoutines();

        // Assert that the actual result matches the expected result
        $this->assertEquals($expectedResult, $actualResult);
    }

    /**
     * Test getMinorUnitTaxPercent method
     */
    public function testGetMinorUnitTaxPercent()
    {
        // Define a tax percentage
        $taxPercent = 0.070; // 7%

        // Define the expected result
        $expectedResult = (int)($taxPercent * 100); // 700

        // Call the method to get the actual result
        $actualResult = $this->dataHelper->getMinorUnitTaxPercent($taxPercent);

        // Assert that the actual result matches the expected result
        $this->assertEquals($expectedResult, $actualResult);
    }

    public function testFormatAmount()
    {
        $this->assertEquals('1234', $this->dataHelper->formatAmount('12.34', 'EUR'));
        $this->assertEquals('1200', $this->dataHelper->formatAmount('12.00', 'USD'));
        $this->assertEquals('12', $this->dataHelper->formatAmount('12.00', 'JPY'));
    }

    public function testisPaymentMethodOpenInvoiceMethod()
    {
        $this->assertEquals(true, $this->dataHelper->isPaymentMethodOpenInvoiceMethod('klarna'));
        $this->assertEquals(true, $this->dataHelper->isPaymentMethodOpenInvoiceMethod('klarna_account'));
        $this->assertEquals(true, $this->dataHelper->isPaymentMethodOpenInvoiceMethod('afterpay'));
        $this->assertEquals(true, $this->dataHelper->isPaymentMethodOpenInvoiceMethod('afterpay_default'));
        $this->assertEquals(true, $this->dataHelper->isPaymentMethodOpenInvoiceMethod('ratepay'));
        $this->assertEquals(false, $this->dataHelper->isPaymentMethodOpenInvoiceMethod('ideal'));
        $this->assertEquals(true, $this->dataHelper->isPaymentMethodOpenInvoiceMethod('test_klarna'));
    }

    /**
     * @param string $expectedResult
     * @param string $pspReference
     * @param string $checkoutEnvironment
     * @dataProvider checkoutEnvironmentsProvider
     *
     */
    public function testGetPspReferenceSearchUrl(string $expectedResult, string $pspReference, string $checkoutEnvironment)
    {
        $pspSearchUrl = $this->dataHelper->getPspReferenceSearchUrl($pspReference, $checkoutEnvironment);
        $this->assertEquals($expectedResult, $pspSearchUrl);
    }

    /**
     * Test getHmac method for live mode
     */
    public function testGetHmacForLiveMode()
    {
        $storeId = 1;
        $hmacLive = 'hmac_live_value';
        $expectedResult = 'decrypted_hmac_live_value';

        $this->configHelper->method('isDemoMode')
            ->with($storeId)
            ->willReturn(false);

        $this->configHelper->method('getAdyenHppConfigData')
            ->with('hmac_live', $storeId)
            ->willReturn($hmacLive);

        $this->encryptor->method('decrypt')
            ->with($hmacLive)
            ->willReturn($expectedResult);

        $actualResult = $this->dataHelper->getHmac($storeId);

        // Assert that the actual result matches the expected result
        $this->assertEquals($expectedResult, $actualResult);
    }

    public function testGetHmacInDemoMode()
    {
        $hmacTest = 'hmac_test_value';
        $expectedResult = 'decrypted_hmac';
        $storeId = 1;

        $this->configHelper->method('isDemoMode')
            ->with($storeId)
            ->willReturn(true);

        $this->configHelper->method('getAdyenHppConfigData')
            ->with('hmac_test', $storeId)
            ->willReturn($hmacTest);

        $this->encryptor->method('decrypt')
            ->with($hmacTest)
            ->willReturn($expectedResult);

        // Call the method
        $result = $this->dataHelper->getHmac($storeId);

        // Assertions
        $this->assertEquals('decrypted_hmac', $result);
    }

    public function testGetHmacWhenHmacLiveIsNull()
    {
        $storeId = 1;

        $this->configHelper->expects($this->once())
            ->method('isDemoMode')
            ->with($storeId)
            ->willReturn(false);

        $this->configHelper->expects($this->once())
            ->method('getAdyenHppConfigData')
            ->with('hmac_live', $storeId)
            ->willReturn(null);

        // Call the method under test
        $result = $this->dataHelper->getHmac($storeId);

        // Assert that the result is null
        $this->assertNull($result);
    }

    /**
     * Test isDemoMode method when demo mode is disabled
     */
    public function testIsDemoModeWhenDisabled()
    {
        $storeId = 1;
        $expectedResult = false;

        $this->configHelper->method('getAdyenAbstractConfigDataFlag')
            ->with('demo_mode', $storeId)
            ->willReturn($expectedResult);

        // Call the method to get the actual result
        $actualResult = $this->dataHelper->isDemoMode($storeId);

        // Assert that the actual result matches the expected result
        $this->assertEquals($expectedResult, $actualResult);
    }

    /**
     * Test getAPIKey method in demo mode
     */
    public function testGetAPIKeyInDemoMode()
    {
        $storeId = 1;
        $expectedResult = 'decrypted_api_key_test_value';
        $apiKeyTest = 'api_key_test_value';
        $demoMode = true;
        // Mock isDemoMode method to return true
        $this->configHelper->method('isDemoMode')
            ->with($storeId)
            ->willReturn($demoMode);

        $this->configHelper->method('getAdyenAbstractConfigData')
            ->with('api_key_test', $storeId)
            ->willReturn($apiKeyTest);

        // Mock decrypt method to return decrypted API key live value
        $this->encryptor->method('decrypt')
            ->with($apiKeyTest)
            ->willReturn($expectedResult);

        // Call the method
        $actualResult = $this->dataHelper->getAPIKey($storeId);

        // Assert that the actual API key matches the expected API key
        $this->assertEquals($expectedResult, $actualResult);
    }

    /**
     * Test getAPIKey method when not in demo mode
     */
    public function testGetAPIKeyNotInDemoMode()
    {
        $storeId = 1;
        $demoMode = false;
        $apiKeyLive = 'api_key_live_value';
        $expectedResult = 'decrypted_api_key_live_value';

        $this->configHelper->method('isDemoMode')
            ->with($storeId)
            ->willReturn($demoMode);

        $this->configHelper->method('getAdyenAbstractConfigData')
            ->with('api_key_live', $storeId)
            ->willReturn($apiKeyLive);

        $this->encryptor->method('decrypt')
            ->with($apiKeyLive)
            ->willReturn($expectedResult);

        $actualResult = $this->dataHelper->getAPIKey($storeId);

        // Assert that the actual result matches the expected result
        $this->assertEquals($expectedResult, $actualResult);
    }

    public function testGetAPIKeyWhenApiKeyTestIsNull()
    {
        $storeId = 1;

        $this->configHelper->expects($this->once())
            ->method('isDemoMode')
            ->with($storeId)
            ->willReturn(true);

        $this->configHelper->expects($this->once())
            ->method('getAdyenAbstractConfigData')
            ->with('api_key_test', $storeId)
            ->willReturn(null);

        $result = $this->dataHelper->getAPIKey($storeId);

        // Assert that the result is null
        $this->assertNull($result);
    }

    public function testGetAPIKeyWhenApiKeyLiveIsNull()
    {
        $storeId = 1;

        $this->configHelper->expects($this->once())
            ->method('isDemoMode')
            ->with($storeId)
            ->willReturn(false);

        $this->configHelper->expects($this->once())
            ->method('getAdyenAbstractConfigData')
            ->with('api_key_live', $storeId)
            ->willReturn(null);

        $result = $this->dataHelper->getAPIKey($storeId);

        // Assert that the result is null
        $this->assertNull($result);
    }


    /**
     * Test getWsUsername method in demo mode
     */
    public function testGetWsUsernameInDemoMode()
    {
        $storeId = 1;
        $demoMode = true;
        $wsUsernameTest = 'ws_username_test_value';
        $expectedResult = 'ws_username_test_value';

        // Mock isDemoMode method to return false
        $this->configHelper->method('isDemoMode')
            ->with($storeId)
            ->willReturn($demoMode);

        $this->configHelper->method('getAdyenAbstractConfigData')
            ->with('ws_username_test', $storeId)
            ->willReturn($wsUsernameTest);

        $actualResult = $this->dataHelper->getWsUsername($storeId);

        // Assert that the actual result matches the expected result
        $this->assertEquals($expectedResult, $actualResult);
    }

    public function testGetWsUsernameNotInDemoMode()
    {
        $storeId = 1;
        $demoMode = false;
        $wsUsernameLive = 'ws_username_live_value';
        $expectedResult = 'ws_username_live_value';

        $this->configHelper->method('isDemoMode')
            ->with($storeId)
            ->willReturn($demoMode);

        $this->configHelper->method('getAdyenAbstractConfigData')
            ->with('ws_username_live', $storeId)
            ->willReturn($wsUsernameLive);

        $actualResult = $this->dataHelper->getWsUsername($storeId);

        // Assert that the actual result matches the expected result
        $this->assertEquals($expectedResult, $actualResult);
    }

    public function testGetWsUsernameWhenWsUsernameTestIsNull()
    {
        $storeId = 1;

        $this->configHelper->expects($this->once())
            ->method('isDemoMode')
            ->with($storeId)
            ->willReturn(true);

        $this->configHelper->expects($this->once())
            ->method('getAdyenAbstractConfigData')
            ->with('ws_username_test', $storeId)
            ->willReturn(null);

        $result = $this->dataHelper->getWsUsername($storeId);

        // Assert that the result is null
        $this->assertNull($result);
    }

    public function testGetWsUsernameWhenWsUsernameLiveIsNull()
    {
        $storeId = 1;

        $this->configHelper->expects($this->once())
            ->method('isDemoMode')
            ->with($storeId)
            ->willReturn(false);

        $this->configHelper->expects($this->once())
            ->method('getAdyenAbstractConfigData')
            ->with('ws_username_live', $storeId)
            ->willReturn(null);

        $result = $this->dataHelper->getWsUsername($storeId);

        // Assert that the result is null
        $this->assertNull($result);
    }

    public function testGetModuleVersionWhenVersionAvailable()
    {
        $result = $this->dataHelper->getModuleVersion();
        $expectedResult = "1.2.3";

        $this->assertEquals($expectedResult, $result);
    }


    /**
     * Test cancelOrder method
     */
    public function testCancelOrder()
    {
        $orderStatus = 'payment_cancelled';
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->configHelper->method('getAdyenAbstractConfigData')
            ->with($orderStatus)
            ->willReturn($orderStatus);

        $order->expects($this->once())
            ->method('canCancel')
            ->willReturn(true);

        $order->expects($this->once())
            ->method('getEntityId')
            ->willReturn(1);

        // Call the method
        $this->dataHelper->cancelOrder($order);
    }

    public function testCancelOrderCanHold()
    {
        $orderMock = $this->createMock(Order::class);

        // Setup the mock to return STATE_HOLDED for getAdyenAbstractConfigData
        $this->configHelper->expects($this->once())
            ->method('getAdyenAbstractConfigData')
            ->with('payment_cancelled')
            ->willReturn(Order::STATE_HOLDED);

        // Setup the order mock to allow holding
        $orderMock->expects($this->once())
            ->method('canHold')
            ->willReturn(true);

        $orderMock->expects($this->once())
            ->method('hold')
            ->willReturnSelf();

        $orderMock->expects($this->once())
            ->method('save');

        // Call the method under test
        $this->dataHelper->cancelOrder($orderMock);
    }

    public function testCancelOrderCanCancelNewProcess()
    {
        $orderMock = $this->createMock(Order::class);
        $orderStatusHistoryMock = $this->createMock(\Magento\Sales\Model\Order\Status\History::class);

        // Setup the mock to return a status other than STATE_HOLDED for getAdyenAbstractConfigData
        $this->configHelper->expects($this->once())
            ->method('getAdyenAbstractConfigData')
            ->with('payment_cancelled')
            ->willReturn(Order::STATE_NEW);

        // Setup the order mock to allow cancellation
        $orderMock->expects($this->once())
            ->method('canCancel')
            ->willReturn(true);

        // Ensure getEntityId returns a valid order ID
        $orderMock->expects($this->once())
            ->method('getEntityId')
            ->willReturn(1);

        // Call the method under test
        $this->dataHelper->cancelOrder($orderMock);
    }

    public function testCancelOrderCannotBeCancelled()
    {
        $orderMock = $this->createMock(Order::class);

        // Setup the mock to return a status other than STATE_HOLDED for getAdyenAbstractConfigData
        $this->configHelper->expects($this->once())
            ->method('getAdyenAbstractConfigData')
            ->with('payment_cancelled')
            ->willReturn(Order::STATE_NEW); // For example, set a status that does not require cancellation

        // Setup the order mock to return false for canCancel()
        $orderMock->expects($this->once())
            ->method('canCancel')
            ->willReturn(false);

        // Expect a debug log message for "Order can not be canceled"
        $this->adyenLogger->expects($this->once())
            ->method('addAdyenDebug')
            ->with(
                'Order can not be canceled',
                $this->adyenLogger->getOrderContext($orderMock)
            );

        // Call the method under test
        $this->dataHelper->cancelOrder($orderMock);
    }

    /**
     * Test getMagentoCreditCartType method with known credit card type
     */
    public function testGetMagentoCreditCartTypeWithKnownType()
    {
        $ccType = 'visa';
        $expectedResult = 'visa';

        $actualResult = $this->dataHelper->getMagentoCreditCartType($ccType);

        // Assert that the actual result matches the expected result
        $this->assertEquals($expectedResult, $actualResult);
    }

    /**
     * Test getMagentoCreditCartType method with unknown credit card type
     */
    public function testGetMagentoCreditCartTypeWithUnknownType()
    {
        $ccType = 'unknown';
        $expectedResult = 'unknown';

        $actualResult = $this->dataHelper->getMagentoCreditCartType($ccType);

        // Assert that the actual result matches the expected result
        $this->assertEquals($expectedResult, $actualResult);
    }

    public function testGetMagentoCreditCartTypeWhenNotSet()
    {
        $ccType = '';

        // Call the method under test
        $result = $this->dataHelper->getMagentoCreditCartType($ccType);

        // Assert that the result is the same as the input
        $this->assertEquals($ccType, $result);
    }


    /**
     * Test getCcTypesAltData method
     */
    public function testGetCcTypesAltData()
    {
        // Call the method
        $actualData = $this->dataHelper->getCcTypesAltData();

        // Assert that the actual data matches the expected data
        $this->assertEquals($this->ccTypesAltData, $actualData);
    }

    public function testGetLiveEndpointPrefix()
    {
        $storeId = 1;
        $liveEndpointPrefix = 'live_endpoint_prefix_value';
        $expectedResult = 'live_endpoint_prefix_value';

        $this->configHelper->method('getAdyenAbstractConfigData')
            ->with('live_endpoint_url_prefix', $storeId)
            ->willReturn($liveEndpointPrefix);

        // Call the method to get the actual result
        $actualResult = $this->dataHelper->getLiveEndpointPrefix($storeId);

        // Assert that the actual result matches the expected result
        $this->assertEquals($expectedResult, $actualResult);
    }

    public function testGetLiveEndpointPrefixNull()
    {
        $storeId = 1;
        $liveEndpointPrefix = '';
        $expectedResult = '';

        $this->configHelper->method('getAdyenAbstractConfigData')
            ->with('live_endpoint_url_prefix', $storeId)
            ->willReturn($liveEndpointPrefix);

        $actualResult = $this->dataHelper->getLiveEndpointPrefix($storeId);

        // Assert that the actual result matches the expected result
        $this->assertEquals($expectedResult, $actualResult);
    }

    public function testGetLiveEndpointPrefixWhenPrefixIsNull()
    {
        $storeId = 1;

        $this->configHelper->expects($this->once())
            ->method('getAdyenAbstractConfigData')
            ->with('live_endpoint_url_prefix', $storeId)
            ->willReturn(null);

        $result = $this->dataHelper->getLiveEndpointPrefix($storeId);

        // Assert that the result is null
        $this->assertNull($result);
    }


    /**
     * Test getClientKey method when not in demo mode
     */
    public function testGetClientKeyNotInDemoMode()
    {
        $storeId = 1;
        $demoMode = false;
        $clientKeyLive = 'client_key_live_value';
        $expectedResult = 'client_key_live_value';

        $this->configHelper->method('isDemoMode')
            ->with($storeId)
            ->willReturn($demoMode);

        $this->configHelper->method('getAdyenAbstractConfigData')
            ->with('client_key_live', $storeId)
            ->willReturn($clientKeyLive);

        // Call the method to get the actual result
        $actualResult = $this->dataHelper->getClientKey($storeId);

        // Assert that the actual result matches the expected result
        $this->assertEquals($expectedResult, $actualResult);
    }

    public function testGetClientKeyWhenClientKeyTestIsNull()
    {
        $storeId = 1;

        $this->configHelper->expects($this->once())
            ->method('isDemoMode')
            ->with($storeId)
            ->willReturn(true);

        $this->configHelper->expects($this->once())
            ->method('getAdyenAbstractConfigData')
            ->with('client_key_test', $storeId)
            ->willReturn(null);

        // Call the method under test
        $result = $this->dataHelper->getClientKey($storeId);

        // Assert that the result is null
        $this->assertNull($result);
    }

    public function testGetClientKeyWhenClientKeyLiveIsNull()
    {
        $storeId = 1;

        $this->configHelper->expects($this->once())
            ->method('isDemoMode')
            ->with($storeId)
            ->willReturn(false);

        $this->configHelper->expects($this->once())
            ->method('getAdyenAbstractConfigData')
            ->with('client_key_live', $storeId)
            ->willReturn(null);

        $result = $this->dataHelper->getClientKey($storeId);

        // Assert that the result is null
        $this->assertNull($result);
    }


    /**
     * Test isPaymentMethodOfType method with various scenarios
     *
     * @dataProvider paymentMethodProvider
     */
    public function testIsPaymentMethodOfType($paymentMethod, $type, $expectedResult)
    {
        $actualResult = $this->dataHelper->isPaymentMethodOfType($paymentMethod, $type);

        // Assert that the actual result matches the expected result
        $this->assertEquals($expectedResult, $actualResult);
    }

    public function testIsPaymentMethodOpenInvoiceMethodIsNull()
    {
        $paymentMethod = null;

        $result = $this->dataHelper->isPaymentMethodOpenInvoiceMethod($paymentMethod);

        // Assert that the result is false
        $this->assertFalse($result);
    }

    /**
     * Test isVatCategoryHigh method with various scenarios
     *
     * @dataProvider paymentMethodProviderForVat
     */
    public function testIsVatCategoryHigh($paymentMethod, $expectedResult)
    {
        // Call the method with the provided payment method
        $actualResult = $this->dataHelper->isVatCategoryHigh($paymentMethod);

        // Assert that the actual result matches the expected result
        $this->assertEquals($expectedResult, $actualResult);
    }

    /**
     * Data provider for isVatCategoryHigh method
     */
    public function paymentMethodProviderForVat(): array
    {
        return [
            ['klarna', true], // KLARNA payment method
            ['afterpay_nl', true], // afterpay_ prefixed payment method
            ['adyen_cc', false], // Non-high VAT category payment method
            ['paypal_express', false], // Non-high VAT category payment method
        ];
    }

    /**
     * Data provider for isPaymentMethodOfType method
     */
    public function paymentMethodProvider(): array
    {
        return [
            ['adyen_cc', 'adyen', true], // Payment method contains the specified type
            ['adyen_sepa', 'adyen', true], // Payment method contains the specified type
            ['paypal_express', 'paypal', true], // Payment method contains the specified type
            ['paypal_express_bml', 'paypal', true], // Payment method contains the specified type
            ['stripe', 'adyen', false], // Payment method does not contain the specified type
            ['paypal_express', 'adyen', false], // Payment method does not contain the specified type
            ['paypal_express_bml', 'stripe', false], // Payment method does not contain the specified type
        ];
    }

    /**
     * Test showLogos method with various scenarios
     *
     * @dataProvider showLogosProvider
     */
    public function testShowLogos($titleRenderer, $expectedResult)
    {
        // Mock ConfigHelper class
        $configHelperMock = $this->getMockBuilder(\Adyen\Payment\Helper\Config::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Set up ConfigHelper mock to return the specified title_renderer value
        $configHelperMock->method('getAdyenAbstractConfigData')
            ->with('title_renderer')
            ->willReturn($titleRenderer);

        // Call the method
        $actualResult = $this->dataHelper->showLogos();

        // Assert that the actual result matches the expected result
        $this->assertEquals($expectedResult, $actualResult);
    }

    /**
     * Data provider for showLogos method
     */
    public function showLogosProvider(): array
    {
        return [
            [RenderMode::MODE_TITLE, false], // Title renderer mode is text, logos should not be shown
            [RenderMode::MODE_TITLE_IMAGE, false], // Title renderer mode is image, logos should be shown
            ['other_mode', false], // Invalid mode, logos should not be shown
        ];
    }

    public function testShowLogosReturnsTrue()
    {
        // Set up the mock for configHelper to return RenderMode::MODE_TITLE_IMAGE
        $this->configHelper->expects($this->once())
            ->method('getAdyenAbstractConfigData')
            ->with('title_renderer')
            ->willReturn(RenderMode::MODE_TITLE_IMAGE);

        // Call the method under test
        $result = $this->dataHelper->showLogos();

        // Assert that the result is true
        $this->assertTrue($result);
    }

    public static function checkoutEnvironmentsProvider(): array
    {
        return [
            [
                'https://ca-test.adyen.com/ca/ca/accounts/showTx.shtml?pspReference=7914073381342284',
                '7914073381342284',
                'false'
            ],
            [
                'https://ca-live.adyen.com/ca/ca/accounts/showTx.shtml?pspReference=883580976999434D',
                '883580976999434D',
                'true'
            ]
        ];
    }

    /*
     * Provider for testInitializeAdyenClientWithClientConfig test case
     */
    public static function clientConfigProvider(): array
    {
        return [
            [
                '$clientConfig' => [
                    'storeId' => 1
                ]
            ],
            [
                '$clientConfig' => [
                    'storeId' => 1,
                    'isMotoTransaction' => true,
                    'motoMerchantAccount' => 'TESTMERCHANTACCOUNT'
                ]
            ]
        ];
    }

    /**
     * @dataProvider clientConfigProvider
     */
    public function testInitializeAdyenClientWithClientConfig($clientConfig)
    {
        $this->assertInstanceOf(
            Client::class,
            $this->dataHelper->initializeAdyenClientWithClientConfig($clientConfig)
        );
    }

    public function testGetMagentoDetails()
    {
        $expectedDetails = [
            'name' => 'magento',
            'version' => '2.x.x',
            'edition' => 'Community'
        ];

        $actualDetails = $this->dataHelper->getMagentoDetails();
        $this->assertEquals($expectedDetails, $actualDetails);
    }

    public function testBuildRequestHeaders()
    {
        $expectedHeaders = [
            'external-platform-name' => 'magento',
            'external-platform-version' => '2.x.x',
            'external-platform-edition' => 'Community',
            'merchant-application-name' => 'adyen-magento2',
            'merchant-application-version' => '1.2.3'
        ];

        $headers = $this->dataHelper->buildRequestHeaders();
        $this->assertEquals($expectedHeaders, $headers);
    }

    public function testBuildRequestHeadersWithFrontendTypeSet(): void
    {
        // Mock dependencies as needed
        $payment = $this->createMock(Payment::class);

        // Set up expectations for the getAdditionalInformation method
        $payment->method('getAdditionalInformation')
            ->with(HeaderDataBuilderInterface::ADDITIONAL_DATA_FRONTEND_TYPE_KEY)
            ->willReturn(null);

        $headers = $this->dataHelper->buildRequestHeaders($this->paymentMock);

        $this->assertArrayHasKey(HeaderDataBuilderInterface::EXTERNAL_PLATFORM_FRONTEND_TYPE, $headers);
        $this->assertEquals('headless-rest', $headers[HeaderDataBuilderInterface::EXTERNAL_PLATFORM_FRONTEND_TYPE]);
    }

    public function testBuildRequestHeadersWithNullFrontendTypeGraphQL(): void
    {
        $this->paymentMock->method('getAdditionalInformation')
            ->with(HeaderDataBuilderInterface::ADDITIONAL_DATA_FRONTEND_TYPE_KEY)
            ->willReturn(null);

        $this->request->method('getOriginalPathInfo')
            ->willReturn('/graphql');
        $this->request->method('getMethod')
            ->willReturn('POST');

        $headers = $this->dataHelper->buildRequestHeaders($this->paymentMock);

        $this->assertArrayHasKey(HeaderDataBuilderInterface::EXTERNAL_PLATFORM_FRONTEND_TYPE, $headers);
        $this->assertEquals('headless-graphql', $headers[HeaderDataBuilderInterface::EXTERNAL_PLATFORM_FRONTEND_TYPE]);
    }

    public function testBuildApplicationInfo()
    {
        $expectedApplicationInfo =  new ApplicationInfo();

        // These getters are deprecated but needed to mock the client
        $expectedApplicationInfo->setAdyenLibrary(new CommonField([
            'name' => $this->clientMock->getLibraryName(),
            'version' => $this->clientMock->getLibraryVersion()
        ]));

        $expectedApplicationInfo->setAdyenPaymentSource(new CommonField(
            $this->clientMock->getConfig()->getAdyenPaymentSource())
        );

        $expectedApplicationInfo->setExternalPlatform(
            $this->clientMock->getConfig()->getExternalPlatform()
        );

        $expectedApplicationInfo->setMerchantApplication(new CommonField(
            $this->clientMock->getConfig()->getMerchantApplication())
        );

        $applicationInfo = $this->dataHelper->buildApplicationInfo($this->clientMock);

        $this->assertEquals(
            $expectedApplicationInfo,
            $applicationInfo
        );
    }

    public function testBuildRequestHeadersWithNonNullFrontendType()
    {
        // Mock dependencies as needed
        $payment = $this->createMock(Payment::class);

        // Set up expectations for the getAdditionalInformation method
        $payment->method('getAdditionalInformation')
            ->with(HeaderDataBuilderInterface::ADDITIONAL_DATA_FRONTEND_TYPE_KEY)
            ->willReturn('default');

        // Call the method under test
        $result = $this->dataHelper->buildRequestHeaders($payment);

        // Assert that the 'frontend-type' header is correctly set
        $this->assertArrayHasKey(HeaderDataBuilderInterface::EXTERNAL_PLATFORM_FRONTEND_TYPE, $result);
        $this->assertEquals('default', $result[HeaderDataBuilderInterface::EXTERNAL_PLATFORM_FRONTEND_TYPE]);

        // Assert other headers as needed
    }


    public function testBuildRequestHeadersWithoutPayment()
    {
        // Call the method under test without providing a payment object
        $result = $this->dataHelper->buildRequestHeaders();

        // Assert that the 'frontend-type' header is not set
        $this->assertArrayNotHasKey(HeaderDataBuilderInterface::EXTERNAL_PLATFORM_FRONTEND_TYPE, $result);
    }

    public function testLogResponse()
    {
        // Set up dummy data
        $response = ['key' => 'value'];
        $storeId = 1;
        $isDemo = true;

        // Mock methods for store manager and config helper
        $this->store->method('getId')->willReturn($storeId);
        $this->storeManager->method('getStore')->willReturn($this->store);
        $this->configHelper->method('isDemoMode')->with($storeId)->willReturn($isDemo);

        // Assert that the logger was called with the correct parameters based on demo mode
        $this->adyenLogger->expects($this->once())
            ->method('info')
            ->with('Response from Adyen API', ['body' => $response]);
        // Call the method under test
        $this->dataHelper->logResponse($response);

    }

    public function testLogResponseWhenDemoModeFalse()
    {
        // Set up dummy data
        $storeId = 1;
        $isDemo = false;

        // Mock methods for store manager and config helper
        $this->store->method('getId')->willReturn($storeId);
        $this->storeManager->method('getStore')->willReturn($this->store);
        $this->configHelper->method('isDemoMode')->with($storeId)->willReturn($isDemo);

        // Expect filtering of references in the response
        $filteredResponse = ['reference' => '123'];
        // Mock the private method filterReferences using ReflectionClass
        $reflectionClass = new ReflectionClass(Data::class);
        $method = $reflectionClass->getMethod('filterReferences');
        $method->setAccessible(true);
        $filteredResponse = $method->invokeArgs($this->dataHelper, [$filteredResponse]);

        $this->adyenLogger->expects($this->once())
            ->method('info')
            ->with('Response from Adyen API', ['body' => $filteredResponse]);
        // Call the method under test
        $this->dataHelper->logResponse($filteredResponse);
    }

    public function testLogRequest()
    {
        // Set up dummy data
        $request = ['reference' => '123', 'not_reference' => '456'];
        $apiVersion = 'v1';
        $endpoint = 'payment/authorise';

        // Set up store ID and demo mode
        $storeId = 1;
        $isDemo = false;

        // Mock methods for store manager and config helper
        $this->store->method('getId')->willReturn($storeId);
        $this->storeManager->method('getStore')->willReturn($this->store);
        $this->configHelper->method('isDemoMode')->with($storeId)->willReturn($isDemo);

        $this->configHelper->method('getLiveEndpointPrefix')->willReturn('live_prefix');
        $reflectionClass = new ReflectionClass(Data::class);
        $method = $reflectionClass->getMethod('filterReferences');
        $method->setAccessible(true);

        $filteredResponse = $method->invokeArgs($this->dataHelper, [$request]);

        // Expect info method to be called on adyenLogger with correct context
        $this->adyenLogger->expects($this->once())
            ->method('info')
            ->with('Request to Adyen API payment/authorise', [
                'apiVersion' => $apiVersion,
                'livePrefix' => 'live_prefix',
                'body' => $filteredResponse
            ]);

        // Call the method under test
        $this->dataHelper->logRequest($request, $apiVersion, $endpoint);
    }

    public function testPadShopperReference()
    {
        // Test case 1: When the shopper reference length is less than 3
        $shopperReference1 = '12';
        $expected1 = '012'; // Expected output after padding
        $result1 = $this->dataHelper->padShopperReference($shopperReference1);
        $this->assertEquals($expected1, $result1);

        // Test case 2: When the shopper reference length is equal to 3
        $shopperReference2 = '123';
        $expected2 = '123'; // No padding needed
        $result2 = $this->dataHelper->padShopperReference($shopperReference2);
        $this->assertEquals($expected2, $result2);

        // Test case 3: When the shopper reference length is greater than 3
        $shopperReference3 = '1234';
        $expected3 = '1234'; // No padding needed
        $result3 = $this->dataHelper->padShopperReference($shopperReference3);
        $this->assertEquals($expected3, $result3);
    }

    public function testGetCurrentLocaleCode()
    {
        $storeId = 1;

        $this->configHelper->method('getAdyenHppConfigData')
            ->willReturnMap([
                ['shopper_locale', $storeId, 'en_US'], // Mocking a shopper locale value
            ]);
        $this->localeHelper->method('mapLocaleCode')
            ->willReturnMap([
                ['en_US', 'en_US'], // Mocking locale mapping
            ]);
        $this->localeResolver->method('getLocale')
            ->willReturn('en_US'); // Mocking locale resolver
        $this->config->method('getValue')
            ->willReturn('en_US'); // Mocking default locale config

        // Test case: When a shopper locale is provided
        $expected1 = 'en_US';
        $result1 = $this->dataHelper->getCurrentLocaleCode($storeId);
        $this->assertEquals($expected1, $result1);

        // Test case: When neither shopper locale nor locale resolver returns a value
        $this->localeResolver->method('getLocale')
            ->willReturn('');
        $expected3 = 'en_US'; // Assuming en_US is the default locale
        $result3 = $this->dataHelper->getCurrentLocaleCode($storeId);
        $this->assertEquals($expected3, $result3);
    }

    public function testBuildThreeDS2ProcessResponseJson()
    {
        // Test case: When type and token are not provided
        $expected1 = '{"threeDS2":false}';
        $result1 = $this->dataHelper->buildThreeDS2ProcessResponseJson();
        $this->assertEquals($expected1, $result1);

        // Test case: When type is provided but token is not provided
        $expected2 = '{"threeDS2":false,"type":"example"}';
        $result2 = $this->dataHelper->buildThreeDS2ProcessResponseJson('example');
        $this->assertEquals($expected2, $result2);

        // Test case: When both type and token are provided
        $expected3 = '{"threeDS2":true,"type":"example","token":"xyz123"}';
        $result3 = $this->dataHelper->buildThreeDS2ProcessResponseJson('example', 'xyz123');
        $this->assertEquals($expected3, $result3);
    }

    public function testFormatDateWithValidDate()
    {
        // Test case: When a valid date is provided
        $inputDate = '2024-05-16 10:30:00';
        $expected = '2024-05-16 10:30:00';
        $result = $this->dataHelper->formatDate($inputDate);
        $this->assertEquals($expected, $result);
    }

    public function testFormatDateWithDefaultDate()
    {
        // Test case: When date is not provided, should default to current date/time
        $expectedFormat = 'Y-m-d H:i:s';
        $expectedTimestamp = date($expectedFormat);
        $result = $this->dataHelper->formatDate();
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result);
        $this->assertStringContainsString($expectedTimestamp, $result);
    }

    public function testFormatDateWithInvalidDate()
    {
        // Test case: When an invalid date format is provided
        $invalidDate = 'invalid_date_format';
        $this->expectException(\Exception::class);
        $this->dataHelper->formatDate($invalidDate);
    }

    public function testIsHppVaultEnabled_WhenEnabled()
    {
        // Mock the return value of getAdyenHppVaultConfigDataFlag
        $this->configHelper->expects($this->once())
            ->method('getAdyenHppVaultConfigDataFlag')
            ->with('active', null)
            ->willReturn(true);

        // Call the method
        $result = $this->dataHelper->isHppVaultEnabled();

        // Assert that the result is true
        $this->assertTrue($result);
    }

    public function testIsHppVaultEnabled_WhenDisabled()
    {
        // Mock the return value of getAdyenHppVaultConfigDataFlag
        $this->configHelper->expects($this->once())
            ->method('getAdyenHppVaultConfigDataFlag')
            ->with('active', null)
            ->willReturn(false);

        // Call the method
        $result = $this->dataHelper->isHppVaultEnabled();

        // Assert that the result is false
        $this->assertFalse($result);
    }

    public function testGetCustomerId()
    {
        // Mock an Order object
        $orderMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Define the expected customer ID
        $expectedCustomerId = 123;

        // Mock the getCustomerId method of the Order object to return the expected value
        $orderMock->expects($this->once())
            ->method('getCustomerId')
            ->willReturn($expectedCustomerId);

        // Call the method getCustomerId from the Data helper
        $customerId = $this->dataHelper->getCustomerId($orderMock);

        // Assert that the returned customer ID matches the expected value
        $this->assertEquals($expectedCustomerId, $customerId);
    }

    public function testGetCheckoutEnvironmentDemoMode()
    {
        $this->configHelper->expects($this->once())
            ->method('isDemoMode')
            ->willReturn(true);

        // Call the method under test
        $result = $this->dataHelper->getCheckoutEnvironment();

        // Assert the expected result
        $this->assertEquals(Data::TEST, $result);
    }

    public function testGetCheckoutEnvironmentLiveAU()
    {
        // Setup the mock to return false for isDemoMode
        $this->configHelper->expects($this->once())
            ->method('isDemoMode')
            ->willReturn(false);

        // Setup the mock to return "au" for getCheckoutFrontendRegion
        $this->configHelper->expects($this->once())
            ->method('getCheckoutFrontendRegion')
            ->willReturn("au");

        // Call the method under test
        $result = $this->dataHelper->getCheckoutEnvironment();

        // Assert the expected result
        $this->assertEquals(Data::LIVE_AU, $result);
    }

    public function testGetCheckoutEnvironmentLiveUS()
    {
        // Setup the mock to return false for isDemoMode
        $this->configHelper->expects($this->once())
            ->method('isDemoMode')
            ->willReturn(false);

        // Setup the mock to return "us" for getCheckoutFrontendRegion
        $this->configHelper->expects($this->once())
            ->method('getCheckoutFrontendRegion')
            ->willReturn("us");

        // Call the method under test
        $result = $this->dataHelper->getCheckoutEnvironment();

        // Assert the expected result
        $this->assertEquals(Data::LIVE_US, $result);
    }

    public function testGetCheckoutEnvironmentLiveIN()
    {
        // Setup the mock to return false for isDemoMode
        $this->configHelper->expects($this->once())
            ->method('isDemoMode')
            ->willReturn(false);

        // Setup the mock to return "in" for getCheckoutFrontendRegion
        $this->configHelper->expects($this->once())
            ->method('getCheckoutFrontendRegion')
            ->willReturn("in");

        // Call the method under test
        $result = $this->dataHelper->getCheckoutEnvironment();

        // Assert the expected result
        $this->assertEquals(Data::LIVE_IN, $result);
    }

    public function testGetCheckoutEnvironmentDefault()
    {
        // Setup the mock to return false for isDemoMode
        $this->configHelper->expects($this->once())
            ->method('isDemoMode')
            ->willReturn(false);

        // Setup the mock to return a value other than "au", "us", or "in" for getCheckoutFrontendRegion
        $this->configHelper->expects($this->once())
            ->method('getCheckoutFrontendRegion')
            ->willReturn("eu");

        // Call the method under test
        $result = $this->dataHelper->getCheckoutEnvironment();

        // Assert the expected result
        $this->assertEquals(Data::LIVE, $result);
    }

    public function testGetOrigin()
    {
        $storeId = 1;
        $expectedBaseUrl = 'https://example.com/';

        $stateMock = $this->createMock(State::class);

        $objectManagerStub = $this->createMock(\Magento\Framework\App\ObjectManager::class);
        $objectManagerStub->method('get')->willReturnMap([
            [State::class, $stateMock]
        ]);
        \Magento\Framework\App\ObjectManager::setInstance($objectManagerStub);

        // Mock the config helper to return an empty value
        $this->configHelper->expects($this->once())
            ->method('getAdyenAbstractConfigData')
            ->with('payment_origin_url', $storeId)
            ->willReturn('');

        // Mock the store to return the expected base URL
        $this->store->expects($this->once())
            ->method('getBaseUrl')
            ->with(UrlInterface::URL_TYPE_WEB)
            ->willReturn($expectedBaseUrl);

        // Mock the state to return a different area code
        $stateMock->expects($this->once())
            ->method('getAreaCode')
            ->willReturn('frontend');

        // Call the method under test
        $result = $this->dataHelper->getOrigin($storeId);

        // Parse the expected base URL
        $parsed = parse_url($expectedBaseUrl);
        $expectedOrigin = $parsed['scheme'] . '://' . $parsed['host'];

        // Assert the result
        $this->assertEquals($expectedOrigin, $result);
    }

    public function testFormatTerminalAPIReceipt()
    {
        // Mock payment receipt data
        $paymentReceipt = [
            [
                'DocumentQualifier' => 'CustomerReceipt',
                'OutputContent' => [
                    'OutputText' => [
                        ['Text' => 'name=Item 1&value=$10'],
                        ['Text' => 'name=Item 2&value=$20']
                    ]
                ]
            ],
        ];

        // Call the method under test
        $formattedHtml = $this->dataHelper->formatTerminalAPIReceipt($paymentReceipt);

        // Assert the generated HTML
        $expectedHtml = "<table class='terminal-api-receipt'>"
            . "<tr class='terminal-api-receipt'><td class='terminal-api-receipt-name'>Item 1</td>"
            . "<td class='terminal-api-receipt-value' align='right'>$10</td></tr>"
            . "<tr class='terminal-api-receipt'><td class='terminal-api-receipt-name'>Item 2</td>"
            . "<td class='terminal-api-receipt-value' align='right'>$20</td></tr>"
            . "</table>";
        $this->assertEquals($expectedHtml, $formattedHtml);
    }

    public function testGetAdyenMerchantAccountForAdyenPaymentMethod()
    {
        // Mock the store ID
        $storeId = 1;

        // Mock the payment method
        $paymentMethod = 'adyen';

        // Mock the merchant account data
        $merchantAccount = 'mock_merchant_account';

        // Mock the store manager and config helper
        $storeMock = $this->createMock(Store::class);
        $storeMock->expects($this->any())
            ->method('getId')
            ->willReturn($storeId);

        $this->storeManager->expects($this->any())
            ->method('getStore')
            ->willReturn($storeMock);

        $this->configHelper->expects($this->once())
            ->method('getAdyenAbstractConfigData')
            ->with('merchant_account', $storeId)
            ->willReturn($merchantAccount);

        // Call the method under test
        $result = $this->dataHelper->getAdyenMerchantAccount($paymentMethod, $storeId);

        // Assert the result
        $this->assertEquals($merchantAccount, $result);
    }

    public function testGetAdyenMerchantAccountForAdyenPosCloudPaymentMethod()
    {
        $storeId = 1;

        $paymentMethod = 'adyen_pos_cloud';

        $merchantAccountPos = 'mock_pos_merchant_account';

        // Mock the store manager and config helper
        $storeMock = $this->createMock(Store::class);
        $storeMock->expects($this->any())
            ->method('getId')
            ->willReturn($storeId);

        $this->storeManager->expects($this->any())
            ->method('getStore')
            ->willReturn($storeMock);

        $this->configHelper->expects($this->once())
            ->method('getAdyenAbstractConfigData')
            ->willReturn('mock_merchant_account');

        $this->configHelper->expects($this->once())
            ->method('getAdyenPosCloudConfigData')
            ->willReturn($merchantAccountPos);

        // Call the method under test
        $result = $this->dataHelper->getAdyenMerchantAccount($paymentMethod, $storeId);

        // Assert the result
        $this->assertEquals($merchantAccountPos, $result);
    }

    public function testGetPosApiKeyInDemoMode()
    {
        $storeId = 1;

        $encryptedApiKeyTest = 'mock_encrypted_api_key_test';
        $decryptedApiKey = 'mock_decrypted_api_key';

        // Mock isDemoMode to return true
        $this->configHelper->expects($this->once())
            ->method('isDemoMode')
            ->with($storeId)
            ->willReturn(true);

        // Mock getAdyenPosCloudConfigData to return the encrypted API key
        $this->configHelper->expects($this->once())
            ->method('getAdyenPosCloudConfigData')
            ->with('api_key_test', $storeId)
            ->willReturn($encryptedApiKeyTest);

        // Mock decryptor to decrypt the API key
        $this->encryptor->expects($this->once())
            ->method('decrypt')
            ->with(trim((string) $encryptedApiKeyTest))
            ->willReturn($decryptedApiKey);

        // Call the method under test
        $result = $this->dataHelper->getPosApiKey($storeId);

        // Assert the result
        $this->assertEquals($decryptedApiKey, $result);
    }

    public function testGetPosApiKeyInLiveMode()
    {
        $storeId = 1;

        $encryptedApiKeyLive = 'mock_encrypted_api_key_live';
        $decryptedApiKey = 'mock_decrypted_api_key';

        $this->configHelper->expects($this->once())
            ->method('isDemoMode')
            ->with($storeId)
            ->willReturn(false);

        // Mock getAdyenPosCloudConfigData to return the encrypted API key
        $this->configHelper->expects($this->once())
            ->method('getAdyenPosCloudConfigData')
            ->with('api_key_live', $storeId)
            ->willReturn($encryptedApiKeyLive);

        // Mock decryptor to decrypt the API key
        $this->encryptor->expects($this->once())
            ->method('decrypt')
            ->with(trim((string) $encryptedApiKeyLive))
            ->willReturn($decryptedApiKey);

        // Call the method under test
        $result = $this->dataHelper->getPosApiKey($storeId);

        // Assert the result
        $this->assertEquals($decryptedApiKey, $result);
    }

    public function testGetOpenInvoiceLineData()
    {
        // Mock dependencies as needed
        $formFields = [];
        $count = 1;
        $currencyCode = "EUR";
        $description = "Product Description";
        $itemAmount = "10000";
        $itemVatAmount = "1000";
        $itemVatPercentage = 1000;
        $numberOfItems = 1;
        $payment = $this->createMock(Payment::class);
        $itemId = "item_1";

        $payment->expects($this->once())
            ->method('getAdditionalInformation')
            ->with(AdyenPaymentMethodDataAssignObserver::BRAND_CODE)
            ->willReturn('adyen_brand_code');

        // Call the method under test
        $result = $this->dataHelper->getOpenInvoiceLineData(
            $formFields,
            $count,
            $currencyCode,
            $description,
            $itemAmount,
            $itemVatAmount,
            $itemVatPercentage,
            $numberOfItems,
            $payment,
            $itemId
        );

        // Assert that the formFields array is correctly populated
        $this->assertArrayHasKey('openinvoicedata.line1.itemId', $result);
        $this->assertEquals($itemId, $result['openinvoicedata.line1.itemId']);
        $this->assertArrayHasKey('openinvoicedata.line1.currencyCode', $result);
        $this->assertEquals($currencyCode, $result['openinvoicedata.line1.currencyCode']);
        $this->assertArrayHasKey('openinvoicedata.line1.description', $result);
        $this->assertEquals($description, $result['openinvoicedata.line1.description']);
        $this->assertArrayHasKey('openinvoicedata.line1.itemAmount', $result);
        $this->assertEquals($itemAmount, $result['openinvoicedata.line1.itemAmount']);
        $this->assertArrayHasKey('openinvoicedata.line1.itemVatAmount', $result);
        $this->assertEquals($itemVatAmount, $result['openinvoicedata.line1.itemVatAmount']);
        $this->assertArrayHasKey('openinvoicedata.line1.itemVatPercentage', $result);
        $this->assertEquals($itemVatPercentage, $result['openinvoicedata.line1.itemVatPercentage']);
        $this->assertArrayHasKey('openinvoicedata.line1.numberOfItems', $result);
        $this->assertEquals($numberOfItems, $result['openinvoicedata.line1.numberOfItems']);
        $this->assertArrayHasKey('openinvoicedata.line1.vatCategory', $result);
    }


    public function testCreateOpenInvoiceLineItem()
    {
        // Mock any dependencies as needed
        $formFields = [];
        $count = 1;
        $name = "Product Name";
        $price = 100.00;
        $currency = "EUR";
        $taxAmount = 10.00;
        $priceInclTax = 110.00;
        $taxPercent = 10.0;
        $numberOfItems = 1;
        $itemId = "item_1";
        $payment = $this->createMock(Payment::class);
        $expectedResult = [
            'openinvoicedata.line1.itemId' => 'item_1',
            'openinvoicedata.line1.description' => 'Product Name',
            'openinvoicedata.line1.itemAmount' => 10000,
            'openinvoicedata.line1.itemVatAmount' => 1000,
            'openinvoicedata.line1.itemVatPercentage' => 1000,
            'openinvoicedata.line1.currencyCode' => 'EUR',
            'openinvoicedata.line1.numberOfItems' => 1,
            'openinvoicedata.line1.vatCategory' => 'None'
        ];

        $payment->expects($this->once())
            ->method('getAdditionalInformation')
            ->with(AdyenPaymentMethodDataAssignObserver::BRAND_CODE)
            ->willReturn('adyen_brand_code');

        // Call the method under test
        $result = $this->dataHelper->createOpenInvoiceLineItem(
            $formFields,
            $count,
            $name,
            $price,
            $currency,
            $taxAmount,
            $priceInclTax,
            $taxPercent,
            $numberOfItems,
            $payment,
            $itemId
        );

        // Assert that the result matches the expected data
        $this->assertEquals($expectedResult, $result);
    }

    public function testCreateOpenInvoiceLineShipping()
    {
        $formFields = [];
        $count = 1;
        $order = $this->createMock(Order::class);
        $shippingAmount = 10.00;
        $shippingTaxAmount = 2.00;
        $currency = "USD";
        $class= 2;
        $payment = $this->createMock(Payment::class);
        $storeId = 1;
        // Mock order methods to return required data
        $order->expects($this->once())
            ->method('getShippingDescription')
            ->willReturn("Shipping Description");
        $order->expects($this->once())
            ->method('getShippingAddress')
            ->willReturn($this->createMock(Address::class));
        $order->expects($this->once())
            ->method('getBillingAddress')
            ->willReturn($this->createMock(Address::class));
        $order->method('getStoreId')
            ->willReturn(1);
        $order->expects($this->once())
            ->method('getCustomerId')
            ->willReturn(1);

        // Mock tax calculation methods
        $this->taxConfig->expects($this->once())
            ->method('getShippingTaxClass')
            ->with($storeId)
            ->willReturn($class);
        $this->taxCalculation->expects($this->once())
            ->method('getRateRequest')
            ->willReturn($this->createMock(RateRequest::class));

        // Call the method under test
        $result = $this->dataHelper->createOpenInvoiceLineShipping(
            $formFields,
            $count,
            $order,
            $shippingAmount,
            $shippingTaxAmount,
            $currency,
            $payment
        );

        // Assert that the formFields array is correctly populated
        $this->assertArrayHasKey('openinvoicedata.line1.itemId', $result);
        $this->assertEquals("shippingCost", $result['openinvoicedata.line1.itemId']);
    }

    /**
     * @test
     */
    public function getRecurringTypesShouldReturnAnArrayOfRecurringTypes()
    {
        $this->assertEquals([
            RecurringType::ONECLICK => 'ONECLICK',
            RecurringType::ONECLICK_RECURRING => 'ONECLICK,RECURRING',
            RecurringType::RECURRING => 'RECURRING'
        ], $this->dataHelper->getRecurringTypes());
    }

    public function getCheckoutFrontendRegionsShouldReturnAnArray()
    {
        $this->assertEquals([
            'eu' => 'Default (EU - Europe)',
            'au' => 'AU - Australasia',
            'us' => 'US - United States',
            'in' => 'IN - India',
            'apse' => 'APSE - Asia Pacific Southeast'
        ], $this->dataHelper->getRecurringTypes());
    }

    public function testGetClientKey()
    {
        $expectedValue = 'client_key_test_value';
        $storeId = 1;

        $this->configHelper->method('isDemoMode')
            ->with($storeId)
            ->willReturn(true);

        $this->configHelper->method('getAdyenAbstractConfigData')
            ->with('client_key_test', $storeId)
            ->willReturn($expectedValue);

        $key = $this->dataHelper->getClientKey(1);
        $this->assertEquals($expectedValue, $key);
    }

    public function testGetApiKey()
    {
        $apiKey = 'api_key_test_value';
        $expectedValue = 'api_key_test_decryted_value';
        $storeId = 1;

        $this->configHelper->method('isDemoMode')
            ->with($storeId)
            ->willReturn(true);

        $this->configHelper->method('getAdyenAbstractConfigData')
            ->with('api_key_test', $storeId)
            ->willReturn($apiKey);

        $this->encryptor->method('decrypt')
            ->with($apiKey)
            ->willReturn($expectedValue);

        $key = $this->dataHelper->getAPIKey(1);
        $this->assertEquals($expectedValue, $key);
    }

    public function testIsDemoMode()
    {
        $storeId = 1;
        $this->configHelper->method('getAdyenAbstractConfigDataFlag')
            ->with('demo_mode', $storeId)
            ->willReturn(true);

        $value = $this->dataHelper->isDemoMode($storeId);

        $this->assertEquals(true, $value);
    }

    public function testCaptureModes()
    {
        $this->assertSame(
            [
                'auto' => 'Immediate',
                'manual' => 'Manual'
            ],
            $this->dataHelper->getCaptureModes()
        );
    }

    public function testInitializePaymentsApi()
    {
        $service = $this->dataHelper->initializePaymentsApi($this->clientMock);
        $this->assertInstanceOf(PaymentsApi::class, $service);
    }

    public function testInitializeModificationsApi()
    {
        $service = $this->dataHelper->initializeModificationsApi($this->clientMock);
        $this->assertInstanceOf(ModificationsApi::class, $service);
    }

    public function testInitializeRecurringApi()
    {
        $service = $this->dataHelper->initializeRecurringApi($this->clientMock);
        $this->assertInstanceOf(RecurringApi::class, $service);
    }

    public function testInitializeOrdersApi()
    {
        $service = $this->dataHelper->initializeOrdersApi($this->clientMock);
        $this->assertInstanceOf(OrdersApi::class, $service);
    }

    public function testInitializePaymentLinksApi()
    {
        $service = $this->dataHelper->initializePaymentLinksApi($this->clientMock);
        $this->assertInstanceOf(PaymentLinksApi::class, $service);
    }

    public function testLogAdyenException()
    {
        $this->store->method('getId')->willReturn(1);
        $this->adyenLogger->expects($this->once())->method('info');
        $this->dataHelper->logAdyenException(new AdyenException('error message', 123));
    }
}
