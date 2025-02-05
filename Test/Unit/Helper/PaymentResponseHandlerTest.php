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

use Adyen\Payment\Helper\PaymentResponseHandler;
use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Helper\Vault;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Helper\Quote;
use Adyen\Payment\Helper\Order as OrderHelper;
use Adyen\Payment\Model\Method\Adapter;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Exception;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order as MagentoOrder;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Status\History;
use Magento\Sales\Model\ResourceModel\Order;
use Magento\Sales\Model\OrderRepository;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use Adyen\Payment\Helper\StateData;
use Adyen\Payment\Model\ResourceModel\PaymentResponse\Collection;
use Adyen\Payment\Model\ResourceModel\PaymentResponse\CollectionFactory;
use Adyen\Payment\Helper\Config;
use PHPUnit\Framework\MockObject\MockObject;
use Adyen\Payment\Helper\PaymentMethods;
use ReflectionClass;

class PaymentResponseHandlerTest extends AbstractAdyenTestCase
{
    private Payment $paymentMock;
    private MagentoOrder $orderMock;
    private AdyenLogger $adyenLoggerMock;
    private Vault $vaultHelperMock;
    private Order $orderResourceModelMock;
    private Data $dataHelperMock;
    private Quote $quoteHelperMock;
    private OrderHelper $orderHelperMock;
    private OrderRepository $orderRepositoryMock;
    private HistoryFactory $orderHistoryFactoryMock;
    private StateData $stateDataHelperMock;
    private PaymentResponseHandler $paymentResponseHandler;
    private Config $configHelperMock;
    private Collection $paymentResponseMockForFactory;
    private CollectionFactory $paymentResponseCollectionFactoryMock;
    private OrderStatusHistoryRepositoryInterface|MockObject $historyRepositoryMock;
    private Adapter|MockObject $paymentMethodInstanceMock;
    private PaymentMethods|MockObject $paymentMethodsHelperMock;

    protected function setUp(): void
    {
        $this->paymentMock  = $this->createMock(Payment::class);
        $this->orderMock = $this->createMock(MagentoOrder::class);
        $this->paymentMethodInstanceMock = $this->createMock(Adapter::class);
        $this->adyenLoggerMock = $this->createMock(AdyenLogger::class);
        $this->vaultHelperMock = $this->createMock(Vault::class);
        $this->orderResourceModelMock = $this->createMock(Order::class);
        $this->dataHelperMock = $this->createMock(Data::class);
        $this->quoteHelperMock = $this->createMock(Quote::class);
        $this->orderHelperMock = $this->createMock(OrderHelper::class);
        $this->orderRepositoryMock = $this->createMock(OrderRepository::class);
        $this->orderHistoryFactoryMock = $this->createGeneratedMock(HistoryFactory::class, [
            'create'
        ]);
        $this->stateDataHelperMock = $this->createMock(StateData::class);
        $this->configHelperMock = $this->createMock(Config::class);
        $this->paymentMethodsHelperMock = $this->createMock(PaymentMethods::class);
        $this->historyRepositoryMock = $this->createMock(OrderStatusHistoryRepositoryInterface::class);

        $this->paymentResponseMockForFactory = $this->createMock(Collection::class);

        $this->paymentResponseCollectionFactoryMock = $this->createGeneratedMock(CollectionFactory::class, ['create']);

        $orderHistory = $this->createMock(History::class);
        $orderHistory->method('setStatus')->willReturnSelf();
        $orderHistory->method('setComment')->willReturnSelf();
        $orderHistory->method('setEntityName')->willReturnSelf();
        $orderHistory->method('setOrder')->willReturnSelf();

        $this->orderHistoryFactoryMock->method('create')->willReturn($orderHistory);
        $this->orderMock->method('getQuoteId')->willReturn(1);
        $this->orderMock->method('getPayment')->willReturn($this->paymentMock);
        $this->orderMock->method('getStatus')->willReturn('pending');
        $this->orderMock->method('getIncrementId')->willReturn('00123456');
        $this->paymentMock->method('getMethodInstance')->willReturn($this->paymentMethodInstanceMock);

        $this->orderHelperMock->method('setStatusOrderCreation')->willReturn($this->orderMock);

        $this->paymentResponseHandler = new PaymentResponseHandler(
            $this->adyenLoggerMock,
            $this->vaultHelperMock,
            $this->orderResourceModelMock,
            $this->dataHelperMock,
            $this->quoteHelperMock,
            $this->orderHelperMock,
            $this->orderRepositoryMock,
            $this->orderHistoryFactoryMock,
            $this->stateDataHelperMock,
            $this->paymentResponseCollectionFactoryMock,
            $this->configHelperMock,
            $this->historyRepositoryMock,
            $this->paymentMethodsHelperMock
        );
    }

