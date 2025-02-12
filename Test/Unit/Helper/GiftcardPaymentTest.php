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

namespace Adyen\Payment\Test\Unit\Helper;

use Adyen\Payment\Helper\Data;
use Adyen\Payment\Helper\GiftcardPayment;
use Adyen\Payment\Model\ResourceModel\StateData\Collection as StateDataCollection;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Directory\Model\Currency;
use Magento\Framework\Pricing\Helper\Data as PricingData;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;

class GiftcardPaymentTest extends AbstractAdyenTestCase
{
    const CREDIT_CARD_REQUEST = <<<JSON
    {
      "merchantAccount": "MERCHANT_ACCOUNT",
      "shopperReference": "003",
      "shopperEmail": "roni_cost@example.com",
      "telephoneNumber": "+00000000000000",
      "shopperName": {
        "firstName": "Test",
        "lastName": "Account"
      },
      "countryCode": "NL",
      "shopperLocale": "en_US",
      "shopperIP": "192.168.58.1",
      "billingAddress": {
        "street": "Superstreet",
        "postalCode": "1011 HJ",
        "city": "Amsterdam",
        "houseNumberOrName": "10B",
        "country": "NL",
        "stateOrProvince": "1"
      },
      "deliveryAddress": {
        "street": "Superstreet",
        "postalCode": "1011 HJ",
        "city": "Amsterdam",
        "houseNumberOrName": "10B",
        "country": "NL",
        "stateOrProvince": "1"
      },
      "amount": {
        "currency": "EUR",
        "value": 5900
      },
      "reference": "000000001",
      "fraudOffset": "0",
      "browserInfo": {
        "userAgent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/117.0.0.0 Safari/537.36",
        "acceptHeader": "*/*",
        "colorDepth": 24,
        "language": "en-GB",
        "javaEnabled": false,
        "screenHeight": 1440,
        "screenWidth": 2560,
        "timeZoneOffset": -120
      },
      "storePaymentMethod": false,
      "shopperInteraction": "Ecommerce",
      "paymentMethod": {
        "type": "scheme",
        "holderName": "",
        "encryptedCardNumber": "PLACEHOLDER_CREDIT_CARD_NUMBER",
        "encryptedExpiryMonth": "PLACEHOLDER_CREDIT_CARD_EXPIRY_MONTH",
        "encryptedExpiryYear": "PLACEHOLDER_CREDIT_CARD_EXPIRY_YEAR",
        "encryptedSecurityCode": "PLACEHOLDER_CREDIT_CARD_CCV",
        "checkoutAttemptId": "PLACEHOLDER_CREDIT_CARD_CHECKOUT_ATTEMPT"
      },
      "riskData": {
        "clientData": "eyJ2ZXJzaW9uIjoiMS4wLjAiLCJkZXZpY2VGaW5nZXJwcmludCI6IkRwcXdVNHpFZE4wMDUwMDAwMDAwMDAwMDAwS1piSVFqNmt6czAwODkxNDY3NzZjVkI5NGlLekJHaFB0cWFLdVNwdEJpeDdSWDNhejgwMDJUWEZScVFVbkdFMDAwMDBZVnhFcjAwMDAwdGYzUVdxcHhsaEdVeTJqOGdGR1Q6NDAiLCJwZXJzaXN0ZW50Q29va2llIjpbXX0\u003d"
      },
      "additionalData": {
        "allow3DS2": true
      },
      "returnUrl": "https://192.168.58.20/index.php/adyen/return?merchantReference\u003d000000474",
      "channel": "web",
      "origin": "https://192.168.58.20/index.php/"
    }
    JSON;

    const STATE_DATA = <<<JSON
    {
      "paymentMethod": {
        "type": "giftcard",
        "brand": "svs",
        "encryptedCardNumber": "PLACEHOLDER_GIFT_CARD_NUMBER",
        "encryptedSecurityCode": "PLACEHOLDER_GIFT_CARD_PIN"
      },
      "giftcard": {
        "balance": {
          "currency": "EUR",
          "value": 5000
        },
        "title": "SVS"
      }
    }
    JSON;

