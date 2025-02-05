<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2023 Adyen N.V. (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Model\Api;

use Adyen\AdyenException;
use Adyen\Payment\Api\GuestAdyenPaymentMethodManagementInterface;
use Adyen\Payment\Helper\PaymentMethods;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;

class GuestAdyenPaymentMethodManagement implements GuestAdyenPaymentMethodManagementInterface
{
    public function __construct(
        private readonly PaymentMethods $paymentMethodsHelper,
        private readonly MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId
    ) { }

    /**
     * @param string $cartId
     * @param string|null $shopperLocale
     * @param string|null $country
     * @param string|null $channel
     * @return string
     * @throws AdyenException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getPaymentMethods(
        string $cartId,
        ?string $shopperLocale = null,
        ?string $country = null,
        ?string $channel = null
    ): string {
        $quoteId = $this->maskedQuoteIdToQuoteId->execute($cartId);

        return $this->paymentMethodsHelper->getPaymentMethods($quoteId, $country, $shopperLocale, $channel);
    }
}
