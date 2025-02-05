<?php

namespace Adyen\Payment\Test\Unit\Model\Api;

use Adyen\Payment\Model\Api\GuestAdyenPaymentMethodManagement;
use Adyen\Payment\Helper\PaymentMethods;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use PHPUnit\Framework\MockObject\MockObject;

class GuestAdyenPaymentMethodManagementTest extends AbstractAdyenTestCase
{
    /** @var MaskedQuoteIdToQuoteIdInterface|MockObject */
    private MaskedQuoteIdToQuoteIdInterface|MockObject $maskedQuoteIdToQuoteIdMock;

    /** @var PaymentMethods|MockObject */
    private PaymentMethods|MockObject $paymentMethodsHelperMock;

    /** @var GuestAdyenPaymentMethodManagement */
    private GuestAdyenPaymentMethodManagement $guestAdyenPaymentMethodManagement;

    protected function setUp(): void
    {
        $this->maskedQuoteIdToQuoteIdMock = $this->createMock(MaskedQuoteIdToQuoteIdInterface::class);
        $this->paymentMethodsHelperMock = $this->createMock(PaymentMethods::class);

        $this->guestAdyenPaymentMethodManagement = new GuestAdyenPaymentMethodManagement(
            $this->paymentMethodsHelperMock,
            $this->maskedQuoteIdToQuoteIdMock
        );
    }

    public function testGetPaymentMethods()
    {
        $cartId = '123';
        $quoteId = 123;
        $shopperLocale = 'en_US';
        $country = 'US';
        $channel = 'Web';
        $expectedPaymentMethods = 'sample_payment_methods';

        $this->maskedQuoteIdToQuoteIdMock->expects($this->once())->method('execute')->willReturn($quoteId);

        $this->paymentMethodsHelperMock->expects($this->once())
            ->method('getPaymentMethods')
            ->with($quoteId, $country, $shopperLocale, $channel)
            ->willReturn($expectedPaymentMethods);

        $result = $this->guestAdyenPaymentMethodManagement->getPaymentMethods($cartId, $shopperLocale, $country, $channel);

        $this->assertEquals($expectedPaymentMethods, $result);
    }
}
