<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2023 Adyen BV (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Api;

use Magento\Quote\Api\Data\AddressInterface;

/**
 * Interface for fetching payment methods from Adyen for guest customers
 */
interface GuestAdyenPaymentMethodManagementInterface
{
    /**
     * Fetches Adyen payment methods for guest customers
     *
     * @param string $cartId
     * @param string|null $shopperLocale
     * @return string
     */
    public function getPaymentMethods(string $cartId, ?string $shopperLocale = null): string;
}
