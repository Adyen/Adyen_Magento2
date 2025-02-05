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

use Adyen\Payment\Api\Data\CreditmemoInterface;
use Adyen\Payment\Api\Data\NotificationInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

interface AdyenCreditmemoRepositoryInterface
{
    /**
     * Retrieve adyen_creditmemo entity by the ID.
     *
     * @param int $entityId
     * @return CreditmemoInterface Adyen creditmemo entity
     * @throws NoSuchEntityException
     */
    public function get(int $entityId): CreditmemoInterface;

    /**
     * Retrieve adyen_creditmemo entities by `adyen_order_payment_id`.
     *
     * @param int $adyenOrderPaymentId
     * @return CreditmemoInterface[]|null
     */
    public function getByAdyenOrderPaymentId(int $adyenOrderPaymentId): ?array;

    /**
     * Retrieve adyen_creditmemo entity by the given notification using the `pspreference` column.
     *
     * @param NotificationInterface $notification
     * @return CreditmemoInterface|null
     */
    public function getByRefundWebhook(NotificationInterface $notification): ?CreditmemoInterface;

    /**
     * Retrieve adyen_creditmemo entities which match a specified criteria.
     *
     * @param SearchCriteriaInterface $searchCriteria
     * @return SearchResultsInterface
     *
     * @throws LocalizedException
     */
    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface;

    /**
     * Performs persist operations for a specified adyen_creditmemo.
     *
     * @param CreditmemoInterface $entity adyen_creditmemo entity.
     * @return CreditmemoInterface
     */
    public function save(CreditmemoInterface $entity): CreditmemoInterface;
}
