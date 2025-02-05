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

namespace Adyen\Payment\Test\Unit\Model\Api;

use Adyen\Payment\Helper\StateData;
use Adyen\Payment\Model\Api\GuestAdyenStateData;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use PHPUnit\Framework\MockObject\MockObject;

class GuestAdyenStateDataTest extends AbstractAdyenTestCase
{
    private GuestAdyenStateData $guestAdyenStateDataModel;
    private StateData|MockObject $stateDataHelperMock;
    private MaskedQuoteIdToQuoteIdInterface|MockObject $maskedQuoteIdToQuoteIdMock;

    protected function setUp(): void
    {
        $this->maskedQuoteIdToQuoteIdMock = $this->createMock(MaskedQuoteIdToQuoteIdInterface::class);
        $this->stateDataHelperMock = $this->createMock(StateData::class);

        $this->guestAdyenStateDataModel = new GuestAdyenStateData(
            $this->stateDataHelperMock,
            $this->maskedQuoteIdToQuoteIdMock
        );
    }

    public function testSaveSuccessful()
    {
        $stateData = '{"stateData":"dummyData"}';
        $cartId = 'ABC123456789';

        $this->maskedQuoteIdToQuoteIdMock->method('execute')->willReturn(1);

        $stateDataMock = $this->createMock(\Adyen\Payment\Model\StateData::class);
        $stateDataMock->method('getEntityId')->willReturn(1);

        $this->stateDataHelperMock->expects($this->once())->method('saveStateData')->willReturn($stateDataMock);

        $this->guestAdyenStateDataModel->save($stateData, $cartId);
    }

    public function testRemoveSuccessful()
    {
        $stateDataId = 1;
        $cartId = 'ABC123456789';

        $this->maskedQuoteIdToQuoteIdMock->method('execute')->willReturn(1);

        $this->stateDataHelperMock->expects($this->once())->method('removeStateData');
        $this->guestAdyenStateDataModel->remove($stateDataId, $cartId);
    }

    public function testSaveException()
    {
        $this->expectException(InputException::class);

        $stateData = '{"stateData":"dummyData"}';
        $cartId = '';

        $this->maskedQuoteIdToQuoteIdMock->method('execute')->willThrowException(new NoSuchEntityException());


        $this->guestAdyenStateDataModel->save($stateData, $cartId);
    }
}