    const STATE_DATA_NEGATIVE_BALANCE = <<<JSON
    {
      "paymentMethod": {
        "type": "giftcard",
        "brand": "svs",
        "encryptedCardNumber": "PLACEHOLDER_GIFT_CARD_NUMBER",
        "encryptedSecurityCode": "PLACEHOLDER_GIFT_CARD_PIN"
      },
      "giftcard": {
        "balance": {
          "currency": "EUR",
          "value": -2000
        },
        "title": "SVS"
      }
    }
    JSON;

    const ORDER_DATA = <<<JSON
    {
      "pspReference": "ABC12345DEF",
      "resultCode": "Success",
      "amount": {
        "currency": "EUR",
        "value": 5900
      },
      "expiresAt": "2030-01-01T00:00:00Z",
      "orderData": "PLACEHOLDER_ORDER_DATA",
      "reference": "000000001",
      "remainingAmount": {
        "currency": "EUR",
        "value": 5900
      }
    }
    JSON;

    const EXPECTECTED_GIFTCARD_PAYMENT_REQUEST = <<<JSON
    {
      "merchantAccount": "MERCHANT_ACCOUNT",
      "shopperReference": "003",
      "shopperEmail": "roni_cost@example.com",
      "telephoneNumber": "+00000000000000",
      "shopperName": {
        "firstName": "Test",
        "lastName": "Account"
      },
      "countryCode": "NL",
      "shopperLocale": "en_US",
      "shopperIP": "192.168.58.1",
      "billingAddress": {
        "street": "Superstreet",
        "postalCode": "1011 HJ",
        "city": "Amsterdam",
        "houseNumberOrName": "10B",
        "country": "NL",
        "stateOrProvince": "1"
      },
      "deliveryAddress": {
        "street": "Superstreet",
        "postalCode": "1011 HJ",
        "city": "Amsterdam",
        "houseNumberOrName": "10B",
        "country": "NL",
        "stateOrProvince": "1"
      },
      "amount": {
        "currency": "EUR",
        "value": 5000
      },
      "reference": "000000001",
      "additionalData": {
        "allow3DS2": true
      },
      "fraudOffset": "0",
      "browserInfo": {
        "userAgent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/117.0.0.0 Safari/537.36",
        "acceptHeader": "*/*",
        "colorDepth": 24,
        "language": "en-GB",
        "javaEnabled": false,
        "screenHeight": 1440,
        "screenWidth": 2560,
        "timeZoneOffset": -120
      },
      "shopperInteraction": "Ecommerce",
      "returnUrl": "https://192.168.58.20/index.php/adyen/return?merchantReference\u003d000000474",
      "channel": "web",
      "origin": "https://192.168.58.20/index.php/",
      "paymentMethod": {
        "type": "giftcard",
        "brand": "svs",
        "encryptedCardNumber": "PLACEHOLDER_GIFT_CARD_NUMBER",
        "encryptedSecurityCode": "PLACEHOLDER_GIFT_CARD_PIN"
      },
      "order": {
        "pspReference": "ABC12345DEF",
        "orderData": "PLACEHOLDER_ORDER_DATA"
      }
    }
    JSON;

    const STATE_DATA_COLLECTION = [
        [
            'entity_id' => 1,
            'quote_id' => 1,
            'state_data' => self::STATE_DATA
        ]
    ];

    const STATE_DATA_COLLECTION_NEGATIVE_BALANCE = [
        [
            'entity_id' => 1,
            'quote_id' => 1,
            'state_data' => self::STATE_DATA_NEGATIVE_BALANCE
        ]
    ];

    private $quote;

