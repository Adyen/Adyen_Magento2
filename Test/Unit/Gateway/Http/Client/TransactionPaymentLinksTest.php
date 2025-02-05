<?php

namespace Adyen\Payment\Test\Unit\Gateway\Http\Client;

use Adyen\AdyenException;
use Adyen\Model\Checkout\ApplicationInfo;
use Adyen\Model\Checkout\PaymentLinkRequest;
use Adyen\Model\Checkout\PaymentLinkResponse;
use Adyen\Payment\Gateway\Http\Client\TransactionPaymentLinks;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Helper\Idempotency;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Adyen\Client;
use Adyen\Service\Checkout\PaymentLinksApi;
use Magento\Payment\Gateway\Http\TransferInterface;
use PHPUnit\Framework\MockObject\MockObject;

class TransactionPaymentLinksTest extends AbstractAdyenTestCase
{
    private TransactionPaymentLinks $transactionPaymentLinks;
    private Client|MockObject $clientMock;
    private Data|MockObject $adyenHelperMock;
    private Idempotency|MockObject $idempotencyHelperMock;
    private TransferInterface|MockObject $transferObjectMock;
    private PaymentLinksApi|MockObject $paymentLinksApiMock;
    private ApplicationInfo|MockObject $applicationInfoMock;

    protected function setUp(): void
    {
        $this->adyenHelperMock = $this->createMock(Data::class);
        $this->idempotencyHelperMock = $this->createMock(Idempotency::class);
        $this->transferObjectMock = $this->createMock(TransferInterface::class);
        $this->applicationInfoMock = $this->createMock(ApplicationInfo::class);
        $this->adyenHelperMock->method('buildApplicationInfo')->willReturn($this->applicationInfoMock);
        $this->transferObjectMock->method('getClientConfig')->willReturn([]);
        $this->clientMock = $this->createMock(Client::class);
        $this->adyenHelperMock->method('initializeAdyenClientWithClientConfig')->willReturn($this->clientMock);
        $this->paymentLinksApiMock = $this->createMock(PaymentLinksApi::class);
        $this->adyenHelperMock
            ->method('initializePaymentLinksApi')
            ->with($this->clientMock)
            ->willReturn($this->paymentLinksApiMock);

        $this->transactionPaymentLinks = new TransactionPaymentLinks(
            $this->adyenHelperMock,
            $this->idempotencyHelperMock
        );
    }

    public function testSuccessfulPlaceRequest()
    {
        $requestBody = [
            'allowedPaymentMethods' => ['ideal','giropay'],
            'amount' => ['value' => 1000, 'currency' => 'EUR'],
            'applicationInfo' => $this->applicationInfoMock
        ];

        $headers = [ 'idempotencyExtraData' => [], 'header' => 'some-data'];
        $idempotencyKey = 'generated_idempotency_key';

        $this->transferObjectMock->method('getBody')->willReturn($requestBody);
        $this->transferObjectMock->method('getHeaders')->willReturn($headers);

        $this->idempotencyHelperMock->expects($this->once())
            ->method('generateIdempotencyKey')
            ->with($requestBody, $headers['idempotencyExtraData'])
            ->willReturn('generated_idempotency_key');

        $this->paymentLinksApiMock->expects($this->once())
            ->method('paymentLinks')
            ->with(
                $this->callback(function (PaymentLinkRequest $paymentLinkRequest) use ($requestBody) {
                    $amount = $paymentLinkRequest->getAmount();
                    $this->assertEquals($amount, ['value' => 1000, 'currency' => 'EUR']);
                    $allowedPaymentMethods = $paymentLinkRequest->getAllowedPaymentMethods();
                    $this->assertEquals($allowedPaymentMethods, ['ideal', 'giropay']);
                    return true;
                }),
                $this->callback(function ($requestOptions) use ($idempotencyKey, $headers) {
                    $this->assertArrayHasKey('idempotencyKey', $requestOptions);
                    $this->assertArrayHasKey('headers', $requestOptions);
                    $this->assertEquals($idempotencyKey, $requestOptions['idempotencyKey']);
                    $this->assertEquals(['header' => 'some-data'], $requestOptions['headers']);
                    return true;
                })
            )->willReturn(new PaymentLinkResponse(['url' => 'https://paymentlink.com']));

        $response = $this->transactionPaymentLinks->placeRequest($this->transferObjectMock);
        $this->assertIsArray($response);
        $this->assertArrayHasKey('url', $response);
    }

    public function testRequestWithAdyenException()
    {
        $requestBody = [
            'amount' => ['currency' => 'EUR', 'value' => 1000],
            'merchantAccount' => 'TestMerchant',
            'reference' => 'TestReference',
        ];
        $this->transferObjectMock->method('getBody')->willReturn($requestBody);
        $this->transferObjectMock->method('getHeaders')->willReturn([]);
        $this->transferObjectMock->method('getClientConfig')->willReturn([]);

        $this->paymentLinksApiMock
            ->method('paymentLinks')
            ->willThrowException(new AdyenException());

        $response = $this->transactionPaymentLinks->placeRequest($this->transferObjectMock);

        $this->assertArrayHasKey('error', $response);
    }

    public function testRequestWithResultCodePresent()
    {
        $requestBody = ['resultCode' => 'Authorised'];
        $this->transferObjectMock->method('getBody')->willReturn($requestBody);

        $response = $this->transactionPaymentLinks->placeRequest($this->transferObjectMock);
        $this->assertEquals($requestBody, $response);
    }

}
