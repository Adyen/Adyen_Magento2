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

use Adyen\Payment\Helper\GiftcardPayment;
use Adyen\Payment\Model\Api\GuestAdyenGiftcard;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use PHPUnit\Framework\MockObject\MockObject;

class GuestAdyenGiftcardTest extends AbstractAdyenTestCase
{
    protected ?GuestAdyenGiftcard $guestAdyenGiftcard;
    protected MockObject|GiftcardPayment $giftcardPaymentHelperMock;
    protected MockObject|MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->giftcardPaymentHelperMock = $this->createMock(GiftcardPayment::class);
        $this->maskedQuoteIdToQuoteId = $this->createMock(MaskedQuoteIdToQuoteIdInterface::class);

        $this->guestAdyenGiftcard = new GuestAdyenGiftcard(
            $this->giftcardPaymentHelperMock,
            $this->maskedQuoteIdToQuoteId
        );
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->guestAdyenGiftcard = null;
    }

    /**
     * @return void
     * @throws NoSuchEntityException
     */
    public function testGetRedeemedGiftcards()
    {
        $cartId = 'abc_123456789_xyz';
        $quoteId = 1;
        $mockResponse = '{}';

        $this->maskedQuoteIdToQuoteId->expects($this->once())
            ->method('execute')
            ->with($cartId)
            ->willReturn($quoteId);

        $this->giftcardPaymentHelperMock->expects($this->once())
            ->method('fetchRedeemedGiftcards')
            ->with($quoteId)
            ->willReturn($mockResponse);

        $result = $this->guestAdyenGiftcard->getRedeemedGiftcards($cartId);
        $this->assertIsString($result);
    }
}
