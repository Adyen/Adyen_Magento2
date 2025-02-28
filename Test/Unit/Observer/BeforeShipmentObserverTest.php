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

namespace Adyen\Payment\Test\Unit\Observer;

use Adyen\Payment\Helper\Config;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Helper\PaymentMethods;
use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Observer\AdyenPaymentMethodDataAssignObserver;
use Adyen\Payment\Observer\BeforeShipmentObserver;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Exception;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Payment\Model\MethodInterface;
use Magento\Sales\Api\Data\ShipmentItemInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\InvoiceRepository;
use Magento\Sales\Model\Order\Payment;

class BeforeShipmentObserverTest extends AbstractAdyenTestCase
{
    # Build the test class
    private $beforeShipmentObserver;

    # Define constructor arguments as mocks
    private $adyenHelperMock;
    private $configHelperMock;
    private $adyenLoggerMock;
    private $invoiceRepositoryMock;
    private $paymentMethodsHelperMock;

    # Other mock objects
    private $paymentMock;
    private $orderMock;
    private $observerMock;
    private $eventMock;
    private $shipmentMock;

    public function setUp(): void
    {
        $this->adyenHelperMock = $this->createMock(Data::class);
        $this->configHelperMock = $this->createMock(Config::class);
        $this->adyenLoggerMock = $this->createMock(AdyenLogger::class);
        $this->invoiceRepositoryMock = $this->createMock(InvoiceRepository::class);
        $this->paymentMethodsHelperMock = $this->createMock(PaymentMethods::class);

        $this->paymentMock = $this->createMock(Payment::class);

        $this->orderMock = $this->createMock(Order::class);
        $this->orderMock->method('getPayment')->willReturn($this->paymentMock);
        $this->orderMock->method('getStoreId')->willReturn(1);

        $this->shipmentMock = $this->createMock(Order\Shipment::class);
        $this->shipmentMock->method('getOrder')->willReturn($this->orderMock);

        $this->eventMock = $this->createMock(Event::class);
        $this->eventMock->method('getData')->with('shipment')->willReturn($this->shipmentMock);

        $this->observerMock = $this->createMock(Observer::class);
        $this->observerMock->method('getEvent')->willReturn($this->eventMock);

        $this->beforeShipmentObserver = new BeforeShipmentObserver(
            $this->adyenHelperMock,
            $this->configHelperMock,
            $this->adyenLoggerMock,
            $this->invoiceRepositoryMock,
            $this->paymentMethodsHelperMock
        );
    }

    public function testNonAdyenPaymentMethod()
    {
        $randomPaymentMethod = 'random_payment_method';

        $this->paymentMethodsHelperMock->method('isAdyenPayment')
            ->with($randomPaymentMethod)
            ->willReturn(false);

        $this->paymentMock->method('getMethod')->willReturn($randomPaymentMethod);

        $this->adyenLoggerMock->expects($this->once())->method('info');
        $this->configHelperMock->expects($this->never())->method('getConfigData');

        $this->beforeShipmentObserver->execute($this->observerMock);
    }

    public function testCaptureManual()
    {
        $randomPaymentMethod = 'adyen_klarna';

        $this->paymentMethodsHelperMock->method('isAdyenPayment')
            ->with($randomPaymentMethod)
            ->willReturn(true);

        $this->paymentMock->method('getMethod')->willReturn($randomPaymentMethod);

        $this->configHelperMock->method('getConfigData')
            ->with('capture_for_openinvoice', BeforeShipmentObserver::XML_ADYEN_ABSTRACT_PREFIX, 1)
            ->willReturn('manual');

        $this->adyenLoggerMock->expects($this->once())->method('info');
        $this->paymentMock->expects($this->never())->method('getAdditionalInformation');

        $this->beforeShipmentObserver->execute($this->observerMock);
    }

    public function testNonOpenInvoicePaymentMethod()
    {
        $randomPaymentMethod = 'adyen_klarna';

        $paymentMethodInstanceMock = $this->createMock(MethodInterface::class);

        $this->paymentMethodsHelperMock->method('isAdyenPayment')->willReturn(true);
        $this->paymentMethodsHelperMock->method('isOpenInvoice')
            ->with($paymentMethodInstanceMock)
            ->willReturn(false);

        $this->paymentMock->method('getMethod')->willReturn($randomPaymentMethod);
        $this->paymentMock->method('getMethodInstance')->willReturn($paymentMethodInstanceMock);

        $this->configHelperMock->method('getConfigData')
            ->with('capture_for_openinvoice', BeforeShipmentObserver::XML_ADYEN_ABSTRACT_PREFIX, 1)
            ->willReturn(BeforeShipmentObserver::ONSHIPMENT_CAPTURE_OPENINVOICE);

        $this->adyenLoggerMock->expects($this->once())->method('info');
        $this->orderMock->expects($this->never())->method('canInvoice');

        $this->beforeShipmentObserver->execute($this->observerMock);
    }

