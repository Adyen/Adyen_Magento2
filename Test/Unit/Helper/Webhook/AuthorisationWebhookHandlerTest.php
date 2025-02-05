<?php

namespace Adyen\Payment\Test\Unit\Helper\Webhook;

use Adyen\Payment\Helper\AdyenOrderPayment;
use Adyen\Payment\Model\AdyenAmountCurrency;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Adyen\Payment\Helper\Webhook\AuthorisationWebhookHandler;
use Adyen\Payment\Model\Notification;
use Adyen\Webhook\PaymentStates;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use Adyen\Payment\Helper\Order as OrderHelper;
use Adyen\Payment\Helper\CaseManagement;
use Adyen\Payment\Helper\ChargedCurrency;
use Adyen\Payment\Helper\Config;
use Adyen\Payment\Helper\Invoice;
use Adyen\Payment\Helper\PaymentMethods;
use Adyen\Payment\Logger\AdyenLogger;
use Magento\Framework\Serialize\SerializerInterface;
use ReflectionClass;
use ReflectionException as ReflectionExceptionAlias;
use ReflectionMethod;

class AuthorisationWebhookHandlerTest extends AbstractAdyenTestCase
{
    private Notification|MockObject $notificationMock;
    private Order|MockObject $orderMock;
    private AdyenOrderPayment|MockObject $adyenOrderPaymentMock;
    private OrderHelper|MockObject $orderHelperMock;
    private CaseManagement|MockObject $caseManagementMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderMock = $this->createOrder();
        $this->adyenOrderPaymentMock = $this->createMock(AdyenOrderPayment::class);
        $this->orderMock = $this->createMock(Order::class);
        $this->orderHelperMock = $this->createMock(OrderHelper::class);
        $this->caseManagementMock = $this->createMock(CaseManagement::class);

        $paymentMethod = 'ADYEN_CC';
        $merchantReference = 'TestMerchant';
        $pspReference = 'ABCD1234GHJK5678';

