<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2024 Adyen N.V. (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Model\Api;

use Adyen\Payment\Api\GuestAdyenPosCloudInterface;
use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Model\Sales\OrderRepository;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Payment\Gateway\Command\CommandPoolInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectFactoryInterface;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;

class GuestAdyenPosCloud extends AdyenPosCloud implements GuestAdyenPosCloudInterface
{
    /**
     * @param CommandPoolInterface $commandPool
     * @param OrderRepository $orderRepository
     * @param PaymentDataObjectFactoryInterface $paymentDataObjectFactory
     * @param AdyenLogger $adyenLogger
     * @param MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId
     */
    public function __construct(
        CommandPoolInterface $commandPool,
        OrderRepository $orderRepository,
        PaymentDataObjectFactoryInterface $paymentDataObjectFactory,
        AdyenLogger $adyenLogger,
        private readonly MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId
    ) {
        parent::__construct(
            $commandPool,
            $orderRepository,
            $paymentDataObjectFactory,
            $adyenLogger
        );
    }

    /**
     * @param string $cartId
     * @return void
     * @throws NoSuchEntityException
     */
    public function payByCart(string $cartId): void
    {
        $quoteId = $this->maskedQuoteIdToQuoteId->execute($cartId);
        $order = $this->orderRepository->getOrderByQuoteId($quoteId);
        $this->execute($order);
    }
}
