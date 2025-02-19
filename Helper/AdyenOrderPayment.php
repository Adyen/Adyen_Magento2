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

namespace Adyen\Payment\Helper;

use Adyen\Payment\Api\Data\OrderPaymentInterface;
use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Model\Notification;
use Adyen\Payment\Model\Order\Payment;
use Adyen\Payment\Model\Order\PaymentFactory;
use Adyen\Payment\Model\ResourceModel\Order\Payment as OrderPaymentResourceModel;
use Adyen\Payment\Model\ResourceModel\Order\Payment\CollectionFactory as AdyenOrderPaymentCollection;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Sales\Model\Order;

/**
 * Helper class for anything related to the adyen_order_payment entity
 *
 * Class AdyenOrderPayment
 * @package Adyen\Payment\Helper
 */
class AdyenOrderPayment extends AbstractHelper
{
    /**
     * @var AdyenLogger
     */
    protected $adyenLogger;

    /**
     * @var AdyenOrderPaymentCollection
     */
    protected $adyenOrderPaymentCollection;

    /**
     * @var Data
     */
    protected $adyenDataHelper;

    /**
     * @var ChargedCurrency
     */
    protected $adyenChargedCurrencyHelper;

    /**
     * @var OrderPaymentResourceModel
     */
    protected $orderPaymentResourceModel;

    /**
     * @var Order\PaymentFactory
     */
    protected $adyenOrderPaymentFactory;

    /**
     * @var Invoice
     */
    protected $invoiceHelper;

    /**
     * AdyenOrderPayment constructor.
     *
     * @param Context $context
     * @param AdyenLogger $adyenLogger
     * @param AdyenOrderPaymentCollection $adyenOrderPaymentCollection
     * @param Data $adyenDataHelper
     * @param ChargedCurrency $adyenChargedCurrencyHelper
     * @param OrderPaymentResourceModel $orderPaymentResourceModel
     * @param PaymentFactory $adyenOrderPaymentFactory
     * @param Invoice $invoiceHelper
     */
    public function __construct(
        Context $context,
        AdyenLogger $adyenLogger,
        AdyenOrderPaymentCollection $adyenOrderPaymentCollection,
        Data $adyenDataHelper,
        ChargedCurrency $adyenChargedCurrencyHelper,
        OrderPaymentResourceModel $orderPaymentResourceModel,
        PaymentFactory $adyenOrderPaymentFactory,
        Invoice $invoiceHelper
    ) {
        parent::__construct($context);
        $this->adyenLogger = $adyenLogger;
        $this->adyenOrderPaymentCollection = $adyenOrderPaymentCollection;
        $this->adyenDataHelper = $adyenDataHelper;
        $this->adyenChargedCurrencyHelper = $adyenChargedCurrencyHelper;
        $this->orderPaymentResourceModel = $orderPaymentResourceModel;
        $this->adyenOrderPaymentFactory = $adyenOrderPaymentFactory;
        $this->invoiceHelper = $invoiceHelper;
    }

    /**
     * Add the amount to the total_captured field of the adyen_order_payment
     *
     * @param Payment $adyenOrderPayment
     * @param float $amount
     * @return Payment
     * @throws AlreadyExistsException
     */
    public function updatePaymentTotalCaptured(Payment $adyenOrderPayment, float $amount): Payment
    {
        $newTotalCaptured = $adyenOrderPayment->getTotalCaptured() + $amount;
        $adyenOrderPayment->setTotalCaptured($newTotalCaptured);
        $this->orderPaymentResourceModel->save($adyenOrderPayment);

        return $adyenOrderPayment;
    }

    /**
     * Refresh the capture_status of the adyen_order_payment by comparing the captured amount and the full amount
     *
     * @param Payment $adyenOrderPayment
     * @param string $currency
     * @return Payment
     * @throws AlreadyExistsException
     */
    public function refreshPaymentCaptureStatus(Payment $adyenOrderPayment, string $currency): Payment
    {
        $totalCapturedCents = $this->adyenDataHelper->formatAmount($adyenOrderPayment->getTotalCaptured(), $currency);
        $amountCents = $this->adyenDataHelper->formatAmount($adyenOrderPayment->getAmount(), $currency);

        if ($totalCapturedCents < $amountCents) {
            $captureStatus = OrderPaymentInterface::CAPTURE_STATUS_PARTIAL_CAPTURE;
        } else {
            $captureStatus = OrderPaymentInterface::CAPTURE_STATUS_MANUAL_CAPTURE;
        }

        $adyenOrderPayment->setCaptureStatus($captureStatus);
        $this->orderPaymentResourceModel->save($adyenOrderPayment);

        return $adyenOrderPayment;
    }

    /**
     * Check if the full amount of the order has been finalized, either automatically by checking the
     * adyen_order_payment entries, or manually by checking the invoices
     *
     * @param Order $order
     * @return bool
     */
    public function isFullAmountFinalized(Order $order): bool
    {
        $invoiceAmountCents = 0;
        $orderAmountCurrency = $this->adyenChargedCurrencyHelper->getOrderAmountCurrency($order);
        $autoAdyenOrderPayments = $this->orderPaymentResourceModel->getLinkedAdyenOrderPayments(
            $order->getPayment()->getEntityId(),
            [OrderPaymentInterface::CAPTURE_STATUS_AUTO_CAPTURE]
        );

        // If the full amount has been automatically captured
        if ($this->compareAdyenOrderPaymentsAmount($order, $autoAdyenOrderPayments)) {
            return true;
        }

        $invoices = $order->getInvoiceCollection();
        /** @var Order\Invoice $invoice */
        foreach ($invoices as $invoice) {
            if ($this->invoiceHelper->isFullInvoiceAmountManuallyCaptured($invoice)) {
                $invoiceAmountCents += $this->adyenDataHelper->formatAmount(
                    $invoice->getGrandTotal(),
                    $invoice->getOrderCurrencyCode()
                );
            }
        }

        $orderAmountCents = $this->adyenDataHelper->formatAmount($orderAmountCurrency->getAmount(), $orderAmountCurrency->getCurrencyCode());
        return $invoiceAmountCents === $orderAmountCents;
    }