    public function testNonInvoicableOrder()
    {
        $randomPaymentMethod = 'adyen_klarna';

        $paymentMethodInstanceMock = $this->createMock(MethodInterface::class);

        $this->paymentMethodsHelperMock->method('isAdyenPayment')->willReturn(true);
        $this->paymentMethodsHelperMock->method('isOpenInvoice')
            ->with($paymentMethodInstanceMock)
            ->willReturn(true);

        $this->paymentMock->method('getMethod')->willReturn($randomPaymentMethod);
        $this->paymentMock->method('getMethodInstance')->willReturn($paymentMethodInstanceMock);

        $this->configHelperMock->method('getConfigData')
            ->with('capture_for_openinvoice', BeforeShipmentObserver::XML_ADYEN_ABSTRACT_PREFIX, 1)
            ->willReturn(BeforeShipmentObserver::ONSHIPMENT_CAPTURE_OPENINVOICE);

        $this->orderMock->method('canInvoice')->willReturn(false);

        $this->adyenLoggerMock->expects($this->once())->method('info');

        $this->beforeShipmentObserver->execute($this->observerMock);
    }

    public function testSuccessfulShipment()
    {
        $randomPaymentMethod = 'adyen_klarna';

        $paymentMethodInstanceMock = $this->createMock(MethodInterface::class);

        $this->paymentMock->method('getMethod')->willReturn($randomPaymentMethod);
        $this->paymentMock->method('getMethodInstance')->willReturn($paymentMethodInstanceMock);

        $this->paymentMethodsHelperMock->method('isAdyenPayment')->willReturn(true);
        $this->paymentMethodsHelperMock->method('isOpenInvoice')
            ->with($paymentMethodInstanceMock)
            ->willReturn(true);

        $this->configHelperMock->method('getConfigData')
            ->with('capture_for_openinvoice', BeforeShipmentObserver::XML_ADYEN_ABSTRACT_PREFIX, 1)
            ->willReturn(BeforeShipmentObserver::ONSHIPMENT_CAPTURE_OPENINVOICE);

        $invoiceMock = $this->createMock(Order\Invoice::class);
        $invoiceMock->method('getOrder')->willReturn($this->orderMock);

        $this->orderMock->method('canInvoice')->willReturn(true);
        $this->orderMock->method('prepareInvoice')->willReturn($invoiceMock);

        $shipmentItem = $this->createMock(ShipmentItemInterface::class);
        $shipmentItem->method('getOrderItemId')->willReturn(1);
        $shipmentItem->method('getQty')->willReturn(10);
        $this->shipmentMock->method('getItems')->willReturn([$shipmentItem]);

        $this->invoiceRepositoryMock->expects($this->once())->method('save');

        $this->beforeShipmentObserver->execute($this->observerMock);
    }

    public function testSaveError()
    {
        $this->expectException(Exception::class);

        $randomPaymentMethod = 'adyen_klarna';

        $paymentMethodInstanceMock = $this->createMock(MethodInterface::class);

        $this->paymentMock->method('getMethod')->willReturn($randomPaymentMethod);
        $this->paymentMock->method('getMethodInstance')->willReturn($paymentMethodInstanceMock);

        $this->configHelperMock->method('getConfigData')
            ->with('capture_for_openinvoice', BeforeShipmentObserver::XML_ADYEN_ABSTRACT_PREFIX, 1)
            ->willReturn(BeforeShipmentObserver::ONSHIPMENT_CAPTURE_OPENINVOICE);

        $this->paymentMethodsHelperMock->method('isAdyenPayment')->willReturn(true);
        $this->paymentMethodsHelperMock->expects($this->once())
            ->method('isOpenInvoice')
            ->with($paymentMethodInstanceMock)
            ->willReturn(true);

        $invoiceMock = $this->createMock(Order\Invoice::class);
        $invoiceMock->method('getOrder')->willReturn($this->orderMock);

        $this->orderMock->method('canInvoice')->willReturn(true);
        $this->orderMock->method('prepareInvoice')->willReturn($invoiceMock);

        $shipmentItem = $this->createMock(ShipmentItemInterface::class);
        $shipmentItem->method('getOrderItemId')->willReturn(1);
        $shipmentItem->method('getQty')->willReturn(10);
        $this->shipmentMock->method('getItems')->willReturn([$shipmentItem]);

        $this->invoiceRepositoryMock->method('save')->willThrowException(new Exception());
        $this->adyenLoggerMock->expects($this->once())->method('error');

        $this->beforeShipmentObserver->execute($this->observerMock);
    }
}
