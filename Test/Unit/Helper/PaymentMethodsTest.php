<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2024 Adyen N.V. (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Test\Unit\Helper;

use Adyen\Client;
use Adyen\ConnectionException;
use Adyen\Model\Checkout\PaymentMethodsResponse;
use Adyen\Payment\Helper\ChargedCurrency;
use Adyen\Payment\Helper\Config;
use Adyen\Payment\Helper\Data as AdyenDataHelper;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Helper\PaymentMethods;
use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Model\AdyenAmountCurrency;
use Adyen\Payment\Model\Notification;
use Adyen\Payment\Model\Ui\AdyenCcConfigProvider;
use Adyen\Payment\Model\Ui\AdyenPayByLinkConfigProvider;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Adyen\Service\Checkout;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\View\Asset\File;
use Magento\Framework\View\Asset\Repository;
use Magento\Framework\View\Asset\Source;
use Magento\Framework\View\Design\ThemeInterface;
use Magento\Framework\View\DesignInterface;
use Magento\Framework\View\Design\Theme\ThemeProviderInterface;
use Magento\Payment\Helper\Data as MagentoDataHelper;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Quote\Model\Quote\Address;
use Magento\Sales\Model\Order;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\Store;
use ReflectionClass;
use Adyen\AdyenException;
use Exception;
use ReflectionMethod;

class PaymentMethodsTest extends AbstractAdyenTestCase
{
    private PaymentMethods $paymentMethodsHelper;
    private Context $contextMock;
    private CartRepositoryInterface $quoteRepositoryMock;
    private ScopeConfigInterface $configMock;
    private Data $adyenHelperMock;
    private ResolverInterface $localeResolverMock;
    private AdyenLogger $adyenLoggerMock;
    private Repository $assetRepoMock;
    private RequestInterface $requestMock;
    private Source $assetSourceMock;
    private DesignInterface $designMock;
    private ThemeProviderInterface $themeProviderMock;
    private ChargedCurrency $chargedCurrencyMock;
    private Config $configHelperMock;
    private MagentoDataHelper $dataHelperMock;
    private SerializerInterface $serializerMock;
    private AdyenDataHelper $adyenDataHelperMock;
    private PaymentTokenRepositoryInterface $paymentTokenRepository;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private Address $billingAddressMock;
    private AdyenAmountCurrency $amountCurrencyMock;
    private MethodInterface $methodMock;
    private Order $orderMock;
    private Notification $notificationMock;
    private Order\Payment $orderPaymentMock;
    private Store $storeMock;
    private Quote $quoteMock;
    private ObjectManager $objectManager;

    protected function setUp(): void
    {
        $this->contextMock = $this->createMock(Context::class);
        $this->quoteRepositoryMock = $this->createMock(CartRepositoryInterface::class);
        $this->configMock = $this->getMockBuilder(ScopeConfigInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->billingAddressMock = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->adyenHelperMock = $this->createMock(Data::class);
        $this->localeResolverMock = $this->createMock(ResolverInterface::class);
        $this->adyenLoggerMock = $this->createMock(AdyenLogger::class);
        $this->assetRepoMock = $this->createMock(Repository::class);
        $this->requestMock = $this->createMock(RequestInterface::class);
        $this->assetSourceMock = $this->createMock(Source::class);
        $this->designMock = $this->createMock(DesignInterface::class);
        $this->themeProviderMock = $this->createMock(ThemeProviderInterface::class);
        $this->chargedCurrencyMock = $this->createMock(ChargedCurrency::class);
        $this->configHelperMock = $this->createMock(Config::class);
        $this->dataHelperMock = $this->createMock(MagentoDataHelper::class);
        $this->serializerMock = $this->createMock(SerializerInterface::class);
        $this->adyenDataHelperMock = $this->createMock(AdyenDataHelper::class);
        $this->paymentTokenRepository = $this->createMock(PaymentTokenRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->amountCurrencyMock = $this->createMock(AdyenAmountCurrency::class);
        $this->methodMock = $this->createMock(MethodInterface::class);
        $this->orderMock = $this->createMock(Order::class);
        $this->notificationMock = $this->createMock(Notification::class);
        $this->orderPaymentMock = $this->getMockBuilder(Order\Payment::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->storeMock = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->quoteMock = $this->getMockBuilder(Quote::class)
            ->addMethods(['getCustomerId'])
            ->onlyMethods(['getStore','getBillingAddress','getEntityId'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->objectManager = new ObjectManager($this);

        // Instantiate the PaymentMethods helper class with the mocked dependencies
        $this->paymentMethodsHelper = new PaymentMethods(
            $this->contextMock,
            $this->quoteRepositoryMock,
            $this->configMock,
            $this->adyenHelperMock,
            $this->localeResolverMock,
            $this->adyenLoggerMock,
            $this->assetRepoMock,
            $this->requestMock,
            $this->assetSourceMock,
            $this->designMock,
            $this->themeProviderMock,
            $this->chargedCurrencyMock,
            $this->configHelperMock,
            $this->dataHelperMock,
            $this->serializerMock,
            $this->adyenDataHelperMock,
            $this->paymentTokenRepository,
            $this->searchCriteriaBuilder
        );
    }

    /**
     * @dataProvider comparePaymentMethodProvider
     */
    public function testCompareOrderAndWebhookPaymentMethods(
        $orderPaymentMethod,
        $notificationPaymentMethod,
        $assert,
        $ccType = null
    )
    {
        $paymentMethodsHelper = $this->objectManager->getObject(PaymentMethods::class, []);
        $this->methodMock->method('getConfigData')
            ->willReturnMap([
                ['group', null, PaymentMethods::ADYEN_GROUP_ALTERNATIVE_PAYMENT_METHODS],
                ['is_wallet', null, '0']
            ]);
        $this->methodMock->method('getCode')->willReturn($orderPaymentMethod);

        $this->orderPaymentMock->method('getMethodInstance')->willReturn($this->methodMock);
        $this->orderPaymentMock->method('getMethod')->willReturn($orderPaymentMethod);
        $this->orderPaymentMock->method('getCcType')->willReturn($ccType);
        $this->orderMock->method('getPayment')->willReturn($this->orderPaymentMock);
        $this->notificationMock->method('getPaymentMethod')->willReturn($notificationPaymentMethod);

        $this->assertEquals(
            $assert,
            $paymentMethodsHelper->compareOrderAndWebhookPaymentMethods($this->orderMock, $this->notificationMock)
        );
    }

    public static function comparePaymentMethodProvider(): array
    {
        return [
            [
                'orderPaymentMethod' => 'adyen_klarna',
                'notificationPaymentMethod' => 'klarna',
                'assert' => true
            ],
            [
                'orderPaymentMethod' => 'adyen_cc',
                'notificationPaymentMethod' => 'visa',
                'assert' => true,
                'ccType' => 'visa'
            ],
            [
                'orderPaymentMethod' => 'adyen_klarna',
                'notificationPaymentMethod' => 'boleto',
                'assert' => false
            ]
        ];
    }

    public function testTogglePaymentMethodsActivation()
    {
        $this->configHelperMock
            ->expects($this->once())
            ->method('getIsPaymentMethodsActive')
            ->willReturn(true);
        $this->dataHelperMock
            ->expects($this->once())
            ->method('getPaymentMethodList')
            ->willReturn(
                [
                    'adyen_cc' => [],
                    'adyen_oneclick' => [],
                    'adyen_cc_vault' => [],
                    'adyen_pos_cloud' => [],
                    'adyen_moto' => [],
                    'adyen_pay_by_link' => [],
                ]);

        $this->configHelperMock
            ->expects($this->exactly(3))
            ->method('setConfigData')
            ->withConsecutive(
                ['1', 'active', 'adyen_cc', 'default'],
                ['1', 'active', 'adyen_oneclick', 'default'],
                ['1', 'active', 'adyen_cc_vault', 'default']
            );

        $paymentMethods = $this->paymentMethodsHelper->togglePaymentMethodsActivation();
        $this->assertSame(
            ['adyen_cc', 'adyen_oneclick', 'adyen_cc_vault'],
            $paymentMethods
        );
    }

    public function testFetchPaymentMethodsWithNoPaymentMethodsInResponse()
    {
        $country = 'NL';
        $shopperLocale = 'nl_NL';
        $expectedResult = '[]';

        $storeMock = $this->createMock(Store::class);
        $storeMock->method('getId')->willReturn(1);

        $quoteMock = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->setMethods(['getStore'])
            ->getMock();

        $quoteMock->method('getStore')->willReturn($storeMock);
        $quoteMock->setCustomerId(123);

        $reflectionClass = new \ReflectionClass(get_class($this->paymentMethodsHelper));
        $quoteProperty = $reflectionClass->getProperty('quote');
        $quoteProperty->setAccessible(true);
        $quoteProperty->setValue($this->paymentMethodsHelper, $quoteMock);

        $method = $reflectionClass->getMethod('fetchPaymentMethods');
        $method->setAccessible(true);

        $result = $method->invokeArgs($this->paymentMethodsHelper, [$country, $shopperLocale]);

        $this->assertEquals($expectedResult, $result);
    }

    public function testFilterStoredPaymentMethods()
    {
        $allowMultistoreTokens = false;
        $customerId = 1;
        $responseData = [
            'storedPaymentMethods' => [
                ['id' => '123', 'name' => 'Visa'],
                ['id' => '456', 'name' => 'Mastercard']
            ]
        ];
        $expectedResult = [
            'storedPaymentMethods' => [
                ['id' => '123', 'name' => 'Visa']
            ]
        ];

        $paymentTokenMock = $this->createMock(\Magento\Vault\Api\Data\PaymentTokenInterface::class);
        $paymentTokenMock->method('getGatewayToken')->willReturn('123');

        $searchCriteriaMock = $this->createMock(\Magento\Framework\Api\SearchCriteriaInterface::class);
        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($searchCriteriaMock);
        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn('searchCriteria');
        $this->paymentTokenRepository->method('getList')->willReturn(new \Magento\Framework\DataObject(['items' => [$paymentTokenMock]]));

        $reflectionClass = new \ReflectionClass(get_class($this->paymentMethodsHelper));
        $method = $reflectionClass->getMethod('filterStoredPaymentMethods');
        $method->setAccessible(true);

        $result = $method->invokeArgs($this->paymentMethodsHelper, [$allowMultistoreTokens, $responseData, $customerId]);

        $this->assertEquals($expectedResult, $result);
    }
    //Successfully retrieve payment methods for a valid quote ID. getPaymentMethods
    public function testSuccessfullyRetrievePaymentMethodsForValidQuoteId()
    {
        $quoteId = 123; // Example valid quote ID
        $country = 'US'; // Example country
        $shopperLocale = 'en_US'; // Example shopper locale
        $storeId = 1;

        $this->storeMock->expects($this->any())
            ->method('getId')
            ->willReturn($storeId);

        // Mock the getId method of the quote to return the quoteId
        $this->quoteMock->expects($this->any())
            ->method('getStore')
            ->willReturn($this->storeMock);

        // Mock the quote repository to return the quote mock
        $this->quoteRepositoryMock->expects($this->once())
            ->method('getActive')
            ->with($quoteId)
            ->willReturn($this->quoteMock);

        // Perform the test
        $result = $this->paymentMethodsHelper->getPaymentMethods($quoteId, $country, $shopperLocale);

        $this->assertNotEmpty($result);
    }

    public function testRetrievePaymentMethodsWithInvalidQuoteId()
    {
        $invalidQuoteId = 999; // Example invalid quote ID
        $country = 'US'; // Example country
        $shopperLocale = 'en_US'; // Example shopper locale

        // Mock the quote repository to return null for an invalid quote ID
        $this->quoteRepositoryMock->expects($this->once())
            ->method('getActive')
            ->with($invalidQuoteId)
            ->willReturn(null);

        // Perform the test
        $result = $this->paymentMethodsHelper->getPaymentMethods($invalidQuoteId, $country, $shopperLocale);

        // Assert that the result is an array
        $this->assertEmpty($result);
    }

    public function testGetAdyenPaymentMethods()
    {
        // Mock the Data helper class
        $dataHelperMock = $this->getMockBuilder(MagentoDataHelper::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Define the list of payment methods to be returned by the mock
        $paymentMethods = [
            'adyen_cc' => [],
            'adyen_oneclick' => [],
            'paypal' => [], // Non-Adyen payment method
            'adyen_sepa' => [],
        ];

        // Set up the expected filtered Adyen payment methods
        $expectedAdyenPaymentMethods = [
            'adyen_cc',
            'adyen_oneclick',
            'adyen_sepa',
        ];

        // Set up the mock to return the predefined list of payment methods
        $dataHelperMock->expects($this->once())
            ->method('getPaymentMethodList')
            ->willReturn($paymentMethods);

        $paymentMethods = $this->objectManager->getObject(
            PaymentMethods::class,
            [
                'dataHelper' => $dataHelperMock,
            ]
        );

        // Call the getAdyenPaymentMethods() method
        $actualAdyenPaymentMethods = $paymentMethods->getAdyenPaymentMethods();

        // Assert that the returned array contains only the expected Adyen payment methods
        $this->assertEquals($expectedAdyenPaymentMethods, $actualAdyenPaymentMethods);

    }

    public function testIsAdyenPayment()
    {
        // Define the list of Adyen payment methods
        $adyenPaymentMethods = [
            'adyen_cc' => [],
            'adyen_oneclick' => [],
            'paypal' => [], // Non-Adyen payment method
            'adyen_sepa' => [],
        ];

        // Set up the mock to return the predefined list of Adyen payment methods
        $this->dataHelperMock->expects($this->exactly(2))
            ->method('getPaymentMethodList')
            ->willReturn($adyenPaymentMethods);

        // Test for an Adyen payment method code
        $this->assertTrue($this->paymentMethodsHelper->isAdyenPayment('adyen_cc'));

        // Test for a non-Adyen payment method code
        $this->assertFalse($this->paymentMethodsHelper->isAdyenPayment('paypal'));
    }

    /**
     * @throws ReflectionExceptionAlias
     */
    private function getPrivateMethod(string $className, string $methodName): ReflectionMethod
    {
        $reflectionClass = new ReflectionClass($className);
        $method = $reflectionClass->getMethod($methodName);
        $method->setAccessible(true);
        return $method;
    }

    public function testFetchPaymentMethodsWithEmptyResponseFromAdyenApi()
    {
        $quoteId = 1;
        $storeId = 1;
        $amountValue = 100;
        $adyenClientMock = $this->createMock(Client::class);
        $checkoutServiceMock = $this->createMock(Checkout\PaymentsApi::class);
        // Setup test scenario
        $this->storeMock->expects($this->any())
            ->method('getId')
            ->willReturn($quoteId);

        // Mock the getId method of the quote to return the quoteId
        $this->quoteMock->expects($this->any())
            ->method('getStore')
            ->willReturn($this->storeMock);
        $this->configHelperMock->expects($this->once())
            ->method('getAdyenAbstractConfigData')
            ->with('merchant_account', $storeId) // Ensure it's called with the expected parameters
            ->willReturn('mocked_merchant_account'); // Define the return value for the mocked method
        $this->adyenHelperMock->method('initializeAdyenClient')->willReturn($adyenClientMock);
        $this->adyenHelperMock->method('initializePaymentsApi')->willReturn($checkoutServiceMock);
        $this->amountCurrencyMock->method('getCurrencyCode')->willReturn('EUR');
        $this->amountCurrencyMock->method('getAmount')->willReturn($amountValue);
        $this->chargedCurrencyMock->method('getQuoteAmountCurrency')->willReturn($this->amountCurrencyMock);
        $this->billingAddressMock->expects($this->once())
            ->method('getCountryId')
            ->willReturn('NL');
        $this->quoteMock
            ->method('getBillingAddress')
            ->willReturn($this->billingAddressMock);
        // Simulate successful API call
        $checkoutServiceMock->expects($this->once())
            ->method('paymentMethods')
            ->willThrowException(new AdyenException("The Payment methods response is empty check your Adyen configuration in Magento."));

        $fetchPaymentMethodsMethod = $this->getPrivateMethod(
            PaymentMethods::class,
            'fetchPaymentMethods'
        );

        $paymentMethods = $this->objectManager->getObject(
            PaymentMethods::class,
            [
                'quote' => $this->quoteMock,
                'configHelper' => $this->configHelperMock,
                'chargedCurrency' => $this->chargedCurrencyMock,
                'adyenHelper' => $this->adyenHelperMock
            ]
        );

        // Execute method of the tested class
        $result = $fetchPaymentMethodsMethod->invoke($paymentMethods, null, null);

        // Assert conditions
        $this->assertEquals(json_encode([]), $result);
    }

    public function testSuccessfulRetrievalOfPaymentMethods()
    {
        $expectedResult = [
            'paymentMethods' => [
                '0' => [
                'type' => 'method1'
                ],
                '1' => [
                    'type' => 'method1'
                ]
            ]
        ];

        $adyenClientMock = $this->createMock(Client::class);
        $checkoutServiceMock = $this->createMock(Checkout\PaymentsApi::class);
        $quoteId = 1;
        $storeId = 1;
        $amountValue = '100';

        $requestParams = [
            "channel" => "Web",
            "merchantAccount" => 'MagentoMerchantTest',
            "shopperReference" => 'SomeShopperRef',
            "countryCode" => 'NL',
            "shopperLocale" => 'nl-NL',
            "amount" => [
                "currency" => 'EUR',
                "value" => $amountValue
            ]
        ];

        $paymentMethodsExtraDetails['type']['configuration'] = [
            'amount' => [
                'value' => $amountValue,
                'currency' => 'EUR'
            ],
            'currency' => 'EUR',
        ];

        // Create a partial mock for your class
        $paymentMethodsMock = $this->getMockBuilder(PaymentMethods::class)
            ->onlyMethods(['getPaymentMethodsRequest', 'addExtraConfigurationToPaymentMethods']) // Specify the method(s) to mock
            ->disableOriginalConstructor()
            ->getMock();

        // Set up the expectation for the mocked method
        $paymentMethodsMock->expects($this->any())
            ->method('getPaymentMethodsRequest')
            ->willReturn($requestParams);

        $paymentMethodsMock->expects($this->any())
            ->method('addExtraConfigurationToPaymentMethods')
            ->willReturn($paymentMethodsExtraDetails);

        $this->adyenHelperMock->method('initializeAdyenClient')->willReturn($adyenClientMock);
        $this->adyenHelperMock->method('initializePaymentsApi')->willReturn($checkoutServiceMock);

        $responseMock = $this->createMock(PaymentMethodsResponse::class);
        $responseMock->method('toArray')->willReturn($expectedResult);

        // Simulate successful API call
        $checkoutServiceMock->expects($this->once())
            ->method('paymentMethods')
            ->willReturn($responseMock);

        $this->storeMock->expects($this->any())
            ->method('getId')
            ->willReturn($quoteId);

        // Mock the getId method of the quote to return the quoteId
        $this->quoteMock->expects($this->any())
            ->method('getStore')
            ->willReturn($this->storeMock);

        $this->configHelperMock->expects($this->once())
            ->method('getAdyenAbstractConfigData')
            ->with('merchant_account', $storeId) // Ensure it's called with the expected parameters
            ->willReturn('mocked_merchant_account'); // Define the return value for the mocked method

        $this->amountCurrencyMock->method('getCurrencyCode')->willReturn('EUR');
        $this->amountCurrencyMock->method('getAmount')->willReturn($amountValue);
        $this->chargedCurrencyMock->method('getQuoteAmountCurrency')->willReturn($this->amountCurrencyMock);

        $this->adyenHelperMock->expects($this->once())
            ->method('logResponse');

        $paymentMethods = $this->objectManager->getObject(
            PaymentMethods::class,
            [
                'quote' => $this->quoteMock,
                'configHelper' => $this->configHelperMock,
                'chargedCurrency' => $this->chargedCurrencyMock,
                'adyenHelper' => $this->adyenHelperMock,
                'paymentMethods' => $paymentMethodsMock,
            ]
        );
        $fetchPaymentMethodsMethod = $this->getPrivateMethod(
            PaymentMethods::class,
            'fetchPaymentMethods'
        );
        $result = $fetchPaymentMethodsMethod->invoke($paymentMethods, 'NL', 'nl_NL');

        $this->assertJson($result);
    }

    public function testGetCurrentCountryCodeWithBillingAddressSet()
    {
        $this->billingAddressMock->expects($this->once())
            ->method('getCountryId')
            ->willReturn('NL'); // Simulate the billing address country is set to US
        $this->quoteMock->expects($this->once())
            ->method('getBillingAddress')
            ->willReturn($this->billingAddressMock);

        $paymentMethods = $this->objectManager->getObject(
            PaymentMethods::class,
            [
                'quote' => $this->quoteMock
            ]
        );

        $getCurrentCountryCodeMethod = $this->getPrivateMethod(
            PaymentMethods::class,
            'getCurrentCountryCode'
        );

        $result = $getCurrentCountryCodeMethod->invoke($paymentMethods, $this->storeMock);

        // Assert that the expected country code is returned
        $this->assertEquals('NL', $result);
    }

    public function testGetCurrentCountryCodeWithNoBillingAddressSet()
    {
        $storeMock = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->getMock();
        $mockNoCountry = null;

        // Set up expectations for the mocked objects
        $billingAddressMock = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->getMock();
        $billingAddressMock->expects($this->once())
            ->method('getCountryId')
            ->willReturn($mockNoCountry);
        $this->quoteMock->expects($this->once())
            ->method('getBillingAddress')
            ->willReturn($billingAddressMock);

        $this->configMock->expects($this->any())
            ->method('getValue')
            ->willReturn('GB');

        $paymentMethods = $this->objectManager->getObject(
            PaymentMethods::class,
            [
                'quote' => $this->quoteMock,
                'config' => $this->configMock,
            ]
        );

        $getCurrentCountryCodeMethod = $this->getPrivateMethod(
            PaymentMethods::class,
            'getCurrentCountryCode'
        );

        $result = $getCurrentCountryCodeMethod->invoke($paymentMethods, $storeMock);

        // Assert that the expected country code is returned
        $this->assertEquals('GB', $result);
    }

    public function testGetCurrentPaymentAmountWithValidPositiveNumber()
    {
        // Create a mock for AdyenAmountCurrency
        $this->amountCurrencyMock->method('getAmount')->willReturn(100); // Valid positive number
        $this->chargedCurrencyMock->method('getQuoteAmountCurrency')->willReturn($this->amountCurrencyMock);

        // Create an instance of PaymentMethods with mocked dependencies
        $paymentMethods = $this->objectManager->getObject(
            PaymentMethods::class,
            [
                'quote' => $this->quoteMock,
                'chargedCurrency' => $this->chargedCurrencyMock
            ]
        );

        $getCurrentPaymentAmountMethod = $this->getPrivateMethod(
            PaymentMethods::class,
            'getCurrentPaymentAmount'
        );
        $result = $getCurrentPaymentAmountMethod->invoke($paymentMethods);

        // Assert that the expected positive float is returned
        $this->assertEquals(100.0, $result);
    }

    public function testGetCurrentPaymentAmountWithNonNumericValue()
    {
        $this->amountCurrencyMock->method('getAmount')->willReturn('invalid_value');
        $this->chargedCurrencyMock->method('getQuoteAmountCurrency')->willReturn($this->amountCurrencyMock);

        $this->quoteMock->expects($this->once())
            ->method('getEntityId')
            ->willReturn(1);

        // Create an instance of PaymentMethods with mocked dependencies
        $paymentMethods = $this->objectManager->getObject(
            PaymentMethods::class,
            [
                'quote' => $this->quoteMock, // Inject the mocked quote
                'chargedCurrency' => $this->chargedCurrencyMock // Inject the mocked chargedCurrency
            ]
        );

        $getCurrentPaymentAmountMethod = $this->getPrivateMethod(
            PaymentMethods::class,
            'getCurrentPaymentAmount'
        );

        // Assert that an Exception is thrown when the total amount is not a valid number
        try {
            $getCurrentPaymentAmountMethod->invoke($paymentMethods);
        } catch (Exception $e) {
            $this->assertInstanceOf(Exception::class, $e);
            $this->assertEquals("Cannot retrieve a valid grand total from quote ID: `1`. Expected a numeric value.", $e->getMessage());
            return;
        }

        // If no exception is thrown, fail the test
        $this->fail('An expected exception has not been raised.');
    }

    public function testGetCurrentPaymentAmountWithNegativeNumber()
    {
        $this->amountCurrencyMock->method('getAmount')->willReturn(-100); // Negative number
        $this->chargedCurrencyMock->method('getQuoteAmountCurrency')->willReturn($this->amountCurrencyMock);

        // Create an instance of PaymentMethods with mocked dependencies
        $paymentMethods = $this->objectManager->getObject(
            PaymentMethods::class,
            [
                'quote' => $this->quoteMock, // Inject the mocked quote
                'chargedCurrency' => $this->chargedCurrencyMock // Inject the mocked chargedCurrency
            ]
        );
        $getCurrentPaymentAmountMethod = $this->getPrivateMethod(
            PaymentMethods::class,
            'getCurrentPaymentAmount'
        );

        // Assert that an Exception is thrown when the total amount is negative
        $this->expectException(Exception::class);
        $getCurrentPaymentAmountMethod->invoke($paymentMethods);
    }

    public function testGetCurrentShopperReferenceWithCustomerId()
    {
        $this->quoteMock->expects($this->any())
            ->method('getCustomerId')
            ->willReturn(123);

        // Create an instance of PaymentMethods with the mocked Quote
        $paymentMethods = $this->objectManager->getObject(
            PaymentMethods::class,
            [
                'quote' => $this->quoteMock
            ]
        );

        $getCurrentShopperReferenceMethod = $this->getPrivateMethod(
            PaymentMethods::class,
            'getCurrentShopperReference'
        );

        // Call the method and assert that it returns the expected shopper reference
        $result = $getCurrentShopperReferenceMethod->invoke($paymentMethods);
        $this->assertEquals('123', $result); // Expecting the customerId to be cast to string
    }

    public function testGetCurrentShopperReferenceWithoutCustomerId()
    {
        $this->quoteMock->method('getCustomerId')->willReturn(null);

        // Create an instance of PaymentMethods with the mocked Quote
        $paymentMethods = $this->objectManager->getObject(
            PaymentMethods::class,
            [
                'quote' => $this->quoteMock
            ]
        );
        $getCurrentShopperReferenceMethod = $this->getPrivateMethod(
            PaymentMethods::class,
            'getCurrentShopperReference'
        );

        // Call the method and assert that it returns null when customerId is not set
        $result = $getCurrentShopperReferenceMethod->invoke($paymentMethods);
        $this->assertNull($result);
    }

    public function testIsBankTransfer()
    {
        // Test with bank transfer payment method
        $paymentMethod = 'bankTransferNL';
        $this->assertTrue($this->paymentMethodsHelper->isBankTransfer($paymentMethod));

        // Test with non-bank transfer payment method
        $paymentMethod = 'adyen_cc';
        $this->assertFalse($this->paymentMethodsHelper->isBankTransfer($paymentMethod));
    }

    public function testIsWalletPaymentMethodTrue()
    {
        $this->methodMock->method('getConfigData')
            ->with('is_wallet')
            ->willReturn(true);
        $this->assertTrue($this->paymentMethodsHelper->isWalletPaymentMethod($this->methodMock));

    }

    public function testIsWalletPaymentMethodFalse()
    {
        // Non-wallet payment method
        $this->methodMock->method('getConfigData')
            ->with('is_wallet')
            ->willReturn(false);
        $this->assertFalse($this->paymentMethodsHelper->isWalletPaymentMethod($this->methodMock));
    }

    public function testGetBoletoStatus()
    {
        // Test with valid boleto data
        $this->notificationMock->method('getAdditionalData')
            ->willReturn(json_encode(['boletobancario' => ['originalAmount' => 'BRL 100', 'paidAmount' => 'BRL 90']]));
        $status = $this->paymentMethodsHelper->getBoletoStatus($this->orderMock, $this->notificationMock, 'pending');
        $this->assertEquals('pending', $status);

        // Test with overpaid boleto
        $this->notificationMock->method('getAdditionalData')
            ->willReturn(json_encode(['boletobancario' => ['originalAmount' => 'BRL 100', 'paidAmount' => 'BRL 110']]));
        $status = $this->paymentMethodsHelper->getBoletoStatus($this->orderMock, $this->notificationMock, 'overpaid');
        $this->assertEquals('overpaid', $status);

        // Test with underpaid boleto
        $this->notificationMock->method('getAdditionalData')
            ->willReturn(json_encode(['boletobancario' => ['originalAmount' => 'BRL 100', 'paidAmount' => 'BRL 80']]));
        $status = $this->paymentMethodsHelper->getBoletoStatus($this->orderMock, $this->notificationMock, 'underpaid');
        $this->assertEquals('underpaid', $status);
    }

    public function testIsAlternativePaymentMethod()
    {
        // Test with alternative payment method
        $this->methodMock->method('getConfigData')
            ->with('group')
            ->willReturn(PaymentMethods::ADYEN_GROUP_ALTERNATIVE_PAYMENT_METHODS);
        $this->assertTrue($this->paymentMethodsHelper->isAlternativePaymentMethod($this->methodMock));


    }

    public function testIsNotAlternativePaymentMethod()
    {
        // Test with non-alternative payment method
        $this->methodMock->method('getConfigData')
            ->with('group')
            ->willReturn('some_other_group');
        $this->assertFalse($this->paymentMethodsHelper->isAlternativePaymentMethod($this->methodMock));
    }

    public function testGetAlternativePaymentMethodTxVariant()
    {
        // Test with alternative payment method
        $this->methodMock->method('getConfigData')
            ->with('group')
            ->willReturn(PaymentMethods::ADYEN_GROUP_ALTERNATIVE_PAYMENT_METHODS);
        $this->methodMock->method('getCode')
            ->willReturn('adyen_some_variant');
        $this->assertEquals('some_variant', $this->paymentMethodsHelper->getAlternativePaymentMethodTxVariant($this->methodMock));
    }

    public function testGetAlternativePaymentMethodTxVariantException()
    {
        // Test with non-alternative payment method
        $this->methodMock->method('getConfigData')
            ->with('group')
            ->willReturn('some_other_group');
        $this->expectException(AdyenException::class);
        $this->paymentMethodsHelper->getAlternativePaymentMethodTxVariant($this->methodMock);
    }

    public function testPaymentMethodSupportsRecurring()
    {
        // Test with supports recurring
        $this->methodMock->method('getConfigData')
            ->with('supports_recurring')
            ->willReturn(true);
        $this->assertTrue($this->paymentMethodsHelper->paymentMethodSupportsRecurring($this->methodMock));
    }

    public function testPaymentMethodNotSupportsRecurring()
    {
        // Test without supports recurring
        $this->methodMock->method('getConfigData')
            ->with('supports_recurring')
            ->willReturn(false);
        $this->assertFalse($this->paymentMethodsHelper->paymentMethodSupportsRecurring($this->methodMock));
    }

    public function testCheckPaymentMethod()
    {
        $this->orderPaymentMock->method('getMethod')
            ->willReturn('some_method');
        $this->assertTrue($this->paymentMethodsHelper->checkPaymentMethod($this->orderPaymentMock, 'some_method'));
    }

    public function testCheckPaymentMethodFalse()
    {
        $this->orderPaymentMock->method('getMethod')
            ->willReturn('some_other_method');
        $this->assertFalse($this->paymentMethodsHelper->checkPaymentMethod($this->orderPaymentMock, 'some_method'));
    }

    public function testGetCcAvailableTypes()
    {
        // Mock adyenHelper
        $adyenCcTypes = [
            'visa' => ['name' => 'Visa'],
            'mastercard' => ['name' => 'MasterCard'],
            'amex' => ['name' => 'American Express']
        ];
        $this->adyenHelperMock->expects($this->once())
            ->method('getAdyenCcTypes')
            ->willReturn($adyenCcTypes);

        $this->configHelperMock->expects($this->once())
            ->method('getAdyenCcConfigData')
            ->with('cctypes')
            ->willReturn('visa,mastercard');

        // Test getCcAvailableTypes
        $expectedResult = [
            'visa' => 'Visa',
            'mastercard' => 'MasterCard'
        ];
        $this->assertEquals($expectedResult, $this->paymentMethodsHelper->getCcAvailableTypes());
    }

    public function testGetCcAvailableTypesByAlt()
    {
        $adyenCcTypes = [
            'visa' => ['name' => 'Visa', 'code_alt' => 'VISA'],
            'mastercard' => ['name' => 'MasterCard', 'code_alt' => 'MC'],
            'amex' => ['name' => 'American Express', 'code_alt' => 'AMEX']
        ];
        $this->adyenHelperMock->expects($this->once())
            ->method('getAdyenCcTypes')
            ->willReturn($adyenCcTypes);

        $this->configHelperMock->expects($this->once())
            ->method('getAdyenCcConfigData')
            ->with('cctypes')
            ->willReturn('visa,mastercard');

        // Test getCcAvailableTypesByAlt
        $expectedResult = [
            'VISA' => 'visa',
            'MC' => 'mastercard'
        ];
        $this->assertEquals($expectedResult, $this->paymentMethodsHelper->getCcAvailableTypesByAlt());
    }

    /**
     * @dataProvider autoCaptureDataProvider
     */
    public function testIsAutoCapture(
        $manualCaptureSupported,
        $captureMode,
        $sepaFlow,
        $paymentCode,
        $autoCaptureOpenInvoice,
        $manualCapturePayPal,
        $expectedResult
    ) {
        // Reset Config mock to prevent interventions with other expects() assertions.
        $this->configHelperMock = $this->createMock(Config::class);

        $this->orderMock->method('getStoreId')->willReturn(1);

        $this->configHelperMock->expects($this->any())
            ->method('getConfigData')
            ->with('capture_mode', 'adyen_abstract', '1')
            ->willReturn($captureMode);

        $this->configHelperMock->expects($this->any())
            ->method('getConfigData')
            ->with('sepa-flow', 'adyen_abstract', '1')
            ->willReturn($sepaFlow);

        $this->configHelperMock->expects($this->any())
            ->method('getAutoCaptureOpenInvoice')
            ->with( '1')
            ->willReturn($autoCaptureOpenInvoice);

        $this->configHelperMock->expects($this->any())
            ->method('getConfigData')
            ->with( 'paypal_capture_mode','adyen_abstract','1')
            ->willReturn($manualCapturePayPal);

        // Configure the mock to return the method name
        $this->orderPaymentMock->method('getMethod')
            ->willReturn($paymentCode);

        // Configure the order mock to return the payment mock
        $this->orderMock->expects($this->any())
            ->method('getPayment')
            ->willReturn($this->orderPaymentMock);

        $this->configHelperMock->expects($this->any())
            ->method('getAutoCaptureOpenInvoice')
            ->willReturn($autoCaptureOpenInvoice);

        $result = $this->paymentMethodsHelper->isAutoCapture($this->orderMock, $paymentCode);

        $this->assertEquals($expectedResult, $result);
    }
    public function autoCaptureDataProvider(): array
    {
        return [
            // Manual capture supported, capture mode manual, sepa flow not authcap
            [true, 'manual', 'notauthcap', 'paypal', true, null, true],
            // Manual capture supported, capture mode auto
            [true, 'auto', '', 'sepadirectdebit', true, null, true],
            // Manual capture not supported
            [false, '', '', 'sepadirectdebit', true, null, true]
        ];
    }

    public function testBuildPaymentMethodIconWithSvg()
    {
        // Mock data
        $paymentMethodCode = 'test_method';
        $params = [
            'area' => 'frontend',
            '_secure' => '',
            'theme' => 'Magento/blank'
        ];
        $expectedSVGUrl = 'mocked_svg_url';
        $expectedPNGUrl = 'mocked_png_url';
        $svgAssetMock = $this->createMock(File::class);
        $svgAssetMock->method('getUrl')->willReturn($expectedSVGUrl);
        $pngAssetMock = $this->createMock(File::class);
        $pngAssetMock->method('getUrl')->willReturn($expectedPNGUrl);

        $this->assetRepoMock->method('createAsset')
            ->withConsecutive(
                ["Adyen_Payment::images/logos/{$paymentMethodCode}.svg", $params],
                ["Adyen_Payment::images/logos/{$paymentMethodCode}.png", $params]
            )
            ->willReturnOnConsecutiveCalls($svgAssetMock, $pngAssetMock);

        // Set up asset source mock for SVG asset
        $this->assetSourceMock->method('findSource')
            ->with($svgAssetMock)
            ->willReturn(true);

        // Set up asset source mock for PNG asset
        $this->assetSourceMock->method('findSource')
            ->with($pngAssetMock)
            ->willReturn(false);
        $result = $this->paymentMethodsHelper->buildPaymentMethodIcon($paymentMethodCode, $params);
        $this->assertEquals(['url' => $expectedSVGUrl, 'width' => 77, 'height' => 50], $result);
    }

    public function testBuildPaymentMethodIconWithPngExistsButSvgDoesNot()
    {
        $paymentMethodCode = 'test_method';
        $params = [
            'area' => 'frontend',
            '_secure' => '',
            'theme' => 'Magento/blank'
        ];
        $expectedUrl = "https://checkoutshopper-live.adyen.com/checkoutshopper/images/logos/{$paymentMethodCode}.svg";
        $svgAssetMock = $this->createMock(File::class);
        $pngAssetMock = $this->createMock(File::class);
        $this->assetRepoMock->method('createAsset')
            ->willReturnMap([
                ["Adyen_Payment::images/logos/{$paymentMethodCode}.svg", $params, $svgAssetMock],
                ["Adyen_Payment::images/logos/{$paymentMethodCode}.png", $params, $pngAssetMock]
            ]);
        $this->assetSourceMock->method('findSource')
            ->willReturnMap([
                [$svgAssetMock, false],
                [$pngAssetMock, true]
            ]);
        $pngAssetMock->expects($this->once())->method('getUrl')->willReturn($expectedUrl);
        $result = $this->paymentMethodsHelper->buildPaymentMethodIcon($paymentMethodCode, $params);
        $this->assertEquals(['url' => $expectedUrl, 'width' => 77, 'height' => 50], $result);
    }

    public function testGetPaymentMethodsRequest()
    {
        $merchantAccount = 'TestMerchant';
        $shopperLocale = 'en_US';
        $country = 'NL';
        $amountValue = 100;
        $currencyCode = 'EUR';
        $this->amountCurrencyMock->method('getCurrencyCode')->willReturn('EUR');
        $this->amountCurrencyMock->method('getAmount')->willReturn($amountValue);
        $this->chargedCurrencyMock->method('getQuoteAmountCurrency')->willReturn($this->amountCurrencyMock);
        $this->adyenHelperMock->method('getCurrentLocaleCode')->willReturn($shopperLocale);
        $this->adyenDataHelperMock->method('padShopperReference')->willReturn('123456');
        $this->amountCurrencyMock->method('getCurrencyCode')->willReturn('EUR');
        $expectedResult = [
            "channel" => "Web",
            "merchantAccount" => $merchantAccount,
            "countryCode" => $country,
            "shopperLocale" => $shopperLocale,
            "amount" => ["currency" => $currencyCode]
        ];

        $paymentMethods = $this->objectManager->getObject(
            PaymentMethods::class,
            [
                'quote' => $this->quoteMock,
                'configHelper' => $this->configHelperMock,
                'chargedCurrency' => $this->chargedCurrencyMock,
                'adyenHelper' => $this->adyenHelperMock,
            ]
        );


        $getPaymentMethodsRequest = $this->getPrivateMethod(
            PaymentMethods::class,
            'getPaymentMethodsRequest'
        );
        $result = $getPaymentMethodsRequest->invoke($paymentMethods, $merchantAccount, $this->storeMock, $this->quoteMock, $shopperLocale, $country);


        $this->assertEquals($expectedResult, $result);
    }

    public function testConnectionExceptionHandling(): void
    {
        $requestParams = [
            'area' => 'frontend',
            '_secure' => '',
            'theme' => 'Magento/blank'
        ];
        //$storeMock = $this->createMock(Store::class);
        $storeId = 123; // Provide your store ID
        $this->storeMock->method('getId')->willReturn($storeId);

        $clientMock = $this->createMock(Client::class);
        $checkoutServiceMock = $this->createMock(Checkout\PaymentsApi::class);

        $this->adyenHelperMock->expects($this->once())
            ->method('initializeAdyenClient')
            ->with($storeId)
            ->willReturn($clientMock);

        $this->adyenHelperMock
            ->method('initializePaymentsApi')
            ->with($clientMock)
            ->willReturn($checkoutServiceMock);


        $this->adyenHelperMock->expects($this->once())
            ->method('logRequest')
            ->with($requestParams, Client::API_CHECKOUT_VERSION, '/paymentMethods');

        $this->adyenHelperMock->expects($this->never())->method('logResponse');

        $connectionException = new ConnectionException("Connection failed");
        $checkoutServiceMock->expects($this->once())
            ->method('paymentMethods')
            ->willThrowException($connectionException);

        $this->adyenLoggerMock->expects($this->once())
            ->method('error')
            ->with("Connection to the endpoint failed. Check the Adyen Live endpoint prefix configuration.");

        $getPaymentMethodsResponse = $this->getPrivateMethod(
            PaymentMethods::class,
            'getPaymentMethodsResponse'
        );

        $paymentMethods = $this->objectManager->getObject(
            PaymentMethods::class,
            [
                'quote' => $this->quoteMock,
                'configHelper' => $this->configHelperMock,
                'chargedCurrency' => $this->chargedCurrencyMock,
                'adyenHelper' => $this->adyenHelperMock,
                'adyenLogger' => $this->adyenLoggerMock
            ]
        );
        $result = $getPaymentMethodsResponse->invoke($paymentMethods, $requestParams, $this->storeMock);

        $this->assertEquals([], $result);
    }

    public function testManualCaptureAllowed(): void
    {
        $storeId = 123; // Provide your store ID
        $this->orderMock->method('getStoreId')->willReturn($storeId);
        $this->orderPaymentMock->method('getMethod')->willReturn('sepadirectdebit');
        $this->orderMock->method('getPayment')->willReturn($this->orderPaymentMock);

        $notificationPaymentMethod = 'sepadirectdebit'; // Provide your notification payment method

        $captureMode = 'auto'; // Assuming auto capture mode is set
        $sepaFlow = 'authcap'; // Assuming authcap flow for SEPA
        $manualCapturePayPal = 'manual'; // Assuming manual capture for PayPal

        $this->configHelperMock->expects($this->exactly(3))
            ->method('getConfigData')// Expecting 5 calls to getConfigData method
            ->withConsecutive(
                ['capture_mode', 'adyen_abstract', $storeId],
                ['sepa_flow', 'adyen_abstract', $storeId],
                ['paypal_capture_mode', 'adyen_abstract', $storeId],
                ['capture_mode_pos', 'adyen_abstract', $storeId]
            )
            ->willReturnOnConsecutiveCalls($captureMode, $sepaFlow, '', 'auto', $manualCapturePayPal);

        // Mock your other dependencies as needed for your test scenario

        $paymentMethods = $this->objectManager->getObject(
            PaymentMethods::class,
            [
                'quote' => $this->quoteMock,
                'configHelper' => $this->configHelperMock,
                'chargedCurrency' => $this->chargedCurrencyMock,
                'adyenHelper' => $this->adyenHelperMock,
                'adyenLogger' => $this->adyenLoggerMock
            ]
        );

        // Now, you can assert the return value of the method
        $result = $paymentMethods->isAutoCapture($this->orderMock, $notificationPaymentMethod);
        $this->assertFalse($result); // Assuming manual capture is allowed, so it should return false
    }

    public function testShowLogosPaymentMethods()
    {
        $themeId = 123; // Assuming a theme ID
        $this->adyenHelperMock->expects($this->any())
            ->method('showLogos')
            ->willReturn(true); // Mock the method to return true for this test
        $paymentMethods = [
            ['type' => 'visa', 'brand' => 'visa']
        ];
        $paymentMethodsExtraDetails = [
            'visa' => []
        ];
        $paymentMethodCode = 'visa';
        $params = [
            'area' => 'frontend',
            '_secure' => '',
            'theme' => 'Magento/blank'
        ];

        // Set up the mock for design->getConfigurationDesignTheme
        $this->designMock->expects($this->any())
            ->method('getConfigurationDesignTheme')
            ->willReturn($themeId);

        // Set up the mock for themeProvider->getThemeById
        $themeMock = $this->createMock(ThemeInterface::class);

        // Set up the getCode method of the theme object
        $themeMock->expects($this->any())
            ->method('getCode')
            ->willReturn('Magento/blank');

        $this->themeProviderMock->expects($this->any())
            ->method('getThemeById')
            ->with($themeId)
            ->willReturn($themeMock);

        $expectedSVGUrl = 'mocked_svg_url';
        $expectedPNGUrl = 'mocked_png_url';
        $svgAssetMock = $this->createMock(File::class);
        $svgAssetMock->method('getUrl')->willReturn($expectedSVGUrl);
        $pngAssetMock = $this->createMock(File::class);
        $pngAssetMock->method('getUrl')->willReturn($expectedPNGUrl);

        $this->assetRepoMock->method('createAsset')
            ->withConsecutive(
                ["Adyen_Payment::images/logos/{$paymentMethodCode}.svg", $params],
                ["Adyen_Payment::images/logos/{$paymentMethodCode}.png", $params]
            )
            ->willReturnOnConsecutiveCalls($svgAssetMock, $pngAssetMock);

        // Set up asset source mock for SVG asset
        $this->assetSourceMock->method('findSource')
            ->with($svgAssetMock)
            ->willReturn(true);

        // Set up asset source mock for PNG asset
        $this->assetSourceMock->method('findSource')
            ->with($pngAssetMock)
            ->willReturn(false);

        $paymentMethodsHelper = $this->objectManager->getObject(
            PaymentMethods::class,
            [
                'adyenHelper' => $this->adyenHelperMock,
                'design' => $this->designMock,
                'themeProvider' => $this->themeProviderMock,
                'assetRepo' => $this->assetRepoMock,
                'assetSource' => $this->assetSourceMock
            ]
        );

        $method = $this->getPrivateMethod(
            PaymentMethods::class,
            'showLogosPaymentMethods'
        );

        // Call the protected method
        $result = $method->invokeArgs($paymentMethodsHelper, [$paymentMethods, $paymentMethodsExtraDetails]);

        // Assert that the returned array has the expected structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('visa', $result);
        $this->assertArrayHasKey('icon', $result['visa']);
        $this->assertArrayHasKey('isOpenInvoice', $result['visa']);
    }

    public function testRemovePaymentMethodsActivation()
    {
        $this->dataHelperMock->method('getPaymentMethodList')->willReturn([
            AdyenCcConfigProvider::CODE => 'Card',
            AdyenPayByLinkConfigProvider::CODE => 'Pay by Link'
        ]);

        $this->configHelperMock->expects($this->atLeastOnce())->method('removeConfigData');

        $this->paymentMethodsHelper->removePaymentMethodsActivation('default', 0);
    }
}
