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

use Adyen\Payment\Api\GuestAdyenOrderPaymentStatusInterface;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Helper\PaymentResponseHandler;
use Adyen\Payment\Logger\AdyenLogger;
use Magento\Framework\Exception\NotFoundException;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

class GuestAdyenOrderPaymentStatus implements GuestAdyenOrderPaymentStatusInterface
{
    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param AdyenLogger $adyenLogger
     * @param Data $adyenHelper
     * @param PaymentResponseHandler $paymentResponseHandler
     * @param MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId
     */
    public function __construct(
        protected readonly OrderRepositoryInterface $orderRepository,
        protected readonly AdyenLogger $adyenLogger,
        protected readonly Data $adyenHelper,
        private readonly PaymentResponseHandler $paymentResponseHandler,
        private readonly MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId
    ) { }

    public function getOrderPaymentStatus(string $orderId, string $cartId): string
    {
        $quoteId = $this->maskedQuoteIdToQuoteId->execute($cartId);

        $order = $this->orderRepository->get($orderId);

        if (intval($order->getQuoteId()) !== $quoteId) {
            $errorMessage = sprintf("Order for ID %s not found!", $orderId);
            $this->adyenLogger->error($errorMessage);

            throw new NotFoundException(
                __("The entity that was requested doesn't exist. Verify the entity and try again.")
            );
        }

        $payment = $order->getPayment();
        $additionalInformation = $payment->getAdditionalInformation();

        if (empty($additionalInformation['resultCode'])) {
            $this->adyenLogger->info('resultCode is empty in the payment\'s additional information');
            return json_encode(
                $this->paymentResponseHandler->formatPaymentResponse(PaymentResponseHandler::ERROR)
            );
        }

        return json_encode($this->paymentResponseHandler->formatPaymentResponse(
            $additionalInformation['resultCode'],
            !empty($additionalInformation['action']) ? $additionalInformation['action'] : null,
            !empty($additionalInformation['additionalData']) ? $additionalInformation['additionalData'] : null
        ));
    }
}
