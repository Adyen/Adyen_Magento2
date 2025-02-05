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

namespace Adyen\Payment\Test\Plugin;

use Adyen\Payment\Helper\PaymentMethods;
use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Plugin\GuestPaymentInformationResetOrderId;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Checkout\Api\GuestPaymentInformationManagementInterface;
use Magento\Framework\Exception\NotFoundException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Magento\Quote\Model\Quote;

class GuestPaymentInformationResetOrderIdTest extends AbstractAdyenTestCase
{
    protected ?GuestPaymentInformationResetOrderId $guestPaymentInformationResetOrderId;
    protected CartRepositoryInterface $quoteRepositoryMock;
    protected PaymentMethods $paymentMethodsHelperMock;
    protected AdyenLogger $adyenLoggerMock;
    protected MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteIdMock;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->quoteRepositoryMock = $this->createMock(CartRepositoryInterface::class);
        $this->paymentMethodsHelperMock = $this->createMock(PaymentMethods::class);
        $this->adyenLoggerMock = $this->createMock(AdyenLogger::class);
        $this->maskedQuoteIdToQuoteIdMock = $this->createMock(MaskedQuoteIdToQuoteIdInterface::class);

        $this->guestPaymentInformationResetOrderId = new GuestPaymentInformationResetOrderId(
            $this->quoteRepositoryMock,
            $this->paymentMethodsHelperMock,
            $this->adyenLoggerMock,
            $this->maskedQuoteIdToQuoteIdMock
        );
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->guestPaymentInformationResetOrderId = null;
    }

    /**
     * @return void
     */
    public function testBeforeSavePaymentInformationAndPlaceOrder()
    {
        $cartId = 'abc123456789abcde';
        $quoteId = 1;
        $method = 'adyen_ideal';

        $this->maskedQuoteIdToQuoteIdMock->expects($this->once())
            ->method('execute')
            ->with($cartId)
            ->willReturn($quoteId);

        $paymentMock = $this->createMock(Quote\Payment::class);
        $paymentMock->expects($this->once())
            ->method('getMethod')
            ->willReturn($method);

        $quoteMock = $this->createMock(Quote::class);
        $quoteMock->expects($this->once())
            ->method('getPayment')
            ->willReturn($paymentMock);

        $this->quoteRepositoryMock->expects($this->once())
            ->method('get')
            ->with($quoteId)
            ->willReturn($quoteMock);

        $this->paymentMethodsHelperMock->expects($this->once())
            ->method('isAdyenPayment')
            ->with($method)
            ->willReturn(true);

        $quoteMock->expects($this->once())
            ->method('setReservedOrderId')
            ->with(null);

        $mockArgument = $this->createMock(GuestPaymentInformationManagementInterface::class);

        $result = $this->guestPaymentInformationResetOrderId
            ->beforeSavePaymentInformationAndPlaceOrder($mockArgument, $cartId);

        $this->assertNull($result);
    }

    /**
     * @return void
     */
    public function testBeforeSavePaymentInformationAndPlaceOrderNonAdyenMethod()
    {
        $cartId = 'abc123456789abcde';
        $quoteId = 1;
        $method = 'non_adyen';

        $this->maskedQuoteIdToQuoteIdMock->expects($this->once())
            ->method('execute')
            ->with($cartId)
            ->willReturn($quoteId);

        $paymentMock = $this->createMock(Quote\Payment::class);
        $paymentMock->expects($this->once())
            ->method('getMethod')
            ->willReturn($method);

        $quoteMock = $this->createMock(Quote::class);
        $quoteMock->expects($this->once())
            ->method('getPayment')
            ->willReturn($paymentMock);

        $this->quoteRepositoryMock->expects($this->once())
            ->method('get')
            ->with($quoteId)
            ->willReturn($quoteMock);

        $this->paymentMethodsHelperMock->expects($this->once())
            ->method('isAdyenPayment')
            ->with($method)
            ->willReturn(false);

        $quoteMock->expects($this->never())->method('setReservedOrderId');

        $mockArgument = $this->createMock(GuestPaymentInformationManagementInterface::class);
        $this->guestPaymentInformationResetOrderId->beforeSavePaymentInformationAndPlaceOrder($mockArgument, $cartId);
    }

    /**
     * @return void
     */
    public function testBeforeSavePaymentInformationAndPlaceOrderException()
    {
        $cartId = 'abc123456789abcde';

        $this->maskedQuoteIdToQuoteIdMock->expects($this->once())
            ->method('execute')
            ->with($cartId)
            ->willThrowException(new NotFoundException(__('Not found')));

        $this->adyenLoggerMock->expects($this->once())->method('error');

        $mockArgument = $this->createMock(GuestPaymentInformationManagementInterface::class);
        $result = $this->guestPaymentInformationResetOrderId
            ->beforeSavePaymentInformationAndPlaceOrder($mockArgument, $cartId);

        $this->assertNull($result);
    }
}
