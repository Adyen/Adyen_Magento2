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

use Adyen\Payment\Api\Repository\AdyenOrderPaymentRepositoryInterface;
use Adyen\Payment\Helper\Creditmemo as CreditMemoHelper;
use Adyen\Payment\Model\Order\Payment;
use Adyen\Payment\Observer\CreditmemoObserver;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo as MagentoCreditmemo;
use PHPUnit\Framework\MockObject\MockObject;

class CreditmemoObserverTest extends AbstractAdyenTestCase
{
    protected ?CreditmemoObserver $creditmemoObserver;
    protected CreditmemoHelper|MockObject $creditmemoHelperMock;
    protected AdyenOrderPaymentRepositoryInterface|MockObject $adyenOrderPaymentRepositoryMock;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->creditmemoHelperMock = $this->createMock(CreditMemoHelper::class);
        $this->adyenOrderPaymentRepositoryMock =
            $this->createMock(AdyenOrderPaymentRepositoryInterface::class);

        $this->creditmemoObserver = new CreditmemoObserver(
            $this->creditmemoHelperMock,
            $this->adyenOrderPaymentRepositoryMock
        );
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->creditmemoObserver = null;
    }

    /**
     * @return void
     */
    public function testExecute()
    {
        $paymentId = 1;

        $paymentMock = $this->createMock(Order\Payment::class);
        $paymentMock->expects($this->any())->method('getEntityId')->willReturn($paymentId);

        $orderMock = $this->createMock(Order::class);
        $orderMock->expects($this->once())->method('getPayment')->willReturn($paymentMock);

        $creditmemoMock = $this->createMock(MagentoCreditmemo::class);
        $creditmemoMock->expects($this->once())->method('getOrder')->willReturn($orderMock);

        $observerMock = $this->createMock(Observer::class);
        $observerMock->expects($this->once())
            ->method('getData')
            ->with('creditmemo')
            ->willReturn($creditmemoMock);

        $adyenOrderPayments[] = $this->createMock(Payment::class);
        $this->adyenOrderPaymentRepositoryMock->expects($this->once())
            ->method('getByPaymentId')
            ->with($paymentId, [])
            ->willReturn($adyenOrderPayments);

        $this->creditmemoHelperMock->expects($this->once())
            ->method('linkAndUpdateAdyenCreditmemos')
            ->with($adyenOrderPayments[0], $creditmemoMock);

        // Assert updating Adyen creditmemo entity above
        $this->creditmemoObserver->execute($observerMock);
    }
}
