<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2021 Adyen BV (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Test\Unit\Block\Checkout;

use Adyen\Payment\Block\Checkout\Success;
use Adyen\Payment\Helper\Config;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Model\Ui\AdyenCheckoutSuccessConfigProvider;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Quote\Model\QuoteIdToMaskedQuoteId;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;

class SuccessTest extends AbstractAdyenTestCase
{
    private Success $successBlock;
    private CheckoutSession|MockObject $checkoutSessionMock;
    private CustomerSession|MockObject $customerSessionMock;
    private OrderFactory|MockObject $orderFactoryMock;
    private Order|MockObject $orderMock;
    private QuoteIdToMaskedQuoteId|MockObject $quoteIdToMaskedQuoteIdMock;
    private OrderRepositoryInterface|MockObject $orderRepositoryMock;
    private Context|MockObject $contextMock;
    private Data|MockObject $adyenDataHelper;
    private Config|MockObject $configMock;
    private AdyenCheckoutSuccessConfigProvider|MockObject $adyenCheckoutSuccessConfigProviderMock;
    private StoreManagerInterface|MockObject $storeManagerMock;
    private SerializerInterface|MockObject $serializerMock;

    protected function setUp(): void
    {
        $this->checkoutSessionMock = $this->createGeneratedMock(
            CheckoutSession::class,
            ['getLastOrderId']
        );
        $this->customerSessionMock = $this->createGeneratedMock(
            CustomerSession::class,
            ['isLoggedIn']
        );
        $this->orderFactoryMock = $this->createGeneratedMock(OrderFactory::class, ['create']);
        $this->orderMock = $this->createMock(Order::class);
        $this->quoteIdToMaskedQuoteIdMock = $this->createMock(QuoteIdToMaskedQuoteId::class);
        $this->adyenDataHelper = $this->createMock(Data::class);
        $this->contextMock = $this->createMock(Context::class);
        $this->orderRepositoryMock = $this->createMock(OrderRepositoryInterface::class);
        $this->configMock = $this->createMock(Config::class);
        $this->adyenCheckoutSuccessConfigProviderMock =
            $this->createMock(AdyenCheckoutSuccessConfigProvider::class);
        $this->storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $this->serializerMock = $this->createMock(SerializerInterface::class);

        $this->successBlock = new Success(
            $this->contextMock,
            $this->checkoutSessionMock,
            $this->customerSessionMock,
            $this->quoteIdToMaskedQuoteIdMock,
            $this->orderFactoryMock,
            $this->adyenDataHelper,
            $this->configMock,
            $this->adyenCheckoutSuccessConfigProviderMock,
            $this->storeManagerMock,
            $this->serializerMock,
            $this->orderRepositoryMock
        );
    }

    public function testGetMaskedQuoteIdSuccessful()
    {
        $orderId = 100;
        $maskedQuoteId = 'masked_id_123';
        $quoteId = 1;

        $this->checkoutSessionMock->method('getLastOrderId')->willReturn($orderId);
        $this->orderRepositoryMock->method('get')->with($orderId)->willReturn($this->orderMock);
        $this->orderMock->method('getQuoteId')->willReturn($quoteId);
        $this->quoteIdToMaskedQuoteIdMock->method('execute')->willReturn($maskedQuoteId);

        $result = $this->successBlock->getMaskedQuoteId();
        $this->assertEquals($maskedQuoteId, $result);
    }

    public function testGetMaskedQuoteIdException()
    {
        $orderId = 100;
        $exception = new \Exception('Error during getMaskedQuoteId');

        $this->checkoutSessionMock->method('getLastOrderId')->willReturn($orderId);
        $this->orderRepositoryMock->method('get')->with($orderId)->willThrowException($exception);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Error during getMaskedQuoteId');

        $this->successBlock->getMaskedQuoteId();
    }

    public function testGetIsCustomerLoggedIn()
    {
        $this->customerSessionMock->method('isLoggedIn')->willReturn(true);

        $this->assertTrue($this->successBlock->getIsCustomerLoggedIn());
    }
}
