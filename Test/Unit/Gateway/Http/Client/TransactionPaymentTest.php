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

namespace Adyen\Payment\Test\Unit\Gateway\Http\Client;

use Adyen\AdyenException;
use Adyen\Model\Checkout\ApplicationInfo;
use Adyen\Model\Checkout\PaymentRequest;
use Adyen\Model\Checkout\PaymentResponse as CheckoutPaymentResponse;
use Adyen\Payment\Api\Data\PaymentResponseInterface;
use Adyen\Payment\Model\PaymentResponse;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Adyen\Service\Checkout\PaymentsApi;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Model\PaymentResponseFactory;
use Adyen\Payment\Model\ResourceModel\PaymentResponse as PaymentResponseResourceModel;
use Adyen\Payment\Helper\Idempotency;
use Adyen\Payment\Helper\OrdersApi;
use Adyen\Payment\Gateway\Http\Client\TransactionPayment;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Adyen\Payment\Helper\GiftcardPayment;
use Magento\Payment\Gateway\Http\TransferInterface;
use PHPUnit\Framework\MockObject\MockObject;

class TransactionPaymentTest extends AbstractAdyenTestCase
{
    private Data|MockObject$adyenHelperMock;
    private PaymentResponseFactory|MockObject $paymentResponseFactoryMock;
    private PaymentResponseResourceModel|MockObject $paymentResponseResourceModelMock;
    private Idempotency|MockObject $idempotencyHelperMock;
    private OrdersApi|MockObject $orderApiHelperMock;
    private StoreManagerInterface|MockObject $storeManagerMock;
    private GiftcardPayment|MockObject $giftcardPaymentHelperMock;
    private TransactionPayment $transactionPayment;
    private ApplicationInfo|MockObject $applicationInfoMock;

    protected function setUp(): void
    {
        $this->adyenHelperMock = $this->createMock(Data::class);
        $this->paymentResponseResourceModelMock = $this->createMock(PaymentResponseResourceModel::class);
        $this->idempotencyHelperMock = $this->createMock(Idempotency::class);
        $this->orderApiHelperMock = $this->createMock(OrdersApi::class);
        $this->storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $this->giftcardPaymentHelperMock = $this->createMock(GiftcardPayment::class);
        $paymentResponseInterfaceMock = $this->createMock(PaymentResponseInterface::class);
        $paymentResponseMock = $this->createMock(PaymentResponse::class);
        $paymentResponseMock->method('setResponse')->willReturn($paymentResponseInterfaceMock);
        $paymentResponseMock->method('setResultCode')->willReturn($paymentResponseInterfaceMock);
        $paymentResponseMock->method('setMerchantReference')->willReturn($paymentResponseInterfaceMock);
        $this->paymentResponseFactoryMock = $this->createGeneratedMock(PaymentResponseFactory::class, ['create']);
        $this->paymentResponseFactoryMock->method('create')->willReturn($paymentResponseMock);

        $this->applicationInfoMock = $this->createMock(ApplicationInfo::class);
        $this->adyenHelperMock->method('buildApplicationInfo')->willReturn($this->applicationInfoMock);

        $this->transactionPayment = new TransactionPayment(
            $this->adyenHelperMock,
            $this->paymentResponseFactoryMock,
            $this->paymentResponseResourceModelMock,
            $this->idempotencyHelperMock,
            $this->orderApiHelperMock,
            $this->storeManagerMock,
            $this->giftcardPaymentHelperMock
        );
    }

    public function testPlaceRequestWithResultCode()
    {
        $transferObjectMock = $this->createMock(TransferInterface::class);
        $requestBody = ['resultCode' => 'Authorised', 'amount' => ['value' => 1000]];
        $transferObjectMock->method('getBody')->willReturn($requestBody);

        $result = $this->transactionPayment->placeRequest($transferObjectMock);

        $this->assertEquals($requestBody, $result);
    }

    public function testPlaceRequestWithoutResultCode()
    {
        $transferObjectMock = $this->createMock(TransferInterface::class);

        $requestBody = ['amount' => ['value' => 1000]];
        $transferObjectMock->method('getBody')->willReturn($requestBody);
        $transferObjectMock->method('getClientConfig')->willReturn(['storeId' => 1]);

        $clientMock = $this->createMock(PaymentsApi::class);
        $clientMock->method('payments')->willThrowException(new AdyenException());

        $this->adyenHelperMock->method('initializePaymentsApi')->willReturn($clientMock);

        $response = $this->transactionPayment->placeRequest($transferObjectMock);
        $this->assertArrayHasKey('errorCode', $response[0]);
    }

    public function testPlaceRequestGeneratesIdempotencyKey()
    {
        $requestBody = ['reference' => 'ABC12345', 'amount' => ['value' => 100], 'applicationInfo' => $this->applicationInfoMock];
        $transferObjectMock = $this->createConfiguredMock(TransferInterface::class, [
            'getBody' => $requestBody,
            'getHeaders' => ['idempotencyExtraData' => ['someData']],
            'getClientConfig' => []
        ]);

        $expectedIdempotencyKey = 'generated_idempotency_key';
        $this->idempotencyHelperMock->expects($this->once())
            ->method('generateIdempotencyKey')
            ->with(
                $this->equalTo(['reference' => 'ABC12345', 'amount' => ['value' => 100], 'applicationInfo' => $this->applicationInfoMock]),
                $this->equalTo(['someData'])
            )
            ->willReturn($expectedIdempotencyKey);

        $paymentResponse = new CheckoutPaymentResponse([
            'reference' => 'ABC12345',
            'amount' => ['value' => 100],
            'resultCode' => 'Authorised'
        ]);
        $serviceMock = $this->createMock(PaymentsApi::class);
        $serviceMock->expects($this->once())
            ->method('payments')
            ->with(
                $this->anything(),
                $this->callback(function ($requestOptions) use ($expectedIdempotencyKey) {
                    return isset($requestOptions['idempotencyKey']) &&
                        $requestOptions['idempotencyKey'] === $expectedIdempotencyKey;
                })
            )
            ->willReturn($paymentResponse);

        $this->adyenHelperMock->method('initializePaymentsApi')->willReturn($serviceMock);

        $response = $this->transactionPayment->placeRequest($transferObjectMock);

        $this->assertArrayHasKey('resultCode', $response[0]);
        $this->assertEquals('Authorised', $response[0]['resultCode']);
    }

