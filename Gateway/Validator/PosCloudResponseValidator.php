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

namespace Adyen\Payment\Gateway\Validator;

use Adyen\Payment\Helper\Data;
use Adyen\Payment\Logger\AdyenLogger;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;

class PosCloudResponseValidator extends AbstractValidator
{
    private AdyenLogger $adyenLogger;
    private Data $adyenHelper;

    public function __construct(
        ResultInterfaceFactory $resultFactory,
        AdyenLogger $adyenLogger,
        Data $adyenHelper
    ) {
        $this->adyenLogger = $adyenLogger;
        $this->adyenHelper = $adyenHelper;
        parent::__construct($resultFactory);
    }

    public function validate(array $validationSubject): ResultInterface
    {
        $response = SubjectReader::readResponse($validationSubject);
        $paymentDataObjectInterface = SubjectReader::readPayment($validationSubject);
        $payment = $paymentDataObjectInterface->getPayment();

        $this->adyenLogger->addAdyenDebug(json_encode($response));

        // Do not validate (async) payment requests
        if (!empty($response['async'])) {
            // Async payment request
            return $this->createResult(true, []);
        }

        // Do not validate in progress status response
        $errorCondition = $response
            ['SaleToPOIResponse']
            ['TransactionStatusResponse']
            ['Response']
            ['ErrorCondition'] ?? null;
        if ($errorCondition === 'InProgress') {
            // Payment in progress
            return $this->createResult(true, []);
        }

        // Check for errors
        if (!empty($response['error'])) {
            if (!empty($response['code']) && $response['code'] == CURLE_OPERATION_TIMEOUTED) {
                // If the initiate call resulted in a timeout, do a status call(try to place an order)
                return $this->createResult(true, []);
            } else {
                // There is an error which is not a timeout, stop the transaction and show the error
                throw new LocalizedException(__($response['error']));
            }
        } else {
            // We have a paymentResponse from the terminal
            $paymentResponse = $response['SaleToPOIResponse']['PaymentResponse']
                ?? $response['SaleToPOIResponse']['TransactionStatusResponse']['RepeatedMessageResponse']['RepeatedResponseMessageBody']['PaymentResponse'];
        }

        if (!empty($paymentResponse) && $paymentResponse['Response']['Result'] != 'Success') {
            $errorMsg = __($paymentResponse['Response']['ErrorCondition']);
            $this->adyenLogger->error($errorMsg);
            throw new LocalizedException(__("The transaction could not be completed."));
        }

        if (!empty($paymentResponse['PaymentReceipt'])) {
            $formattedReceipt = $this->adyenHelper->formatTerminalAPIReceipt($paymentResponse['PaymentReceipt']);
            $payment->setAdditionalInformation('receipt', $formattedReceipt);
        }
        return $this->createResult(true, []);
    }
}
