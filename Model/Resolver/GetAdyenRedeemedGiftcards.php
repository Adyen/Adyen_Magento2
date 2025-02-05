<?php
/**
 *
 * Adyen Payment Module
 *
 * Copyright (c) 2024 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <magento@adyen.com>
 */
declare(strict_types=1);

namespace Adyen\Payment\Model\Resolver;

use Adyen\Payment\Exception\GraphQlAdyenException;
use Adyen\Payment\Helper\GiftcardPayment;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Serialize\Serializer\Json;

class GetAdyenRedeemedGiftcards implements ResolverInterface
{
    /**
     * @param GiftcardPayment $giftcardPayment
     * @param Json $jsonSerializer
     * @param MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId
     */
    public function __construct(
        private readonly GiftcardPayment $giftcardPayment,
        private readonly Json $jsonSerializer,
        private readonly MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId
    ) { }

    /**
     * @param Field $field
     * @param $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return array
     * @throws GraphQlAdyenException
     * @throws GraphQlInputException
     * @throws NoSuchEntityException
     */
    public function resolve(
        Field       $field,
                    $context,
        ResolveInfo $info,
        array       $value = null,
        array       $args = null
    ) {
        if (empty($args['cartId'])) {
            throw new GraphQlInputException(__('Required parameter "cartId" is missing'));
        }

        $quoteId = $this->maskedQuoteIdToQuoteId->execute($args['cartId']);

        try {
            $redeemedGiftcardsJson = $this->giftcardPayment->fetchRedeemedGiftcards($quoteId);
        } catch (\Exception $e) {
            throw new GraphQlAdyenException(
                __('An error occurred while fetching redeemed gift cards: %1', $e->getMessage())
            );
        }

        $redeemedGiftcardsData = $this->jsonSerializer->unserialize($redeemedGiftcardsJson);

        return [
            'redeemedGiftcards' => $redeemedGiftcardsData['redeemedGiftcards'],
            'remainingAmount' => $redeemedGiftcardsData['remainingAmount'],
            'totalDiscount' => $redeemedGiftcardsData['totalDiscount']
        ];
    }
}
