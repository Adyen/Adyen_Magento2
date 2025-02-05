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

use Adyen\Payment\Api\GuestAdyenPaymentsDetailsInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\NotFoundException;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

class GuestAdyenPaymentsDetails implements GuestAdyenPaymentsDetailsInterface
{
    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param AdyenPaymentsDetails $adyenPaymentsDetails
     * @param MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly AdyenPaymentsDetails $adyenPaymentsDetails,
        private readonly MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId
    ) { }

    /**
     * @param string $payload
     * @param string $orderId
     * @param string $cartId
     * @return string
     * @throws AlreadyExistsException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @api
     */
    public function initiate(string $payload, string $orderId, string $cartId): string
    {
        $order = $this->orderRepository->get(intval($orderId));
        $quoteId = $this->maskedQuoteIdToQuoteId->execute($cartId);

        if ($order->getQuoteId() != $quoteId) {
            throw new NotFoundException(
                __("The entity that was requested doesn't exist. Verify the entity and try again.")
            );
        }

        return $this->adyenPaymentsDetails->initiate($payload, $orderId);
    }
}
