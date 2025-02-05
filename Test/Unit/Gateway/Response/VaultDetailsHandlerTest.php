<?php

namespace Test\Unit\Gateway\Response;

use Adyen\Payment\Gateway\Response\VaultDetailsHandler;
use Adyen\Payment\Helper\Vault;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Payment\Gateway\Data\OrderAdapterInterface;
use Magento\Payment\Gateway\Data\PaymentDataObject;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;

class VaultDetailsHandlerTest extends AbstractAdyenTestCase
{
    private VaultDetailsHandler $vaultDetailsHandler;
    private Payment|MockObject $paymentMock;
    private Vault|MockObject $vaultHelperMock;
    private PaymentDataObject $paymentDataObject;
    private array $handlingSubject;

    public function setUp() : void
    {
        $this->vaultHelperMock = $this->createMock(Vault::class);
        $this->vaultDetailsHandler = new VaultDetailsHandler($this->vaultHelperMock);

        $orderAdapterMock = $this->createMock(OrderAdapterInterface::class);
        $orderMock = $this->createMock(Order::class);

        $this->paymentMock = $this->createMock(Payment::class);
        $this->paymentMock->method('getOrder')->willReturn($orderMock);
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
                'additionalData' => ['someData' => 'value'],
                'amount' => [],
                'resultCode' => 'Authorised',
            ]
        ];

        // Ensure the vaultHelper's method is called once with the correct arguments.
        $this->vaultHelperMock
            ->expects($this->once())
            ->method('handlePaymentResponseRecurringDetails')
            ->with($this->paymentMock, $responseCollection[0]);

        $this->vaultDetailsHandler->handle($this->handlingSubject, $responseCollection);
    }

    public function testIfPaymentsWithoutAdditionalDataAreIgnored()
    {
        // Prepare a responseCollection without additionalData
        $responseCollection = [
            'hasOnlyGiftCards' => false,
            0 => [
                'additionalData' => [],
                'amount' => [],
                'resultCode' => 'Authorised',
            ]
        ];

        // Ensure the vaultHelper's method is NOT called since additionalData is empty
        $this->vaultHelperMock
            ->expects($this->never())
            ->method('handlePaymentResponseRecurringDetails');

        $this->vaultDetailsHandler->handle($this->handlingSubject, $responseCollection);
    }

    public function testIfGiftCardOnlyPaymentsAreIgnored()
    {
        $responseCollection = [
            'hasOnlyGiftCards' => true,
                'additionalData' => ['someData' => 'value'],
                'amount' => [],
                'resultCode' => 'Authorised',
        ];

        // Ensure the vaultHelper's method is NOT called since additionalData is empty
        $this->vaultHelperMock
            ->expects($this->never())
            ->method('handlePaymentResponseRecurringDetails');

        $this->vaultDetailsHandler->handle($this->handlingSubject, $responseCollection);
    }
}
