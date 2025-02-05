<?php

namespace Adyen\Payment\Test\Unit\Model\Api;

use Adyen\AdyenException;
use Adyen\Client;
use Adyen\Model\Checkout\Amount;
use Adyen\Model\Checkout\BalanceCheckResponse;
use Adyen\Payment\Model\Api\AdyenPaymentMethodsBalance;
use Adyen\Payment\Helper\Config;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Adyen\Service\Checkout\OrdersApi;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManager;
use Magento\Framework\Serialize\Serializer\Json;

class AdyenPaymentMethodsBalanceTest extends AbstractAdyenTestCase
{
    private StoreManager $storeManager;
    private Config $config;
    private Data $adyenHelper;
    private AdyenLogger $adyenLogger;
    private OrdersApi $ordersApi;
    private BalanceCheckResponse $response;
    private Json $jsonSerializer;

    protected function setUp(): void
    {
        $this->jsonSerializer = new Json();
        $this->storeManager = $this->createConfiguredMock(
            StoreManager::class,
            [
                'getStore' => $this->createConfiguredMock(
                    Store::class, [
                        'getId' => 'StoreId'
                    ]
                ),
            ]
        );
        $this->config = $this->createConfiguredMock(Config::class, [
            'getMerchantAccount' => 'merchantAccount'
        ]);
        $this->response = new BalanceCheckResponse();
        $this->response->setResultCode('Success');
        $this->response->setBalance(new Amount(['currency'=> 'EUR', 'value'=> 400]));
        $this->ordersApi = $this->createConfiguredMock(OrdersApi::class, [
            'getBalanceOfGiftCard' => $this->response
        ]);
        $this->adyenHelper = $this->createConfiguredMock(Data::class, [
            'initializeAdyenClient' => $this->createConfiguredMock(Client::class, []),
            'initializeOrdersApi' => $this->ordersApi
        ]);
        $this->adyenLogger = $this->createMock(AdyenLogger::class);
    }

    public function testSuccessfulGetBalance()
    {
        $payload = '{"paymentMethod":{"type":"giftcard","brand":"genericgiftcard","encryptedCardNumber":"eyJhbGciOi......"},"amount":{"currency":"EUR","value":8361}}';
        $adyenPaymentMethodsBalance = new AdyenPaymentMethodsBalance(
            $this->jsonSerializer,
            $this->storeManager,
            $this->config,
            $this->adyenHelper,
            $this->adyenLogger
        );

        $balance = $adyenPaymentMethodsBalance->getBalance($payload);
        $this->assertEquals(json_encode($this->response->jsonSerialize()), $balance);
    }

    public function testFailedGetBalance()
    {

        $response = new BalanceCheckResponse();
        $response->setResultCode(AdyenPaymentMethodsBalance::FAILED_RESULT_CODE);
        $ordersApi = $this->createConfiguredMock(OrdersApi::class, [
            'getBalanceOfGiftCard' => $response
        ]);

        $adyenHelper = $this->createConfiguredMock(Data::class, [
            'initializeAdyenClient' => $this->createConfiguredMock(Client::class, []),
            'initializeOrdersApi' => $ordersApi
        ]);

        $payload = '{"paymentMethod":{"type":"giftcard","brand":"genericgiftcard","encryptedCardNumber":"eyJhbGciOi......"},"amount":{"currency":"EUR","value":8361}}';
        $adyenPaymentMethodsBalance = new AdyenPaymentMethodsBalance(
            $this->jsonSerializer,
            $this->storeManager,
            $this->config,
            $adyenHelper,
            $this->adyenLogger
        );

        $this->expectException(AdyenException::class);
        $adyenPaymentMethodsBalance->getBalance($payload);
    }
}
