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

use Adyen\Payment\Api\Data\InvoiceInterface;
use Adyen\Payment\Api\Data\NotificationInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\LocalizedException;

interface AdyenInvoiceRepositoryInterface
{
    /**
     * Retrieve adyen_invoice entities which match a specified criteria.
     *
     * @param SearchCriteriaInterface $searchCriteria
     * @return SearchResultsInterface
     *
     * @throws LocalizedException
     */
    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface;

    /**
     * Retrieve adyen_invoice entity by the given notification using the `pspreference` column.
     *
     * @param NotificationInterface $notification
     * @return InvoiceInterface|null
     */
    public function getByCaptureWebhook(NotificationInterface $notification): ?InvoiceInterface;

    /**
     * Retrieve adyen_invoice entities by `adyen_order_payment_id`.
     *
     * @param int $adyenOrderPaymentId
     * @return InvoiceInterface[]|null
     */
    public function getByAdyenOrderPaymentId(int $adyenOrderPaymentId): ?array;

    /**
     * Performs persist operations for a specified adyen_invoice.
     *
     * @param InvoiceInterface $entity adyen_invoice entity
     * @return InvoiceInterface
     */
    public function save(InvoiceInterface $entity): InvoiceInterface;
}
