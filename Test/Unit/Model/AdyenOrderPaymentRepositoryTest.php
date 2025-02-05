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

namespace Adyen\Payment\Test\Helper\Unit\Model;

use Adyen\AdyenException;
use Adyen\Payment\Api\Data\OrderPaymentInterface;
use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Model\AdyenOrderPaymentRepository;
use Adyen\Payment\Model\Order\Payment;
use Adyen\Payment\Model\Order\PaymentFactory as AdyenOrderPaymentFactory;
use Adyen\Payment\Model\ResourceModel\Order\Payment as AdyenOrderPaymentResourceModel;
use Adyen\Payment\Model\ResourceModel\Order\Payment\Collection;
use Adyen\Payment\Model\ResourceModel\Order\Payment\CollectionFactory;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Framework\Api\AbstractSimpleObject;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\Search\SearchResultFactory;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteria\CollectionProcessor;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use PHPUnit\Framework\MockObject\MockObject;

class AdyenOrderPaymentRepositoryTest extends AbstractAdyenTestCase
{
    private ?AdyenOrderPaymentRepository $adyenOrderPaymentRepository;
    private AdyenOrderPaymentResourceModel|MockObject $resourceModelMock;
    private AdyenOrderPaymentFactory|MockObject $adyenOrderPaymentFactoryMock;
    private SearchResultFactory|MockObject $searchResultsFactoryMock;
    private CollectionFactory|MockObject $collectionFactoryMock;
    private CollectionProcessor|MockObject $collectionProcessorMock;
    private SearchCriteriaBuilder|MockObject $searchCriteriaBuilderMock;
    private FilterBuilder|MockObject $filterBuilderMock;
    private FilterGroupBuilder|MockObject $filterGroupBuilderMock;
    private AdyenLogger|MockObject $adyenLoggerMock;

    protected function setUp(): void
    {
        $this->resourceModelMock = $this->createMock(AdyenOrderPaymentResourceModel::class);
        $this->adyenOrderPaymentFactoryMock = $this->createGeneratedMock(AdyenOrderPaymentFactory::class, [
            'create'
        ]);
        $this->searchResultsFactoryMock = $this->createMock(SearchResultFactory::class);
        $this->collectionFactoryMock = $this->createGeneratedMock(CollectionFactory::class, [
            'create'
        ]);
        $this->collectionProcessorMock = $this->createMock(CollectionProcessor::class);
        $this->searchCriteriaBuilderMock = $this->createMock(SearchCriteriaBuilder::class);
        $this->filterBuilderMock = $this->createMock(FilterBuilder::class);
        $this->filterGroupBuilderMock = $this->createMock(FilterGroupBuilder::class);
        $this->adyenLoggerMock = $this->createMock(AdyenLogger::class);

        $this->adyenOrderPaymentRepository = new AdyenOrderPaymentRepository(
            $this->resourceModelMock,
            $this->adyenOrderPaymentFactoryMock,
            $this->searchResultsFactoryMock,
            $this->collectionFactoryMock,
            $this->collectionProcessorMock,
            $this->searchCriteriaBuilderMock,
            $this->filterBuilderMock,
            $this->filterGroupBuilderMock,
            $this->adyenLoggerMock
        );
    }

    /**
     * @return void
     */
    public function testGetList()
    {
        $searchResultMock = $this->createPartialMock(SearchResultInterface::class, []);
        $this->searchResultsFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($searchResultMock);

        $collectionMock = $this->createMock(Collection::class);
        $collectionResult[] = $this->createMock(Payment::class);
        $collectionMock->method('getItems')->willReturn($collectionResult);
        $collectionMock->method('getSize')->willReturn(count($collectionResult));
        $this->collectionFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($collectionMock);

        $searchCriteriaMock = $this->createMock(SearchCriteriaInterface::class);

        $this->collectionProcessorMock->expects($this->once())
            ->method('process')
            ->with($searchCriteriaMock, $collectionMock);

        $searchResultMock->expects($this->once())
            ->method('setItems')
            ->with($collectionResult);
        $searchResultMock->expects($this->once())
            ->method('setTotalCount')
            ->with(count($collectionResult));

        $result = $this->adyenOrderPaymentRepository->getList($searchCriteriaMock);

        $this->assertInstanceOf(SearchResultsInterface::class, $result);
    }

