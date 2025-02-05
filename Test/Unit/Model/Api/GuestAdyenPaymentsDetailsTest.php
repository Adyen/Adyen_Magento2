<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2024 Adyen N.V. (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Test\Unit\Model\Api;

use Adyen\Payment\Model\Api\AdyenPaymentsDetails;
use Adyen\Payment\Model\Api\GuestAdyenPaymentsDetails;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Framework\Exception\NotFoundException;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;

class GuestAdyenPaymentsDetailsTest extends AbstractAdyenTestCase
{
    private GuestAdyenPaymentsDetails $guestAdyenPaymentsDetails;
    private OrderRepositoryInterface|MockObject $orderRepositoryMock;
    private AdyenPaymentsDetails|MockObject $adyenPaymentsDetailsMock;
    private MaskedQuoteIdToQuoteIdInterface|MockObject $maskedQuoteIdToQuoteIdMock;

    protected function setUp(): void
    {
        $this->orderRepositoryMock = $this->createMock(OrderRepositoryInterface::class);
        $this->adyenPaymentsDetailsMock = $this->createMock(AdyenPaymentsDetails::class);
        $this->maskedQuoteIdToQuoteIdMock = $this->createMock(MaskedQuoteIdToQuoteIdInterface::class);

        $this->guestAdyenPaymentsDetails = new GuestAdyenPaymentsDetails(
            $this->orderRepositoryMock,
            $this->adyenPaymentsDetailsMock,
            $this->maskedQuoteIdToQuoteIdMock
        );
    }

    public function testSuccessfulCall()
    {
        $payload = '{"someData":"someValue"}';
        $result = '{"resultCode": "Authorised", "isFinal": "true"}';
        $orderId = 1;
        $maskedCartId = 'abcdef123456';
        $cartId = 99;
        $orderQuoteId = 99;

        $this->maskedQuoteIdToQuoteIdMock->expects($this->once())->method('execute')->willReturn($cartId);

        $orderMock = $this->createMock(OrderInterface::class);
        $orderMock->method('getQuoteId')->willReturn($orderQuoteId);

        $this->orderRepositoryMock->method('get')
            ->willReturn($orderMock);

        $this->adyenPaymentsDetailsMock->method('initiate')
            ->willReturn($result);

        $response = $this->guestAdyenPaymentsDetails->initiate($payload, $orderId, $maskedCartId);

        $this->assertJson($response);
        $this->assertArrayHasKey('isFinal', json_decode($response, true));
        $this->assertArrayHasKey('resultCode', json_decode($response, true));
    }

    public function testWrongCartId()
    {
        $this->expectException(NotFoundException::class);

        $payload = '{"someData":"someValue"}';
        $orderId = 1;
        $maskedCartId = 'abcdef123456';
        $cartId = 99;
        $orderQuoteId = 200;

        $this->maskedQuoteIdToQuoteIdMock->expects($this->once())->method('execute')->willReturn($cartId);
        
        $orderMock = $this->createMock(OrderInterface::class);
        $orderMock->method('getQuoteId')->willReturn($orderQuoteId);

        $this->orderRepositoryMock->method('get')
            ->willReturn($orderMock);

        $this->guestAdyenPaymentsDetails->initiate($payload, $orderId, $maskedCartId);
    }
}
