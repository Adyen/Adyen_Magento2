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

namespace Adyen\Payment\Helper;

use Adyen\Payment\Model\ResourceModel\StateData\Collection as StateDataCollection;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Framework\Pricing\Helper\Data as PricingData;

class GiftcardPayment
{
    const VALID_GIFTCARD_REQUEST_FIELDS = [
        'merchantAccount',
        'shopperReference',
        'shopperEmail',
        'telephoneNumber',
        'shopperName',
        'countryCode',
        'shopperLocale',
        'shopperIP',
        'billingAddress',
        'deliveryAddress',
        'amount',
        'reference',
        'additionalData',
        'fraudOffset',
        'browserInfo',
        'shopperInteraction',
        'returnUrl',
        'channel',
        'origin'
    ];

    private StateDataCollection $adyenStateData;
    private Data $adyenHelper;
    private PricingData $pricingDataHelper;
    private CartRepositoryInterface $quoteRepository;

    public function __construct(
        StateDataCollection $adyenStateData,
        Data $adyenHelper,
        PricingData $pricingDataHelper,
        CartRepositoryInterface $quoteRepository
    ) {
        $this->adyenStateData = $adyenStateData;
        $this->adyenHelper = $adyenHelper;
        $this->pricingDataHelper = $pricingDataHelper;
        $this->quoteRepository = $quoteRepository;
    }

    public function buildGiftcardPaymentRequest(
        array $request,
        array $orderData,
        array $stateData,
        int $amount
    ): array {
        $giftcardPaymentRequest = [];

        foreach (self::VALID_GIFTCARD_REQUEST_FIELDS as $key) {
            if (isset($request[$key])) {
                $giftcardPaymentRequest[$key] = $request[$key];
            }
        }

        $giftcardPaymentRequest['paymentMethod'] = $stateData['paymentMethod'];
        $giftcardPaymentRequest['amount']['value'] = $amount;

        $giftcardPaymentRequest['order']['pspReference'] = $orderData['pspReference'];
        $giftcardPaymentRequest['order']['orderData'] = $orderData['orderData'];

        return $giftcardPaymentRequest;
    }

    public function getQuoteGiftcardDiscount(CartInterface $quote): int
    {
        $formattedOrderAmount = $this->adyenHelper->formatAmount(
            $quote->getGrandTotal(),
            $quote->getCurrency()
        );

        $totalGiftcardBalance = $this->getQuoteGiftcardTotalBalance($quote->getId());

        if ($totalGiftcardBalance > 0) {
            if ($totalGiftcardBalance > $formattedOrderAmount) {
                return $formattedOrderAmount;
            } else {
                return $totalGiftcardBalance;
            }
        } else {
            return 0;
        }
    }

    public function getQuoteGiftcardTotalBalance(int $quoteId): int
    {
        $stateDataCollection = $this->adyenStateData->getStateDataRowsWithQuoteId($quoteId);
        $stateDataArray = $stateDataCollection->getData();

        $totalBalance = 0;

        foreach ($stateDataArray as $stateData) {
            $state = $stateData['state_data'] ?? null;
            if (!is_string($state)) {
                continue;
            }

            $stateData = json_decode($state, true);
            $giftCardValue = $stateData['giftcard']['balance']['value'] ?? null;
            if (!is_numeric($giftCardValue)) {
                continue;
            }

            if (isset($stateData['paymentMethod']['type']) ||
                isset($stateData['paymentMethod']['brand']) ||
                $stateData['paymentMethod']['type'] === 'giftcard') {
                $totalBalance += $giftCardValue;
            }
        }

        return $totalBalance;
    }

    public function fetchRedeemedGiftcards(int $quoteId): string
    {
        $quote = $this->quoteRepository->get($quoteId);

        $stateDataArray = $this->adyenStateData->getStateDataRowsWithQuoteId($quote->getId(), 'ASC');
        $currency = $quote->getQuoteCurrencyCode();
        $formattedOrderAmount = $this->adyenHelper->formatAmount(
            $quote->getGrandTotal(),
            $currency
        );
        $giftcardDiscount = $this->getQuoteGiftcardDiscount($quote);

        $totalDiscount = $this->pricingDataHelper->currency(
            $this->adyenHelper->originalAmount(
                $giftcardDiscount,
                $currency
            ),
            $currency,
            false
        );

        $remainingOrderAmount = $this->pricingDataHelper->currency(
            $this->adyenHelper->originalAmount(
                $formattedOrderAmount - $giftcardDiscount,
                $currency
            ),
            $currency,
            false
        );

        $response = [
            'redeemedGiftcards' => $this->filterGiftcardStateData($stateDataArray->getData(), $quote),
            'remainingAmount' => $remainingOrderAmount,
            'totalDiscount' => $totalDiscount
        ];

        return json_encode($response);
    }

    private function filterGiftcardStateData(array $stateDataArray, CartInterface $quote): array
    {
        $responseArray = [];

        $remainingAmount = $this->adyenHelper->formatAmount(
            $quote->getGrandTotal(),
            $quote->getCurrency()->getQuoteCurrencyCode()
        );

        foreach ($stateDataArray as $key => $item) {
            $stateData = json_decode($item['state_data'], true);
            if (!isset($stateData['paymentMethod']['type']) ||
                !isset($stateData['paymentMethod']['brand']) ||
                $stateData['paymentMethod']['type'] !== 'giftcard') {
                unset($stateDataArray[$key]);
                continue;
            }

            if ($remainingAmount > $stateData['giftcard']['balance']['value']) {
                $deductedAmount = $stateData['giftcard']['balance']['value'];
            } else {
                $deductedAmount = $remainingAmount;
            }

            $responseArray[] = [
                'stateDataId' => $item['entity_id'],
                'brand' => $stateData['paymentMethod']['brand'],
                'title' => $stateData['giftcard']['title'],
                'balance' => $stateData['giftcard']['balance'],
                'deductedAmount' =>  $this->pricingDataHelper->currency(
                    $this->adyenHelper->originalAmount(
                        $deductedAmount,
                        $quote->getCurrency()->getQuoteCurrencyCode()
                    ),
                    $quote->getCurrency()->getQuoteCurrencyCode(),
                    false
                )
            ];

            $remainingAmount -= $deductedAmount;
        }

        return $responseArray;
    }
}
