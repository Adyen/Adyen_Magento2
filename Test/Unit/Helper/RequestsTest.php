<?php declare(strict_types=1);

namespace Adyen\Payment\Test\Unit\Helper;

use Adyen\Payment\Helper\Address;
use Adyen\Payment\Helper\Config;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Helper\Requests;
use Adyen\Payment\Helper\StateData;
use Adyen\Payment\Helper\Vault;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Payment\Model\MethodInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Framework\App\Request\Http;

class RequestsTest extends AbstractAdyenTestCase
{
    private Requests $sut;
    private Payment $paymentMock;
    private Data $adyenHelperMock;
    private \Magento\Quote\Model\Quote\Address $billingAddressMock;
    private Order $order;
    private MethodInterface $methodInstance;
    private Payment $payment;
    private Config $adyenConfigMock;
    private Address $addressHelperMock;
    private Http $requestInterfaceMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->billingAddressMock = $this->getMockBuilder(\Magento\Quote\Model\Quote\Address::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->order = $this->createMock(Order::class);
        $this->methodInstance = $this->createMock(MethodInterface::class);
        $this->payment = $this->createMock(Payment::class);
        $this->payment->method('getMethodInstance')->willReturn($this->methodInstance);
        $this->adyenConfigMock = $this->createMock(Config::class);

        // Mock dependencies
        $this->adyenHelperMock = $this->createMock(Data::class);
        $this->addressHelperMock = $this->createMock(Address::class);
        $configHelperMock = $this->createMock(Config::class);
        $stateDataHelperMock = $this->createMock(StateData::class);
        $vaultHelperMock = $this->createMock(Vault::class);
        $this->requestInterfaceMock = $this->createGeneratedMock(Http::class, [
                     'getServer'
                    ]);

        // Initialize the subject under test
        $this->sut = new Requests(
            $this->adyenHelperMock,
            $configHelperMock,
            $this->addressHelperMock,
            $stateDataHelperMock,
            $vaultHelperMock,
            $this->requestInterfaceMock
        );
    }

    public function testBuildCardRecurringGuestNoStorePaymentMethod()
    {
        $this->setMockObjects([], false, '');
        $this->assertEmpty($this->sut->buildCardRecurringData(1, $this->paymentMock));
    }

    public function testBuildCardRecurringStorePaymentMethodTrueVault(): void
    {
        $this->setMockObjects(['storePaymentMethod' => true], true, Vault::SUBSCRIPTION);
        $request = $this->sut->buildCardRecurringData(1, $this->paymentMock);

        $this->assertTrue($request['storePaymentMethod']);
        $this->assertEquals(Vault::SUBSCRIPTION, $request['recurringProcessingModel']);
    }

    public function testBuildCardRecurringStorePaymentMethodTrueAdyenCardOnFile(): void
    {
        $this->setMockObjects(['storePaymentMethod' => true], true, Vault::CARD_ON_FILE);
        $request = $this->sut->buildCardRecurringData(1, $this->paymentMock);

        $this->assertTrue($request['storePaymentMethod']);
        $this->assertEquals(Vault::CARD_ON_FILE, $request['recurringProcessingModel']);
    }

    public function testBuildCardRecurringStorePaymentMethodTrueAdyenSubscription(): void
    {
        $this->setMockObjects(['storePaymentMethod' => true], true, Vault::SUBSCRIPTION);
        $request = $this->sut->buildCardRecurringData(1, $this->paymentMock);

        $this->assertTrue($request['storePaymentMethod']);
        $this->assertEquals(Vault::SUBSCRIPTION, $request['recurringProcessingModel']);
    }

    public function testBuildCardRecurringStorePaymentMethodFalse(): void
    {
        $this->setMockObjects(['storePaymentMethod' => false], false, Vault::SUBSCRIPTION);
        $request = $this->sut->buildCardRecurringData(1, $this->paymentMock);

        $this->assertArrayNotHasKey('storePaymentMethod', $request);
        $this->assertArrayNotHasKey('recurringProcessingModel', $request);
    }

    public function testBuildCardRecurringWithEmptyStateData(): void
    {
        $this->setMockObjects([], true, Vault::SUBSCRIPTION);
        $request = $this->sut->buildCardRecurringData(1, $this->paymentMock);

        $this->assertArrayNotHasKey('storePaymentMethod', $request);
        $this->assertArrayNotHasKey('recurringProcessingModel', $request);
    }

