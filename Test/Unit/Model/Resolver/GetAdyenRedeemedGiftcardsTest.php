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
namespace Adyen\Payment\Test\Model\Resolver;

use Adyen\Payment\Exception\GraphQlAdyenException;
use Adyen\Payment\Helper\GiftcardPayment;
use Adyen\Payment\Model\Resolver\GetAdyenRedeemedGiftcards;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Exception;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use PHPUnit\Framework\MockObject\MockObject;

class GetAdyenRedeemedGiftcardsTest extends AbstractAdyenTestCase
{
    private GetAdyenRedeemedGiftcards $getAdyenRedeemedGiftcards;
    private GiftcardPayment|MockObject $giftcardPaymentMock;
    private Json|MockObject $jsonSerializerMock;
    private Field|MockObject $fieldMock;
    private ContextInterface|MockObject $contextMock;
    private ResolveInfo|MockObject $resolveInfoMock;
    private MaskedQuoteIdToQuoteIdInterface|MockObject $maskedQuoteIdToQuoteIdMock;

    protected function setUp(): void
    {
        $this->giftcardPaymentMock = $this->createMock(GiftcardPayment::class);
        $this->jsonSerializerMock = $this->createMock(Json::class);
        $this->maskedQuoteIdToQuoteIdMock = $this->createMock(MaskedQuoteIdToQuoteIdInterface::class);

        $this->fieldMock = $this->createMock(Field::class);
        $this->contextMock = $this->createMock(ContextInterface::class);
        $this->resolveInfoMock = $this->createMock(ResolveInfo::class);

        $this->getAdyenRedeemedGiftcards = new GetAdyenRedeemedGiftcards(
            $this->giftcardPaymentMock,
            $this->jsonSerializerMock,
            $this->maskedQuoteIdToQuoteIdMock
        );
    }

    public function testSuccessfulRetrievalOfRedeemedGiftCardDetailsWithValidCartId()
    {
        $cartId = 'test_cart_id';
        $quoteId = 0;
        $args = ['cartId' => $cartId];
        $redeemedGiftcardsJson = '{"redeemedGiftcards": [], "remainingAmount": 100, "totalDiscount": 10}';
        $redeemedGiftcardsData = json_decode($redeemedGiftcardsJson, true);

        $this->maskedQuoteIdToQuoteIdMock->expects($this->once())->method('execute')->willReturn($quoteId);

        $this->giftcardPaymentMock->expects($this->once())
            ->method('fetchRedeemedGiftcards')
            ->with($quoteId)
            ->willReturn($redeemedGiftcardsJson);

        $this->jsonSerializerMock->expects($this->once())
            ->method('unserialize')
            ->with($redeemedGiftcardsJson)
            ->willReturn($redeemedGiftcardsData);

        $result = $this->getAdyenRedeemedGiftcards->resolve($this->fieldMock, $this->contextMock, $this->resolveInfoMock, [], $args);

        $this->assertEquals(
            [
                'redeemedGiftcards' => $redeemedGiftcardsData['redeemedGiftcards'],
                'remainingAmount' => $redeemedGiftcardsData['remainingAmount'],
                'totalDiscount' => $redeemedGiftcardsData['totalDiscount']
            ],
            $result
        );
    }

    public function testFailedRetrievalOfRedeemedGiftCards()
    {
        $this->expectException(GraphQlAdyenException::class);

        $cartId = 'test_cart_id';
        $args = ['cartId' => $cartId];
        $quoteId = 1;

        $this->maskedQuoteIdToQuoteIdMock->expects($this->once())->method('execute')->willReturn($quoteId);

        $this->giftcardPaymentMock->method('fetchRedeemedGiftcards')
            ->willThrowException(new Exception());

        $this->getAdyenRedeemedGiftcards->resolve($this->fieldMock, $this->contextMock, $this->resolveInfoMock, [], $args);
    }

    public function testFailedRetrievalOfRedeemedGiftCardsWithNullCartId()
    {
        $this->expectException(GraphQlInputException::class);

        $args = ['cartId' => ""];

        $this->getAdyenRedeemedGiftcards->resolve($this->fieldMock, $this->contextMock, $this->resolveInfoMock, [], $args);
    }
}