    private static function dataSourceForFormatPaymentResponseFinalResultCodes(): array
    {
        return [
            ['resultCode' => PaymentResponseHandler::AUTHORISED],
            ['resultCode' => PaymentResponseHandler::REFUSED],
            ['resultCode' => PaymentResponseHandler::ERROR],
            ['resultCode' => PaymentResponseHandler::POS_SUCCESS]
        ];
    }

    /**
     * @param $resultCode
     * @return void
     * @dataProvider dataSourceForFormatPaymentResponseFinalResultCodes
     */
    public function testFormatPaymentResponseForFinalResultCodes($resultCode)
    {
        $expectedResult = [
            "isFinal" => true,
            "resultCode" => $resultCode
        ];

        // Execute method of the tested class
        $result = $this->paymentResponseHandler->formatPaymentResponse($resultCode);

        // Assert conditions
        $this->assertEquals($expectedResult, $result);
    }

    private static function dataSourceForFormatPaymentResponseActionRequiredPayments(): array
    {
        return [
            ['resultCode' => PaymentResponseHandler::REDIRECT_SHOPPER, 'action' => ['type' => 'qrCode']],
            ['resultCode' => PaymentResponseHandler::IDENTIFY_SHOPPER, 'action' => ['type' => 'qrCode']],
            ['resultCode' => PaymentResponseHandler::CHALLENGE_SHOPPER, 'action' => ['type' => 'qrCode']],
            ['resultCode' => PaymentResponseHandler::PENDING, 'action' => ['type' => 'qrCode']],
        ];
    }

    /**
     * @param $resultCode
     * @param $action
     * @return void
     * @dataProvider dataSourceForFormatPaymentResponseActionRequiredPayments
     */
    public function testFormatPaymentResponseForActionRequiredPayments($resultCode, $action)
    {
        $expectedResult = [
            "isFinal" => false,
            "resultCode" => $resultCode,
            "action" => $action
        ];

        // Execute method of the tested class
        $result = $this->paymentResponseHandler->formatPaymentResponse($resultCode, $action);

        // Assert conditions
        $this->assertEquals($expectedResult, $result);
    }

    /**
     * @return void
     */
    public function testFormatPaymentResponseForVoucherPayments()
    {
        $resultCode = PaymentResponseHandler::PRESENT_TO_SHOPPER;
        $action = ['type' => 'voucher'];

        $expectedResult = [
            "isFinal" => true,
            "resultCode" => $resultCode,
            "action" => $action
        ];

        // Execute method of the tested class
        $result = $this->paymentResponseHandler->formatPaymentResponse($resultCode, $action);

        // Assert conditions
        $this->assertEquals($expectedResult, $result);
    }

    /**
     * @return void
     */
    public function testFormatPaymentResponseForOfflinePayments()
    {
        $resultCode = PaymentResponseHandler::RECEIVED;
        $additionalData = ['action' => ['voucher']];

        $expectedResult = [
            "isFinal" => true,
            "resultCode" => $resultCode,
            "additionalData" => $additionalData
        ];

        // Execute method of the tested class
        $result = $this->paymentResponseHandler->formatPaymentResponse($resultCode, null, $additionalData);

        // Assert conditions
        $this->assertEquals($expectedResult, $result);
    }

    /**
     * @return void
     */
    public function testFormatPaymentResponseForUnknownResults()
    {
        $resultCode = 'UNRECOGNISED_RESULT_CODE';

        $expectedResult = [
            "isFinal" => true,
            "resultCode" => PaymentResponseHandler::ERROR
        ];

        // Execute method of the tested class
        $result = $this->paymentResponseHandler->formatPaymentResponse($resultCode);

        // Assert conditions
        $this->assertEquals($expectedResult, $result);
    }

    public function testHandlePaymentsDetailsResponseWithNullResultCode()
    {
        $orderMock = $this->createMock(MagentoOrder::class);

        $paymentsDetailsResponse = [
            'randomData' => 'someRandomValue'
        ];

        $result = $this->paymentResponseHandler->handlePaymentsDetailsResponse(
            $paymentsDetailsResponse,
            $orderMock
        );

        $this->assertFalse($result);
    }