    public function testBuildCardRecurringWithInvalidRecurringProcessingModel(): void
    {
        $this->setMockObjects(['storePaymentMethod' => true], true, 'INVALID_MODEL');
        $request = $this->sut->buildCardRecurringData(1, $this->paymentMock);

        $this->assertArrayHasKey('storePaymentMethod', $request);
        //$this->assertArrayNotHasKey('recurringProcessingModel', $request, 'Unexpected model should not be set');
    }

    public function testBuildCardRecurringWithPartialStateData(): void
    {
        $this->setMockObjects(['storePaymentMethod' => true], true, Vault::CARD_ON_FILE);
        $request = $this->sut->buildCardRecurringData(1, $this->paymentMock);

        $this->assertTrue($request['storePaymentMethod']);
        $this->assertEquals(Vault::CARD_ON_FILE, $request['recurringProcessingModel']);
    }

    // Edge case: Vault disabled but storePaymentMethod is present in stateData
    public function testBuildCardRecurringVaultDisabledWithStorePaymentMethod(): void
    {
        $this->setMockObjects(['storePaymentMethod' => true], false, Vault::SUBSCRIPTION);
        $request = $this->sut->buildCardRecurringData(1, $this->paymentMock);

        $this->assertArrayNotHasKey('storePaymentMethod', $request);
        $this->assertArrayNotHasKey('recurringProcessingModel', $request);
    }

    public function testBuildCardRecurringGuestWithVaultDisabled(): void
    {
        $this->setMockObjects([], false, '');
        $request = $this->sut->buildCardRecurringData(1, $this->paymentMock);

        $this->assertArrayNotHasKey('storePaymentMethod', $request);
        $this->assertArrayNotHasKey('recurringProcessingModel', $request);
    }

    public function testBuildCardRecurringWithSubscriptionModelAndStoreTrue(): void
    {
        $this->setMockObjects(['storePaymentMethod' => true], true, Vault::SUBSCRIPTION);
        $request = $this->sut->buildCardRecurringData(1, $this->paymentMock);

        $this->assertTrue($request['storePaymentMethod']);
        $this->assertEquals(Vault::SUBSCRIPTION, $request['recurringProcessingModel']);
    }

    public function testBuildMerchantAccountDataWithValidAccount(): void
    {
        // Arrange
        $paymentMethod = 'adyen_cc';
        $storeId = 1;
        $merchantAccount = 'ValidMerchantAccount';

        $this->adyenHelperMock->method('getAdyenMerchantAccount')
            ->with($paymentMethod, $storeId)
            ->willReturn($merchantAccount);

        // Act
        $request = $this->sut->buildMerchantAccountData($paymentMethod, $storeId);

        // Assert
        $this->assertArrayHasKey(Requests::MERCHANT_ACCOUNT, $request);
        $this->assertEquals($merchantAccount, $request[Requests::MERCHANT_ACCOUNT]);
    }

    public function testBuildMerchantAccountDataWithExistingRequestData(): void
    {
        // Arrange
        $paymentMethod = 'adyen_cc';
        $storeId = 1;
        $merchantAccount = 'TestMerchantAccount';
        $existingRequest = ['existingKey' => 'existingValue'];

        $this->adyenHelperMock->method('getAdyenMerchantAccount')
            ->with($paymentMethod, $storeId)
            ->willReturn($merchantAccount);

        // Act
        $request = $this->sut->buildMerchantAccountData($paymentMethod, $storeId, $existingRequest);

        // Assert
        $this->assertArrayHasKey(Requests::MERCHANT_ACCOUNT, $request);
        $this->assertEquals($merchantAccount, $request[Requests::MERCHANT_ACCOUNT]);
        $this->assertArrayHasKey('existingKey', $request);
        $this->assertEquals('existingValue', $request['existingKey']);
    }

    public function testBuildMerchantAccountDataWithEmptyAccount(): void
    {
        // Arrange
        $paymentMethod = 'adyen_cc';
        $storeId = 1;
        $merchantAccount = ''; // Simulating empty account return

        $this->adyenHelperMock->method('getAdyenMerchantAccount')
            ->with($paymentMethod, $storeId)
            ->willReturn($merchantAccount);

        // Act
        $request = $this->sut->buildMerchantAccountData($paymentMethod, $storeId);

        // Assert
        $this->assertArrayHasKey(Requests::MERCHANT_ACCOUNT, $request);
        $this->assertEquals($merchantAccount, $request[Requests::MERCHANT_ACCOUNT]);
    }

