<?php

namespace Adyen\Payment\Test\Unit\Gateway\Http\Client;

use Adyen\Client;
use Adyen\Model\Checkout\PaymentCancelResponse;
use Adyen\Payment\Gateway\Http\Client\TransactionCancel;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Helper\Idempotency;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Payment\Gateway\Http\TransferInterface;
use Adyen\Service\Checkout;
use Adyen\AdyenException;
use PHPUnit\Framework\MockObject\MockObject;

class TransactionCancelTest extends AbstractAdyenTestCase
{
    private TransactionCancel $transactionCancel;
    private Data|MockObject $adyenHelperMock;
    private Idempotency|MockObject $idempotencyHelperMock;
    private TransferInterface|MockObject $transferObjectMock;
    private Checkout\ModificationsApi|MockObject $checkoutServiceMock;
    private Client|MockObject $clientMock;

    protected function setUp(): void
    {
        $this->adyenHelperMock = $this->createMock(Data::class);

        $this->idempotencyHelperMock = $this->createMock(Idempotency::class);
        $this->transferObjectMock = $this->createMock(TransferInterface::class);
        $this->clientMock = $this->createMock(Client::class);
        $this->checkoutServiceMock = $this->createMock(Checkout\ModificationsApi::class);
        $this->transferObjectMock->method('getClientConfig')->willReturn([]);
        $this->checkoutServiceMock
            ->method('cancelAuthorisedPaymentByPspReference')
            ->willReturn(new PaymentCancelResponse(['status' => 'received']));

        $this->transactionCancel = new TransactionCancel(
            $this->adyenHelperMock,
            $this->idempotencyHelperMock
        );
    }

    public function testSuccessfulCancellation()
    {
        // Arrange
        $requestBody = [
            [
                'merchantAccount' => 'TestMerchantAccount',
                'originalReference' => 'TestOriginalReference',
                'paymentPspReference' => 'paymentPspReference'
            ]
        ];
        $this->transferObjectMock->method('getBody')->willReturn($requestBody);
        $this->transferObjectMock->method('getHeaders')->willReturn([]);
        $this->transferObjectMock->method('getClientConfig')->willReturn([]);
        $this->adyenHelperMock->method('initializeAdyenClientWithClientConfig')->willReturn($this->clientMock);
        $this->adyenHelperMock->method('initializeModificationsApi')->willReturn($this->checkoutServiceMock);
        $this->adyenHelperMock->method('buildRequestHeaders')->willReturn(['x-api-key' => 'test_key']);

        $expectedResult = ['status' => 'received'];

        // Act
        $result = $this->transactionCancel->placeRequest($this->transferObjectMock);

        // Assert
        $this->assertEquals($expectedResult, $result);
    }

    public function testCancellationWithAdyenApiException()
    {
        // Arrange
        $requestBody = [
            [
                'merchantAccount' => 'TestMerchantAccount',
                'originalReference' => 'TestOriginalReference',
                'paymentPspReference' => 'paymentPspReference'
            ]
        ];
        $this->transferObjectMock->method('getBody')->willReturn($requestBody);

        $this->adyenHelperMock->method('initializeAdyenClientWithClientConfig')->willReturn($this->clientMock);
        $this->adyenHelperMock->method('initializeModificationsApi')->willReturn($this->checkoutServiceMock);
        $this->adyenHelperMock->method('buildRequestHeaders')->willReturn(['x-api-key' => 'test_key']);

        // Simulate Adyen API exception
        $this->checkoutServiceMock->method('cancelAuthorisedPaymentByPspReference')->willThrowException(new AdyenException('API exception'));

        // Act
        $result = $this->transactionCancel->placeRequest($this->transferObjectMock);

        // Assert
        $this->assertSame($result, ['error' => 'API exception']);
    }

    public function testCancellationWithMultipleRequests()
    {
        // Arrange
        $requestBody = [
            [
                'merchantAccount' => 'TestMerchantAccount1',
                'originalReference' => 'TestOriginalReference1',
                'paymentPspReference' => 'paymentPspReference1'
            ],
            [
                'merchantAccount' => 'TestMerchantAccount2',
                'originalReference' => 'TestOriginalReference2',
                'paymentPspReference' => 'paymentPspReference2'
            ]
        ];
        $this->transferObjectMock->method('getBody')->willReturn($requestBody);
        $this->transferObjectMock->method('getHeaders')->willReturn([]);
        $this->transferObjectMock->method('getClientConfig')->willReturn([]);

        $this->adyenHelperMock->method('initializeAdyenClientWithClientConfig')->willReturn($this->clientMock);
        $this->adyenHelperMock->method('initializeModificationsApi')->willReturn($this->checkoutServiceMock);
        $this->adyenHelperMock->method('buildRequestHeaders')->willReturn(['x-api-key' => 'test_key']);

        $expectedResults = ['status' => 'received'];

        // Act
        $results = $this->transactionCancel->placeRequest($this->transferObjectMock);

        // Assert
        $this->assertEquals($expectedResults, $results);
    }

    public function testCancellationWithEmptyRequestArray()
    {
        // Arrange
        $requestBody = [];
        $this->transferObjectMock->method('getBody')->willReturn($requestBody);

        // Act
        $result = $this->transactionCancel->placeRequest($this->transferObjectMock);

        // Assert
        $this->assertEmpty($result);
    }
}