<?php
/**
 *
 * Adyen Payment Module
 *
 * Copyright (c) 2024 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Observer;

use Adyen\Payment\Helper\Config;
use Adyen\Payment\Helper\PaymentResponseHandler;
use Adyen\Payment\Model\Method\Adapter;
use Exception;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Model\MethodInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\StatusResolver;

class SetOrderStateAfterPaymentObserver implements ObserverInterface
{
    public function __construct(
        private readonly StatusResolver $statusResolver,
        private readonly Config $configHelper,
        private readonly OrderRepositoryInterface $orderRepository
    ) { }

    /**
     * @throws Exception
     */
    public function execute(Observer $observer): void
    {
        /** @var Payment $payment */
        $payment = $observer->getData('payment');

        $paymentMethod = $payment->getMethod();

        if ($paymentMethod === 'adyen_pos_cloud') {
            $this->handlePosPayment($payment);
        } else {
            $this->handlePaymentWithAction($payment);
        }
    }

    /**
     * @param Payment $payment
     * @return void
     * @throws Exception
     */
    private function handlePosPayment(Payment $payment): void
    {
        $storeId = $payment->getOrder()->getStoreId();
        $posPaymentAction = $this->configHelper->getAdyenPosCloudPaymentAction($storeId);

        if ($posPaymentAction === MethodInterface::ACTION_ORDER) {
            $order = $payment->getOrder();
            $status = $this->statusResolver->getOrderStatusByState(
                $payment->getOrder(),
                Order::STATE_PENDING_PAYMENT
            );
            $order->setState(Order::STATE_PENDING_PAYMENT);
            $order->setStatus($status);
            $message = __("Pos payment initiated and waiting for payment");
            $order->addCommentToStatusHistory($message, $status);
            $this->orderRepository->save($order);
        }
    }

    /**
     * @param Payment $payment
     * @return void
     * @throws LocalizedException
     */
    private function handlePaymentWithAction(Payment $payment): void
    {
        $methodInstance = $payment->getMethodInstance();

        if ($methodInstance instanceof Adapter) {
            $order = $payment->getOrder();
            $resultCode = $payment->getAdditionalInformation('resultCode');
            $action = $payment->getAdditionalInformation('action');

            /*
             * Set order status and state to pending_payment if an addition action is required.
             * This status will be changed when the shopper completes the action or returns from a redirection.
             */
            if (in_array($resultCode, PaymentResponseHandler::ACTION_REQUIRED_STATUSES) &&
            !is_null($action)
            ) {
                $actionType = $action['type'];

                $status = $this->statusResolver->getOrderStatusByState(
                    $payment->getOrder(),
                    Order::STATE_PENDING_PAYMENT
                );
                $order->setState(Order::STATE_PENDING_PAYMENT);
                $order->setStatus($status);

                $message = sprintf(
                    __("%s action is required to complete the payment.<br>Result code: %s"),
                    ucfirst($actionType),
                    $resultCode
                );

                $order->addCommentToStatusHistory($message, $status);
                $this->orderRepository->save($order);
            }
        }
    }
}
