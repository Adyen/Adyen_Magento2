<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2022 Adyen N.V. (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Test\Unit\Helper;

use Adyen\Payment\Api\Data\InvoiceInterface;
use Adyen\Payment\Api\Repository\AdyenInvoiceRepositoryInterface;
use Adyen\Payment\Helper\ChargedCurrency;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Helper\Invoice;
use Adyen\Payment\Model\AdyenAmountCurrency;
use Adyen\Payment\Model\InvoiceFactory;
use Adyen\Payment\Model\Order\Payment;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Exception;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order as MagentoOrder;
use Magento\Sales\Model\Order\Invoice as InvoiceModel;
use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Model\ResourceModel\Invoice\Collection;
use Adyen\Payment\Model\ResourceModel\Order\Payment as OrderPaymentResourceModel;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\DB\Transaction;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Adyen\Payment\Model\Invoice as AdyenInvoice;

class InvoiceTest extends AbstractAdyenTestCase
{
    /**
     * @throws LocalizedException
     */
    public function testCreateInvoice()
    {
        $invoiceMock = $this->createConfiguredMock(InvoiceModel::class, [
            'getOrder' => $this->createMock(Order::class),
            'register' => $this->createMock(InvoiceModel::class)
        ]);

        $orderMock = $this->createConfiguredMock(MagentoOrder::class, [
            'prepareInvoice' => $invoiceMock,
            'canInvoice' => true,
        ]);

        $scopeConfigMock = $this->createConfiguredMock(ScopeConfigInterface::class, [
            'isSetFlag' => false
        ]);
        $contextMock = $this->createConfiguredMock(Context::class, [
            'getScopeConfig' => $scopeConfigMock
        ]);

        $invoiceHelper = $this->createInvoiceHelper($contextMock);

        $notificationMock = $this->createWebhook();

        $invoice = $invoiceHelper->createInvoice(
            $orderMock,
            $notificationMock,
            true
        );

        $this->assertInstanceOf(InvoiceModel::class, $invoice);
    }

    private static function notificationAmountDataProvider(): array
    {
        return [
            ['notificationAmount' => 0],
            ['notificationAmount' => 1000]
        ];
    }

    /**
     * @dataProvider notificationAmountDataProvider
     *
     * @throws LocalizedException
     */
    public function testCreateInvoiceManualCapture($notificationAmount = 1000)
    {
        $invoiceMock = $this->createGeneratedMock(InvoiceModel::class, [
            'setRequestedCaptureCase',
            'getOrder',
            'register'
        ]);
        $invoiceMock->method('getOrder')->willReturn($this->createMock(Order::class));
        $invoiceMock->method('register')->willReturn($this->createMock(InvoiceModel::class));

        $orderMock = $this->createConfiguredMock(MagentoOrder::class, [
            'prepareInvoice' => $invoiceMock,
            'canInvoice' => true,
        ]);

        $scopeConfigMock = $this->createConfiguredMock(ScopeConfigInterface::class, [
            'isSetFlag' => false
        ]);
        $contextMock = $this->createConfiguredMock(Context::class, [
            'getScopeConfig' => $scopeConfigMock
        ]);

        $invoiceHelper = $this->createInvoiceHelper($contextMock);

        $notificationMock = $this->createWebhook(null, null, $notificationAmount);

        if ($notificationAmount == 0) {
            $invoiceMock->expects($this->once())
                ->method('setRequestedCaptureCase')
                ->with(InvoiceModel::CAPTURE_OFFLINE);
        } else {
            $invoiceMock->expects($this->once())
                ->method('setRequestedCaptureCase')
                ->with(InvoiceModel::NOT_CAPTURE);
        }

        $invoice = $invoiceHelper->createInvoice(
            $orderMock,
            $notificationMock,
            false
        );

        $this->assertInstanceOf(InvoiceModel::class, $invoice);
    }

    public function testCreateAdyenInvoice()
    {
        $adyenInvoiceMockForFactory = $this->createMock(AdyenInvoice::class);
        $adyenInvoiceFactoryMock = $this->createGeneratedMock(InvoiceFactory::class, ['create']);
        $adyenInvoiceFactoryMock->method('create')->willReturn($adyenInvoiceMockForFactory);

        $orderPaymentResourceModelMock = $this->createConfiguredMock(OrderPaymentResourceModel::class, [
            'getOrderPaymentDetails' => [
                'entity_id' => 1000
            ]
        ]);

        $invoiceHelper = $this->createInvoiceHelper(
            null,
            null,
            null,
            null,
            $adyenInvoiceFactoryMock,
            $orderPaymentResourceModelMock
        );

        $orderPaymentMock = $this->createConfiguredMock(MagentoOrder\Payment::class, [
            'getOrder' => $this->createMock(Order::class)
        ]);

        $adyenInvoice = $invoiceHelper->createAdyenInvoice(
            $orderPaymentMock,
            'PSPREFERENCE',
            'ORIGINALREFERENCE',
            1000,
            1
        );

        $this->assertInstanceOf(AdyenInvoice::class, $adyenInvoice);
    }

    /**
     * @return void
     * @throws AlreadyExistsException
     */
    public function testHandleCaptureWebhook()
    {
        $magentoInvoiceId = 1;

        $scopeConfigMock = $this->createConfiguredMock(ScopeConfigInterface::class, [
            'isSetFlag' => false
        ]);
        $contextMock = $this->createConfiguredMock(Context::class, [
            'getScopeConfig' => $scopeConfigMock
        ]);

        $adyenInvoiceMock = $this->createMock(AdyenInvoice::class);
        $adyenInvoiceMock->method('getInvoiceId')->willReturn($magentoInvoiceId);

        $adyenInvoiceFactory = $this->createGeneratedMock(InvoiceFactory::class, ['create']);
        $adyenInvoiceFactory->method('create')->willReturn($adyenInvoiceMock);

        $orderMock = $this->createMock(MagentoOrder::class);

        $invoiceLoadedMock = $this->createConfiguredMock(InvoiceModel::class, [
            'getOrder' => $orderMock
        ]);

        $invoiceMock = $this->createConfiguredMock(InvoiceModel::class, [
            'getId' => $magentoInvoiceId,
            'load' => $invoiceLoadedMock,
            'getOrder' => $orderMock
        ]);

        $magentoInvoiceRepositoryMock = $this->createMock(InvoiceRepositoryInterface::class);
        $magentoInvoiceRepositoryMock->method('get')->with($magentoInvoiceId)->willReturn($invoiceMock);

        $orderPaymentResourceModelMock = $this->createConfiguredMock(OrderPaymentResourceModel::class, [
            'getOrderPaymentDetails' => [
                'entity_id' => 1000
            ]
        ]);

        $transactionMock = $this->createGeneratedMock(Transaction::class, ['addObject']);
        $transactionMock->method('addObject')->willReturn($invoiceMock);

        $adyenAmountCurrencyMock = $this->createMock(AdyenAmountCurrency::class);
        $adyenAmountCurrencyMock->method('getAmount')->willReturn(10);
        $adyenAmountCurrencyMock->method('getCurrencyCode')->willReturn('EUR');

        $invoiceAmountCurrency = $this->createMock(AdyenAmountCurrency::class);

        $chargedCurrencyMock = $this->createMock(ChargedCurrency::class);
        $chargedCurrencyMock->method('getOrderAmountCurrency')->willReturn($adyenAmountCurrencyMock);
        $chargedCurrencyMock->method('getInvoiceAmountCurrency')->willReturn($invoiceAmountCurrency);

        $invoiceHelper = $this->createInvoiceHelper(
            $contextMock,
            null,
            null,
            $magentoInvoiceRepositoryMock,
            $adyenInvoiceFactory,
            $orderPaymentResourceModelMock,
            null,
            null,
            $transactionMock,
            $chargedCurrencyMock
        );

        $orderPaymentMock = $this->createConfiguredMock(MagentoOrder\Payment::class, [
            'getMethod' => 'adyen_cc',
            'getOrder' => $this->createMock(Order::class)
        ]);

        $orderMock = $this->createConfiguredMock(MagentoOrder::class, [
            'canInvoice' => true,
            'getPayment' => $orderPaymentMock,
            'getBaseGrandTotal' => 10,
            'getOrderCurrencyCode' => 'EUR',
            'prepareInvoice' => $invoiceMock
        ]);

        $notificationMock = $this->createWebhook(
            'ADY00000000XY',
            'ADY00000000TX'
        );

        $adyenInvoice = $invoiceHelper->handleCaptureWebhook(
            $orderMock,
            $notificationMock
        );

        $this->assertInstanceOf(AdyenInvoice::class, $adyenInvoice);
    }

    public function testLinkAndUpdateAdyenInvoices()
    {
        $adyenInvoiceMock = $this->createConfiguredMock(AdyenInvoice::class, [
            'getAmount' => 1000.0,
            'getEntityId' => 99,
        ]);

        $adyenInvoiceRepositoryMock = $this->createMock(AdyenInvoiceRepositoryInterface::class);
        $adyenInvoiceRepositoryMock->method('getByAdyenOrderPaymentId')->willReturn([$adyenInvoiceMock]);

        $invoiceHelper = $this->createInvoiceHelper(
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $adyenInvoiceRepositoryMock
        );

        $adyenOrderPaymentMock = $this->createMock(Payment::class);
        $adyenOrderPaymentMock->method('getEntityId')->willReturn(1);

        $invoiceMock = $this->createConfiguredMock(InvoiceModel::class, [
            'getEntityId' => 99
        ]);

        $linkedAmount = $invoiceHelper->linkAndUpdateAdyenInvoices(
            $adyenOrderPaymentMock,
            $invoiceMock
        );

        $this->assertEquals(1000.0, $linkedAmount);
    }

    /**
     * @return void
     */
    public function testIsFullInvoiceAmountManuallyCaptured()
    {
        $adyenInvoiceCollectionMock = $this->createConfiguredMock(Collection::class, [
            'getAdyenInvoicesLinkedToMagentoInvoice' => [
                [
                    InvoiceInterface::STATUS => InvoiceInterface::STATUS_SUCCESSFUL,
                    InvoiceInterface::AMOUNT => 1000
                ]
            ],
        ]);

        $invoiceAmountCurrency = $this->createMock(AdyenAmountCurrency::class);
        $invoiceAmountCurrency->expects($this->once())
            ->method('getAmount')
            ->willReturn(1000);
        $invoiceAmountCurrency->expects($this->once())
            ->method('getCurrencyCode')
            ->willReturn('EUR');

        $chargedCurrencyMock = $this->createMock(ChargedCurrency::class);
        $chargedCurrencyMock->method('getInvoiceAmountCurrency')->willReturn($invoiceAmountCurrency);

        $invoiceHelper = $this->createInvoiceHelper(
            null,
            null,
            null,
            null,
            null,
            null,
            $adyenInvoiceCollectionMock,
            null,
            null,
            $chargedCurrencyMock
        );

        $invoiceMock = $this->createConfiguredMock(InvoiceModel::class, [
            'getGrandTotal' => 1000,
            'getOrderCurrencyCode' => 'EUR',
        ]);

        $isFullInvoiceAmountManuallyCaptured = $invoiceHelper->isFullInvoiceAmountManuallyCaptured(
            $invoiceMock
        );

        $this->assertTrue($isFullInvoiceAmountManuallyCaptured);
    }

    /**
     * @throws AlreadyExistsException
     */
    public function testCreateInvoiceFromWebhook()
    {
        $adyenInvoiceMockForFactory = $this->createMock(AdyenInvoice::class);
        $adyenInvoiceFactoryMock = $this->createGeneratedMock(InvoiceFactory::class, ['create']);
        $adyenInvoiceFactoryMock->method('create')->willReturn($adyenInvoiceMockForFactory);

        $orderPaymentResourceModelMock = $this->createConfiguredMock(OrderPaymentResourceModel::class, [
            'getOrderPaymentDetails' => [
                'entity_id' => 1000
            ]
        ]);

        $scopeConfigMock = $this->createConfiguredMock(ScopeConfigInterface::class, [
            'isSetFlag' => false
        ]);
        $contextMock = $this->createConfiguredMock(Context::class, [
            'getScopeConfig' => $scopeConfigMock
        ]);

        $invoiceMock = $this->createConfiguredMock(InvoiceModel::class, [
            'getId' => 1,
            'getOrder' => $this->createMock(Order::class)
        ]);
        $transactionMock = $this->createGeneratedMock(Transaction::class, ['addObject']);
        $transactionMock->method('addObject')->willReturn($invoiceMock);

        $invoiceHelper = $this->createInvoiceHelper(
            $contextMock,
            null,
            null,
            null,
            $adyenInvoiceFactoryMock,
            $orderPaymentResourceModelMock,
            null,
            null,
            $transactionMock
        );

        $orderPaymentMock = $this->createConfiguredMock(MagentoOrder\Payment::class, [
            'getMethod' => 'adyen_cc',
            'getOrder' => $this->createMock(Order::class)
        ]);

        $orderMock = $this->createConfiguredMock(MagentoOrder::class, [
            'prepareInvoice' => $invoiceMock,
            'getPayment' => $orderPaymentMock,
            'getIncrementId' => '000000001'
        ]);

        $notificationMock = $this->createWebhook(
            'ADY00000000XY',
            'ADY00000000TX'
        );

        $adyenInvoice = $invoiceHelper->createInvoiceFromWebhook(
            $orderMock,
            $notificationMock
        );

        $this->assertInstanceOf(AdyenInvoice::class, $adyenInvoice);
    }

    public function testSendInvoiceMailCatchesException()
    {
        $invoiceSenderMock = $this->createMock(InvoiceSender::class);
        $invoiceModelMock = $this->createMock(InvoiceModel::class);

        $invoiceSenderMock
            ->expects($this->once())
            ->method('send')
            ->willThrowException(new Exception('Test Exception Message'));

        $invoiceHelper = $this->createInvoiceHelper(
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $invoiceSenderMock
        );

        $invoiceHelper->sendInvoiceMail($invoiceModelMock);
    }

    /**
     * @param null $contextMock
     * @param null $adyenLoggerMock
     * @param null $adyenDataHelperMock
     * @param null $invoiceRepositoryInterfaceMock
     * @param null $adyenInvoiceFactory
     * @param null $orderPaymentResourceModelMock
     * @param null $adyenInvoiceCollectionMock
     * @param null $invoiceSenderMock
     * @param null $transactionMock
     * @param null $chargedCurrencyMock
     * @param null $orderRepositoryMock
     * @param null $adyenInvoiceRepositoryMock
     * @return Invoice
     */
    protected function createInvoiceHelper(
        $contextMock = null,
        $adyenLoggerMock = null,
        $adyenDataHelperMock = null,
        $invoiceRepositoryInterfaceMock = null,
        $adyenInvoiceFactory = null,
        $orderPaymentResourceModelMock = null,
        $adyenInvoiceCollectionMock = null,
        $invoiceSenderMock = null,
        $transactionMock = null,
        $chargedCurrencyMock = null,
        $orderRepositoryMock = null,
        $adyenInvoiceRepositoryMock = null
    ): Invoice {
        if (is_null($contextMock)) {
            $contextMock = $this->createMock(Context::class);
        }

        if (is_null($adyenLoggerMock)) {
            $adyenLoggerMock = $this->createMock(AdyenLogger::class);
        }

        if (is_null($adyenDataHelperMock)) {
            $adyenDataHelperMock = $this->createPartialMock(Data::class, []);
        }

        if (is_null($invoiceRepositoryInterfaceMock)) {
            $invoiceRepositoryInterfaceMock = $this->createMock(InvoiceRepositoryInterface::class);
        }

        if (is_null($adyenInvoiceFactory)) {
            $adyenInvoiceFactory = $this->createGeneratedMock(InvoiceFactory::class);
        }

        if (is_null($orderPaymentResourceModelMock)) {
            $orderPaymentResourceModelMock = $this->createMock(OrderPaymentResourceModel::class);
        }

        if (is_null($adyenInvoiceCollectionMock)) {
            $adyenInvoiceCollectionMock = $this->createMock(Collection::class);
        }

        if (is_null($invoiceSenderMock)) {
            $invoiceSenderMock = $this->createMock(InvoiceSender::class);
        }

        if (is_null($transactionMock)) {
            $transactionMock = $this->createGeneratedMock(Transaction::class);
        }

        if (is_null($chargedCurrencyMock)) {
            $chargedCurrencyMock = $this->createMock(ChargedCurrency::class);
        }

        if (is_null($orderRepositoryMock)) {
            $orderRepositoryMock = $this->createMock(OrderRepositoryInterface::class);
        }

        if (is_null($adyenInvoiceRepositoryMock)) {
            $adyenInvoiceRepositoryMock = $this->createMock(AdyenInvoiceRepositoryInterface::class);
        }

        return new Invoice(
            $contextMock,
            $adyenLoggerMock,
            $adyenDataHelperMock,
            $invoiceRepositoryInterfaceMock,
            $adyenInvoiceFactory,
            $orderPaymentResourceModelMock,
            $adyenInvoiceCollectionMock,
            $invoiceSenderMock,
            $transactionMock,
            $chargedCurrencyMock,
            $orderRepositoryMock,
            $adyenInvoiceRepositoryMock
        );
    }
}