    public function testBuildMerchantAccountDataWithNullAccount(): void
    {
        $paymentMethod = 'adyen_cc';
        $storeId = 1;
        $merchantAccount = null; // Simulating null account return

        $this->adyenHelperMock->method('getAdyenMerchantAccount')
            ->with($paymentMethod, $storeId)
            ->willReturn($merchantAccount);

        $request = $this->sut->buildMerchantAccountData($paymentMethod, $storeId);

        $this->assertArrayHasKey(Requests::MERCHANT_ACCOUNT, $request);
        $this->assertNull($request[Requests::MERCHANT_ACCOUNT]);
    }

    public function testBuildCustomerDataWithGuestEmail(): void
    {
        $this->payment->method('getOrder')->willReturn($this->order);
        $this->order->method('getIncrementId')->willReturn('12345');

        $additionalData = ['guestEmail' => 'guest@example.com'];

        $request = $this->sut->buildCustomerData($this->billingAddressMock, 1, 0, $this->payment, $additionalData);

        $this->assertEquals('guest@example.com', $request['shopperEmail']);
    }

    public function testBuildCustomerDataWithBillingAddressEmail(): void
    {
        $this->billingAddressMock->method('getEmail')->willReturn('customer@example.com');

        $this->payment->method('getOrder')->willReturn($this->order);
        $this->order->method('getIncrementId')->willReturn('12345');

        $request = $this->sut->buildCustomerData($this->billingAddressMock, 1, 0, $this->payment);

        $this->assertEquals('customer@example.com', $request['shopperEmail']);
    }

    public function testBuildCustomerDataWithPhoneNumber(): void
    {
        $this->billingAddressMock->method('getTelephone')->willReturn('1234567890');


        $this->methodInstance->method('getCode')->willReturn('adyen_cc');

        $this->payment->method('getOrder')->willReturn($this->order);
        $this->order->method('getIncrementId')->willReturn('12345');

        $request = $this->sut->buildCustomerData($this->billingAddressMock, 1, 0, $this->payment);

        $this->assertEquals('1234567890', $request['telephoneNumber']);
    }

    public function testBuildCustomerDataWithShopperName(): void
    {
        $this->billingAddressMock->method('getFirstname')->willReturn('John');
        $this->billingAddressMock->method('getLastname')->willReturn('Doe');
        $this->payment->method('getOrder')->willReturn($this->order);
        $this->order->method('getIncrementId')->willReturn('12345');
        $this->methodInstance->method('getCode')->willReturn('adyen_cc');
        $request = $this->sut->buildCustomerData($this->billingAddressMock, 1, 0, $this->payment);

        $this->assertEquals('John', $request['shopperName']['firstName']);
        $this->assertEquals('Doe', $request['shopperName']['lastName']);
    }

    public function testBuildCustomerDataWithCountryCode(): void
    {
        $this->billingAddressMock->method('getCountryId')->willReturn('US');

        $this->addressHelperMock->method('getAdyenCountryCode')->with('US')->willReturn('US');
        $this->methodInstance->method('getCode')->willReturn('adyen_cc');
        $this->payment->method('getOrder')->willReturn($this->order);
        $this->order->method('getIncrementId')->willReturn('12345');

        $request = $this->sut->buildCustomerData($this->billingAddressMock, 1, 0, $this->payment);

        $this->assertEquals('US', $request['countryCode']);
    }

    public function testBuildCustomerDataWithLocale(): void
    {
        $this->payment->method('getOrder')->willReturn($this->order);
        $this->order->method('getIncrementId')->willReturn('12345');
        $this->methodInstance->method('getCode')->willReturn('adyen_cc');
        $this->adyenHelperMock->method('getStoreLocale')->with(1)->willReturn('en_US');

        $request = $this->sut->buildCustomerData($this->billingAddressMock, 1, 0, $this->payment);

        $this->assertEquals('en_US', $request['shopperLocale']);
    }

    public function testBuildCustomerIpData(): void
    {
        // Define test IP address
        $ipAddress = '192.168.1.1';

        // Call buildCustomerIpData method
        $result = $this->sut->buildCustomerIpData($ipAddress);

        // Assert that 'shopperIP' is correctly set in the request array
        $this->assertArrayHasKey('shopperIP', $result);
        $this->assertEquals($ipAddress, $result['shopperIP']);
    }

