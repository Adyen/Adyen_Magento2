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
use Adyen\Payment\Api\Data\CreditmemoInterface;
use Adyen\Payment\Api\Data\NotificationInterface;
use Adyen\Payment\Api\Repository\AdyenCreditmemoRepositoryInterface;
use Adyen\Payment\Model\ResourceModel\Creditmemo\Creditmemo as CreditmemoResourceModel;
use Adyen\Payment\Model\ResourceModel\Creditmemo\CollectionFactory;
use Adyen\Webhook\EventCodes;
use Magento\Framework\Api\Search\SearchResultFactory;
use Magento\Framework\Api\SearchCriteria\CollectionProcessor;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;

class AdyenCreditmemoRepository implements AdyenCreditmemoRepositoryInterface
{
    /**
     * @param CreditmemoFactory $adyenCreditmemoFactory
     * @param CreditmemoResourceModel $resourceModel
     * @param SearchResultFactory $searchResultsFactory
     * @param CollectionFactory $collectionFactory
     * @param CollectionProcessor $collectionProcessor
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        private readonly CreditmemoFactory $adyenCreditmemoFactory,
        private readonly CreditmemoResourceModel $resourceModel,
        private readonly SearchResultFactory $searchResultsFactory,
        private readonly CollectionFactory $collectionFactory,
        private readonly CollectionProcessor $collectionProcessor,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) { }

    /**
     * @param int $entityId
     * @return CreditmemoInterface
     */
    public function get(int $entityId): CreditmemoInterface
    {
        $entity = $this->adyenCreditmemoFactory->create();
        $this->resourceModel->load($entity, $entityId, CreditmemoInterface::ENTITY_ID);

        return $entity;
    }

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
     * @param int $adyenOrderPaymentId
     * @return array|CreditmemoInterface[]|null
     */
    public function getByAdyenOrderPaymentId(int $adyenOrderPaymentId): ?array
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter(CreditmemoInterface::ADYEN_ORDER_PAYMENT_ID, $adyenOrderPaymentId)
            ->create();

        return $this->getList($searchCriteria)->getItems();
    }

    /**
     * @throws AlreadyExistsException
     */
    public function save(CreditmemoInterface $entity): CreditmemoInterface
    {
        $this->resourceModel->save($entity);

        return $entity;
    }

    /**
     * @param NotificationInterface $notification
     * @return CreditmemoInterface|null
     * @throws AdyenException
     * @throws LocalizedException
     */
    public function getByRefundWebhook(NotificationInterface $notification): ?CreditmemoInterface
    {
        if ($notification->getEventCode() !== EventCodes::REFUND) {
            throw new AdyenException(sprintf(
                'Refund webhook is expected to get the adyen_creditmemo, %s notification given.',
                $notification->getEventCode()
            ));
        }

        $entityId = $this->resourceModel->getIdByPspreference($notification->getPspreference());

        if (empty($entityId)) {
            return null;
        } else {
            $entity = $this->adyenCreditmemoFactory->create();
            $this->resourceModel->load($entity, $entityId, CreditmemoInterface::ENTITY_ID);

            return $entity;
        }
    }
}
