<?php
/**
 *
 * Adyen Payment Module
 *
 * Copyright (c) 2022 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Helper\Webhook;

use Adyen\Payment\Api\Data\OrderPaymentInterface;
use Adyen\Payment\Helper\AdyenOrderPayment;
use Adyen\Payment\Helper\Invoice;
use Adyen\Payment\Helper\Order;
use Adyen\Payment\Helper\PaymentMethods;
use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Model\Notification;
use Adyen\Payment\Model\Order\PaymentFactory;
use Adyen\Webhook\PaymentStates;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Model\Order as MagentoOrder;
use Magento\Sales\Model\Order\Invoice as MagentoInvoice;
use Magento\Sales\Model\Order\InvoiceFactory as MagentoInvoiceFactory;

class CaptureWebhookHandler implements WebhookHandlerInterface
{
    public function __construct(
        private readonly Invoice $invoiceHelper,
        private readonly PaymentFactory $adyenOrderPaymentFactory,
        private readonly AdyenOrderPayment $adyenOrderPaymentHelper,
        private readonly AdyenLogger $adyenLogger,
        private readonly MagentoInvoiceFactory $magentoInvoiceFactory,
        private readonly Order $orderHelper,
        private readonly PaymentMethods $paymentMethodsHelper,
        private readonly InvoiceRepositoryInterface $invoiceRepository
    ) { }

    /**
     * @param MagentoOrder $order
     * @param Notification $notification
     * @param string $transitionState
     * @return MagentoOrder
     * @throws AlreadyExistsException
     */
    public function handleWebhook(MagentoOrder $order, Notification $notification, string $transitionState): MagentoOrder
    {
        $isAutoCapture = $this->paymentMethodsHelper->isAutoCapture($order, $notification->getPaymentMethod());

        if ($isAutoCapture || $transitionState !== PaymentStates::STATE_PAID) {
            $this->adyenLogger->addAdyenNotification(sprintf(
                'Capture webhook for order %s was not handled due to AutoCapture: %b, OR TransitionState: %s',
                $order->getIncrementId(),
                $isAutoCapture,
                $transitionState
            ),
            [
                'pspReference' => $notification->getPspreference(),
                'merchantReference' => $notification->getMerchantReference()
            ]);

            return $order;
        }

        // TODO Get functionality out of the invoiceHelper function, so we don't have to fetch the order from the db
        $adyenInvoice = $this->invoiceHelper->handleCaptureWebhook($order, $notification);
        // Refresh the order by fetching it from the db
        $order = $this->orderHelper->fetchOrderByIncrementId($notification);
        $adyenOrderPayment = $this->adyenOrderPaymentFactory->create()->load($adyenInvoice->getAdyenPaymentOrderId(), OrderPaymentInterface::ENTITY_ID);
        $this->adyenOrderPaymentHelper->refreshPaymentCaptureStatus($adyenOrderPayment, $notification->getAmountCurrency());
        $this->adyenLogger->addAdyenNotification(
            sprintf(
                'adyen_invoice %s linked to invoice %s and adyen_order_payment %s was updated',
                $adyenInvoice->getEntityId(),
                $adyenInvoice->getInvoiceId(),
                $adyenInvoice->getAdyenPaymentOrderId()
            ),
            [
                'pspReference' => $notification->getPspreference(),
                'merchantReference' => $notification->getMerchantReference()
            ]
        );

        $magentoInvoice = $this->invoiceRepository->get($adyenInvoice->getInvoiceId());

        $this->adyenLogger->addAdyenNotification(
            sprintf('Notification %s updated invoice {invoiceId}', $notification->getEntityId()),
            array_merge(
                $this->adyenLogger->getInvoiceContext($magentoInvoice),
                [
                    'pspReference' => $notification->getPspreference(),
                    'merchantReference' => $notification->getMerchantReference()
                ]
            )
        );

        return $this->orderHelper->finalizeOrder($order, $notification);
    }
}
