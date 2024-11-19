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

namespace Adyen\Payment\Plugin;

use Adyen\AdyenException;
use Adyen\Model\Recurring\DisableRequest;
use Adyen\Client;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Helper\Requests;
use Adyen\Payment\Helper\Vault;
use Adyen\Payment\Logger\AdyenLogger;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;

class PaymentVaultDeleteToken
{
    /**
     * @var StoreManagerInterface
     */
    protected StoreManagerInterface $storeManager;

    /**
     * @var Data
     */
    protected Data $dataHelper;

    /**
     * @var AdyenLogger
     */
    protected AdyenLogger $adyenLogger;

    /**
     * @var Requests
     */
    protected Requests $requestsHelper;

    /**
     * @var Vault
     */
    protected Vault $vaultHelper;

    /**
     * @param StoreManagerInterface $storeManager
     * @param Data $dataHelper
     * @param AdyenLogger $adyenLogger
     * @param Requests $requestsHelper
     * @param Vault $vaultHelper
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        Data $dataHelper,
        AdyenLogger $adyenLogger,
        Requests $requestsHelper,
        Vault $vaultHelper
    ) {
        $this->storeManager = $storeManager;
        $this->dataHelper = $dataHelper;
        $this->adyenLogger = $adyenLogger;
        $this->requestsHelper = $requestsHelper;
        $this->vaultHelper = $vaultHelper;
    }

    /**
     * @param PaymentTokenRepositoryInterface $subject
     * @param PaymentTokenInterface $paymentToken
     * @return PaymentTokenInterface[]|void
     * @throws NoSuchEntityException
     */
    public function beforeDelete(PaymentTokenRepositoryInterface $subject, PaymentTokenInterface $paymentToken): ?array
    {
        $paymentMethodCode = $paymentToken->getPaymentMethodCode();
        $storeId = $this->storeManager->getStore()->getStoreId();

        if (!is_null($paymentMethodCode) && $this->vaultHelper->isAdyenPaymentCode($paymentMethodCode)) {
            $request = $this->createDisableTokenRequest($paymentToken);

            try {
                $client = $this->dataHelper->initializeAdyenClient($storeId);
                $recurringService = $this->dataHelper->initializeRecurringApi($client);

                $this->dataHelper->logRequest(
                    $request,
                    Client::API_RECURRING_VERSION,
                    sprintf("/pal/servlet/Recurring/%s/disable", Client::API_RECURRING_VERSION)
                );

                $response = $recurringService->disable(new DisableRequest($request));

                $responseData = $response->toArray();
                $this->dataHelper->logResponse($responseData);
            } catch (AdyenException $e) {
                $this->adyenLogger->error(sprintf(
                    'Error while attempting to disable token with id %s: %s',
                    $paymentToken->getEntityId(),
                    $e->getMessage()
                ));
            } catch (NoSuchEntityException $e) {
                $this->adyenLogger->error(sprintf(
                    'No such entity while attempting to disable token with id %s: %s',
                    $paymentToken->getEntityId(),
                    $e->getMessage()
                ));
            }
        }

        return [$paymentToken];
    }

    private function createDisableTokenRequest(PaymentTokenInterface $paymentToken): array
    {
        return [
            Requests::MERCHANT_ACCOUNT => $this->dataHelper->getAdyenMerchantAccount(
                $paymentToken->getPaymentMethodCode()
            ),
            Requests::SHOPPER_REFERENCE =>
                $this->requestsHelper->getShopperReference($paymentToken->getCustomerId(), null, null),
            Requests::RECURRING_DETAIL_REFERENCE => $paymentToken->getGatewayToken()
        ];
    }
}