    /**
     * Check if the full amount of the order has been authorized by checking the adyen_order_payment entries
     *
     * @param Order $order
     * @return bool
     */
    public function isFullAmountAuthorized(Order $order): bool
    {
        $payment = $order->getPayment();
        $entityId = $payment->getEntityId();
        $authorisedAdyenOrderPayments = $this->orderPaymentResourceModel->getLinkedAdyenOrderPayments($entityId);

        return $this->compareAdyenOrderPaymentsAmount($order, $authorisedAdyenOrderPayments);
    }

    /**
     * Create an entry in the adyen_order_payment table based on the passed notification
     *
     * @param Order $order
     * @param Notification $notification
     * @param bool $autoCapture
     * @return Payment|null
     */
    public function createAdyenOrderPayment(Order $order, Notification $notification, bool $autoCapture): ?Payment
    {
        $adyenOrderPayment = null;
        $payment = $order->getPayment();
        $amount = $this->adyenDataHelper->originalAmount($notification->getAmountValue(),
            $notification->getAmountCurrency());
        $captureStatus = $autoCapture ? Payment::CAPTURE_STATUS_AUTO_CAPTURE : Payment::CAPTURE_STATUS_NO_CAPTURE;
        $merchantReference = $notification->getMerchantReference();
        $pspReference = $notification->getPspreference();

        try {
            $date = new \DateTime();
            $adyenOrderPayment = $this->adyenOrderPaymentFactory->create();
            $this->orderPaymentResourceModel->load($adyenOrderPayment, $pspReference, 'pspreference');
            $adyenOrderPayment->setPspreference($pspReference);
            $adyenOrderPayment->setMerchantReference($merchantReference);
            $adyenOrderPayment->setPaymentId($payment->getId());
            $adyenOrderPayment->setPaymentMethod($notification->getPaymentMethod());
            $adyenOrderPayment->setCaptureStatus($captureStatus);
            $adyenOrderPayment->setAmount($amount);
            $adyenOrderPayment->setTotalRefunded(0);
            $adyenOrderPayment->setCreatedAt($date);
            $adyenOrderPayment->setUpdatedAt($date);
            $this->orderPaymentResourceModel->save($adyenOrderPayment);
        } catch (\Exception $e) {
            $this->adyenLogger->error(sprintf(
                'While processing a notification an exception occured. The payment has already been saved in the ' .
                'adyen_order_payment table but something went wrong later. Please check your logs for potential ' .
                'error messages regarding the merchant reference (order id): %s and PSP reference: %s. ' .
                'Exception message: %s',
                $merchantReference,
                $pspReference,
                $e->getMessage()
            ));
        }

        return $adyenOrderPayment;
    }

    /**
     * Refund the adyenOrderPayment with the amount passed in the notification
     *
     * @param OrderPaymentInterface $adyenOrderPayment
     * @param Notification $notification
     * @return OrderPaymentInterface
     * @throws \Exception
     */
    public function refundAdyenOrderPayment(OrderPaymentInterface $adyenOrderPayment, Notification $notification): OrderPaymentInterface
    {
        $amountRefunded = $adyenOrderPayment->getTotalRefunded() +
            $this->adyenDataHelper->originalAmount($notification->getAmountValue(), $notification->getAmountCurrency());
        $adyenOrderPayment->setUpdatedAt(new \DateTime());
        $adyenOrderPayment->setTotalRefunded($amountRefunded);
        $adyenOrderPayment->save();

        return $adyenOrderPayment;
    }

    /**
     * Fully refund the adyenOrderPayment
     *
     * @param OrderPaymentInterface $adyenOrderPayment
     * @return OrderPaymentInterface
     * @throws \Exception
     */
    public function refundFullyAdyenOrderPayment(OrderPaymentInterface $adyenOrderPayment): OrderPaymentInterface
    {
        $amountRefunded = $adyenOrderPayment->getAmount();
        $adyenOrderPayment->setUpdatedAt(new \DateTime());
        $adyenOrderPayment->setTotalRefunded($amountRefunded);
        $adyenOrderPayment->save();

        return $adyenOrderPayment;
    }

    /**
     * Compare the total of the passed adyen order payments to the grand total of the order
     *
     * @param Order $order
     * @param array $adyenOrderPayments
     * @return bool
     */
    private function compareAdyenOrderPaymentsAmount(Order $order, array $adyenOrderPayments): bool
    {
        $adyenOrderPaymentsTotal = 0;
        $orderAmountCurrency = $this->adyenChargedCurrencyHelper->getOrderAmountCurrency($order);

        foreach ($adyenOrderPayments as $adyenOrderPayment) {
            $adyenOrderPaymentsTotal += $adyenOrderPayment[OrderPaymentInterface::AMOUNT];
        }

        $adyenOrderPaymentsTotalCents = $this->adyenDataHelper->formatAmount($adyenOrderPaymentsTotal, $orderAmountCurrency->getCurrencyCode());
        $orderAmountCents = $this->adyenDataHelper->formatAmount($orderAmountCurrency->getAmount(), $orderAmountCurrency->getCurrencyCode());

        return $adyenOrderPaymentsTotalCents === $orderAmountCents;
    }
}
