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

namespace Adyen\Payment\Test\Unit\Helper;

use Adyen\Payment\Helper\StateData;
use Adyen\Payment\Helper\Util\CheckoutStateDataValidator;
use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Model\ResourceModel\StateData as StateDataResourceModel;
use Adyen\Payment\Model\ResourceModel\StateData\Collection as StateDataCollection;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Adyen\Payment\Model\StateDataFactory;
use PHPUnit\Framework\MockObject\MockObject;

class StateDataTest extends AbstractAdyenTestCase
{
    private StateData $stateDataHelper;
    private StateDataCollection|MockObject $stateDataCollectionMock;
    private StateDataFactory|MockObject $stateDataFactoryMock;
    private StateDataResourceModel|MockObject $stateDataResourceModelMock;
    private CheckoutStateDataValidator|MockObject $checkoutStateDataValidatorMock;
    private AdyenLogger|MockObject $adyenLoggerMock;

    protected function setUp(): void
    {
        $this->stateDataCollectionMock = $this->createMock(StateDataCollection::class);
        $this->stateDataResourceModelMock = $this->createMock(StateDataResourceModel::class);
        $this->checkoutStateDataValidatorMock = $this->createMock(CheckoutStateDataValidator::class);
        $this->adyenLoggerMock = $this->createMock(AdyenLogger::class);

        $stateDataMock = $this->createMock(\Adyen\Payment\Model\StateData::class);

        $this->stateDataFactoryMock = $this->createGeneratedMock(StateDataFactory::class, ['create']);
        $this->stateDataFactoryMock->method('create')->willReturn($stateDataMock);

        $this->stateDataHelper = new StateData(
            $this->stateDataCollectionMock,
            $this->stateDataFactoryMock,
            $this->stateDataResourceModelMock,
            $this->checkoutStateDataValidatorMock,
            $this->adyenLoggerMock
        );
    }

    public function testSaveStateDataSuccessful()
    {
        $stateData = '{"stateData":"dummyData"}';
        $quoteId = 1;

        $stateDataMock = $this->createConfiguredMock(\Adyen\Payment\Model\StateData::class, [
            'getData' => ['entity_id' => 1, 'quote_id' => 1]
        ]);

        $this->stateDataCollectionMock->method('addFieldToFilter')->willReturnSelf();
        $this->stateDataCollectionMock->method('getFirstItem')->willReturn($stateDataMock);
        $this->stateDataResourceModelMock->expects($this->once())->method('save');

        $this->stateDataHelper->saveStateData($stateData, $quoteId);
    }

    public function testRemoveStateDataSuccessful()
    {
        $stateDataId = 1;
        $quoteId = 1;

        $stateDataMock = $this->createConfiguredMock(\Adyen\Payment\Model\StateData::class, [
            'getData' => ['entity_id' => 1, 'quote_id' => 1]
        ]);

        $this->stateDataCollectionMock->method('addFieldToFilter')->willReturnSelf();
        $this->stateDataCollectionMock->method('getFirstItem')->willReturn($stateDataMock);

        $this->assertTrue($this->stateDataHelper->removeStateData($stateDataId, $quoteId));
    }

    public function testRemoveStateDataException()
    {
        $this->expectException(NoSuchEntityException::class);

        $stateDataId = 1;
        $quoteId = 1;

        $stateDataMock = $this->createConfiguredMock(\Adyen\Payment\Model\StateData::class, [
            'getData' => null
        ]);

        $this->stateDataCollectionMock->method('addFieldToFilter')->willReturnSelf();
        $this->stateDataCollectionMock->method('getFirstItem')->willReturn($stateDataMock);

        $this->stateDataHelper->removeStateData($stateDataId, $quoteId);
    }

    /**
     * @dataProvider storedPaymentMethodIdProvider
     */
    public function testGetStoredPaymentMethodId($stateData, $expectedResult)
    {
        $this->assertEquals(
            $expectedResult,
            $this->stateDataHelper->getStoredPaymentMethodIdFromStateData($stateData)
        );
    }

    public static function storedPaymentMethodIdProvider(): array
    {
        $mockStoredPaymentMethodId = hash('md5', time());

        return [
            [
                'stateData' => [
                    'paymentMethod' => [
                        'storedPaymentMethodId' => $mockStoredPaymentMethodId
                    ]
                ],
                'expectedResult' => $mockStoredPaymentMethodId
            ],
            [
                'stateData' => [
                    'paymentMethod' => [
                        'storedPaymentMethodId' => null
                    ]
                ],
                'expectedResult' => null
            ],
            [
                'stateData' => [
                    'paymentMethod' => [
                        'type' => 'scheme'
                    ]
                ],
                'expectedResult' => null
            ]
        ];
    }
}
