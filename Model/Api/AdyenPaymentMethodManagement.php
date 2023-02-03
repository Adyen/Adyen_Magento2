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

namespace Adyen\Payment\Model\Api;

class AdyenPaymentMethodManagement implements \Adyen\Payment\Api\AdyenPaymentMethodManagementInterface
{
    /**
     * @var \Adyen\Payment\Helper\PaymentMethods
     */
    protected $_paymentMethodsHelper;

    /**
     * AdyenPaymentMethodManagement constructor.
     *
     * @param \Adyen\Payment\Helper\PaymentMethods $paymentMethodsHelper
     */
    public function __construct(
        \Adyen\Payment\Helper\PaymentMethods $paymentMethodsHelper
    ) {
        $this->_paymentMethodsHelper = $paymentMethodsHelper;
    }

    /**
     * {@inheritDoc}
     */
    public function getPaymentMethods($cartId, \Magento\Quote\Api\Data\AddressInterface $billingAddress = null, ?string $shopperLocale = null)
    {
        // if billingAddress is provided use this country
        $country = null;
        if ($billingAddress) {
            $country = $billingAddress->getCountryId();
        }

        return $this->_paymentMethodsHelper->getPaymentMethods($cartId, $country, $shopperLocale);
    }
}
