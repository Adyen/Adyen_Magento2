<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2025 Adyen N.V. (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Test\Unit\Model\Api;

use Adyen\AdyenException;
use Adyen\Payment\Helper\ChargedCurrency;
use Adyen\Payment\Helper\Config;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Helper\PaymentMethods;
use Adyen\Payment\Model\AdyenAmountCurrency;
use Adyen\Payment\Model\Api\AdyenDonations;
use Adyen\Payment\Model\Sales\OrderRepository;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Payment\Gateway\Command\CommandPoolInterface;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Model\MethodInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use Magento\Framework\Serialize\Serializer\Json;

class AdyenDonationsTest extends AbstractAdyenTestCase
{
    private ?AdyenDonations $adyenDonations;
    private CommandPoolInterface|MockObject $commandPoolMock;
    private Json|MockObject $jsonMock;
    private Data|MockObject $dataMock;
    private Config|MockObject $configMock;
    private ChargedCurrency|MockObject $chargedCurrencyMock;
    private PaymentMethods|MockObject $paymentMethodsMock;
    private OrderRepository|MockObject $orderRepositoryMock;

    protected function setUp(): void
    {
        $this->commandPoolMock = $this->createMock(CommandPoolInterface::class);
        $this->jsonMock = $this->createPartialMock(Json::class, []);
        $this->dataMock = $this->createPartialMock(Data::class, []);
        $this->chargedCurrencyMock = $this->createMock(ChargedCurrency::class);
        $this->configMock = $this->createMock(Config::class);
        $this->paymentMethodsMock = $this->createPartialMock(PaymentMethods::class, []);
        $this->orderRepositoryMock = $this->createMock(OrderRepository::class);

        $this->adyenDonations = new AdyenDonations(
            $this->commandPoolMock,
            $this->jsonMock,
            $this->dataMock,
            $this->chargedCurrencyMock,
            $this->configMock,
            $this->paymentMethodsMock,
            $this->orderRepositoryMock
        );
    }

    protected function tearDown(): void
    {
        $this->adyenDonations = null;
    }

    private static function paymentMethodDataProvider(): array
    {
        return [
            [
                'paymentMethod' => 'adyen_cc',
                'paymentMethodGroup' => 'adyen',
                'customerId' => 1,
                'executeSuccess' => true,
                'donationTryCount' => 0
            ],
            [
                'paymentMethod' => 'adyen_ideal',
                'paymentMethodGroup' => 'adyen-alternative-payment-method',
                'customerId' => null,
                'executeSuccess' => true,
                'donationTryCount' => 0
            ],
            [
                'paymentMethod' => 'adyen_cc',
                'paymentMethodGroup' => 'adyen',
                'customerId' => null,
                'executeSuccess' => false,
                'donationTryCount' => 0
            ],
            [
                'paymentMethod' => 'adyen_cc',
                'paymentMethodGroup' => 'adyen',
                'customerId' => null,
                'executeSuccess' => false,
                'donationTryCount' => 5
            ],
        ];
    }

    /**
     * @dataProvider paymentMethodDataProvider
     * @param $paymentMethod
     * @param $paymentMethodGroup
     * @param $customerId
     * @param $executeSuccess
     * @param $donationTryCount
     * @return void
     * @throws InputException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function testDonate($paymentMethod, $paymentMethodGroup, $customerId, $executeSuccess, $donationTryCount)
    {
        $orderId = 1;
        $payload = '{"amount":{"value":1000,"currency":"EUR"}}';
        $donationTokenMock = 'mock_token_abcde';
        $orderCurrency = 'EUR';
        $storeId = 1;
        $donationAmounts = '1,5,10';
        $pspReference = 'xyz_12345';
        $orderIncrementId = '00000000001';

        $paymentMethodInstanceMock = $this->createMock(MethodInterface::class);
        $paymentMethodInstanceMock->method('getConfigData')
            ->with('group')
            ->willReturn($paymentMethodGroup);

        $paymentMock = $this->createMock(Order\Payment::class);
        $paymentMock->expects($this->once())
            ->method('getMethodInstance')
            ->willReturn($paymentMethodInstanceMock);
        $paymentMock->expects($this->once())
            ->method('getMethod')
            ->willReturn($paymentMethod);
        $paymentMock->method('getAdditionalInformation')
            ->willReturnMap([
                ['donationToken', $donationTokenMock],
                ['pspReference', $pspReference],
                ['donationTryCount', $donationTryCount]
            ]);

        $orderMock = $this->createMock(Order::class);
        $orderMock->method('getPayment')->willReturn($paymentMock);
        $orderMock->method('getStoreId')->willReturn($storeId);
        $orderMock->method('getCustomerId')->willReturn($customerId);
        $orderMock->method('getIncrementId')->willReturn($orderIncrementId);

        $this->orderRepositoryMock->expects($this->once())
            ->method('get')
            ->with($orderId)
            ->willReturn($orderMock);
        $this->orderRepositoryMock->method('save')->with($orderMock);

        $orderAmountCurrencyMock = $this->createMock(AdyenAmountCurrency::class);
        $orderAmountCurrencyMock->method('getCurrencyCode')->willReturn($orderCurrency);

        $this->chargedCurrencyMock->expects($this->once())
            ->method('getOrderAmountCurrency')
            ->with($orderMock, false)
            ->willReturn($orderAmountCurrencyMock);

        $this->configMock->expects($this->once())
            ->method('getAdyenGivingDonationAmounts')
            ->with($storeId)
            ->willReturn($donationAmounts);

        $donationCommand = $this->createMock(CommandInterface::class);

        if ($executeSuccess) {
            $paymentMock->expects($this->once())
                ->method('unsAdditionalInformation')
                ->with('donationToken');

            $donationCommand->expects($this->once())->method('execute');
        } else {
            $this->expectException(LocalizedException::class);

            $donationCommand->method('execute')->willThrowException(
                new LocalizedException(__('exception'))
            );
        }

        $this->commandPoolMock->expects($this->once())
            ->method('get')
            ->with('capture')
            ->willReturn($donationCommand);

        $this->adyenDonations->donate($orderId, $payload);
    }

    /**
     * @return void
     * @throws LocalizedException
     * @throws AdyenException
     */
    public function testNullDonationToken()
    {
        $this->expectException(LocalizedException::class);

        $payload = '{"amount":{"value":1000,"currency":"EUR"}}';
        $donationTokenMock = null;

        $paymentMock = $this->createMock(Order\Payment::class);
        $paymentMock->method('getAdditionalInformation')
            ->willReturnMap([
                ['donationToken', $donationTokenMock]
            ]);

        $orderMock = $this->createMock(Order::class);
        $orderMock->expects($this->once())->method('getPayment')->willReturn($paymentMock);

        // Assert LocalizedException
        $this->adyenDonations->makeDonation($payload, $orderMock);
    }

    /**
     * @return void
     * @throws AdyenException
     * @throws LocalizedException
     */
    public function testCurrencyMismatch()
    {
        $this->expectException(LocalizedException::class);

        $payload = '{"amount":{"value":1000,"currency":"EUR"}}';
        $donationTokenMock = 'mock_token_abcde';
        $orderCurrency = 'TRY';

        $paymentMock = $this->createMock(Order\Payment::class);
        $paymentMock->method('getAdditionalInformation')
            ->willReturnMap([
                ['donationToken', $donationTokenMock]
            ]);
        $orderMock = $this->createMock(Order::class);
        $orderMock->expects($this->once())->method('getPayment')->willReturn($paymentMock);

        $orderAmountCurrencyMock = $this->createMock(AdyenAmountCurrency::class);
        $orderAmountCurrencyMock->method('getCurrencyCode')->willReturn($orderCurrency);

        $this->chargedCurrencyMock->expects($this->once())
            ->method('getOrderAmountCurrency')
            ->with($orderMock, false)
            ->willReturn($orderAmountCurrencyMock);

        // Assert LocalizedException
        $this->adyenDonations->makeDonation($payload, $orderMock);
    }
}
