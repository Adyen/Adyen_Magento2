<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2025 Adyen N.V. (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Model;

use Adyen\AdyenException;
use Adyen\Payment\Api\Data\OrderPaymentInterface;
use Adyen\Payment\Api\Repository\AdyenOrderPaymentRepositoryInterface;
use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Model\Order\PaymentFactory as AdyenOrderPaymentFactory;
use Adyen\Payment\Model\ResourceModel\Order\Payment\CollectionFactory;
use Adyen\Payment\Model\ResourceModel\Order\Payment as AdyenOrderPaymentResourceModel;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\Search\SearchResultFactory;
use Magento\Framework\Api\SearchCriteria\CollectionProcessor;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;

class AdyenOrderPaymentRepository implements AdyenOrderPaymentRepositoryInterface
{
    /**
     * @param AdyenOrderPaymentResourceModel $resourceModel
     * @param AdyenOrderPaymentFactory $adyenOrderPaymentFactory
     * @param SearchResultFactory $searchResultsFactory
     * @param CollectionFactory $collectionFactory
     * @param CollectionProcessor $collectionProcessor
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param FilterBuilder $filterBuilder
     * @param FilterGroupBuilder $filterGroupBuilder
     * @param AdyenLogger $adyenLogger
     */
    public function __construct(
        private readonly AdyenOrderPaymentResourceModel $resourceModel,
        private readonly AdyenOrderPaymentFactory $adyenOrderPaymentFactory,
        private readonly SearchResultFactory $searchResultsFactory,
        private readonly CollectionFactory $collectionFactory,
        private readonly CollectionProcessor $collectionProcessor,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly FilterBuilder $filterBuilder,
        private readonly FilterGroupBuilder $filterGroupBuilder,
        private readonly AdyenLogger $adyenLogger
    ) { }

    /**
     * @param SearchCriteriaInterface $searchCriteria
     * @return SearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        $searchResult = $this->searchResultsFactory->create();
        $collection = $this->collectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);
        $searchResult->setItems($collection->getItems());
        $searchResult->setTotalCount($collection->getSize());

        return $searchResult;
    }

    /**
     * @param int $paymentId
     * @param array $captureStatuses
     * @return OrderPaymentInterface[]|null
     * @throws AdyenException
     */
    public function getByPaymentId(int $paymentId, array $captureStatuses = []): ?array
    {
        $paymentIdFilter = $this->filterBuilder->setField(OrderPaymentInterface::PAYMENT_ID)
            ->setConditionType('eq')
            ->setValue($paymentId)
            ->create();

        $captureStatusFilters = [];

        if (!empty($captureStatuses)) {
            $this->validateCaptureStatuses($captureStatuses);

            foreach ($captureStatuses as $captureStatus) {
                $captureStatusFilters[] = $this->filterBuilder->setField(OrderPaymentInterface::CAPTURE_STATUS)
                    ->setConditionType('eq')
                    ->setValue($captureStatus)
                    ->create();
            }
        }

        /*
         * Create two different filter groups for logical AND operation between `payment_id` and `capture_status`
         * fields. Filter group `$captureStatusFilters` provides logical OR between `capture_status` values.
         */
        $paymentIdFilterGroup = $this->filterGroupBuilder->setFilters([$paymentIdFilter])->create();
        $captureStatusFilterGroup = $this->filterGroupBuilder->setFilters($captureStatusFilters)->create();

        $searchCriteria = $this->searchCriteriaBuilder
            ->setFilterGroups([$paymentIdFilterGroup, $captureStatusFilterGroup])
            ->create();

        return $this->getList($searchCriteria)->getItems();
    }

    /**
     * @param int $entityId
     * @return OrderPaymentInterface
     */
    public function get(int $entityId): OrderPaymentInterface
    {
        $entity = $this->adyenOrderPaymentFactory->create();
        $this->resourceModel->load($entity, $entityId, 'entity_id');

        return $entity;
    }

    /**
     * @param array $captureStatuses
     * @return void
     * @throws AdyenException
     */
    private function validateCaptureStatuses(array $captureStatuses): void
    {
        foreach ($captureStatuses as $captureStatus) {
            if (!in_array($captureStatus, self::AVAILABLE_CAPTURE_STATUSES)) {
                $message = sprintf(
                    "Invalid capture status %s has been provided for adyen_order_payment repository!",
                    $captureStatus
                );

                $this->adyenLogger->error($message);
                throw new AdyenException($message);
            }
        }
    }
}
