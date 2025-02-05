<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2021 Adyen BV (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Observer;

use Adyen\Payment\Api\Repository\AdyenOrderPaymentRepositoryInterface;
use Adyen\Payment\Helper\AdyenOrderPayment;
use Adyen\Payment\Api\Data\OrderPaymentInterface;
use Adyen\Payment\Helper\Invoice as InvoiceHelper;
use Adyen\Payment\Logger\AdyenLogger;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\StatusResolver;
use Adyen\Payment\Helper\PaymentMethods;

class InvoiceObserver implements ObserverInterface
{
    /**
     * @param InvoiceHelper $invoiceHelper
     * @param StatusResolver $statusResolver
     * @param AdyenOrderPayment $adyenOrderPaymentHelper
     * @param AdyenLogger $logger
     * @param PaymentMethods $paymentMethodsHelper
     * @param AdyenOrderPaymentRepositoryInterface $adyenOrderPaymentRepository
     */
    public function __construct(
        private readonly InvoiceHelper $invoiceHelper,
        private readonly StatusResolver $statusResolver,
        private readonly AdyenOrderPayment $adyenOrderPaymentHelper,
        private readonly AdyenLogger $logger,
        private readonly PaymentMethods $paymentMethodsHelper,
        private readonly AdyenOrderPaymentRepositoryInterface $adyenOrderPaymentRepository
    ) { }

    /**
     * Link all adyen_invoices to the appropriate magento invoice and set the order to PROCESSING to allow
     * further invoices to be generated
     *
     * @param Observer $observer
     * @throws AlreadyExistsException
     */
    public function execute(Observer $observer): void
    {
        /** @var Invoice $invoice */
        $invoice = $observer->getData('invoice');
        $order = $invoice->getOrder();
        $payment = $order->getPayment();
        $method = $payment->getMethod();

        // If payment is not originating from Adyen or invoice has already been paid or full amount is finalized, exit observer
        if (!$this->paymentMethodsHelper->isAdyenPayment($method) || $invoice->wasPayCalled() || $this->adyenOrderPaymentHelper->isFullAmountFinalized($order)) {
            return;
        }

        $this->logger->addAdyenDebug(
            'Event sales_order_invoice_save_after for invoice {invoiceId} will be handled',
            array_merge($this->logger->getInvoiceContext($invoice), $this->logger->getOrderContext($order))
        );

        $adyenOrderPayments = $this->adyenOrderPaymentRepository->getByPaymentId(
            $payment->getEntityId(),
            [OrderPaymentInterface::CAPTURE_STATUS_NO_CAPTURE, OrderPaymentInterface::CAPTURE_STATUS_PARTIAL_CAPTURE]
        );

        foreach ($adyenOrderPayments as $adyenOrderPayment) {
            $linkedAmount = $this->invoiceHelper->linkAndUpdateAdyenInvoices($adyenOrderPayment, $invoice);
            $this->adyenOrderPaymentHelper->updatePaymentTotalCaptured($adyenOrderPayment, $linkedAmount);
        }

        $status = $this->statusResolver->getOrderStatusByState($order, Order::STATE_PAYMENT_REVIEW);
        $order->setState(Order::STATE_PAYMENT_REVIEW);
        $order->setStatus($status);

        $this->logger->addAdyenDebug(
            'Event sales_order_invoice_save_after for invoice {invoiceId} was handled',
            array_merge($this->logger->getInvoiceContext($invoice), $this->logger->getOrderContext($order))
        );
    }
}
