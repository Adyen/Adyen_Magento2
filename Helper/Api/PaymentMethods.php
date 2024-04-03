<?php
/**
 *
 * Adyen Payment Module
 *
 * Copyright (c) 2021 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Helper\Api;

use Adyen\AdyenException;
use Adyen\Client;
use Adyen\ConnectionException;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Logger\AdyenLogger;
use Magento\Store\Model\Store;

/**
 * Class PaymentMethods
 *
 * @package Adyen\Payment\Helper\Api
 */
class PaymentMethods
{
    /**
     * @var \Adyen\Payment\Helper\Data
     */
    protected Data $adyenHelper;

    /**
     * @var \Adyen\Payment\Logger\AdyenLogger
     */
    protected AdyenLogger $adyenLogger;

    /**
     * PaymentMethods Constructor
     *
     * @param \Adyen\Payment\Helper\Data $adyenHelper
     * @param \Adyen\Payment\Logger\AdyenLogger $adyenLogger
     */
    public function __construct(
        Data $adyenHelper,
        AdyenLogger $adyenLogger,
    ) {
        $this->adyenHelper = $adyenHelper;
        $this->adyenLogger = $adyenLogger;
    }

    /**
     * @param array $requestParams
     * @param \Magento\Store\Model\Store $store
     * @return array
     * @throws \Adyen\AdyenException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getPaymentMethods(array $requestParams, Store $store): array
    {
        // initialize the adyen client
        $client = $this->adyenHelper->initializeAdyenClient($store->getId());

        // initialize service
        $service = $this->adyenHelper->createAdyenCheckoutService($client);

        try {
            $this->adyenHelper->logRequest($requestParams, Client::API_CHECKOUT_VERSION, '/paymentMethods');
            $responseData = $service->paymentMethods($requestParams);
        } catch (AdyenException $e) {
            $this->adyenLogger->error(
                "The Payment methods response is empty check your Adyen configuration in Magento."
            );

            // return empty result
            return [];
        } catch (ConnectionException $e) {
            $this->adyenLogger->error(
                "Connection to the endpoint failed. Check the Adyen Live endpoint prefix configuration."
            );
            return [];
        }

        $this->adyenHelper->logResponse($responseData);
        return $responseData;
    }
}
