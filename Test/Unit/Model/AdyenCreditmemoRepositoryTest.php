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
use Adyen\Payment\Api\Data\CreditmemoInterface;
use Adyen\Payment\Api\Data\NotificationInterface;
use Adyen\Payment\Model\AdyenCreditmemoRepository;
use Adyen\Payment\Model\Creditmemo;
use Adyen\Payment\Model\ResourceModel\Creditmemo\Collection as CreditmemoCollection;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Adyen\Payment\Model\CreditmemoFactory;
use Adyen\Payment\Model\ResourceModel\Creditmemo\CollectionFactory;
use Adyen\Payment\Model\ResourceModel\Creditmemo\Creditmemo as CreditmemoResourceModel;
use Magento\Framework\Api\Search\SearchResultFactory;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Api\SearchCriteria\CollectionProcessor;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;

class AdyenCreditmemoRepositoryTest extends AbstractAdyenTestCase
{
    private ?AdyenCreditmemoRepository $adyenCreditmemoRepository;
    private CreditmemoFactory|MockObject $creditmemoFactoryMock;
    private CreditmemoResourceModel|MockObject $creditmemoResourceModelMock;
    private SearchResultFactory|MockObject $searchResultFactoryMock;
    private CollectionFactory|MockObject $collectionFactoryMock;
    private CollectionProcessor|MockObject $collectionProcessorMock;
    private SearchCriteriaBuilder|MockObject $searchCriteriaBuilderMock;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->creditmemoFactoryMock = $this->createGeneratedMock(CreditmemoFactory::class, [
            'create'
        ]);
        $this->creditmemoResourceModelMock = $this->createMock(CreditmemoResourceModel::class);
        $this->searchResultFactoryMock = $this->createMock(SearchResultFactory::class);
        $this->collectionFactoryMock = $this->createGeneratedMock(CollectionFactory::class, [
            'create'
        ]);
        $this->collectionProcessorMock = $this->createMock(CollectionProcessor::class);
        $this->searchCriteriaBuilderMock = $this->createMock(SearchCriteriaBuilder::class);

        $this->adyenCreditmemoRepository = new AdyenCreditmemoRepository(
            $this->creditmemoFactoryMock,
            $this->creditmemoResourceModelMock,
            $this->searchResultFactoryMock,
            $this->collectionFactoryMock,
            $this->collectionProcessorMock,
            $this->searchCriteriaBuilderMock
        );
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->adyenCreditmemoRepository = null;
    }

    /**
     * @return void
     */
    public function testGet()
    {
        $entityId = 1;
        $creditmemo = $this->createMock(Creditmemo::class);

        $this->creditmemoFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($creditmemo);

        $this->creditmemoResourceModelMock->expects($this->once())
            ->method('load')
            ->with($creditmemo, $entityId, CreditmemoInterface::ENTITY_ID)
            ->willReturnSelf();

        $result = $this->adyenCreditmemoRepository->get($entityId);
        $this->assertInstanceOf(CreditmemoInterface::class, $result);
    }

    /**
     * @return void
     */
    public function testGetList()
    {
        $searchResultMock = $this->createPartialMock(SearchResultInterface::class, []);
        $this->searchResultFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($searchResultMock);

        $collectionMock = $this->createMock(CreditmemoCollection::class);
        $collectionResult[] = $this->createMock(Creditmemo::class);
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

        $result = $this->adyenCreditmemoRepository->getList($searchCriteriaMock);

        $this->assertInstanceOf(SearchResultsInterface::class, $result);
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
            ->with(CreditmemoInterface::ADYEN_ORDER_PAYMENT_ID, $adyenOrderPaymentId)
            ->willReturnSelf();

        $this->searchCriteriaBuilderMock->expects($this->once())
            ->method('create')
            ->willReturn($searchCriteriaMock);

        $collectionResult[] = $this->createMock(Creditmemo::class);

        $searchResultMock = $this->createMock(SearchResultInterface::class);
        $searchResultMock->expects($this->once())
            ->method('getItems')
            ->willReturn($collectionResult);

        $this->searchResultFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($searchResultMock);

        $collectionMock = $this->createMock(CreditmemoCollection::class);
        $this->collectionFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($collectionMock);

        $result = $this->adyenCreditmemoRepository->getByAdyenOrderPaymentId($adyenOrderPaymentId);
        $this->assertIsArray($result);
    }

    /**
     * @return void
     * @throws AlreadyExistsException
     */
    public function testSave()
    {
        $creditmemo = $this->createMock(Creditmemo::class);

        $this->creditmemoResourceModelMock->expects($this->once())
            ->method('save')
            ->with($creditmemo)
            ->willReturnSelf();

        $result = $this->adyenCreditmemoRepository->save($creditmemo);
        $this->assertInstanceOf(CreditmemoInterface::class, $result);
    }

    /**
     * @return array[]
     */
    private static function webhookTestDataProvider(): array
    {
        return [
            [
                'eventCode' => 'REFUND',
                'isExpectedType' => true,
                'creditmemoId' => "1",
                'isResultValid' => true
            ],
            [
                'eventCode' => 'CAPTURE',
                'isExpectedType' => false,
                'creditmemoId' => "1",
                'isResultValid' => true
            ],
            [
                'eventCode' => 'REFUND',
                'isExpectedType' => true,
                'creditmemoId' => "",
                'isResultValid' => false
            ]
        ];
    }

    /**
     * @dataProvider webhookTestDataProvider
     *
     * @param $eventCode
     * @param $isExpectedType
     * @param $creditmemoId
     * @param $isResultValid
     * @return void
     * @throws AdyenException
     * @throws LocalizedException
     */
    public function testGetByRefundWebhook($eventCode, $isExpectedType, $creditmemoId, $isResultValid)
    {
        $notificationPspreference = 'xyz_12345';

        $notification = $this->createMock(NotificationInterface::class);
        $notification->method('getEventCode')->willReturn($eventCode);
        $notification->method('getPspreference')->willReturn($notificationPspreference);

        if (!$isExpectedType) {
            $this->expectException(AdyenException::class);

            // No result required, assert exception
            $this->adyenCreditmemoRepository->getByRefundWebhook($notification);
        } else {
            $this->creditmemoResourceModelMock->expects($this->once())
                ->method('getIdByPspreference')
                ->with($notificationPspreference)
                ->willReturn($creditmemoId);

            if ($isResultValid) {
                $creditmemo = $this->createMock(Creditmemo::class);
                $this->creditmemoFactoryMock->expects($this->once())
                    ->method('create')
                    ->willReturn($creditmemo);

                $this->creditmemoResourceModelMock->expects($this->once())
                    ->method('load')
                    ->with($creditmemo, $creditmemoId, CreditmemoInterface::ENTITY_ID)
                    ->willReturnSelf();

                $result = $this->adyenCreditmemoRepository->getByRefundWebhook($notification);
                $this->assertInstanceOf(CreditmemoInterface::class, $result);
            } else {
                $this->assertNull($this->adyenCreditmemoRepository->getByRefundWebhook($notification));
            }
        }
    }
}
