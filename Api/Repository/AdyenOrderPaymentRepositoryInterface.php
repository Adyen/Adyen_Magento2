<?php
/**
 *
 * Adyen Payment Module
 *
 * Copyright (c) 2025 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Api\Repository;

use Adyen\Payment\Api\Data\OrderPaymentInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

interface AdyenOrderPaymentRepositoryInterface
{
    const AVAILABLE_CAPTURE_STATUSES = [
        OrderPaymentInterface::CAPTURE_STATUS_AUTO_CAPTURE,
        OrderPaymentInterface::CAPTURE_STATUS_MANUAL_CAPTURE,
        OrderPaymentInterface::CAPTURE_STATUS_PARTIAL_CAPTURE,
        OrderPaymentInterface::CAPTURE_STATUS_NO_CAPTURE
    ];

    /**
     * Retrieve adyen_order_payment entity by the ID.
     *
     * @param int $entityId
     * @return OrderPaymentInterface Adyen order payment entity
     * @throws NoSuchEntityException
     */
    public function get(int $entityId): OrderPaymentInterface;

    /**
     * Retrieve adyen_order_payment entities by `payment_id`.
     *
     * @param int $paymentId
     * @param array $captureStatuses
     * @return OrderPaymentInterface[]|null
     */
    public function getByPaymentId(int $paymentId, array $captureStatuses = []): ?array;

    /**
     * Retrieve adyen_order_payment entities which match a specified criteria.
     *
     * @param SearchCriteriaInterface $searchCriteria
     * @return SearchResultsInterface
     *
     * @throws LocalizedException
     */
    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface;
}