        $this->notificationMock = $this->createConfiguredMock(Notification::class, [
            'getPspreference' => $pspReference,
            'getMerchantReference' => $merchantReference,
            'getPaymentMethod' => $paymentMethod,
            'isSuccessful' => true
        ]);
    }

    /**
     * @throws LocalizedException
     */
    public function testHandleWebhook(): void
    {
        // Set up expectations for mock objects
        $orderAmountCurrency = new AdyenAmountCurrency(
            10.33,
            'EUR',
            null,
            null,
            10.33
        );
        $storeId = 1;
        $paymentMock = $this->createConfiguredMock(Order\Payment::class, [
            'getMethod' => 'adyen_cc'
        ]);
        $this->orderMock->method('getStoreId')->willReturn($storeId);
        $this->orderMock->method('getPayment')->willReturn($paymentMock);

        $this->notificationMock->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);

        $paymentMethodsHelperMock = $this->createConfiguredMock(PaymentMethods::class, [
            'isAutoCapture' => true
        ]);
        $mockChargedCurrency = $this->createConfiguredMock(ChargedCurrency::class, [
            'getOrderAmountCurrency' => $orderAmountCurrency
        ]);

        $transitionState = PaymentStates::STATE_PAID;

        $handler = $this->createAuthorisationWebhookHandler(
            null,
            $this->orderHelperMock,
            null,
            null,
            null,
            $mockChargedCurrency,
            null,
            null,
            $paymentMethodsHelperMock
        );

        // Call the function to be tested
        $result = $handler->handleWebhook($this->orderMock, $this->notificationMock, $transitionState);

        // Assertions
        $this->assertInstanceOf(Order::class, $result);
    }

    public function isAutoCaptureProvider(): array
    {
        return [[true], [false]];
    }

    /**
     * @dataProvider isAutoCaptureProvider
     */
    public function testHandleSuccessfulAuthorisation($isAutoCapture): void
    {
        // Mock
        $orderAmount = 10.33;
        $this->adyenOrderPaymentMock->expects($this->once())
            ->method('createAdyenOrderPayment');
        $this->adyenOrderPaymentMock->expects($this->once())
            ->method('isFullAmountAuthorized')
            ->willReturn(true);

        $orderAmountCurrency = new AdyenAmountCurrency(
            $orderAmount,
            'EUR',
            null,
            null,
            $orderAmount
        );

        $mockChargedCurrency = $this->createConfiguredMock(ChargedCurrency::class, [
            'getOrderAmountCurrency' => $orderAmountCurrency
        ]);

        // Create mock instances for Order and Notification
        $paymentMock = $this->createMock(Order::class);
        $storeId = 1;
        $this->orderMock->method('getStoreId')->willReturn($storeId);
        $this->orderMock->method('getPayment')->willReturn($paymentMock);

        $this->orderHelperMock->expects($this->once())
            ->method('setPrePaymentAuthorized')->willReturn($this->orderMock);
        $this->orderHelperMock->expects($this->once())
            ->method('updatePaymentDetails');
        $this->orderHelperMock->expects($this->once())
            ->method('sendOrderMail');
        $this->orderHelperMock->expects($this->once())
            ->method('finalizeOrder')->willReturn($this->orderMock);

        $paymentMethodsMock = $this->createConfiguredMock(
            PaymentMethods::class,
            [
                'isAutoCapture' => true
            ]
        );

        $authorisationWebhookHandler = $this->createAuthorisationWebhookHandler(
            $this->adyenOrderPaymentMock,
            $this->orderHelperMock,
            null,
            null,
            null,
            $mockChargedCurrency,
            null,
            null,
            $paymentMethodsMock
        );

        // Invoke the private method
        $handleSuccessfulAuthorisationMethod = $this->getPrivateMethod(
            AuthorisationWebhookHandler::class,
            'handleSuccessfulAuthorisation'
        );

        $result = $handleSuccessfulAuthorisationMethod->invokeArgs(
            $authorisationWebhookHandler,
            [$this->orderMock, $this->notificationMock]
        );

        // Assert the result
        $this->assertInstanceOf(Order::class, $result);
    }

    /**
     * @throws ReflectionExceptionAlias
     */
    public function testHandleFailedAuthorisation(): void
    {
        $this->orderMock->expects($this->atLeastOnce())
            ->method('getData')
            ->withConsecutive(
                ['adyen_notification_event_code'],
                ['adyen_notification_payment_captured']
            )
            ->willReturnCallback(function ($arg) {
                if ($arg === 'adyen_notification_event_code') {
                    return 'AUTHORISATION : TRUE';
                } elseif ($arg === 'adyen_notification_payment_captured') {
                    return false;
                }
            });

        // Create an instance of AuthorisationWebhookHandler
        $webhookHandler = $this->createAuthorisationWebhookHandler();

        $handleFailedAuthorisationMethod = $this->getPrivateMethod(
            AuthorisationWebhookHandler::class,
            'handleFailedAuthorisation'
        );

        // Call the private method directly and provide required parameters
        $result = $handleFailedAuthorisationMethod->invokeArgs(
            $webhookHandler,
            [$this->orderMock, $this->notificationMock]
        );

        // Assert the expected behavior based on the mocked logic and result
        $this->assertInstanceOf(Order::class, $result);
    }

    /**
     * @throws ReflectionExceptionAlias
     */
    public function testHandleAutoCapture(): void
    {
        // Set up expectations for the mocks
        $this->orderMock->expects($this->any())
            ->method('getPayment')
            ->willReturn($this->orderMock);

        $this->orderMock->expects($this->any())
            ->method('getConfig')
            ->willReturnSelf();

        // Create an instance of AuthorisationWebhookHandler
        $webhookHandler = $this->createAuthorisationWebhookHandler();

        // Use Reflection to access the private method
        $handleAutoCaptureMethod = $this->getPrivateMethod(
            AuthorisationWebhookHandler::class,
            'handleAutoCapture'
        );

        // Call the private method directly and provide required parameters
        $result = $handleAutoCaptureMethod->invokeArgs(
            $webhookHandler,
            [$this->orderMock, $this->notificationMock, true] // true indicates requireFraudManualReview
        );

        // Perform assertions on the result and expected behavior
        $this->assertInstanceOf(Order::class, $result);
    }

    /**
     * @throws ReflectionExceptionAlias
     */
    public function testHandleManualCapture(): void
    {
        // Set up expectations for handleManualCapture private method
        $this->orderHelperMock->expects($this->never()) // Since the condition is true
        ->method('setPrePaymentAuthorized');

        $this->caseManagementMock->expects($this->once())
            ->method('markCaseAsPendingReview')
            ->with($this->orderMock, $this->notificationMock->getPspreference(), false);

        // Create an instance of AuthorisationWebhookHandler
        $webhookHandler = $this->createAuthorisationWebhookHandler(
            null,
            $this->orderHelperMock,
            $this->caseManagementMock
        );

        // Use Reflection to access the private method
        $handleManualCaptureMethod = $this->getPrivateMethod(
            AuthorisationWebhookHandler::class,
            'handleManualCapture'
        );

        // Call the private method directly and provide required parameters
        $result = $handleManualCaptureMethod->invokeArgs(
            $webhookHandler,
            [$this->orderMock, $this->notificationMock, true]
        );

        // Perform assertions on the result and expected behavior
        $this->assertInstanceOf(Order::class, $result);
    }

    /**
     * @throws ReflectionExceptionAlias
     */
    public function testCanCancelPayByLinkOrder(): void
    {
        // Create mocks for the required dependencies
        $paymentMock = $this->getMockBuilder(Order\Payment::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Configure the payment mock to return the payByLinkFailureCount
        $payByLinkFailureCount = 3; // Assuming the failure count is 3

        // Use willReturn() to mock chained method calls
        $paymentMock->expects($this->once())
            ->method('getAdditionalInformation')
            ->with('payByLinkFailureCount')
            ->willReturn($payByLinkFailureCount);

        // Configure the order mock to return the payment mock
        $this->orderMock->method('getPayment')->willReturn($paymentMock);

        $paymentMock->expects($this->once())
            ->method('setAdditionalInformation')
            ->with('payByLinkFailureCount', $payByLinkFailureCount + 1);

        // Create an instance of AuthorisationWebhookHandler
        $webhookHandler = $this->createAuthorisationWebhookHandler();

        // Use Reflection to access the private method
        $canCancelPayByLinkOrderMethod = $this->getPrivateMethod(
            AuthorisationWebhookHandler::class,
            'canCancelPayByLinkOrder'
        );

        // Call the private method directly and provide required parameters
        $result = $canCancelPayByLinkOrderMethod->invokeArgs(
            $webhookHandler,
            [$this->orderMock, $this->notificationMock]
        );

        // Perform assertions on the result and expected behavior
        $this->assertIsBool($result);
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

    protected function createAuthorisationWebhookHandler(
        $mockAdyenOrderPayment = null,
        $mockOrderHelper = null,
        $mockCaseManagementHelper = null,
        $mockSerializer = null,
        $mockAdyenLogger = null,
        $mockChargedCurrency = null,
        $mockConfigHelper = null,
        $mockInvoiceHelper = null,
        $mockPaymentMethodsHelper = null
    ): AuthorisationWebhookHandler
    {
        if (is_null($mockAdyenOrderPayment)) {
            $mockAdyenOrderPayment = $this->createMock(AdyenOrderPayment::class);
        }

        if (is_null($mockOrderHelper)) {
            $mockOrderHelper = $this->createMock(OrderHelper::class);
        }

        if (is_null($mockCaseManagementHelper)) {
            $mockCaseManagementHelper = $this->createMock(CaseManagement::class);
        }

        if (is_null($mockSerializer)) {
            $mockSerializer = $this->createMock(SerializerInterface::class);
        }

        if (is_null($mockAdyenLogger)) {
            $mockAdyenLogger = $this->createMock(AdyenLogger::class);
        }

        if (is_null($mockChargedCurrency)) {
            $mockChargedCurrency = $this->createMock(ChargedCurrency::class);
        }

        if (is_null($mockConfigHelper)) {
            $mockConfigHelper = $this->createMock(Config::class);
        }

        if (is_null($mockInvoiceHelper)) {
            $mockInvoiceHelper = $this->createMock(Invoice::class);
        }

        if (is_null($mockPaymentMethodsHelper)) {
            $mockPaymentMethodsHelper = $this->createMock(PaymentMethods::class);
        }

        return new AuthorisationWebhookHandler(
            $mockAdyenOrderPayment,
            $mockOrderHelper,
            $mockCaseManagementHelper,
            $mockSerializer,
            $mockAdyenLogger,
            $mockChargedCurrency,
            $mockConfigHelper,
            $mockInvoiceHelper,
            $mockPaymentMethodsHelper
        );
    }
}
