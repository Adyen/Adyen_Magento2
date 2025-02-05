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

namespace Adyen\Payment\Model\Api;

use Adyen\Payment\Api\GuestAdyenGiftcardInterface;
use Adyen\Payment\Helper\GiftcardPayment;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;

class GuestAdyenGiftcard implements GuestAdyenGiftcardInterface
{
    /**
     * @param GiftcardPayment $giftcardPaymentHelper
     * @param MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId
     */
    public function __construct(
        private readonly GiftcardPayment $giftcardPaymentHelper,
        private readonly MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId
    ) { }

    /**
     * @param string $cartId
     * @return string
     * @throws NoSuchEntityException
     */
    public function getRedeemedGiftcards(string $cartId): string
    {
        $quoteId = $this->maskedQuoteIdToQuoteId->execute($cartId);

        return $this->giftcardPaymentHelper->fetchRedeemedGiftcards($quoteId);
    }
}
