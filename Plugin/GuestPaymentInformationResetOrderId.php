<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2019 Adyen BV (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Plugin;

use Adyen\Payment\Helper\PaymentMethods;
use Adyen\Payment\Logger\AdyenLogger;
use Exception;
use Magento\Checkout\Api\GuestPaymentInformationManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;

class GuestPaymentInformationResetOrderId
{
    /**
     * GuestPaymentInformationResetOrderId constructor.
     * @param CartRepositoryInterface $quoteRepository
     * @param PaymentMethods $paymentMethodsHelper
     * @param AdyenLogger $adyenLogger
     * @param MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId
     */
    public function __construct(
        protected readonly CartRepositoryInterface $quoteRepository,
        protected readonly PaymentMethods $paymentMethodsHelper,
        protected readonly AdyenLogger $adyenLogger,
        protected readonly MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId
    ) { }

    /**
     * @param GuestPaymentInformationManagementInterface $subject
     * @param $cartId
     * @return null
     */
    public function beforeSavePaymentInformationAndPlaceOrder(
        GuestPaymentInformationManagementInterface $subject,
        $cartId
    ) {
        try {
            $quoteId = $this->maskedQuoteIdToQuoteId->execute($cartId);
            $quote = $this->quoteRepository->get($quoteId);
            $method = $quote->getPayment()->getMethod();

            if (isset($method) && $this->paymentMethodsHelper->isAdyenPayment($method)) {
                $quote->setReservedOrderId(null);
            }
        } catch (Exception $e) {
            $this->adyenLogger->error("Failed to reset reservedOrderId for guest shopper" . $e->getMessage());
        }
        return null;
    }
}