    public function testHandlePaymentsDetailsResponseAuthorised()
    {
        $paymentsDetailsResponse = [
            'resultCode' => PaymentResponseHandler::AUTHORISED,
            'pspReference' => 'ABC123456789',
            'paymentMethod' => [
                'brand' => 'ideal'
            ],
            'additionalData' => [
                'someData' => 'someValue'
            ],
            'details' => [
                'someData' => 'someValue'
            ],
            'donationToken' => 'XYZ123456789',
            'merchantReference' => '00123456'
        ];

        $this->quoteHelperMock->method('disableQuote')->willThrowException(new Exception());
        $this->adyenLoggerMock->expects($this->atLeastOnce())->method('error');

        $result = $this->paymentResponseHandler->handlePaymentsDetailsResponse(
            $paymentsDetailsResponse,
            $this->orderMock
        );

        $this->assertTrue($result);
    }


    private static function handlePaymentsDetailsPendingProvider(): array
    {
        return [
            ['paymentMethodCode' => 'bankTransfer'],
            ['paymentMethodCode' => 'sepadirectdebit'],
            ['paymentMethodCode' => 'multibanco'],
        ];
    }

    /**
     * @return void
     * @throws AlreadyExistsException
     * @throws InputException
     * @throws NoSuchEntityException
     * @dataProvider handlePaymentsDetailsPendingProvider
     */
    public function testHandlePaymentsDetailsResponsePending($paymentMethodCode)
    {
        $this->stateDataHelperMock->method('cleanQuoteStateData')
            ->willThrowException(new Exception);
        $this->adyenLoggerMock->expects($this->atLeastOnce())->method('error');

        $paymentsDetailsResponse = [
            'resultCode' => PaymentResponseHandler::PENDING,
            'pspReference' => 'ABC123456789',
            'paymentMethod' => [
                'brand' => $paymentMethodCode
            ],
            'merchantReference' => '00123456'
        ];

        $result = $this->paymentResponseHandler->handlePaymentsDetailsResponse(
            $paymentsDetailsResponse,
            $this->orderMock
        );

        $this->assertTrue($result);
    }

    private static function handlePaymentsDetailsPendingReceived(): array
    {
        return [
            ['paymentMethodCode' => 'alipay_hk', 'expectedResult' => false],
            ['paymentMethodCode' => 'multibanco', 'expectedResult' => true]
        ];
    }

    /**
     * @return void
     * @throws AlreadyExistsException
     * @throws InputException
     * @throws NoSuchEntityException
     * @dataProvider handlePaymentsDetailsPendingReceived
     */
    public function testHandlePaymentsDetailsResponseReceived($paymentMethodCode, $expectedResult)
    {
        $paymentsDetailsResponse = [
            'resultCode' => PaymentResponseHandler::RECEIVED,
            'pspReference' => 'ABC123456789',
            'paymentMethod' => [
                'brand' => $paymentMethodCode
            ],
            'merchantReference' => '00123456'
        ];

        $result = $this->paymentResponseHandler->handlePaymentsDetailsResponse(
            $paymentsDetailsResponse,
            $this->orderMock
        );

        $this->assertEquals($expectedResult, $result);
    }

    private static function handlePaymentsDetailsActionRequiredProvider(): array
    {
        return [
            ['resultCode' => PaymentResponseHandler::PRESENT_TO_SHOPPER],
            ['resultCode' => PaymentResponseHandler::IDENTIFY_SHOPPER],
            ['resultCode' => PaymentResponseHandler::CHALLENGE_SHOPPER],
            ['resultCode' => PaymentResponseHandler::REDIRECT_SHOPPER]
        ];
    }

    /**
     * @return void
     * @throws AlreadyExistsException
     * @throws InputException
     * @throws NoSuchEntityException
     * @dataProvider handlePaymentsDetailsActionRequiredProvider
     */
    public function testHandlePaymentsDetailsResponseActionRequired($resultCode)
    {
        $paymentsDetailsResponse = [
            'resultCode' => $resultCode,
            'pspReference' => 'ABC123456789',
            'paymentMethod' => [
                'brand' => 'ideal'
            ],
            'merchantReference' => '00123456',
            'action' => [
                'actionData' => 'actionValue'
            ]
        ];

        $this->adyenLoggerMock->expects($this->atLeastOnce())->method('addAdyenResult');

        $result = $this->paymentResponseHandler->handlePaymentsDetailsResponse(
            $paymentsDetailsResponse,
            $this->orderMock
        );

        $this->assertTrue($result);
    }

