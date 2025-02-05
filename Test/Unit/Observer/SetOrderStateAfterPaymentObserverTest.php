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

namespace Adyen\Payment\Test\Unit\Observer;

use Adyen\Payment\Helper\Config;
use Adyen\Payment\Helper\PaymentResponseHandler;
use Adyen\Payment\Model\Method\Adapter;
use Adyen\Payment\Observer\SetOrderStateAfterPaymentObserver;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Model\MethodInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\StatusResolver;

class SetOrderStateAfterPaymentObserverTest extends AbstractAdyenTestCase
{
    private $setOrderStateAfterPaymentObserver;
    private $observerMock;
    private $paymentMock;
    private $orderMock;
    private $statusResolverMock;
    private $configHelperMock;
    private $orderRepositoryMock;

    const STORE_ID = 1;

    public function setUp(): void
    {
        $this->observerMock = $this->createMock(Observer::class);
        $this->paymentMock = $this->createMock(Payment::class);
        $this->orderMock = $this->createMock(Order::class);
        $this->statusResolverMock = $this->createMock(Order\StatusResolver::class);
        $this->configHelperMock = $this->createMock(Config::class);
        $this->orderRepositoryMock = $this->createMock(OrderRepositoryInterface::class);

        $paymentMethodInstanceMock = $this->createMock(Adapter::class);
        $this->paymentMock->method('getMethodInstance')->willReturn($paymentMethodInstanceMock);
        $this->paymentMock->method('getOrder')->willReturn($this->orderMock);
        $this->observerMock->method('getData')->with('payment')->willReturn($this->paymentMock);
        $this->statusResolverMock->method('getOrderStatusByState')
            ->willReturn(Order::STATE_PENDING_PAYMENT);

        $this->setOrderStateAfterPaymentObserver = new SetOrderStateAfterPaymentObserver(
            $this->statusResolverMock,
            $this->configHelperMock,
            $this->orderRepositoryMock
        );
    }

    private static function resultCodeProvider(): array
    {
        return [
            [
                'resultCode' => PaymentResponseHandler::REDIRECT_SHOPPER,
                'action' => ['type' => 'TYPE_PLACEHOLDER']
            ],
            [
                'resultCode' => PaymentResponseHandler::CHALLENGE_SHOPPER,
                'action' => ['type' => 'TYPE_PLACEHOLDER']
            ],
            [
                'resultCode' => PaymentResponseHandler::PENDING,
                'action' => ['type' => 'TYPE_PLACEHOLDER']
            ],
            [
                'resultCode' => PaymentResponseHandler::IDENTIFY_SHOPPER,
                'action' => ['type' => 'TYPE_PLACEHOLDER']
            ],
            [
                'resultCode' => PaymentResponseHandler::AUTHORISED,
                'action' => null,
                'changeStatus' => false
            ]
        ];
    }

    /**
     * @dataProvider resultCodeProvider
     * @return void
     * @throws LocalizedException
     */
    public function testExecute($resultCode, $action, $changeStatus = true)
    {
        $this->paymentMock->method('getAdditionalInformation')->will(
            $this->returnValueMap([
                ['resultCode', $resultCode],
                ['action', $action]
            ])
        );

        if ($changeStatus) {
            $this->orderMock->expects($this->once())->method('setState');
            $this->orderRepositoryMock->expects($this->once())->method('save');
        } else {
            $this->orderMock->expects($this->never())->method('setState');
            $this->orderRepositoryMock->expects($this->never())->method('save');
        }

        $this->setOrderStateAfterPaymentObserver->execute($this->observerMock);
    }

    public function testPosPaymentSuccessfulExecute()
    {
        $order = $this->createMock(Order::class);
        $order->expects($this->once())->method('setState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $order->expects($this->once())->method('setStatus')->willReturn('pending');
        $order->method('getStoreId')->willReturn(self::STORE_ID);

        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn('adyen_pos_cloud');
        $payment->method('getOrder')->willReturn($order);

        $statusResolver = $this->createMock(StatusResolver::class);
        $statusResolver->method('getOrderStatusByState')
            ->with($order,  Order::STATE_PENDING_PAYMENT)
            ->willReturn('pending');

        $configHelperMock = $this->createMock(Config::class);
        $configHelperMock->expects($this->once())
            ->method('getAdyenPosCloudPaymentAction')
            ->with(self::STORE_ID)
            ->willReturn(MethodInterface::ACTION_ORDER);

        $eventObserver = $this->createMock(Observer::class);
        $eventObserver->method('getData')->with('payment')->willReturn($payment);

        $observer = new SetOrderStateAfterPaymentObserver(
            $statusResolver,
            $configHelperMock,
            $this->orderRepositoryMock
        );

        $observer->execute($eventObserver);
    }
}