    /**
     * @return void
     */
    public function testGet()
    {
        $entityId = 1;
        $adyenOrderPaymentMock = $this->createMock(Payment::class);

        $this->adyenOrderPaymentFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($adyenOrderPaymentMock);

        $this->resourceModelMock->expects($this->once())
            ->method('load')
            ->with($adyenOrderPaymentMock, $entityId, OrderPaymentInterface::ENTITY_ID)
            ->willReturnSelf();

        $result = $this->adyenOrderPaymentRepository->get($entityId);
        $this->assertInstanceOf(OrderPaymentInterface::class, $result);
    }

    /**
     * @return void
     * @throws AdyenException
     */
    public function testGetByPaymentId()
    {
        $paymentId = 1;
        $captureStatuses = [
            OrderPaymentInterface::CAPTURE_STATUS_AUTO_CAPTURE,
            OrderPaymentInterface::CAPTURE_STATUS_MANUAL_CAPTURE,
        ];

        $paymentIdFilterMock = $this->createMock(Filter::class);

        $this->filterBuilderMock->expects($this->exactly(3))
            ->method('setField')
            ->willReturnMap([
                [OrderPaymentInterface::PAYMENT_ID, $this->filterBuilderMock],
                [OrderPaymentInterface::CAPTURE_STATUS, $this->filterBuilderMock]
            ]);

        $this->filterBuilderMock->expects($this->exactly(3))
            ->method('setConditionType')
            ->with('eq')
            ->willReturnSelf();

        $this->filterBuilderMock->expects($this->exactly(3))
            ->method('setValue')
            ->willReturnSelf();

        $this->filterBuilderMock->expects($this->exactly(3))
            ->method('create')
            ->willReturn($paymentIdFilterMock);

        $this->filterGroupBuilderMock->expects($this->exactly(2))
            ->method('setFilters')
            ->willReturnSelf();

        $filterGroupMock = $this->createMock(AbstractSimpleObject::class);

        $this->filterGroupBuilderMock->expects($this->exactly(2))
            ->method('create')
            ->willReturn($filterGroupMock);

        $searchCriteriaMock = $this->createMock(SearchCriteria::class);

        $this->searchCriteriaBuilderMock->expects($this->once())
            ->method('setFilterGroups')
            ->with([$filterGroupMock, $filterGroupMock])
            ->willReturnSelf();

        $this->searchCriteriaBuilderMock->expects($this->once())
            ->method('create')
            ->willReturn($searchCriteriaMock);

        $collectionMock = $this->createMock(Collection::class);
        $this->collectionFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($collectionMock);

        $collectionResult[] = $this->createMock(Payment::class);
        $collectionMock->method('getItems')->willReturn($collectionResult);
        $collectionMock->method('getSize')->willReturn(count($collectionResult));

        $searchResultMock = $this->createPartialMock(SearchResultInterface::class, []);
        $searchResultMock->expects($this->once())
            ->method('setItems')
            ->with($collectionResult);
        $searchResultMock->expects($this->once())
            ->method('setTotalCount')
            ->with(count($collectionResult));
        $searchResultMock->expects($this->once())
            ->method('getItems')
            ->willReturn($collectionResult);

        $this->searchResultsFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($searchResultMock);

        $result = $this->adyenOrderPaymentRepository->getByPaymentId($paymentId, $captureStatuses);

        $this->assertIsArray($result);
        $this->assertInstanceOf(OrderPaymentInterface::class, $result[0]);
    }

    /**
     * @return void
     * @throws AdyenException
     */
    public function testGetByPaymentIdInvalidCaptureStatusException()
    {
        $this->expectException(AdyenException::class);

        $paymentId = 1;
        $captureStatuses = ['invalid_capture_status_mock'];

        $this->filterBuilderMock->method('setField')->willReturnSelf();
        $this->filterBuilderMock->method('setConditionType')->willReturnSelf();
        $this->filterBuilderMock->method('setValue')->willReturnSelf();

        $this->adyenLoggerMock->expects($this->once())->method('error');

        // Assert expect exception
        $this->adyenOrderPaymentRepository->getByPaymentId($paymentId, $captureStatuses);
    }
}
