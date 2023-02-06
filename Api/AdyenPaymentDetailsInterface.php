<?php
/**
 *
 * Adyen Payment Module
 *
 * Copyright (c) 2023 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Api;

/**
 * Interface for performing an Adyen payment details call
 */
interface AdyenPaymentDetailsInterface
{
    /**
     * @param string $payload
     * @return string
     */
    public function initiate(string $payload): string;
}