    public function testBuildGiftcardPaymentRequest(): void
    {
        $redeemedGiftcardAmount = 5000;
        $giftcardPaymentHelper = $this->createGiftcardPaymentHelper();

        $giftcardPaymentRequest = $giftcardPaymentHelper->buildGiftcardPaymentRequest(
            json_decode(self::CREDIT_CARD_REQUEST, true),
            json_decode(self::ORDER_DATA, true),
            json_decode(self::STATE_DATA, true),
            $redeemedGiftcardAmount
        );

        $this->assertEquals(
            json_decode(self::EXPECTECTED_GIFTCARD_PAYMENT_REQUEST, true),
            $giftcardPaymentRequest
        );
    }

    public function testGetQuoteGiftcardTotalBalance(): void
    {
        $quoteId = 1;
        $expectedGiftcardTotalBalance = 5000;

        $adyenStateDataMock = $this->createConfiguredMock(StateDataCollection::class, [
            'getStateDataRowsWithQuoteId' => $this->createConfiguredMock(StateDataCollection::class, [
                'getData' => self::STATE_DATA_COLLECTION
            ])
        ]);

        $giftcardPaymentHelper = $this->createGiftcardPaymentHelper($adyenStateDataMock);
        $totalBalance = $giftcardPaymentHelper->getQuoteGiftcardTotalBalance($quoteId);

        $this->assertEquals($expectedGiftcardTotalBalance, $totalBalance);
    }

    public function testGetQuoteGiftcardTotalBalanceWithInvalidState(): void
    {
        $quoteId = 1;

        $state = <<<JSON
    {
      "paymentMethod": {
        "type": "giftcard",
        "brand": "svs",
        "encryptedCardNumber": "PLACEHOLDER_GIFT_CARD_NUMBER",
        "encryptedSecurityCode": "PLACEHOLDER_GIFT_CARD_PIN"
      }
    }
    JSON;

        $adyenStateDataMock = $this->createConfiguredMock(StateDataCollection::class, [
            'getStateDataRowsWithQuoteId' => $this->createConfiguredMock(StateDataCollection::class, [
                'getData' => [
                    [
                        'entity_id' => 1,
                        'quote_id' => 1,
                        'state_data' => $state
                    ]
                ]
            ])
        ]);

        $giftcardPaymentHelper = $this->createGiftcardPaymentHelper($adyenStateDataMock);
        $totalBalance = $giftcardPaymentHelper->getQuoteGiftcardTotalBalance($quoteId);

        $this->assertEquals(0, $totalBalance, 'The total must be equal to 0 because the giftcard key is undefined.');
    }

    private static function discountTestDataProvider(): array
    {
        return [
            [
                '$quoteAmount' => 100.00,
                '$giftcardBalance' => 5000,
                '$expectedResult' => 5000
            ],
            [
                '$quoteAmount' => 100.00,
                '$giftcardBalance' => 2500,
                '$expectedResult' => 2500
            ],
            [
                '$quoteAmount' => 15.00,
                '$giftcardBalance' => 5000,
                '$expectedResult' => 1500
            ],
            [
                '$quoteAmount' => 100.00,
                '$giftcardBalance' => -9900,
                '$expectedResult' => 0
            ]
        ];
    }