    private static function handlePaymentsDetailsActionCancelledOrRefusedProvider(): array
    {
        return [
            ['resultCode' => PaymentResponseHandler::REFUSED],
            ['resultCode' => PaymentResponseHandler::CANCELLED]
        ];
    }

    /**
     * @return void
     * @throws AlreadyExistsException
     * @throws InputException
     * @throws NoSuchEntityException
     * @dataProvider handlePaymentsDetailsActionCancelledOrRefusedProvider
     */
    public function testHandlePaymentsDetailsResponseCancelOrRefused($resultCode)
    {
        $paymentsDetailsResponse = [
            'resultCode' => $resultCode,
            'pspReference' => 'ABC123456789',
            'paymentMethod' => [
                'brand' => 'ideal'
            ],
            'merchantReference' => '00123456',
            'action' => [
                'actionData' => 'actionValue'
            ]
        ];

        $this->paymentResponseMockForFactory->expects($this->any())
            ->method('addFieldToFilter')
            ->willReturn($this->paymentResponseMockForFactory);

        $this->paymentResponseMockForFactory->expects($this->any())
            ->method('getSize')
            ->willReturn(1); // Simulate there is at least one record

        // Mock getData to return the desired array of data from the database
        $this->paymentResponseMockForFactory->expects($this->any())
            ->method('getData')
            ->willReturn([
                [
                    'merchant_reference' => '12345',
                    'result_code' => 'Authorised',
                    'response' => '{
                        "additionalData":{"paymentMethod":"svs","merchantReference":"123","acquirerCode":"Test"},
                        "amount":{"currency":"EUR","value":5000},
                        "merchantReference":"123",
                        "order":{"amount":{"currency":"EUR","value":17800},"expiresAt":"2024-10-10T13:11:37Z",
                        "orderData":"orderData....",
                        "pspReference":"XYZ654",
                        "reference":"123",
                        "remainingAmount":{"currency":"EUR","value":12800}},
                        "paymentMethod":{"brand":"svs","type":"giftcard"},
                        "pspReference":"ABC123",
                        "resultCode":"Authorised"
                      }'
                ]
            ]);

        $this->paymentResponseCollectionFactoryMock->expects($this->any())
            ->method('create')
            ->willReturn($this->paymentResponseMockForFactory);

        $merchantAccount = 'mock_merchant_account';
        $storeId = 1;
        $this->orderMock->expects($this->once())->method('getStoreId')->willReturn($storeId);
        $this->configHelperMock->expects($this->any())
            ->method('getAdyenAbstractConfigData')
            ->with('merchant_account', $storeId)
            ->willReturn($merchantAccount);

        // Create an instance of the class that has the private method
        $class = new \ReflectionClass(PaymentResponseHandler::class);
        $instance = $class->newInstanceWithoutConstructor();

        // Inject the mocked factory into the instance if necessary
        $property = $class->getProperty('paymentResponseCollectionFactory');
        $property->setAccessible(true);
        $property->setValue($instance, $this->paymentResponseCollectionFactoryMock);

        // Use Reflection to access the private method
        $method = $class->getMethod('hasActiveGiftCardPayments');
        $method->setAccessible(true);

        // Mock order cancellation
        $this->orderMock->expects($this->any())
            ->method('canCancel')
            ->willReturn(true);

        $this->adyenLoggerMock->expects($this->atLeastOnce())->method('addAdyenResult');

        $result = $this->paymentResponseHandler->handlePaymentsDetailsResponse(
            $paymentsDetailsResponse,
            $this->orderMock
        );

