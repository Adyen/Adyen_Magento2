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

use Adyen\Payment\Api\AdyenStateDataInterface;
use Adyen\Payment\Helper\Util\CheckoutStateDataValidator;
use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Model\ResourceModel\StateData as StateDataResourceModel;
use Adyen\Payment\Model\StateData;
use Adyen\Payment\Model\StateDataFactory;
use Magento\Framework\Exception\LocalizedException;

class AdyenStateData implements AdyenStateDataInterface
{
    private CheckoutStateDataValidator $checkoutStateDataValidator;
    private StateDataFactory $stateDataFactory;
    private StateDataResourceModel $stateDataResourceModel;
    private AdyenLogger $adyenLogger;

    public function __construct(
        CheckoutStateDataValidator $checkoutStateDataValidator,
        StateDataFactory $stateDataFactory,
        StateDataResourceModel $stateDataResourceModel,
        AdyenLogger $adyenLogger
    ) {
        $this->checkoutStateDataValidator = $checkoutStateDataValidator;
        $this->stateDataFactory = $stateDataFactory;
        $this->stateDataResourceModel = $stateDataResourceModel;
        $this->adyenLogger = $adyenLogger;
    }

    public function save(string $stateData, int $quoteId): void
    {
        // Decode payload from frontend
        $stateData = json_decode($stateData, true);

        // Validate JSON that has just been parsed if it was in a valid format
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new LocalizedException(__('State data call failed because the request was not a valid JSON'));
        }

        $stateData = json_encode($this->checkoutStateDataValidator->getValidatedAdditionalData($stateData));

        /** @var StateData $stateDataObj */
        $stateDataObj = $this->stateDataFactory->create();
        $stateDataObj->setQuoteId((int)$quoteId)->setStateData((string)$stateData);
        $this->stateDataResourceModel->save($stateDataObj);
    }

    public function remove(int $stateDataId): bool
    {
        $stateDataObj = $this->stateDataFactory->create();
        $stateDataObj->setEntityId($stateDataId);

        try {
            $this->stateDataResourceModel->delete($stateDataObj);
        } catch (\Exception $e) {
            $this->adyenLogger->error('An error occurred while deleting state data: ' . $e->getMessage());
            return false;
        }

        return true;
    }
}
