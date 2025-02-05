<?php

namespace Test\Unit\Gateway\Response;

use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Adyen\Payment\Gateway\Response\CheckoutPaymentsDetailsHandler;
use Adyen\Payment\Helper\Data;
use Magento\Payment\Gateway\Data\OrderAdapterInterface;
use Magento\Payment\Gateway\Data\PaymentDataObject;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;

class CheckoutPaymentsDetailsHandlerTest extends AbstractAdyenTestCase
{
    private CheckoutPaymentsDetailsHandler $checkoutPaymentsDetailsHandler;
    private Payment|MockObject $paymentMock;
    private Order|MockObject $orderMock;
    private Data|MockObject $adyenHelperMock;
    private PaymentDataObject $paymentDataObject;
    private array $handlingSubject;

    protected function setUp(): void
    {
        $this->adyenHelperMock = $this->createMock(Data::class);
        $this->checkoutPaymentsDetailsHandler = new CheckoutPaymentsDetailsHandler($this->adyenHelperMock);

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
        // prepare Handler input.
        $responseCollection = [
            'hasOnlyGiftCards' => false,
            0 => [
                'additionalData' => [],
                'amount' => [],
                'resultCode' => 'Authorised',
            ]
        ];

        $this->paymentMock
            ->expects($this->once())
            ->method('getMethod')
            ->willReturn('any_method');

        $this->orderMock
            ->expects($this->once())
            ->method('setCanSendNewEmailFlag')
            ->with(false);

        $this->applyGenericMockExpectations();

        $this->checkoutPaymentsDetailsHandler->handle($this->handlingSubject, $responseCollection);
    }

    public function testIfBoletoSendsAnEmail()
    {
        // prepare Handler input.
        $responseCollection = [
            'hasOnlyGiftCards' => false,
            0 => [
                'additionalData' => [],
                'amount' => [],
                'resultCode' => 'Authorised',
                'pspReference' => 'ABC12345'
            ]
        ];

        $this->paymentMock
            ->expects($this->once())
            ->method('getMethod')
            ->willReturn(CheckoutPaymentsDetailsHandler::ADYEN_BOLETO);

        // for boleto it should not call this function.
        $this->orderMock
            ->expects($this->never())
            ->method('setCanSendNewEmailFlag')
            ->with(false);

        $this->applyGenericMockExpectations();

        $this->checkoutPaymentsDetailsHandler->handle($this->handlingSubject, $responseCollection);
    }

    public function testIfPartialPaymentHandlesLastPaymentResponse()
    {
        // prepare Handler input.
        $responseCollection = [
            'hasOnlyGiftCards' => false,
            0 => [
                'additionalData' => [],
                'amount' => [],
                'resultCode' => 'Authorised',
                'pspReference' => 'ABC54321',
                'paymentMethod' => [
                    'name' => 'giftcard',
                    'type' => 'Givex',
                ]
            ],
            1 => [
                'additionalData' => [
                    'paymentMethod' => 'VI',
                ],
                'amount' => [],
                'resultCode' => 'Authorised',
                'pspReference' => 'ABC12345',
                'paymentMethod' => [
                    'name' => 'card',
                    'type' => 'VI',
                ]
            ]
        ];

        $this->paymentMock
            ->expects($this->once())
            ->method('getMethod')
            ->willReturn('any_method');

        $this->orderMock
            ->expects($this->once())
            ->method('setCanSendNewEmailFlag')
            ->with(false);

        // validate whether the psp reference of the last payment method is used when setting these values.
        $this->paymentMock
            ->expects($this->once())
            ->method('setCcTransId')
            ->with('ABC12345');

        $this->paymentMock
            ->expects($this->once())
            ->method('setLastTransId')
            ->with('ABC12345');

        $this->paymentMock
            ->expects($this->once())
            ->method('setTransactionId')
            ->with('ABC12345');

        $this->paymentMock
            ->expects($this->once())
            ->method('getAdditionalInformation')
            ->with('cc_type')
            ->willReturn(null);

        $this->paymentMock
            ->expects($this->once())
            ->method('setAdditionalInformation')
            ->with('cc_type', 'VI');

        $this->paymentMock
            ->expects($this->once())
            ->method('setCcType')
            ->with('VI');

        $this->applyGenericMockExpectations();

        $this->checkoutPaymentsDetailsHandler->handle($this->handlingSubject, $responseCollection);
    }

    private function applyGenericMockExpectations() : void
    {
        $this->paymentMock
            ->expects($this->once())
            ->method('setIsTransactionPending')
            ->with(true);

        $this->paymentMock
            ->expects($this->once())
            ->method('setIsTransactionClosed')
            ->with(false);

        $this->paymentMock
            ->expects($this->once())
            ->method('setShouldCloseParentTransaction')
            ->with(false);
    }
}
