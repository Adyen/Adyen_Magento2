<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2020 Adyen BV (https://www.adyen.com/)
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
use Adyen\Payment\Gateway\Request\HeaderDataBuilder;

class AdyenCcDataAssignObserver extends AbstractDataAssignObserver
{
    const CC_TYPE = 'cc_type';
    const NUMBER_OF_INSTALLMENTS = 'number_of_installments';
    const STORE_CC = 'store_cc';
    const GUEST_EMAIL = 'guestEmail';
    const COMBO_CARD_TYPE = 'combo_card_type';
    const STATE_DATA = 'stateData';
    const STORE_PAYMENT_METHOD = 'storePaymentMethod';
    const RETURN_URL = 'returnUrl';
    const RECURRING_PROCESSING_MODEL = 'recurringProcessingModel';
    const SHOPPER_REFERENCE = 'shopperReference';

    /**
     * Approved root level keys from additional data array
     *
     * @var array
     */
    private static $approvedAdditionalDataKeys = [
        self::STATE_DATA,
        self::GUEST_EMAIL,
        self::COMBO_CARD_TYPE,
        self::NUMBER_OF_INSTALLMENTS,
        self::CC_TYPE,
        self::RETURN_URL,
        self::RECURRING_PROCESSING_MODEL,
        self::SHOPPER_REFERENCE,
        HeaderDataBuilder::FRONTENDTYPE
    ];

    /**
     * @var CheckoutStateDataValidator
     */
    protected $checkoutStateDataValidator;

    /**
     * @var Collection
     */
    protected $stateDataCollection;

    /**
     * @var StateData
     */
    private $stateData;

    /**
     * @var Vault
     */
    private $vaultHelper;

    /**
     * AdyenCcDataAssignObserver constructor.
     * @param CheckoutStateDataValidator $checkoutStateDataValidator
     * @param Collection $stateDataCollection
     * @param StateData $stateData
     */
    public function __construct(
        CheckoutStateDataValidator $checkoutStateDataValidator,
        Collection $stateDataCollection,
        StateData $stateData,
        Vault $vaultHelper
    ) {
        $this->checkoutStateDataValidator = $checkoutStateDataValidator;
        $this->stateDataCollection = $stateDataCollection;
        $this->stateData = $stateData;
        $this->vaultHelper = $vaultHelper;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        // Get request fields
        $data = $this->readDataArgument($observer);
        $paymentInfo = $this->readPaymentModelArgument($observer);

        // Remove remaining brand_code information from the previous payment
        $paymentInfo->unsAdditionalInformation('brand_code');

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
            $stateData = json_decode((string)$additionalData[self::STATE_DATA], true);
        } else {
            $stateData = $this->stateDataCollection->getStateDataArrayWithQuoteId($paymentInfo->getData('quote_id'));
        }

        // Get validated state data array
        if (!empty($stateData) && $stateData['paymentMethod']['type'] != 'giftcard') {
            $stateData = $this->checkoutStateDataValidator->getValidatedAdditionalData($stateData);
            // Set stateData in a service and remove from payment's additionalData
            $this->stateData->setStateData($stateData, $paymentInfo->getData('quote_id'));

            // set storeCc
            if (!empty($stateData[self::STORE_PAYMENT_METHOD])) {
                $paymentInfo->setAdditionalInformation(self::STORE_CC, $stateData[self::STORE_PAYMENT_METHOD]);
            }
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
        foreach ($additionalData as $key => $data) {
            $paymentInfo->setAdditionalInformation($key, $data);
        }

        // set ccType
        if (!empty($additionalData[self::CC_TYPE])) {
            $paymentInfo->setCcType($additionalData[self::CC_TYPE]);
        }
    }
}
