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

namespace Adyen\Payment\Test\Unit\Model\Api;

use Adyen\Payment\Helper\Data;
use Adyen\Payment\Helper\PaymentResponseHandler;
use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Model\Api\GuestAdyenOrderPaymentStatus;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Framework\Exception\NotFoundException;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;

class GuestAdyenOrderPaymentStatusTest extends AbstractAdyenTestCase
{
    protected ?GuestAdyenOrderPaymentStatus $guestAdyenOrderPaymentStatus;
    protected OrderRepositoryInterface|MockObject $orderRepositoryMock;
    protected AdyenLogger|MockObject $adyenLoggerMock;
    protected Data|MockObject $adyenHelperMock;
    protected PaymentResponseHandler|MockObject $paymentResponseHandlerMock;
    protected MaskedQuoteIdToQuoteIdInterface|MockObject $maskedQuoteIdToQuoteIdMock;

    protected function setUp(): void
    {
        $this->orderRepositoryMock = $this->createMock(OrderRepositoryInterface::class);
        $this->adyenLoggerMock = $this->createMock(AdyenLogger::class);
        $this->adyenHelperMock = $this->createMock(Data::class);
        $this->paymentResponseHandlerMock = $this->createPartialMock(PaymentResponseHandler::class, []);
        $this->maskedQuoteIdToQuoteIdMock = $this->createMock(MaskedQuoteIdToQuoteIdInterface::class);

        $this->guestAdyenOrderPaymentStatus = new GuestAdyenOrderPaymentStatus(
            $this->orderRepositoryMock,
            $this->adyenLoggerMock,
            $this->adyenHelperMock,
            $this->paymentResponseHandlerMock,
            $this->maskedQuoteIdToQuoteIdMock
        );
    }

    protected function tearDown(): void
    {
        $this->guestAdyenOrderPaymentStatus = null;
    }

    /**
     * Assert exception if input quote id does not match with the order's quote id.
     *
     * @return void
     * @throws NotFoundException
     */
    public function testGetOrderPaymentStatusQuoteIdMismatch()
    {
        $this->expectException(NotFoundException::class);

        $cartId = 'abcdefg123456789abcdef';
        $quoteId = 75;
        $orderId = '50';
        $orderQuoteId = 200;

        $this->maskedQuoteIdToQuoteIdMock->expects($this->once())
            ->method('execute')
            ->with($cartId)
            ->willReturn($quoteId);

        $orderMock = $this->createMock(Order::class);
        $orderMock->expects($this->once())
            ->method('getQuoteId')
            ->willReturn($orderQuoteId);

        $this->orderRepositoryMock->expects($this->once())
            ->method('get')
            ->with($orderId)
            ->willReturn($orderMock);

        $this->guestAdyenOrderPaymentStatus->getOrderPaymentStatus($orderId, $cartId);
    }

    /**
     * @return array[]
     */
    private static function testDataProvider(): array
    {
        return [
            [
                'resultCode' => PaymentResponseHandler::AUTHORISED,
                'expectedResultCode' => PaymentResponseHandler::AUTHORISED
            ],
            [
                'resultCode' => null,
                'expectedResultCode' => PaymentResponseHandler::ERROR
            ],
        ];
    }

    /**
     * Assert json result type and check the existence of required response fields
     *
     * @dataProvider testDataProvider
     *
     * @return void
     * @throws NotFoundException
     */
    public function testGetOrderPaymentStatusSuccess($resultCode, $expectedResultCode)
    {
        $cartId = 'abcdefg123456789abcdef';
        $quoteId = 75;
        $orderId = '50';
        $orderQuoteId = 75;

        $this->maskedQuoteIdToQuoteIdMock->expects($this->once())
            ->method('execute')
            ->with($cartId)
            ->willReturn($quoteId);

        $additionalInformation = [
            'resultCode' => $resultCode
        ];

        $paymentMock = $this->createMock(Order\Payment::class);
        $paymentMock->expects($this->once())
            ->method('getAdditionalInformation')
            ->willReturn($additionalInformation);

        $orderMock = $this->createMock(Order::class);
        $orderMock->method('getPayment')->willReturn($paymentMock);
        $orderMock->expects($this->once())
            ->method('getQuoteId')
            ->willReturn($orderQuoteId);

        $this->orderRepositoryMock->expects($this->once())
            ->method('get')
            ->with($orderId)
            ->willReturn($orderMock);

        $result = $this->guestAdyenOrderPaymentStatus->getOrderPaymentStatus($orderId, $cartId);

        // Result should contain error object
        $this->assertJson($result);
        $decodedResult = json_decode($result, true);
        $this->assertArrayHasKey('isFinal', $decodedResult);
        $this->assertArrayHasKey('resultCode', $decodedResult);
        $this->assertTrue($decodedResult['isFinal']);
        $this->assertEquals($expectedResultCode, $decodedResult['resultCode']);
    }
}
