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
use Adyen\Payment\Api\Data\InvoiceInterface;
use Adyen\Payment\Api\Data\NotificationInterface;
use Adyen\Payment\Model\AdyenInvoiceRepository;
use Adyen\Payment\Model\Invoice;
use Adyen\Payment\Model\ResourceModel\Creditmemo\Collection as CreditmemoCollection;
use Adyen\Payment\Model\ResourceModel\Invoice\Collection as InvoiceCollection;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use Adyen\Payment\Model\ResourceModel\Invoice\CollectionFactory;
use Adyen\Payment\Model\ResourceModel\Invoice\Invoice as InvoiceResourceModel;
use Adyen\Payment\Model\InvoiceFactory;
use Magento\Framework\Api\Search\SearchResultFactory;
use Magento\Framework\Api\SearchCriteria\CollectionProcessor;
use Magento\Framework\Api\SearchCriteriaBuilder;

class AdyenInvoiceRepositoryTest extends AbstractAdyenTestCase
{
    private ?AdyenInvoiceRepository $adyenInvoiceRepository;
    private SearchResultFactory|MockObject $searchResultsFactoryMock;
    private CollectionFactory|MockObject $collectionFactoryMock;
    private CollectionProcessor|MockObject $collectionProcessorMock;
    private InvoiceResourceModel|MockObject $resourceModelMock;
    private InvoiceFactory|MockObject $adyenInvoiceFactoryMock;
    private SearchCriteriaBuilder|MockObject $searchCriteriaBuilderMock;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->searchResultsFactoryMock = $this->createMock(SearchResultFactory::class);
        $this->collectionFactoryMock = $this->createGeneratedMock(CollectionFactory::class, [
            'create'
        ]);
        $this->collectionProcessorMock = $this->createMock(CollectionProcessor::class);
        $this->resourceModelMock = $this->createMock(InvoiceResourceModel::class);
        $this->adyenInvoiceFactoryMock = $this->createGeneratedMock(InvoiceFactory::class, [
            'create'
        ]);
        $this->searchCriteriaBuilderMock = $this->createMock(SearchCriteriaBuilder::class);

        $this->adyenInvoiceRepository = new AdyenInvoiceRepository(
            $this->searchResultsFactoryMock,
            $this->collectionFactoryMock,
            $this->collectionProcessorMock,
            $this->resourceModelMock,
            $this->adyenInvoiceFactoryMock,
            $this->searchCriteriaBuilderMock
        );
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->adyenInvoiceRepository = null;
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

        $collectionMock = $this->createMock(InvoiceCollection::class);
        $collectionResult[] = $this->createMock(Invoice::class);
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

        $result = $this->adyenInvoiceRepository->getList($searchCriteriaMock);

        $this->assertInstanceOf(SearchResultsInterface::class, $result);
    }

    /**
     * @return void
     * @throws AlreadyExistsException
     */
    public function testSave()
    {
        $invoiceMock = $this->createMock(Invoice::class);

        $this->resourceModelMock->expects($this->once())
            ->method('save')
            ->with($invoiceMock)
            ->willReturnSelf();

        $result = $this->adyenInvoiceRepository->save($invoiceMock);
        $this->assertInstanceOf(InvoiceInterface::class, $result);
    }

    /**
     * @return void
     */
    public function testGetByAdyenOrderPaymentId()
    {
        $adyenOrderPaymentId = 1;

        $searchCriteriaMock = $this->createMock(SearchCriteriaInterface::class);
        $this->searchCriteriaBuilderMock->expects($this->once())
            ->method('addFilter')
            ->with(InvoiceInterface::ADYEN_ORDER_PAYMENT_ID, $adyenOrderPaymentId)
            ->willReturnSelf();

        $this->searchCriteriaBuilderMock->expects($this->once())
            ->method('create')
            ->willReturn($searchCriteriaMock);

        $collectionResult[] = $this->createMock(Invoice::class);

        $searchResultMock = $this->createMock(SearchResultInterface::class);
        $searchResultMock->expects($this->once())
            ->method('getItems')
            ->willReturn($collectionResult);

        $this->searchResultsFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($searchResultMock);

        $collectionMock = $this->createMock(CreditmemoCollection::class);
        $this->collectionFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($collectionMock);

        $result = $this->adyenInvoiceRepository->getByAdyenOrderPaymentId($adyenOrderPaymentId);
        $this->assertIsArray($result);
    }

    /**
     * @return array[]
     */
    private static function webhookTestDataProvider(): array
    {
        return [
            [
                'eventCode' => 'CAPTURE',
                'isExpectedType' => true,
                'invoiceId' => "1",
                'isResultValid' => true
            ],
            [
                'eventCode' => 'REFUND',
                'isExpectedType' => false,
                'invoiceId' => "1",
                'isResultValid' => true
            ],
            [
                'eventCode' => 'CAPTURE',
                'isExpectedType' => true,
                'invoiceId' => "",
                'isResultValid' => false
            ]
        ];
    }

    /**
     * @dataProvider webhookTestDataProvider
     *
     * @param $eventCode
     * @param $isExpectedType
     * @param $invoiceId
     * @param $isResultValid
     * @return void
     * @throws AdyenException
     * @throws LocalizedException
     */
    public function testGetByRefundWebhook($eventCode, $isExpectedType, $invoiceId, $isResultValid)
    {
        $notificationPspreference = 'xyz_12345';

        $notification = $this->createMock(NotificationInterface::class);
        $notification->method('getEventCode')->willReturn($eventCode);
        $notification->method('getPspreference')->willReturn($notificationPspreference);

        if (!$isExpectedType) {
            $this->expectException(AdyenException::class);

            // No result required, assert exception
            $this->adyenInvoiceRepository->getByCaptureWebhook($notification);
        } else {
            $this->resourceModelMock->expects($this->once())
                ->method('getIdByPspreference')
                ->with($notificationPspreference)
                ->willReturn($invoiceId);

            if ($isResultValid) {
                $invoiceMock = $this->createMock(Invoice::class);
                $this->adyenInvoiceFactoryMock->expects($this->once())
                    ->method('create')
                    ->willReturn($invoiceMock);

                $this->resourceModelMock->expects($this->once())
                    ->method('load')
                    ->with($invoiceMock, $invoiceId, InvoiceInterface::ENTITY_ID)
                    ->willReturnSelf();

                $result = $this->adyenInvoiceRepository->getByCaptureWebhook($notification);
                $this->assertInstanceOf(InvoiceInterface::class, $result);
            } else {
                $this->assertNull($this->adyenInvoiceRepository->getByCaptureWebhook($notification));
            }
        }
    }
}




