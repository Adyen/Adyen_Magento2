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

use Adyen\AdyenException;
use Adyen\Payment\Api\GuestAdyenDonationsInterface;
use Adyen\Payment\Model\Sales\OrderRepository;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;

class GuestAdyenDonations implements GuestAdyenDonationsInterface
{
    /**
     * @param AdyenDonations $adyenDonationsModel
     * @param OrderRepository $orderRepository
     * @param MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId
     */
    public function __construct(
        private readonly AdyenDonations $adyenDonationsModel,
        private readonly OrderRepository $orderRepository,
        private readonly MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId
    ) { }

    /**
     * @param string $cartId
     * @param string $payload
     * @return void
     * @throws AdyenException
     * @throws LocalizedException
     */
    public function donate(string $cartId, string $payload): void
    {
        $quoteId = $this->maskedQuoteIdToQuoteId->execute($cartId);

        $order = $this->orderRepository->getOrderByQuoteId($quoteId);

        if (!$order) {
            throw new AdyenException('Donation Failed!');
        }

        $this->adyenDonationsModel->makeDonation($payload, $order);
    }
}
