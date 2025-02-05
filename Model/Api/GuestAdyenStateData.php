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

use Adyen\Payment\Api\GuestAdyenStateDataInterface;
use Adyen\Payment\Helper\StateData as StateDataHelper;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;

class GuestAdyenStateData implements GuestAdyenStateDataInterface
{
    /**
     * @param StateDataHelper $stateDataHelper
     * @param MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId
     */
    public function __construct(
        private readonly StateDataHelper $stateDataHelper,
        private readonly MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId
    ) { }

    /**
     * @param string $stateData
     * @param string $cartId
     * @return int
     * @throws InputException
     * @throws LocalizedException
     * @throws AlreadyExistsException
     */
    public function save(string $stateData, string $cartId): int
    {
        $quoteId = $this->getQuoteIdFromMaskedCartId($cartId);
        $stateData = $this->stateDataHelper->saveStateData($stateData, $quoteId);

        return $stateData->getEntityId();
    }

    /**
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function remove(int $stateDataId, string $cartId): bool
    {
        $quoteId = $this->getQuoteIdFromMaskedCartId($cartId);

        return $this->stateDataHelper->removeStateData($stateDataId, $quoteId);
    }

    /**
     * @param string $cartId
     * @return int
     * @throws InputException
     */
    private function getQuoteIdFromMaskedCartId(string $cartId): int
    {
        try {
            $quoteId = $this->maskedQuoteIdToQuoteId->execute($cartId);
        } catch (NoSuchEntityException $e) {
            $errorMessage = __("An error occurred: missing required parameter :cartId!");
            throw new InputException($errorMessage);
        }

        return $quoteId;
    }
}