        $this->assertFalse($result);
    }

    public function testHandlePaymentsDetailsResponseInvalid()
    {
        $paymentsDetailsResponse = [
            'resultCode' => 'UNRECOGNISED_RESULT_CODE'
        ];

        $this->adyenLoggerMock->expects($this->atLeastOnce())->method('error');

        $result = $this->paymentResponseHandler->handlePaymentsDetailsResponse(
            $paymentsDetailsResponse,
            $this->orderMock
        );

        $this->assertFalse($result);
    }

    public function testHandlePaymentsDetailsEmptyResponse()
    {
        $paymentsDetailsResponse = [];
        $this->adyenLoggerMock->expects($this->atLeastOnce())->method('error');

        $result = $this->paymentResponseHandler->handlePaymentsDetailsResponse(
            $paymentsDetailsResponse,
            $this->orderMock
        );

        $this->assertFalse($result);
    }

    public function testHandlePaymentsDetailsResponseInvalidMerchantReference(){
        $paymentsDetailsResponse = [
            'resultCode' => PaymentResponseHandler::AUTHORISED,
            'pspReference' => 'ABC123456789',
            'paymentMethod' => [
                'brand' => 'ideal'
            ],
            'merchantReference' => '00777777'
        ];

        $result = $this->paymentResponseHandler->handlePaymentsDetailsResponse(
            $paymentsDetailsResponse,
            $this->orderMock
        );

        $this->assertFalse($result);
    }

    public function testHandlePaymentsDetailsResponseValidMerchantReference()
    {
        $paymentsDetailsResponse = [
            'resultCode' => PaymentResponseHandler::AUTHORISED,
            'pspReference' => 'ABC123456789',
            'paymentMethod' => [
                'brand' => 'ideal'
            ],
            'merchantReference' => '00123456' // assuming this is a valid reference
        ];
        // Mock the isValidMerchantReference to return true
        $reflectionClass = new ReflectionClass(PaymentResponseHandler::class);
        $method = $reflectionClass->getMethod('isValidMerchantReference');
        $method->setAccessible(true);
        $isValidMerchantReference = $method->invokeArgs($this->paymentResponseHandler, [$paymentsDetailsResponse,$this->orderMock]);
        $this->assertTrue($isValidMerchantReference);
    }

    public function testPaymentDetailsCallFailureLogsError()
    {
        $resultCode = 'some_result_code';
        $paymentsDetailsResponse = ['error' => 'some error message'];

        // Expect the logger to be called with the specific message
        $this->adyenLoggerMock->expects($this->once())
            ->method('error');

        // Call the method that triggers the logging, e.g., handlePaymentDetailsFailure()
        $this->paymentResponseHandler->handlePaymentsDetailsResponse(
            $paymentsDetailsResponse,
            $this->orderMock
        );
    }

    public function testLogsErrorAndReturnsFalseForUnknownResult()
    {
        // Arrange
        $paymentsDetailsResponse = [
            'merchantReference' => '00123456'
        ];

        // Mock the logger to expect an error to be logged
        $this->adyenLoggerMock->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Unexpected result query parameter. Response: ' . json_encode($paymentsDetailsResponse)));

        // Act: Call the method that will trigger the unexpected result handling
        $result = $this->paymentResponseHandler->handlePaymentsDetailsResponse($paymentsDetailsResponse, $this->orderMock);

        // Assert: Ensure the method returned false
        $this->assertFalse($result);
    }

    public function testOrderStatusUpdateWhenResponseIsValid()
    {
        $paymentsDetailsResponse = [
            'merchantReference' => '00123456',
            'resultCode' => 'AUTHORISED'
        ];

        $this->orderMock->expects($this->once())
            ->method('getState')
            ->willReturn('pending_payment');

        // Mock the order repository to save the order
        $this->orderRepositoryMock->expects($this->once())
            ->method('save')
            ->with($this->orderMock);

        $this->paymentResponseHandler->handlePaymentsDetailsResponse($paymentsDetailsResponse, $this->orderMock);
    }

    public function testHandlePaymentsDetailsResponseSetsCcType()
    {

        // Mock the method `isWalletPaymentMethod` in your helper if it's being checked
        $this->paymentMethodsHelperMock->method('isWalletPaymentMethod')
            ->with($this->paymentMethodInstanceMock)
            ->willReturn(false); // Assuming false for this test case

        // Payment details response with a payment method brand
        $paymentsDetailsResponse = [
            'resultCode' => PaymentResponseHandler::AUTHORISED,
            'paymentMethod' => [
                'brand' => 'VI'
            ],
            'merchantReference' => '00123456'
        ];

        // Expect the `setCcType` method to be called on the payment object with the correct value
        $this->paymentMock
            ->method('setCcType')
            ->with($this->equalTo('VI'));

        // Call the method under test
        $result = $this->paymentResponseHandler->handlePaymentsDetailsResponse(
            $paymentsDetailsResponse,
            $this->orderMock
        );

        // Assert the response is as expected
        $this->assertTrue($result);
    }


}
