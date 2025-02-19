<?php
/**
 *
 * Adyen Payment Module
 *
 * Copyright (c) 2023 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Gateway\Response;

use Adyen\AdyenException;
use Adyen\Payment\Helper\PaymentResponseHandler;
use Adyen\Payment\Helper\Quote;
use Adyen\Payment\Helper\Vault;
use Adyen\Payment\Logger\AdyenLogger;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\StatusResolver;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Response\HandlerInterface;

class PaymentPosCloudHandler implements HandlerInterface
{
    private AdyenLogger $adyenLogger;
    private Vault $vaultHelper;
    private StatusResolver $statusResolver;
    private Quote $quoteHelper;

    public function __construct(
        AdyenLogger $adyenLogger,
        Vault $vaultHelper,
        StatusResolver $statusResolver,
        Quote $quoteHelper
    ) {
        $this->adyenLogger = $adyenLogger;
        $this->vaultHelper = $vaultHelper;
        $this->statusResolver = $statusResolver;
        $this->quoteHelper = $quoteHelper;
    }

    public function handle(array $handlingSubject, array $response)
    {
        $paymentDataObject = SubjectReader::readPayment($handlingSubject);
        $payment = $paymentDataObject->getPayment();
        if (!empty($response['async'])) {
            // Async payment request, save Order
            $order = $payment->getOrder();
            $message = __('Pos payment initiated');
            $order->addCommentToStatusHistory($message);
            $order->save();

            return;
        }

        $errorCondition = $response
            ['SaleToPOIResponse']
            ['TransactionStatusResponse']
            ['Response']
            ['ErrorCondition'] ?? null;
        if ($errorCondition === 'InProgress') {
            // Payment in progress
            return;
        }

        $paymentResponse = $response['SaleToPOIResponse']['PaymentResponse']
            ?? $response['SaleToPOIResponse']['TransactionStatusResponse']['RepeatedMessageResponse']['RepeatedResponseMessageBody']['PaymentResponse'];

        // set transaction not to processing by default wait for notification
        $payment->setIsTransactionPending(true);

        // do not send order confirmation mail
        $payment->getOrder()->setCanSendNewEmailFlag(false);
        $resultCode = null;

        if (!empty($paymentResponse) && isset($paymentResponse['Response']['Result'])) {
            $resultCode = $paymentResponse['Response']['Result'];
            $payment->setAdditionalInformation('resultCode', $resultCode);
        }

        if (!empty($paymentResponse['Response']['AdditionalResponse']))
        {
            $paymentResponseDecoded = json_decode(
                base64_decode($paymentResponse['Response']['AdditionalResponse']),
                true
            );
            $payment->setAdditionalInformation('additionalData', $paymentResponseDecoded['additionalData']);

            if (isset($paymentResponseDecoded['additionalData']['pspReference'])) {
                $payment->setAdditionalInformation('pspReference', $paymentResponseDecoded['additionalData']['pspReference']);
            }

            $this->vaultHelper->handlePaymentResponseRecurringDetails(
                $payment->getOrder()->getPayment(),
                $paymentResponseDecoded
            );
        }
        // set transaction(status)
        if (!empty($paymentResponse['PaymentResult']['PaymentAcquirerData']['AcquirerTransactionID']['TransactionID']))
        {
            $pspReference = $paymentResponse['PaymentResult']['PaymentAcquirerData']
            ['AcquirerTransactionID']['TransactionID'];
            $payment->setTransactionId($pspReference);
            // set transaction(payment)
        } else {
            $this->adyenLogger->error("Missing POS Transaction ID");
            throw new AdyenException("Missing POS Transaction ID");
        }

        // do not close transaction so you can do a cancel() and void
        $payment->setIsTransactionClosed(false);
        $payment->setShouldCloseParentTransaction(false);

        // Transaction is final
        $payment->unsAdditionalInformation('pos_request');

        if ($resultCode === PaymentResponseHandler::POS_SUCCESS) {
            $order = $payment->getOrder();
            $status = $this->statusResolver->getOrderStatusByState(
                $payment->getOrder(),
                Order::STATE_NEW
            );
            $order->setState(Order::STATE_NEW);
            $order->setStatus($status);
            $message = __("Pos payment authorized");
            $order->addCommentToStatusHistory($message, $status);
            $order->save();
            $this->quoteHelper->disableQuote($order->getQuoteId());
        }
    }
}
