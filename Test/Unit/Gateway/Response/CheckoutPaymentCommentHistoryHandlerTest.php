<?php

namespace Test\Unit\Gateway\Response;

use Adyen\Payment\Gateway\Response\CheckoutPaymentCommentHistoryHandler;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Payment\Gateway\Data\OrderAdapterInterface;
use Magento\Payment\Gateway\Data\PaymentDataObject;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;

class CheckoutPaymentCommentHistoryHandlerTest extends AbstractAdyenTestCase
{
    private CheckoutPaymentCommentHistoryHandler $checkoutPaymentCommentHistoryHandler;
    private Payment|MockObject $paymentMock;
    private Order|MockObject $orderMock;
    private PaymentDataObject $paymentDataObject;
    private array $handlingSubject;

    public function setUp() : void
    {
        $this->checkoutPaymentCommentHistoryHandler = new CheckoutPaymentCommentHistoryHandler();

        $orderAdapterMock = $this->createMock(OrderAdapterInterface::class);
        $this->orderMock = $this->createMock(Order::class);

        $this->paymentMock = $this->createMock(Payment::class);
        $this->paymentMock->method('getOrder')->willReturn($this->orderMock);
        $this->paymentDataObject = new PaymentDataObject($orderAdapterMock, $this->paymentMock);

        $this->handlingSubject  = [
            'payment' => $this->paymentDataObject,
            'paymentAction' => "authorize",
            'stateObject' => null
        ];
    }
    public function testIfGeneralFlowIsHandledCorrectly()
    {
        // Prepare the sample response collection
        $responseCollection = [
            'hasOnlyGiftCards' => false,
            0 => [
                'additionalData' => [],
                'amount' => [],
                'resultCode' => 'Authorised',
                'pspReference' => 'MDH54321',
                'paymentMethod' => [
                    'name' => 'giftcard',
                    'type' => 'Givex',
                ],
            ],
            1 => [
                'additionalData' => [],
                'amount' => [],
                'resultCode' => 'Authorised',
                'pspReference' => 'ABC12345',
                'paymentMethod' => [
                    'name' => 'card',
                    'type' => 'CreditCard',
                ],
            ],
        ];

        // Set expectations for the mocked order object
        $this->orderMock
            ->expects($this->once())
            ->method('addStatusHistoryComment')
            ->with(
                $this->stringContains(
                    "authResult: Authorised<br /> pspReference: MDH54321<br /> " .
                    "<br /> authResult: Authorised<br /> pspReference: ABC12345<br />"
                ),
                $this->anything()
            );

       $this->checkoutPaymentCommentHistoryHandler->handle($this->handlingSubject, $responseCollection);
    }

    public function testIfEmptyResponseCodeIsHandledCorrectly()
    {
        // Prepare a sample response collection without a resultCode
        $responseCollection = [
            [
                'pspReference' => '123456789'
            ]
        ];

        // Set expectations for the mocked order object
        $this->orderMock
            ->expects($this->once())
            ->method('addStatusHistoryComment')
            ->with(
                $this->stringContains('pspReference: 123456789'),
                $this->anything()
            );

        // Execute the handler
        $this->checkoutPaymentCommentHistoryHandler->handle($this->handlingSubject, $responseCollection);
    }

    public function testIfNoPspReferenceIsHandledCorrectly()
    {
        // Prepare a sample response collection without a pspReference
        $responseCollection = [
            [
                'resultCode' => 'Authorised'
            ]
        ];

        // Set expectations for the mocked order object
        $this->orderMock
            ->expects($this->once())
            ->method('addStatusHistoryComment')
            ->with(
                $this->stringContains('authResult: Authorised'),
                $this->anything()
            );

        // Execute the handler
        $this->checkoutPaymentCommentHistoryHandler->handle($this->handlingSubject, $responseCollection);
    }
}