    public function testRequestHeadersAreAddedToPaymentsCall()
    {
        $requestBody = new PaymentRequest(['reference' => 'ABC12345', 'amount' => ['value' => 1000], 'applicationInfo' => $this->applicationInfoMock]);
        $expectedHeaders = ['header1' => 'value1', 'header2' => 'value2'];

        $transferObjectMock = $this->createConfiguredMock(TransferInterface::class, [
            'getBody' => ['reference' => 'ABC12345', 'amount' => ['value' => 1000], 'applicationInfo' => $this->applicationInfoMock],
            'getHeaders' => ['header1' => 'value1', 'header2' => 'value2'],
            'getClientConfig' => []
        ]);

        $this->adyenHelperMock->expects($this->once())
            ->method('buildRequestHeaders')
            ->willReturn($expectedHeaders);

        $actualHeaders = $this->adyenHelperMock->buildRequestHeaders();

        $paymentResponse = new CheckoutPaymentResponse([
            'reference' => 'ABC12345',
            'amount' => ['value' => 100],
            'resultCode' => 'Authorised'
        ]);

        $serviceMock = $this->createMock(PaymentsApi::class);
        $serviceMock->expects($this->once())
            ->method('payments')
            ->with(
                $this->equalTo($requestBody),
                $this->callback(function ($requestOptions) use ($expectedHeaders) {
                    return isset($requestOptions['headers']) && $requestOptions['headers'] === $expectedHeaders;
                })
            )
            ->willReturn($paymentResponse);

        $this->adyenHelperMock->method('initializePaymentsApi')->willReturn($serviceMock);

        $response = $this->transactionPayment->placeRequest($transferObjectMock);

        $this->assertArrayHasKey('resultCode', $response[0]);
        $this->assertEquals('Authorised', $response[0]['resultCode']);
        $this->assertEquals($expectedHeaders, $actualHeaders);
    }

    public function testProcessGiftCardsWithNoGiftCards()
    {
        $originalRequest = ['amount' => ['value' => 150, 'currency' => 'EUR']];
        $service = $this->createMock(PaymentsApi::class);
        list($request, $giftcardResponse) = $this->transactionPayment->processGiftcards($originalRequest, $service);

        $this->assertEquals($request, $originalRequest);
        $this->assertEmpty($giftcardResponse);
    }

    public function testProcessGiftCardsWithGiftCards()
    {
        $orderData = [
            'pspReference' => 'pspReference!23',
            'orderData' => 'orderData....'
        ];

        list($request, $giftCardResponseCollection) = $this->doMultipleGiftCardPayments($orderData);

        $this->assertEquals(
            $request,
            [
                'reference' => '0000020',
                'amount' => [
                    'value' => 100,
                    'currency' => 'EUR'
                ],
                'order' => $orderData
            ]
        );
    }

    public function testProcessGiftCardReturnsMultipleGiftCardResponses()
    {
        $orderData = [
            'pspReference' => 'pspReference!23',
            'orderData' => 'orderData....'
        ];

        list($request, $giftCardResponseCollection) = $this->doMultipleGiftCardPayments($orderData);

        // make sure processGiftcards response is an array
        $this->assertIsArray($giftCardResponseCollection);
        // make sure the size of response array is equal to the number of redeemed gift cards
        $this->assertEquals(2, count($giftCardResponseCollection));
    }

    private function doMultipleGiftCardPayments($orderData)
    {
        $amount = 250;
        $store = $this->createConfiguredMock(StoreInterface::class, [
            'getId' => 12
        ]);
        $this->storeManagerMock->method('getStore')->willReturn($store);
        $originalRequest = [
            'reference' => '0000020',
            'giftcardRequestParameters' => [
                [
                    'state_data' => '{"paymentMethod":{"type": "giftcard"}, "giftcard": {"balance": {"value": 100}, "currency": "EUR"}}'],
                [
                    'state_data' => '{"paymentMethod":{"type": "giftcard"}, "giftcard": {"balance": {"value": 50}, "currency": "EUR"}}'
                ]
            ],
            'amount' => [
                'value' => $amount,
                'currency' => 'EUR'
            ]
        ];
        $response = new CheckoutPaymentResponse();
        $response->setResultCode('Authorised');
        $response->setMerchantReference('PSPDMDM2222');
        $serviceMock = $this->createMock(PaymentsApi::class);
        $serviceMock->expects($this->exactly(2))
            ->method('payments')
            ->with(
                $this->callback(function (PaymentRequest $detailsRequest) {
                    return true;
                }),
            )->willReturn($response);
        $reflector = new \ReflectionProperty(TransactionPayment::class, 'remainingOrderAmount');
        $reflector->setAccessible(true);
        $reflector->setValue($this->transactionPayment, $amount);

        $this->orderApiHelperMock
            ->expects($this->once())
            ->method('createOrder')
            ->willReturn($orderData);

        return $this->transactionPayment->processGiftcards($originalRequest, $serviceMock);
    }
}
