<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2022 Adyen NV (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Gateway\Request;

use Adyen\Payment\Model\Ui\AdyenPayByLinkConfigProvider;
use Adyen\Payment\Observer\AdyenPayByLinkDataAssignObserver;
use Magento\Framework\App\RequestInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;

class ExpiryDateDataBuilder implements BuilderInterface
{
    /**
     * @param RequestInterface $request
     */
    public function __construct(
        private readonly RequestInterface $request
    )
    { }

    /**
     * Add Pay by Link expiry date to the request
     *
     * @param array $buildSubject
     * @return array
     */
    public function build(array $buildSubject): array
    {
        $expiryDate = null;
        $request = [];
        $paymentFormFields = $this->request->getParam('payment');
        $payment = $buildSubject['payment']->getPayment();
        $paymentExpiryDate = $payment->getAdditionalInformation(AdyenPayByLinkDataAssignObserver::PBL_EXPIRY_DATE);

        if (isset($paymentFormFields[AdyenPayByLinkDataAssignObserver::PBL_EXPIRY_DATE])) {
            $expiryDate = $paymentFormFields[AdyenPayByLinkDataAssignObserver::PBL_EXPIRY_DATE];
        } elseif (isset($paymentExpiryDate)) {
            $expiryDate = $paymentExpiryDate;
        }

        if ($expiryDate) {
            $expiryDateTime = date_create_from_format(
                AdyenPayByLinkConfigProvider::DATE_TIME_FORMAT,
                $expiryDate . ' 23:59:59'
            );

            $request['body']['expiresAt'] = $expiryDateTime->format(DATE_ATOM);
        }

        return $request;
    }
}