    public function testBuildCustomerIpDataWithExistingRequest(): void
    {
        // Define test IP address and an initial request array
        $ipAddress = '192.168.1.1';
        $initialRequest = ['existingKey' => 'existingValue'];

        // Call buildCustomerIpData method with the existing request array
        $result = $this->sut->buildCustomerIpData($ipAddress, $initialRequest);

        // Assert that 'shopperIP' is correctly set in the request array
        $this->assertArrayHasKey('shopperIP', $result);
        $this->assertEquals($ipAddress, $result['shopperIP']);
        // Ensure the initial request data is preserved
        $this->assertArrayHasKey('existingKey', $result);
        $this->assertEquals('existingValue', $result['existingKey']);
    }

    public function testGetShopperReferenceWithCustomerId(): void
    {
        // Define a customer ID and expected padded result
        $customerId = '12345';
        $paddedCustomerId = 'PaddedCustomerId';

        // Set up AdyenHelper mock to return padded customer ID
        $this->adyenHelperMock
            ->expects($this->once())
            ->method('padShopperReference')
            ->with($customerId)
            ->willReturn($paddedCustomerId);

        // Call getShopperReference with a valid customerId
        $result = $this->sut->getShopperReference($customerId, 'order123');

        // Assert that the result matches the padded customer ID
        $this->assertEquals($paddedCustomerId, $result);
    }

    public function testGetShopperReferenceWithoutCustomerId(): void
    {
        // Define the order increment ID and simulate UUID generation
        $orderIncrementId = 'order123';

        // Call getShopperReference with null customerId
        $result = $this->sut->getShopperReference(null, $orderIncrementId);

        $expectedResultPattern = '/^' . preg_quote($orderIncrementId, '/') . '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
        $this->assertMatchesRegularExpression($expectedResultPattern, $result);
    }

    public function testBuildDonationDataWithValidData(): void
    {
        $buildSubject = [
            'paymentMethod' => 'some_payment_method',
            'amount' => 1000,
            'shopperReference' => 'shopper123',
            'donationToken' => 'donationToken123',
            'donationOriginalPspReference' => 'originalPspReference123',
            'returnUrl' => 'https://example.com/return'
        ];

        $storeId = 1;

        // Mock the charity merchant account return
        $this->adyenConfigMock
            ->method('getCharityMerchantAccount')
            ->with($storeId)
            ->willReturn('charityMerchantAccount123');

        // Mock the merchant account return
        $this->adyenHelperMock
            ->expects($this->once())
            ->method('getAdyenMerchantAccount')
            ->with('adyen_giving', $storeId)
            ->willReturn('adyenMerchantAccount123');

        $result = $this->sut->buildDonationData($buildSubject, $storeId);

        // Assert the structure and values of the returned array
        $this->assertIsArray($result);
        $this->assertArrayHasKey('amount', $result);
        $this->assertArrayHasKey('reference', $result);
        $this->assertArrayHasKey('shopperReference', $result);
        $this->assertArrayHasKey('paymentMethod', $result);
        $this->assertArrayHasKey('donationToken', $result);
        $this->assertArrayHasKey('donationOriginalPspReference', $result);
        $this->assertArrayHasKey('donationAccount', $result);
        $this->assertArrayHasKey('returnUrl', $result);
        $this->assertArrayHasKey('merchantAccount', $result);
        $this->assertArrayHasKey('shopperInteraction', $result);

        // Assert specific values
        $this->assertEquals(1000, $result['amount']);
        $this->assertEquals('shopper123', $result['shopperReference']);
        $this->assertEquals('donationToken123', $result['donationToken']);
        $this->assertEquals('originalPspReference123', $result['donationOriginalPspReference']);
        $this->assertEquals('https://example.com/return', $result['returnUrl']);
        $this->assertEquals('adyenMerchantAccount123', $result['merchantAccount']);
    }

    public function testBuildDonationDataWithMappedPaymentMethod(): void
    {
        $buildSubject = [
            'paymentMethod' => 'original_payment_method',
            'amount' => 2000,
            'shopperReference' => 'shopper456',
            'donationToken' => 'donationToken456',
            'donationOriginalPspReference' => 'originalPspReference456',
            'returnUrl' => 'https://example.com/return'
        ];

        $storeId = 2;

        $this->adyenHelperMock
            ->expects($this->once())
            ->method('getAdyenMerchantAccount')
            ->with('adyen_giving', $storeId)
            ->willReturn('adyenMerchantAccount456');

        $result = $this->sut->buildDonationData($buildSubject, $storeId);

        // Assert that the mapped payment method is used
        $this->assertEquals('original_payment_method', $result['paymentMethod']['type']);
    }

