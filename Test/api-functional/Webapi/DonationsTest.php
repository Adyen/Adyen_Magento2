<?php

namespace Adyen\Payment\Test\Webapi;

use Magento\Framework\Webapi\Rest\Request;
use Magento\GraphQl\Quote\GetMaskedQuoteIdByReservedOrderId;
use Magento\Integration\Api\CustomerTokenServiceInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\WebapiAbstract;

class DonationsTest extends WebapiAbstract
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

    public function testSuccessfulDonation()
    {
        $this->placeOrder();

        $serviceInfo = [
            'rest' => [
                'resourcePath' => "/V1/adyen/orders/guest-carts/{$this->maskedQuoteId}/donations?XDEBUG_SESSION_START=PHPSTORM",
                'httpMethod' => Request::HTTP_METHOD_POST
            ]
        ];

        $payload = '{"amount":{"currency":"EUR","value":100},"returnUrl":"https://local.store/index.php/checkout/onepage/success/"}';
        $response = $this->_webApiCall($serviceInfo, ['payload' => $payload]);

        $this->assertEmpty($response);
    }

    private function placeOrder()
    {
        $this->maskedQuoteId = $this->createGuestCart();


        $this->addItemToCart($this->maskedQuoteId);
        $this->setBillingAddress($this->maskedQuoteId);
        $this->setShippingInformation($this->maskedQuoteId);

        $serviceInfo = [
            'rest' => [
                'resourcePath' => "/guest-carts/{$this->maskedQuoteId}/payment-information",
                'httpMethod' => Request::HTTP_METHOD_POST
            ]
        ];

        $payload = '{"email":"roni_cost@example.com","paymentMethod":{"method":"adyen_cc","additional_data":{"cc_brand":"VI","stateData":""}}}';
        $decodedPayload = json_decode($payload, true);
        $decodedPayload['paymentMethod']['additional_data']['stateData'] ="{\"paymentMethod\":{\"type\":\"scheme\",\"holderName\":\"Foo Bar\",\"encryptedCardNumber\":\"test_2222400070000005\",\"encryptedExpiryMonth\":\"test_03\",\"encryptedExpiryYear\":\"test_2030\",\"encryptedSecurityCode\":\"test_737\"},\"browserInfo\":{\"acceptHeader\":\"*/*\",\"colorDepth\":24,\"language\":\"en-US\",\"javaEnabled\":false,\"screenHeight\":1080,\"screenWidth\":1920,\"userAgent\":\"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/101.0.4951.41 Safari/537.36\",\"timeZoneOffset\":-120},\"origin\":\"http://localhost\",\"clientStateDataIndicator\":true}";

        $this->_webApiCall($serviceInfo, $decodedPayload, null, 'V1');
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
        return $this->_webApiCall($serviceInfo, json_decode($payload), null, 'V1');
    }

    private function setShippingInformation($maskedQuoteId): array
    {
        $serviceInfo = [
            'rest' => [
                'resourcePath' => "/guest-carts/{$maskedQuoteId}/shipping-information",
                'httpMethod' => Request::HTTP_METHOD_POST
            ]
        ];

        $payload = '{"addressInformation":{"shipping_address":{"firstname":"Veronica","lastname":"Costello","company":"Adyen","street":["Simon Carmiggeltstraat","Main Street"],"city":"Amsterdam","region":"Amsterdam","postcode":"1011 DK","country_id":"NL","telephone":"123456789"},"shipping_carrier_code":"flatrate","shipping_method_code":"flatrate"}}';
        return $this->_webApiCall($serviceInfo, json_decode($payload), null, 'V1');
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
        return $this->_webApiCall($serviceInfo, json_decode($payload), null, 'V1');
    }
}
