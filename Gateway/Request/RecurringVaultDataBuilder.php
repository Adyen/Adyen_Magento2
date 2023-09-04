<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2022 Adyen BV (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Gateway\Request;

use Adyen\Payment\Helper\StateData;
use Adyen\Payment\Helper\Vault;
use Adyen\Payment\Model\Ui\AdyenCcConfigProvider;
use Adyen\Payment\Model\Ui\AdyenOneclickConfigProvider;
use Magento\Payment\Gateway\Data\PaymentDataObject;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;

class RecurringVaultDataBuilder implements BuilderInterface
{
    /**
     * @var StateData
     */
    private $stateData;

    public function __construct(StateData $stateData)
    {
        $this->stateData = $stateData;
    }

    /**
     * @param array $buildSubject
     * @return array
     */
    public function build(array $buildSubject)
    {
        $requestBody = [];
        /** @var PaymentDataObject $paymentDataObject */
        $paymentDataObject = SubjectReader::readPayment($buildSubject);
        $payment = $paymentDataObject->getPayment();
        $paymentMethod = $payment->getMethodInstance();
        $order = $payment->getOrder();
        $extensionAttributes = $payment->getExtensionAttributes();
        $paymentToken = $extensionAttributes->getVaultPaymentToken();
        $details = json_decode($paymentToken->getTokenDetails() ?: '{}', true);

        // Initialize the request body with the current state data
        $requestBody = $order->getQuoteId() ? $this->stateData->getStateData((int)$order->getQuoteId()) : [];

        // For now this will only be used by tokens created trough adyen_hpp payment methods
        if (array_key_exists(Vault::TOKEN_TYPE, $details)) {
            $requestBody['recurringProcessingModel'] = $details[Vault::TOKEN_TYPE];
        }

        /*
         * allow3DS flag is required to trigger the native 3DS challenge.
         * Otherwise, shopper will be redirected to the issuer for challenge.
         */
        if ($paymentMethod->getCode() === AdyenCcConfigProvider::CC_VAULT_CODE ||
            $paymentMethod->getCode() === AdyenOneclickConfigProvider::CODE) {
            $requestBody['additionalData']['allow3DS2'] = true;
        }

        $request['body'] = $requestBody;

        return $request;
    }
}
