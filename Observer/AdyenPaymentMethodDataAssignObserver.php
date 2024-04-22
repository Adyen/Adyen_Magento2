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

namespace Adyen\Payment\Observer;

use Adyen\Payment\Helper\StateData;
use Adyen\Payment\Helper\Util\CheckoutStateDataValidator;
use Adyen\Payment\Helper\Util\DataArrayValidator;
use Adyen\Payment\Helper\Vault;
use Adyen\Payment\Model\ResourceModel\StateData\Collection;
use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Store\Model\StoreManagerInterface;

class AdyenPaymentMethodDataAssignObserver extends AbstractDataAssignObserver
{
    const BRAND_CODE = 'brand_code';
    const DF_VALUE = 'df_value';
    const GUEST_EMAIL = 'guestEmail';
    const STATE_DATA = 'stateData';
    const RETURN_URL = 'returnUrl';
    const RECURRING_PROCESSING_MODEL = 'recurringProcessingModel';
    const CC_NUMBER = 'cc_number';
    const FRONTEND_TYPE = 'frontendType';

    private static $approvedAdditionalDataKeys = [
        self::BRAND_CODE,
        self::DF_VALUE,
        self::GUEST_EMAIL,
        self::STATE_DATA,
        self::RETURN_URL,
        self::RECURRING_PROCESSING_MODEL,
        self::CC_NUMBER,
        self::FRONTEND_TYPE
        // add frontend_type validator
    ];

    protected CheckoutStateDataValidator $checkoutStateDataValidator;
    protected Collection $stateDataCollection;
    private StateData $stateData;
    private StoreManagerInterface $storeManager;
    private Vault $vaultHelper;

    public function __construct(
        CheckoutStateDataValidator $checkoutStateDataValidator,
        Collection $stateDataCollection,
        StateData $stateData,
        StoreManagerInterface $storeManager,
        Vault $vaultHelper
    ) {
        $this->checkoutStateDataValidator = $checkoutStateDataValidator;
        $this->stateDataCollection = $stateDataCollection;
        $this->stateData = $stateData;
        $this->storeManager = $storeManager;
        $this->vaultHelper = $vaultHelper;
    }

    public function execute(Observer $observer)
    {
        $additionalDataToSave = [];
        $stateData = null;
        // Get request fields
        $data = $this->readDataArgument($observer);
        $paymentInfo = $this->readPaymentModelArgument($observer);

        // Remove cc_type information from the previous payment
        $paymentInfo->unsAdditionalInformation('cc_type');

        // Get additional data array
        $additionalData = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA);
        if (!is_array($additionalData)) {
            return;
        }

        // Get a validated additional data array
        $additionalData = DataArrayValidator::getArrayOnlyWithApprovedKeys(
            $additionalData,
            self::$approvedAdditionalDataKeys
        );

        // JSON decode state data from the frontend or fetch it from the DB entity with the quote ID
        if (!empty($additionalData[self::STATE_DATA])) {
            $stateData = json_decode((string) $additionalData[self::STATE_DATA], true);
        } elseif (!empty($additionalData[self::CC_NUMBER])) {
            //This block goes for multi shipping scenarios
            $stateData = json_decode((string) $additionalData[self::CC_NUMBER], true);
            $paymentInfo->setAdditionalInformation(self::BRAND_CODE, $stateData['paymentMethod']['type']);
        } elseif($paymentInfo->getData('method') != 'adyen_giftcard') {
            $stateData = $this->stateDataCollection->getStateDataArrayWithQuoteId($paymentInfo->getData('quote_id'));
            if(!empty($stateData) && $stateData['paymentMethod']['type'] == 'giftcard')
            {
                $stateData = null;
            }
        }

        // Get validated state data array
        if (!empty($stateData)) {
            $stateData = $this->checkoutStateDataValidator->getValidatedAdditionalData($stateData);
            // Set stateData in a service and remove from payment's additionalData
            $this->stateData->setStateData($stateData, $paymentInfo->getData('quote_id'));
        }

        unset($additionalData[self::STATE_DATA]);

        if (
            !empty($additionalData[self::RECURRING_PROCESSING_MODEL]) &&
            !$this->vaultHelper->validateRecurringProcessingModel($additionalData[self::RECURRING_PROCESSING_MODEL])
        ) {
            unset($additionalData[self::RECURRING_PROCESSING_MODEL]);
            $paymentInfo->unsAdditionalInformation(self::RECURRING_PROCESSING_MODEL);
        }

        // Set additional data in the payment
        foreach (array_merge($additionalData, $additionalDataToSave) as $key => $data) {
            $paymentInfo->setAdditionalInformation($key, $data);
        }

        // Set ccType. If payment method is tokenizable, update additional information
        if (!empty($additionalData[self::BRAND_CODE])) {
            $paymentMethod = $additionalData[self::BRAND_CODE];
            $paymentInfo->setCcType($paymentMethod);
        }
    }
}
