<?php declare(strict_types=1);
/**
 *
 * Adyen Payment Module
 *
 * Copyright (c) 2022 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\GraphQl;

use Magento\Framework\Exception\AuthenticationException;
use Magento\GraphQl\Quote\GetMaskedQuoteIdByReservedOrderId;
use Magento\Integration\Api\CustomerTokenServiceInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\GraphQl\ResponseContainsErrorsException;
use Magento\TestFramework\TestCase\GraphQlAbstract;

class AdyenTest extends GraphQlAbstract
{
    /**
     * @var GetMaskedQuoteIdByReservedOrderId
     */
    private $getMaskedQuoteIdByReservedOrderId;
    /**
     * @var CustomerTokenServiceInterface
     */
    private $customerTokenService;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->getMaskedQuoteIdByReservedOrderId = $objectManager->get(GetMaskedQuoteIdByReservedOrderId::class);
        $this->customerTokenService = $objectManager->get(CustomerTokenServiceInterface::class);
    }

    /**
     * @magentoApiDataFixture Magento/GraphQl/Catalog/_files/simple_product.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/guest/create_empty_cart.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/add_simple_product.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/set_new_shipping_address.php
     */
    public function testAdyenPaymentMethods()
    {
        $maskedQuoteId = $this->getMaskedQuoteIdByReservedOrderId->execute('test_quote');
        $query = <<<QUERY
{
  adyenPaymentMethods(cart_id: "$maskedQuoteId") {
    paymentMethodsResponse {
        paymentMethods {
           name,
           type
        }
    },
    paymentMethodsExtraDetails {
        icon {
            url,
            width
            height
        }
    }
  }
}
QUERY;

        $response = $this->graphQlQuery($query);

        $this->assertArrayHasKey('adyenPaymentMethods', $response);
        $this->assertArrayHasKey('paymentMethodsResponse', $response['adyenPaymentMethods']);
        $this->assertArrayHasKey('paymentMethodsExtraDetails', $response['adyenPaymentMethods']);
    }

    /**
     * @magentoApiDataFixture Magento/GraphQl/Catalog/_files/simple_product.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/guest/create_empty_cart.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/guest/set_guest_email.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/add_simple_product.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/set_new_shipping_address.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/set_new_billing_address.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/set_flatrate_shipping_method.php
     */
    public function testAdyenPaymentStatus(): void
    {
        $methodCode = "adyen_ideal";
        $maskedQuoteId = $this->getMaskedQuoteIdByReservedOrderId->execute('test_quote');
        $stateData = '{\"paymentMethod\":{\"type\":\"ideal\"}}';
        $adyenAdditionalData = '
        ,
        adyen_additional_data: {
            stateData: "' . $stateData . '",
            returnUrl: "http://localhost/checkout/?id=:merchantReference&done=1"
        }';
        $query = $this->getPlaceOrderQuery($maskedQuoteId, $methodCode, $adyenAdditionalData);

        $response = $this->graphQlMutation($query);

        self::assertArrayHasKey('placeOrder', $response);
        self::assertArrayHasKey('order', $response['placeOrder']);
        self::assertArrayHasKey('order_number', $response['placeOrder']['order']);
        self::assertArrayHasKey('cart_id', $response['placeOrder']['order']);
        self::assertArrayHasKey('adyen_payment_status', $response['placeOrder']['order']);
        self::assertArrayHasKey('isFinal', $response['placeOrder']['order']['adyen_payment_status']);
        self::assertArrayHasKey('resultCode', $response['placeOrder']['order']['adyen_payment_status']);
        self::assertArrayHasKey('action', $response['placeOrder']['order']['adyen_payment_status']);
    }

    public function testAdyenPaymentDetails()
    {
        $query = <<<QUERY
mutation {
  adyenPaymentDetails(payload: "{\"orderId\": \"nothing here\"}", cart_id: "not found") {
    isFinal
  }
}
QUERY;
        $this->expectException(ResponseContainsErrorsException::class);
        $this->expectExceptionMessage('Could not find a cart with ID "not found"');

        $this->graphQlMutation($query);
    }

    /**
     * @magentoApiDataFixture Magento/GraphQl/Catalog/_files/simple_product.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/guest/create_empty_cart.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/add_simple_product.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/set_new_shipping_address.php
     */
    public function testSetAdyenPaymentMethodOnCart(): void
    {
        $methodCode = "adyen_ideal";
        $maskedQuoteId = $this->getMaskedQuoteIdByReservedOrderId->execute('test_quote');
        $stateData = '{\"paymentMethod\":{\"type\":\"ideal\",\"issuer\":\"1154\"}}';
        $adyenAdditionalData = '
        ,
        adyen_additional_data: {
            stateData: "' . $stateData . '"
        }';
        $query = $this->getQuery($maskedQuoteId, $methodCode, $adyenAdditionalData);

        $response = $this->graphQlMutation($query);

        self::assertArrayHasKey('setPaymentMethodOnCart', $response);
        self::assertArrayHasKey('cart', $response['setPaymentMethodOnCart']);
        self::assertArrayHasKey('selected_payment_method', $response['setPaymentMethodOnCart']['cart']);
        self::assertEquals($methodCode, $response['setPaymentMethodOnCart']['cart']['selected_payment_method']['code']);
    }

    /**
     * @magentoApiDataFixture Magento/GraphQl/Catalog/_files/simple_product.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/guest/create_empty_cart.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/guest/set_guest_email.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/add_simple_product.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/set_new_shipping_address.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/set_new_billing_address.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/set_flatrate_shipping_method.php
     */
    public function testCreditCardGuest()
    {
        $maskedQuoteId = $this->getMaskedQuoteIdByReservedOrderId->execute('test_quote');
        $stateData = <<<'JSON'
{
    "paymentMethod": {
        "type": "scheme",
        "holderName": "Foo Bar",
        "encryptedCardNumber": "test_4111111111111111",
        "encryptedExpiryMonth": "test_03",
        "encryptedExpiryYear": "test_2030",
        "encryptedSecurityCode": "test_737"
    },
    "browserInfo": {
        "acceptHeader": "*/*",
        "colorDepth": 24,
        "language": "en-US",
        "javaEnabled": false,
        "screenHeight": 1080,
        "screenWidth": 1920,
        "userAgent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/101.0.4951.41 Safari/537.36",
        "timeZoneOffset": -120
    },
    "origin": "http://localhost",
    "clientStateDataIndicator": true
}
JSON;
        $adyenAdditionalData = '
        adyen_additional_data_cc: {
            cc_type: "VI",
            stateData: ' . json_encode($stateData) . '
        }';
        $query = $this->getPlaceOrderQuery($maskedQuoteId, "adyen_cc", $adyenAdditionalData);

        $response = $this->graphQlMutation($query);

        self::assertArrayHasKey('placeOrder', $response);
        self::assertArrayHasKey('order', $response['placeOrder']);
        $order = $response['placeOrder']['order'];
        self::assertArrayHasKey('order_number', $order);
        self::assertArrayHasKey('cart_id', $order);
        self::assertArrayHasKey('adyen_payment_status', $order);
        self::assertTrue($order['adyen_payment_status']['isFinal']);
        self::assertEquals('Authorised', $order['adyen_payment_status']['resultCode']);
    }

    /**
     * @magentoApiDataFixture Magento/Customer/_files/customer.php
     * @magentoApiDataFixture Magento/GraphQl/Catalog/_files/simple_product.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/customer/create_empty_cart.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/add_simple_product.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/set_new_shipping_address.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/set_new_billing_address.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/set_flatrate_shipping_method.php
     */
    public function testCreditCardCustomer()
    {
        $maskedQuoteId = $this->getMaskedQuoteIdByReservedOrderId->execute('test_quote');
        $stateData = <<<'JSON'
{
    "paymentMethod": {
        "type": "scheme",
        "holderName": "Foo Bar",
        "encryptedCardNumber": "test_4111111111111111",
        "encryptedExpiryMonth": "test_03",
        "encryptedExpiryYear": "test_2030",
        "encryptedSecurityCode": "test_737"
    },
    "browserInfo": {
        "acceptHeader": "*/*",
        "colorDepth": 24,
        "language": "en-US",
        "javaEnabled": false,
        "screenHeight": 1080,
        "screenWidth": 1920,
        "userAgent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/101.0.4951.41 Safari/537.36",
        "timeZoneOffset": -120
    },
    "origin": "http://localhost",
    "clientStateDataIndicator": true
}
JSON;
        $adyenAdditionalData = '
        adyen_additional_data_cc: {
            cc_type: "VI",
            stateData: ' . json_encode($stateData) . '
        }';
        $query = $this->getPlaceOrderQuery($maskedQuoteId, "adyen_cc", $adyenAdditionalData);

        $response = $this->graphQlMutation($query, [], '', $this->getHeaderMap());

        self::assertEquals('Authorised', $response['placeOrder']['order']['adyen_payment_status']['resultCode']);
    }

    /**
     * @param string $maskedQuoteId
     * @param string $methodCode
     * @param string $adyenAdditionalData
     * @return string
     */
    private function getQuery(
        string $maskedQuoteId,
        string $methodCode,
        string $adyenAdditionalData
    ): string {
        return <<<QUERY
mutation {
  setPaymentMethodOnCart(input: {
    cart_id: "{$maskedQuoteId}",
    payment_method: {
      code: "{$methodCode}"
      {$adyenAdditionalData}
    }
  }) {
    cart {
      selected_payment_method {
        code
      }
    }
  }
}
QUERY;
    }

    /**
     * @param string $maskedQuoteId
     * @param string $methodCode
     * @param string $adyenAdditionalData
     * @return string
     */
    private function getPlaceOrderQuery(
        string $maskedQuoteId,
        string $methodCode,
        string $adyenAdditionalData
    ): string {
        return <<<QUERY
mutation {
    setPaymentMethodOnCart(
        input: {
            cart_id: "$maskedQuoteId"
            payment_method: {
              code: "$methodCode",
              {$adyenAdditionalData}
            }
        }
    ) {
        cart {
            selected_payment_method {
                code
                title
            }
        }
    }

    placeOrder(
        input: {
            cart_id: "$maskedQuoteId"
        }
    ) {
        order {
            order_number
            cart_id
            adyen_payment_status {
                isFinal
                resultCode
                additionalData
                action
            }
        }
    }
}
QUERY;
    }

    /**
     * Create a header with customer token
     *
     * @param string $username
     * @param string $password
     * @return array
     * @throws AuthenticationException
     * @see \Magento\GraphQl\Quote\Customer\GetCustomerCartTest::getHeaderMap
     */
    private function getHeaderMap(string $username = 'customer@example.com', string $password = 'password'): array
    {
        $customerToken = $this->customerTokenService->createCustomerAccessToken($username, $password);
        return ['Authorization' => 'Bearer ' . $customerToken];
    }

    /**
     * @magentoApiDataFixture Magento/GraphQl/Catalog/_files/simple_product.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/guest/create_empty_cart.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/add_simple_product.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/set_new_shipping_address.php
     */
    public function testAdyenPaymentMethodsWithChannelAndroid()
    {
        $maskedQuoteId = $this->getMaskedQuoteIdByReservedOrderId->execute('test_quote');
        $query = <<<QUERY
{
  adyenPaymentMethods(cart_id: "$maskedQuoteId", channel: "Android") {
    paymentMethodsResponse {
        paymentMethods {
           name,
           type
        }
    }
  }
}
QUERY;

        $response = $this->graphQlQuery($query);

        $this->assertArrayHasKey('adyenPaymentMethods', $response);
        $this->assertArrayHasKey('paymentMethodsResponse', $response['adyenPaymentMethods']);
        $paymentMethods = $response['adyenPaymentMethods']['paymentMethodsResponse']['paymentMethods'];

        foreach ($paymentMethods as $paymentMethod) {
            $this->assertNotEquals('Apple Pay', $paymentMethod['name'], 'Apple Pay should not be listed for channel Android');
        }
    }

}
