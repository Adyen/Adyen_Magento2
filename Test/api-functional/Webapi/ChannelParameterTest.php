<?php

namespace Adyen\Payment\Test\Webapi;

use Magento\Framework\Webapi\Rest\Request;
use Magento\GraphQl\Quote\GetMaskedQuoteIdByReservedOrderId;
use Magento\Integration\Api\CustomerTokenServiceInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\WebapiAbstract;

class ChannelParameterTest extends WebapiAbstract
{
    /**
     * @var GetMaskedQuoteIdByReservedOrderId
     */
    private $getMaskedQuoteIdByReservedOrderId;

    /**
     * @var CustomerTokenServiceInterface
     */
    private $customerTokenService;

    private $maskedQuoteId;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        /** @phpstan-ignore-next-line */
        $this->getMaskedQuoteIdByReservedOrderId = $objectManager->get(GetMaskedQuoteIdByReservedOrderId::class);
        $this->customerTokenService = $objectManager->get(CustomerTokenServiceInterface::class);
    }

    public function testApplePayExcludedForAndroidChannel()
    {
        $this->maskedQuoteId = $this->createGuestCart();

        $this->addItemToCart($this->maskedQuoteId);
        $this->setBillingAddress($this->maskedQuoteId);
        $this->setShippingInformationWithChannel($this->maskedQuoteId);

        // Verify that Apple Pay is not present for the Android channel
        $serviceInfo = [
            'rest' => [
                'resourcePath' => "/V1/guest-carts/{$this->maskedQuoteId}/payment-methods",
                'httpMethod' => Request::HTTP_METHOD_GET
            ]
        ];

        $response = $this->_webApiCall($serviceInfo);
        $this->assertIsArray($response, 'Response should be an array');

        foreach ($response as $paymentMethod) {
            $this->assertArrayHasKey('code', $paymentMethod, 'Each payment method should have a code');
            $this->assertNotEquals('adyen_apple_pay', $paymentMethod['code'], 'Apple Pay should not be present for Android channel');
        }
    }

    private function createGuestCart(): string
    {
        $serviceInfo = [
            'rest' => [
                'resourcePath' => "/guest-carts",
                'httpMethod' => Request::HTTP_METHOD_POST
            ]
        ];

        return $this->_webApiCall($serviceInfo, [], null, 'V1');
    }

    private function addItemToCart(string $maskedQuoteId): array
    {
        $serviceInfo = [
            'rest' => [
                'resourcePath' => "/guest-carts/{$maskedQuoteId}/items",
                'httpMethod' => Request::HTTP_METHOD_POST
            ]
        ];

        $payload = '{"cartItem":{"qty":1,"sku":"24-MB04"}}';
        return $this->_webApiCall($serviceInfo, json_decode($payload, true), null, 'V1');
    }

    private function setShippingInformationWithChannel($maskedQuoteId): array
    {
        $serviceInfo = [
            'rest' => [
                'resourcePath' => "/guest-carts/{$maskedQuoteId}/shipping-information?channel=Android",
                'httpMethod' => Request::HTTP_METHOD_POST
            ]
        ];

        $payload = '{"addressInformation":{"shipping_address":{"firstname":"Veronica","lastname":"Costello","company":"Adyen","street":["Simon Carmiggeltstraat","Main Street"],"city":"Amsterdam","region":"Amsterdam","postcode":"1011 DK","country_id":"NL","telephone":"123456789"},"shipping_carrier_code":"flatrate","shipping_method_code":"flatrate"}}';
        return $this->_webApiCall($serviceInfo, json_decode($payload, true), null, 'V1');
    }

    private function setBillingAddress($maskedQuoteId): int
    {
        $serviceInfo = [
            'rest' => [
                'resourcePath' => "/guest-carts/{$maskedQuoteId}/billing-address",
                'httpMethod' => Request::HTTP_METHOD_POST
            ]
        ];

        $payload = '{"address":{"firstname":"Veronica ","lastname":"Costello","company":"Adyen","street":["Simon Carmiggeltstraat","Main Street"],"city":"Amsterdam","region":"Amsterdam","postcode":"1011 DK","country_id":"NL","telephone":"123456789","email":"roni_cost@example.com"},"useForShipping":true}';
        return $this->_webApiCall($serviceInfo, json_decode($payload, true), null, 'V1');
    }
}
