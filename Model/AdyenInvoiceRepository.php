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
use Adyen\Payment\Api\Data\InvoiceInterface;
use Adyen\Payment\Api\Data\NotificationInterface;
use Adyen\Payment\Api\Repository\AdyenInvoiceRepositoryInterface;
use Adyen\Payment\Model\ResourceModel\Invoice\CollectionFactory;
use Adyen\Payment\Model\ResourceModel\Invoice\Invoice as InvoiceResourceModel;
use Adyen\Webhook\EventCodes;
use Magento\Framework\Api\Search\SearchResultFactory;
use Magento\Framework\Api\SearchCriteria\CollectionProcessor;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;

class AdyenInvoiceRepository implements AdyenInvoiceRepositoryInterface
{
    /**
     * @param SearchResultFactory $searchResultsFactory
     * @param CollectionFactory $collectionFactory
     * @param CollectionProcessor $collectionProcessor
     * @param InvoiceResourceModel $resourceModel
     * @param InvoiceFactory $adyenInvoiceFactory
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        private readonly SearchResultFactory $searchResultsFactory,
        private readonly CollectionFactory $collectionFactory,
        private readonly CollectionProcessor $collectionProcessor,
        private readonly InvoiceResourceModel $resourceModel,
        private readonly InvoiceFactory $adyenInvoiceFactory,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
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
     * @throws AlreadyExistsException
     */
    public function save(InvoiceInterface $entity): InvoiceInterface
    {
        $this->resourceModel->save($entity);

        return $entity;
    }

    /**
     * @param NotificationInterface $notification
     * @return InvoiceInterface|null
     * @throws AdyenException
     * @throws LocalizedException
     */
    public function getByCaptureWebhook(NotificationInterface $notification): ?InvoiceInterface
    {
        if ($notification->getEventCode() !== EventCodes::CAPTURE) {
            throw new AdyenException(sprintf(
                'Capture webhook is expected to get the adyen_invoice, %s notification given.',
                $notification->getEventCode()
            ));
        }

        $adyenInvoiceId = $this->resourceModel->getIdByPspreference($notification->getPspreference());

        if (empty($adyenInvoiceId)) {
            return null;
        } else {
            $adyenInvoice = $this->adyenInvoiceFactory->create();
            $this->resourceModel->load($adyenInvoice, $adyenInvoiceId, 'entity_id');

            return $adyenInvoice;
        }
    }

    /**
     * @param int $adyenOrderPaymentId
     * @return InvoiceInterface[]|null
     */
    public function getByAdyenOrderPaymentId(int $adyenOrderPaymentId): ?array
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter(InvoiceInterface::ADYEN_ORDER_PAYMENT_ID, $adyenOrderPaymentId)
            ->create();

        return $this->getList($searchCriteria)->getItems();
    }
}
