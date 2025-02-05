<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2025 Adyen N.V. (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Test\Unit\Observer;

use Adyen\Payment\Api\Data\OrderPaymentInterface;
use Adyen\Payment\Api\Repository\AdyenOrderPaymentRepositoryInterface;
use Adyen\Payment\Helper\AdyenOrderPayment;
use Adyen\Payment\Helper\Invoice as InvoiceHelper;
use Adyen\Payment\Helper\PaymentMethods;
use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Model\Order\Payment;
use Adyen\Payment\Observer\InvoiceObserver;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice as MagentoInvoice;
use Magento\Sales\Model\Order\StatusResolver;
use PHPUnit\Framework\MockObject\MockObject;

class InvoiceObserverTest extends AbstractAdyenTestCase
{
    protected ?InvoiceObserver $invoiceObserver;
    protected InvoiceHelper|MockObject $invoiceHelperMock;
    protected StatusResolver|MockObject $statusResolverMock;
    protected AdyenOrderPayment|MockObject $adyenOrderPaymentHelperMock;
    protected AdyenLogger|MockObject $adyenLoggerMock;
    protected PaymentMethods|MockObject $paymentMethodsHelperMock;
    protected AdyenOrderPaymentRepositoryInterface|MockObject $adyenOrderPaymentRepositoryMock;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->invoiceHelperMock = $this->createMock(InvoiceHelper::class);
        $this->statusResolverMock = $this->createMock(StatusResolver::class);
        $this->adyenOrderPaymentHelperMock = $this->createMock(AdyenOrderPayment::class);
        $this->adyenLoggerMock = $this->createMock(AdyenLogger::class);
        $this->paymentMethodsHelperMock = $this->createMock(PaymentMethods::class);
        $this->adyenOrderPaymentRepositoryMock =
            $this->createMock(AdyenOrderPaymentRepositoryInterface::class);

        $this->invoiceObserver = new InvoiceObserver(
            $this->invoiceHelperMock,
            $this->statusResolverMock,
            $this->adyenOrderPaymentHelperMock,
            $this->adyenLoggerMock,
            $this->paymentMethodsHelperMock,
            $this->adyenOrderPaymentRepositoryMock
        );
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->invoiceObserver = null;
    }

    /**
     * @return array
     */
    private static function skipObserverDataSet(): array
    {
        return [
            [
                'isAdyenPaymentMethod' => false,
                'isPaid' => false,
                'isFinalized' => false,
                'shouldExecute' => false
            ],
            [
                'isAdyenPaymentMethod' => true,
                'isPaid' => true,
                'isFinalized' => false,
                'shouldExecute' => false
            ],
            [
                'isAdyenPaymentMethod' => true,
                'isPaid' => false,
                'isFinalized' => true,
                'shouldExecute' => false
            ],
            [
                'isAdyenPaymentMethod' => true,
                'isPaid' => false,
                'isFinalized' => false,
                'shouldExecute' => true
            ]
        ];
    }

    /**
     * @dataProvider skipObserverDataSet
     *
     * @param bool $isAdyenPaymentMethod
     * @param bool $isPaid
     * @param bool $isFinalized
     * @param bool $shouldExecute
     * @return void
     * @throws AlreadyExistsException
     */
    public function testExecute(
        bool $isAdyenPaymentMethod,
        bool $isPaid,
        bool $isFinalized,
        bool $shouldExecute
    ) {
        $method = 'method_name';
        $paymentId = 1;
        $linkedAmount = 1000.00;
        $status = 'payment_review';

        $paymentMock = $this->createMock(Order\Payment::class);
        $paymentMock->expects($this->atLeastOnce())->method('getMethod')->willReturn($method);
        $paymentMock->expects($this->any())->method('getEntityId')->willReturn($paymentId);

        $orderMock = $this->createMock(Order::class);
        $orderMock->expects($this->once())->method('getPayment')->willReturn($paymentMock);

        $invoiceMock = $this->createMock(MagentoInvoice::class);
        $invoiceMock->expects($this->once())->method('getOrder')->willReturn($orderMock);

        $observerMock = $this->createMock(Observer::class);
        $observerMock->expects($this->once())
            ->method('getData')
            ->with('invoice')
            ->willReturn($invoiceMock);

        $this->paymentMethodsHelperMock->expects($this->atLeastOnce())
            ->method('isAdyenPayment')
            ->with($method)
            ->willReturn($isAdyenPaymentMethod);

        $invoiceMock->expects($this->any())
            ->method('wasPayCalled')
            ->willReturn($isPaid);

        $this->adyenOrderPaymentHelperMock->expects($this->any())
            ->method('isFullAmountFinalized')
            ->with($orderMock)
            ->willReturn($isFinalized);

        if ($shouldExecute) {
            // Assert required method calls

            $adyenOrderPayments[] = $this->createMock(Payment::class);
            $this->adyenOrderPaymentRepositoryMock->expects($this->once())
                ->method('getByPaymentId')
                ->with($paymentId, [
                    OrderPaymentInterface::CAPTURE_STATUS_NO_CAPTURE,
                    OrderPaymentInterface::CAPTURE_STATUS_PARTIAL_CAPTURE
                ])
                ->willReturn($adyenOrderPayments);

            $this->invoiceHelperMock->expects($this->once())
                ->method('linkAndUpdateAdyenInvoices')
                ->with($adyenOrderPayments[0], $invoiceMock)
                ->willReturn($linkedAmount);

            $this->adyenOrderPaymentHelperMock->expects($this->once())
                ->method('updatePaymentTotalCaptured')
                ->with($adyenOrderPayments[0], $linkedAmount);

            $this->statusResolverMock->expects($this->once())
                ->method('getOrderStatusByState')
                ->with($orderMock, Order::STATE_PAYMENT_REVIEW)
                ->willReturn($status);

            $orderMock->expects($this->once())
                ->method('setState')
                ->with(Order::STATE_PAYMENT_REVIEW);

            $orderMock->expects($this->once())
                ->method('setStatus')
                ->with($status);

            $this->adyenLoggerMock->expects($this->any())->method('addAdyenDebug');
        } else {
            // Under these circumstances, observer shouldn't intercept the code. Assert not calling methods.

            $this->invoiceHelperMock->expects($this->never())->method('linkAndUpdateAdyenInvoices');
            $this->adyenOrderPaymentHelperMock->expects($this->never())->method('updatePaymentTotalCaptured');
            $orderMock->expects($this->never())->method('setState');
            $orderMock->expects($this->never())->method('setStatus');
            $this->adyenLoggerMock->expects($this->never())->method('addAdyenDebug');
        }

        $this->invoiceObserver->execute($observerMock);
    }
}
