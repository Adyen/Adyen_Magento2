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

namespace Adyen\Payment\Model\Sales;

use Magento\Framework\Api\ExtensionAttribute\JoinProcessorInterface;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Payment\Api\Data\PaymentAdditionalInfoInterfaceFactory;
use Magento\Sales\Api\Data\OrderExtensionFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterfaceFactory as SearchResultFactory;
use Magento\Sales\Model\OrderRepository as SalesOrderRepository;
use Magento\Sales\Model\ResourceModel\Metadata;
use Magento\Tax\Api\OrderTaxManagementInterface;

class OrderRepository extends SalesOrderRepository
{
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private SortOrderBuilder $sortOrderBuilder;
    private FilterBuilder $filterBuilder;
    private FilterGroupBuilder $filterGroupBuilder;

    public function __construct(
        SearchCriteriaBuilder $searchCriteriaBuilder,
        FilterBuilder $filterBuilder,
        FilterGroupBuilder $filterGroupBuilder,
        SortOrderBuilder $sortOrderBuilder,
        Metadata $metadata,
        SearchResultFactory $searchResultFactory,
        CollectionProcessorInterface $collectionProcessor = null,
        OrderExtensionFactory $orderExtensionFactory = null,
        OrderTaxManagementInterface $orderTaxManagement = null,
        PaymentAdditionalInfoInterfaceFactory $paymentAdditionalInfoFactory = null,
        JsonSerializer $serializer = null,
        JoinProcessorInterface $extensionAttributesJoinProcessor = null
    ) {
        parent::__construct(
            $metadata,
            $searchResultFactory,
            $collectionProcessor,
            $orderExtensionFactory,
            $orderTaxManagement,
            $paymentAdditionalInfoFactory,
            $serializer,
            $extensionAttributesJoinProcessor
        );

        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->sortOrderBuilder = $sortOrderBuilder;
        $this->filterBuilder = $filterBuilder;
        $this->filterGroupBuilder = $filterGroupBuilder;
    }

    public function getOrderByQuoteId(int $quoteId)
    {
        $quoteIdFilter = $this->filterBuilder->setField('quote_id')
            ->setConditionType('eq')
            ->setValue($quoteId)
            ->create();

        $quoteIdFilterGroup = $this->filterGroupBuilder->setFilters([$quoteIdFilter])->create();
        $sortOrder = $this->sortOrderBuilder->setField('entity_id')
            ->setDescendingDirection()
            ->create();

        $searchCriteria = $this->searchCriteriaBuilder
            ->setFilterGroups([$quoteIdFilterGroup])
            ->setSortOrders([$sortOrder])
            ->setPageSize(1)
            ->create();

        $orders = $this->getList($searchCriteria)->getItems();

        /** @var OrderInterface $order */
        return reset($orders);
    }
}
