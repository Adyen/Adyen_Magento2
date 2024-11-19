<?php
/**
 *
 * Adyen Payment Module
 *
 * Copyright (c) 2022 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Gateway\Request;

use Adyen\Payment\Helper\ChargedCurrency;
use Adyen\Payment\Helper\Config;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Helper\Requests;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use Magento\Store\Model\StoreManagerInterface;

class AdditionalDataLevel23DataBuilder implements BuilderInterface
{
    /**
     * @var Config
     */
    private $config;
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;
    /**
     * @var Data
     */
    private $adyenHelper;
    /**
     * @var ChargedCurrency
     */
    private $chargedCurrency;
    /**
     * @var Requests
     */
    private $adyenRequestHelper;

    public function __construct(
        Config $config,
        StoreManagerInterface $storeManager,
        ChargedCurrency $chargedCurrency,
        Requests $adyenRequestHelper,
        Data $adyenHelper
    )
    {
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->adyenHelper = $adyenHelper;
        $this->chargedCurrency = $chargedCurrency;
        $this->adyenRequestHelper = $adyenRequestHelper;
    }

    public function build(array $buildSubject)
    {
        $requestBody = [];
        if ($this->config->sendLevel23AdditionalData($this->storeManager->getStore()->getId())) {
            $paymentDataObject = SubjectReader::readPayment($buildSubject);
            $payment = $paymentDataObject->getPayment();
            /** @var Order $order */
            $order = $payment->getOrder();
            $currencyCode = $this->chargedCurrency->getOrderAmountCurrency($order)->getCurrencyCode();

            $prefix = 'enhancedSchemeData';
            $requestBody['additionalData'][$prefix . '.totalTaxAmount'] = $this->adyenHelper->formatAmount($order->getTaxAmount(), $currencyCode);
            $requestBody['additionalData'][$prefix . '.customerReference'] = $this->adyenRequestHelper->getShopperReference($order->getCustomerId(), $order->getIncrementId(), $payment->getAdditionalInformation('shopperReference'));
            if ($order->getIsNotVirtual()) {
                $requestBody['additionalData'][$prefix . '.freightAmount'] = $this->adyenHelper->formatAmount($order->getBaseShippingAmount(), $currencyCode);
                $requestBody['additionalData'][$prefix . '.destinationPostalCode'] = $order->getShippingAddress()->getPostcode();
                $requestBody['additionalData'][$prefix . '.destinationCountryCode'] = $order->getShippingAddress()->getCountryId();
            }

            $itemIndex = 0;
            foreach ($order->getItems() as $item) {
                /** @var Item $item */
                if ($item->getPrice() == 0 && !empty($item->getParentItem())) {
                    // Products variants get added to the order as separate items, filter out the variants.
                    continue;
                }

                $itemPrefix = $prefix . '.itemDetailLine';
                $requestBody['additionalData'][$itemPrefix . $itemIndex . '.description'] = $item->getName();
                $requestBody['additionalData'][$itemPrefix . $itemIndex . '.unitPrice'] = $this->adyenHelper->formatAmount($item->getPrice(), $currencyCode);
                $requestBody['additionalData'][$itemPrefix . $itemIndex . '.discountAmount'] = $this->adyenHelper->formatAmount($item->getDiscountAmount(), $currencyCode);
                $requestBody['additionalData'][$itemPrefix . $itemIndex . '.commodityCode'] = $item->getQuoteItemId();
                $requestBody['additionalData'][$itemPrefix . $itemIndex . '.quantity'] = $item->getQtyOrdered();
                $requestBody['additionalData'][$itemPrefix . $itemIndex . '.productCode'] = $item->getSku();
                $requestBody['additionalData'][$itemPrefix . $itemIndex . '.totalAmount'] = $this->adyenHelper->formatAmount($item->getRowTotal(), $currencyCode);

                $itemIndex++;
            }
        }

        return ['body' => $requestBody];
    }
}
