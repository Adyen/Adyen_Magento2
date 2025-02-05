<?php

namespace Adyen\Payment\Test\Unit\Model\Api;

use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Model\Sales\OrderRepository;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Payment\Gateway\Command\CommandPoolInterface;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Model\InfoInterface;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectFactoryInterface;
use Adyen\Payment\Model\Api\GuestAdyenPosCloud;
use PHPUnit\Framework\MockObject\MockObject;

class GuestAdyenPosCloudTest extends AbstractAdyenTestCase
{
    protected GuestAdyenPosCloud $guestAdyenPosCloud;
    private CommandPoolInterface|MockObject $commandPoolMock;
    protected AdyenLogger|MockObject $adyenLoggerMock;
    protected OrderRepository|MockObject $orderRepositoryMock;
    protected PaymentDataObjectFactoryInterface|MockObject $paymentDataObjectFactoryMock;
    private MaskedQuoteIdToQuoteIdInterface|MockObject $maskedQuoteIdToQuoteIdMock;

    protected function setUp(): void
    {
        $this->commandPoolMock = $this->createMock(CommandPoolInterface::class);
        $this->orderRepositoryMock = $this->createMock(OrderRepository::class);
        $this->paymentDataObjectFactoryMock = $this->createMock(
            PaymentDataObjectFactoryInterface::class
        );
        $this->adyenLoggerMock = $this->createMock(AdyenLogger::class);
        $this->maskedQuoteIdToQuoteIdMock = $this->createMock(MaskedQuoteIdToQuoteIdInterface::class);

        $this->guestAdyenPosCloud = new GuestAdyenPosCloud(
            $this->commandPoolMock,
            $this->orderRepositoryMock,
            $this->paymentDataObjectFactoryMock,
            $this->adyenLoggerMock,
            $this->maskedQuoteIdToQuoteIdMock
        );
    }

    public function testPayByCartSuccessfully()
    {
        $catId = '123';
        $quoteId = 11;

        $this->maskedQuoteIdToQuoteIdMock->expects($this->once())->method('execute')->willReturn($quoteId);

        $commandInterfaceMock = $this->createMock(CommandInterface::class);
        $this->commandPoolMock->method('get')
            ->with('authorize')
            ->willReturn($commandInterfaceMock);

        $paymentInfoMock = $this->createMock(InfoInterface::class);

        $orderMock = $this->createMock(OrderInterface::class);
        $orderMock->method('getPayment')->willReturn($paymentInfoMock);

        $this->orderRepositoryMock->expects($this->once())
            ->method('getOrderByQuoteId')
            ->with($quoteId)
            ->willReturn($orderMock);

        $this->guestAdyenPosCloud->payByCart($catId);
    }
}