    public function testBuildAdyenTokenizedPaymentRecurringDataWithExistingModel(): void
    {
        $storeId = 1;
        $paymentMock = $this->createMock(\Magento\Payment\Model\InfoInterface::class);
        $paymentMock->method('getAdditionalInformation')
            ->willReturn(['recurringProcessingModel' => 'some_model']);

        $result = $this->sut->buildAdyenTokenizedPaymentRecurringData($storeId, $paymentMock);

        // Assert that the recurringProcessingModel is set correctly
        $this->assertArrayHasKey('recurringProcessingModel', $result);
        $this->assertNotEmpty($result['recurringProcessingModel']);
    }

    public function testBuildAdyenTokenizedPaymentRecurringDataWithCardType(): void
    {
        $storeId = 1;
        $paymentMock = $this->createMock(\Magento\Payment\Model\InfoInterface::class);
        $paymentMock->method('getAdditionalInformation')
            ->willReturn(['cc_type' => 'visa']);

        $result = $this->sut->buildAdyenTokenizedPaymentRecurringData($storeId, $paymentMock);

        // Assert that the recurringProcessingModel is set to the model returned from the vault helper
        $this->assertArrayHasKey('recurringProcessingModel', $result);
        $this->assertNotEmpty($result['recurringProcessingModel']);
    }

    public function testBuildAdyenTokenizedPaymentRecurringDataWithOtherPaymentMethod(): void
    {
        $storeId = 1;
        $paymentMock = $this->createMock(\Magento\Payment\Model\InfoInterface::class);
        $paymentMock->method('getAdditionalInformation')
            ->willReturn(['cc_type' => 'other_pm_type', 'method' => 'other_pm']);

        $result = $this->sut->buildAdyenTokenizedPaymentRecurringData($storeId, $paymentMock);

        // Assert that the recurringProcessingModel is set to the model returned from the vault helper
        $this->assertArrayHasKey('recurringProcessingModel', $result);
        $this->assertNotEmpty($result['recurringProcessingModel']);
    }

    public function testBuildBrowserDataWithUserAgentAndAcceptHeader()
    {
        // Arrange
        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/87.0.4280.66 Safari/537.36';
        $acceptHeader = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8';

        // Setting up the request mock to return headers
        $this->requestInterfaceMock->method('getServer')
            ->will($this->returnCallback(function ($header) use ($userAgent, $acceptHeader) {
                if ($header === 'HTTP_USER_AGENT') {
                    return $userAgent;
                } elseif ($header === 'HTTP_ACCEPT') {
                    return $acceptHeader;
                }
                return null;
            }));

        // Act
        $result = $this->sut->buildBrowserData([]);

        // Assert
        $this->assertArrayHasKey('browserInfo', $result);
        $this->assertArrayHasKey('userAgent', $result['browserInfo']);
        $this->assertArrayHasKey('acceptHeader', $result['browserInfo']);
        $this->assertEquals($userAgent, $result['browserInfo']['userAgent']);
        $this->assertEquals($acceptHeader, $result['browserInfo']['acceptHeader']);
    }

    public function testBuildBrowserDataWithNoUserAgentOrAcceptHeader()
    {
        // Arrange
        $this->requestInterfaceMock->method('getServer')
            ->willReturn(null);

        // Act
        $result = $this->sut->buildBrowserData([]);

        // Assert
        $this->assertArrayNotHasKey('browserInfo', $result);
    }

    private function setMockObjects(array $stateDataArray, bool $vaultEnabled, string $tokenType): void
    {
        $stateDataMock = $this->createConfiguredMock(StateData::class, [
            'getStateData' => $stateDataArray
        ]);

        $vaultHelperMock = $this->createConfiguredMock(Vault::class, [
            'getPaymentMethodRecurringActive' => $vaultEnabled,
            'getPaymentMethodRecurringProcessingModel' => $tokenType
        ]);

        $this->adyenHelperMock = $this->createMock(Data::class);


        $configHelperMock = $this->createConfiguredMock(Config::class, [
            //'getPaymentMethodRecurringProcessingModel' => $tokenType
            //'getCardRecurringActive' => true
        ]);

        $this->sut = new Requests(
            $this->createMock(Data::class),
            $configHelperMock,
            $this->createMock(Address::class),
            $stateDataMock,
            $vaultHelperMock,
            $this->createMock(http::class)
        );

        $orderMock = $this->createConfiguredMock(Order::class, [
            'getQuoteId' => 1
        ]);
        $this->paymentMock = $this->createConfiguredMock(Payment::class, [
            'getOrder' => $orderMock,
            'getMethod' => 'adyen_cc'
        ]);
    }
}