    /**
     * @dataProvider discountTestDataProvider
     * @param float $quoteAmount
     * @param int $giftcardBalance
     * @param int $expectedResult
     * @return void
     */
    public function testGetQuoteGiftcardDiscount(float $quoteAmount, int $giftcardBalance, int $expectedResult): void
    {
        $stateDataCollectionMock = [
            [
                'entity_id' => 1,
                'quote_id' => 1,
                'state_data' => "{\"paymentMethod\":{\"type\":\"giftcard\",\"brand\":\"svs\",\"encryptedCardNumber\":\"PLACEHOLDER_GIFT_CARD_NUMBER\",\"encryptedSecurityCode\":\"PLACEHOLDER_GIFT_CARD_PIN\"},\"giftcard\":{\"balance\":{\"currency\":\"EUR\",\"value\":$giftcardBalance},\"title\":\"SVS\"}}"
            ]
        ];

        $this->quote = $this->createMockWithMethods(
            Quote::class,
            ['getId', 'getCurrency'],
            ['getGrandTotal']
        );

        $this->mockMethods($this->quote, [
            'getId' => 1,
            'getGrandTotal' => $quoteAmount,
            'getCurrency' => 'EUR'
        ]);

        $adyenStateDataMock = $this->createConfiguredMock(StateDataCollection::class, [
            'getStateDataRowsWithQuoteId' => $this->createConfiguredMock(StateDataCollection::class, [
                'getData' => $stateDataCollectionMock
            ])
        ]);

        $giftcardPaymentHelper = $this->createGiftcardPaymentHelper($adyenStateDataMock);
        $giftcardDiscount = $giftcardPaymentHelper->getQuoteGiftcardDiscount($this->quote);

        $this->assertEquals($expectedResult, $giftcardDiscount);
    }

    public function testFetchRedeemedGiftcards(): void
    {
        $this->quote = $this->createMockWithMethods(
            Quote::class,
            ['getId', 'getCurrency'],
            ['getGrandTotal', 'getQuoteCurrencyCode']
        );

        $this->mockMethods($this->quote, [
            'getId' => 1,
            'getGrandTotal' => 75.00,
            'getCurrency' => $this->createConfiguredMock(Currency::class, [
                'getCurrencyCode' => 'EUR'
            ]),
            'getQuoteCurrencyCode' => 'EUR'
        ]);

        $cartRepositoryMock = $this->createConfiguredMock(CartRepositoryInterface::class, [
            'get' => $this->quote
        ]);

        $adyenStateDataMock = $this->createConfiguredMock(StateDataCollection::class, [
            'getStateDataRowsWithQuoteId' => $this->createConfiguredMock(StateDataCollection::class, [
                'getData' => self::STATE_DATA_COLLECTION
            ])
        ]);

        $pricingHelper = $this->createConfiguredMock(PricingData::class, []);
        $pricingHelper->method('currency')->willReturnOnConsecutiveCalls('€50.00', '€25.00');

        $giftcardPaymentHelper = $this->createGiftcardPaymentHelper(
            $adyenStateDataMock,
            null,
            $pricingHelper,
            $cartRepositoryMock
        );

        $redeemedGiftcards = $giftcardPaymentHelper->fetchRedeemedGiftcards($this->quote->getId());
        $expected = '{"redeemedGiftcards":[{"stateDataId":1,"brand":"svs","title":"SVS","balance":{"currency":"EUR","value":5000},"deductedAmount":null}],"remainingAmount":"\u20ac25.00","totalDiscount":"\u20ac50.00"}';

        $this->assertEquals($expected, $redeemedGiftcards);
    }

    private function createGiftcardPaymentHelper(
        $adyenStateDataMock = null,
        $adyenHelperMock = null,
        $pricingDataHelperMock = null,
        $quoteRepositoryMock = null
    ): GiftcardPayment {
        if (is_null($adyenStateDataMock)) {
            $adyenStateDataMock = $this->createMock(StateDataCollection::class);
        }

        if (is_null($adyenHelperMock)) {
            $adyenHelperMock = $this->createPartialMock(Data::class, []);
        }

        if (is_null($pricingDataHelperMock)) {
            $pricingDataHelperMock = $this->createMock(PricingData::class);
        }

        if (is_null($quoteRepositoryMock)) {
            $quoteRepositoryMock = $this->createMock(CartRepositoryInterface::class);
        }

        return new GiftcardPayment($adyenStateDataMock, $adyenHelperMock, $pricingDataHelperMock, $quoteRepositoryMock);
    }

    private function mockMethods(MockObject $object, $methods): void
    {
        foreach ($methods as $method => $return) {
            $object->method($method)->willReturn($return);
        }
    }
}
