<?php

namespace Adyen\Payment\Test\Gateway\Request;

use Magento\Payment\Gateway\Data\OrderAdapterInterface;
use Magento\Payment\Gateway\Data\PaymentDataObject;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Item;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Sales\Model\Order\Address;
use Adyen\Payment\Gateway\Request\AdditionalDataLevel23DataBuilder;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Adyen\Payment\Model\AdyenAmountCurrency;
use Adyen\Payment\Helper\Config;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Helper\ChargedCurrency;
use Adyen\Payment\Helper\Requests;

class AdditionalDataLevel23DataBuilderTest extends AbstractAdyenTestCase
{
    private $storeMock;
    private $configMock;
    private $storeManagerMock;
    private $adyenHelperMock;
    private $chargedCurrencyMock;
    private $adyenRequestHelperMock;


    protected function setUp(): void
    {
        $this->configMock = $this->createMock(Config::class);
        $this->storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $this->chargedCurrencyMock = $this->createMock(ChargedCurrency::class);
        $this->adyenRequestHelperMock = $this->createMock(Requests::class);
        $this->adyenHelperMock = $this->createMock(Data::class);
        $this->storeMock = $this->createMock(StoreInterface::class);
        $this->storeManagerMock->method('getStore')->willReturn($this->storeMock);

        $this->additionalDataBuilder = new AdditionalDataLevel23DataBuilder(
            $this->configMock,
            $this->storeManagerMock,
            $this->chargedCurrencyMock,
            $this->adyenRequestHelperMock,
            $this->adyenHelperMock
        );
    }

    public function testLevel23DataConfigurationEnabled()
    {
        $storeId = 1;
        $currencyCode = 'USD';
        $customerId = 123;
        $orderIncrementId = '000000123';
        $shopperReference = '000123';
        $taxAmount = 10.00;
        $formattedTaxAmount = '1000';

        $this->storeMock->method('getId')->willReturn($storeId);
        $this->configMock->method('sendLevel23AdditionalData')->with($storeId)->willReturn(true);
        $this->chargedCurrencyMock->method('getOrderAmountCurrency')->willReturn(new AdyenAmountCurrency(null, $currencyCode));
        $this->adyenHelperMock->method('formatAmount')->willReturn($formattedTaxAmount);
        $this->adyenRequestHelperMock->method('getShopperReference')->with($customerId, $orderIncrementId)->willReturn($shopperReference);
        $itemMock1 = $this->createMock(Item::class);
        $itemMock2 = $this->createMock(Item::class);

        $orderMock = $this->createConfiguredMock(Order::class, [
            'getCustomerId' => $customerId,
            'getIncrementId' => $orderIncrementId,
            'getTaxAmount' => $taxAmount,
            'getItems' => [$itemMock1, $itemMock2]
        ]);

        $orderAdapterMock = $this->createMock(OrderAdapterInterface::class);
        $paymentMock = $this->createMock(Payment::class);
        $paymentMock->method('getOrder')->willReturn($orderMock);
        $paymentDataObject = new PaymentDataObject($orderAdapterMock, $paymentMock);
        $buildSubject = ['payment' => $paymentDataObject, 'order' => $orderMock];
        $result = $this->additionalDataBuilder->build($buildSubject);
        $expectedResult = [
            'body' => [
                'additionalData' => [
                    'enhancedSchemeData.totalTaxAmount' => '1000',
                    'enhancedSchemeData.customerReference' => '000123',
                    'enhancedSchemeData.itemDetailLine0.description' => null,
                    'enhancedSchemeData.itemDetailLine0.unitPrice' => '1000',
                    'enhancedSchemeData.itemDetailLine0.discountAmount' => '1000',
                    'enhancedSchemeData.itemDetailLine0.commodityCode' => null,
                    'enhancedSchemeData.itemDetailLine0.quantity' => null,
                    'enhancedSchemeData.itemDetailLine0.productCode' => null,
                    'enhancedSchemeData.itemDetailLine0.totalAmount' => '1000',
                    'enhancedSchemeData.itemDetailLine1.description' => null,
                    'enhancedSchemeData.itemDetailLine1.unitPrice' => '1000',
                    'enhancedSchemeData.itemDetailLine1.discountAmount' => '1000',
                    'enhancedSchemeData.itemDetailLine1.commodityCode' => null,
                    'enhancedSchemeData.itemDetailLine1.quantity' => null,
                    'enhancedSchemeData.itemDetailLine1.productCode' => null,
                    'enhancedSchemeData.itemDetailLine1.totalAmount' => '1000',
                ]
            ]
        ];

        $this->assertEquals($expectedResult, $result);
    }

    public function testLevel23DataConfigurationDisabled()
    {
        $storeId = 1;
        $orderIncrementId = '000000123';
        $customerId = 123;
        $taxAmount = 10.00;

        $this->storeMock->method('getId')->willReturn($storeId);
        $this->configMock->method('sendLevel23AdditionalData')->with($storeId)->willReturn(false);
        $itemMock1 = $this->createMock(Item::class);
        $itemMock2 = $this->createMock(Item::class);

        $orderMock = $this->createConfiguredMock(Order::class, [
            'getCustomerId' => $customerId,
            'getIncrementId' => $orderIncrementId,
            'getTaxAmount' => $taxAmount,
            'getItems' => [$itemMock1, $itemMock2]
        ]);

        $orderAdapterMock = $this->createMock(OrderAdapterInterface::class);
        $paymentMock = $this->createMock(Payment::class);
        $paymentMock->method('getOrder')->willReturn($orderMock);
        $paymentDataObject = new PaymentDataObject($orderAdapterMock, $paymentMock);
        $buildSubject = ['payment' => $paymentDataObject, 'order' => $orderMock];
        $result = $this->additionalDataBuilder->build($buildSubject);
        $expectedResult = [
            'body' => []
        ];

        $this->assertEquals($expectedResult, $result);
    }

    public function testVirtualOrder()
    {
        $storeId = 1;
        $currencyCode = 'USD';
        $customerId = 123;
        $orderIncrementId = '000000123';
        $shopperReference = '000123';
        $taxAmount = 10.00;
        $formattedTaxAmount = '1000';

        $this->storeMock->method('getId')->willReturn($storeId);
        $this->configMock->method('sendLevel23AdditionalData')->with($storeId)->willReturn(true);
        $this->chargedCurrencyMock->method('getOrderAmountCurrency')->willReturn(new AdyenAmountCurrency(null, $currencyCode));
        $this->adyenHelperMock->method('formatAmount')->willReturn($formattedTaxAmount);
        $this->adyenRequestHelperMock->method('getShopperReference')->with($customerId, $orderIncrementId)->willReturn($shopperReference);

        $orderMock = $this->createConfiguredMock(Order::class, [
            'getCustomerId' => $customerId,
            'getIncrementId' => $orderIncrementId,
            'getTaxAmount' => $taxAmount,
            'getItems' => [],
            'getIsNotVirtual' => false,
            'getShippingAddress' => null,
            'getBaseShippingAmount' => 0.00
        ]);

        $orderAdapterMock = $this->createMock(OrderAdapterInterface::class);
        $paymentMock = $this->createMock(Payment::class);
        $paymentMock->method('getOrder')->willReturn($orderMock);
        $paymentDataObject = new PaymentDataObject($orderAdapterMock, $paymentMock);
        $buildSubject = ['payment' => $paymentDataObject, 'order' => $orderMock];
        $result = $this->additionalDataBuilder->build($buildSubject);

        $expectedResult = [
            'body' => [
                'additionalData' => [
                    'enhancedSchemeData.totalTaxAmount' => '1000',
                    'enhancedSchemeData.customerReference' => '000123'
                ]
            ]
        ];

        $this->assertEquals($expectedResult, $result);
    }

    public function testVirtualOrderGuest()
    {
        $storeId = 1;
        $orderShopperReference = 123;
        $currencyCode = 'USD';
        $customerId = null;
        $orderIncrementId = '000000123';
        $shopperReference = 'guest-cart-123';
        $taxAmount = 10.00;
        $formattedTaxAmount = '1000';

        $this->storeMock->method('getId')->willReturn($storeId);
        $this->configMock->method('sendLevel23AdditionalData')->with($storeId)->willReturn(true);
        $this->chargedCurrencyMock->method('getOrderAmountCurrency')->willReturn(new AdyenAmountCurrency(null, $currencyCode));
        $this->adyenHelperMock->method('formatAmount')->willReturn($formattedTaxAmount);
        $this->adyenRequestHelperMock->method('getShopperReference')->with(null, $orderIncrementId, $orderShopperReference)->willReturn($shopperReference);

        $orderMock = $this->createConfiguredMock(Order::class, [
            'getCustomerId' => $customerId,
            'getIncrementId' => $orderIncrementId,
            'getTaxAmount' => $taxAmount,
            'getItems' => [],
            'getIsNotVirtual' => false,
            'getShippingAddress' => null,
            'getBaseShippingAmount' => 0.00,
        ]);

        $orderAdapterMock = $this->createMock(OrderAdapterInterface::class);
        $paymentMock = $this->createMock(Payment::class);
        $paymentMock->method('getOrder')->willReturn($orderMock);
        $paymentMock->method('getAdditionalInformation')->willReturn($orderShopperReference);
        $paymentDataObject = new PaymentDataObject($orderAdapterMock, $paymentMock);
        $buildSubject = ['payment' => $paymentDataObject, 'order' => $orderMock];
        $result = $this->additionalDataBuilder->build($buildSubject);

        $expectedResult = [
            'body' => [
                'additionalData' => [
                    'enhancedSchemeData.totalTaxAmount' => '1000',
                    'enhancedSchemeData.customerReference' => $shopperReference
                ]
            ]
        ];

        $this->assertEquals($expectedResult, $result);
    }

    public function testNonVirtualOrder()
    {
        $storeId = 1;
        $currencyCode = 'USD';
        $customerId = 123;
        $orderIncrementId = '000000123';
        $shopperReference = '000123';
        $taxAmount = 10.00;
        $shippingAmount = 5.00;
        $formattedAmount = '1000';
        $postalCode = '12345';
        $countryId = 'US';

        $this->storeMock->method('getId')->willReturn($storeId);
        $this->configMock->method('sendLevel23AdditionalData')->with($storeId)->willReturn(true);
        $this->chargedCurrencyMock->method('getOrderAmountCurrency')->willReturn(new AdyenAmountCurrency(null, $currencyCode));
        $this->adyenHelperMock->method('formatAmount')->willReturn($formattedAmount);
        $this->adyenRequestHelperMock->method('getShopperReference')->with($customerId, $orderIncrementId)->willReturn($shopperReference);
        $shippingAddressMock = $this->createMock(Address::class);
        $shippingAddressMock->method('getPostcode')->willReturn($postalCode);
        $shippingAddressMock->method('getCountryId')->willReturn($countryId);

        $orderMock = $this->createConfiguredMock(Order::class, [
            'getCustomerId' => $customerId,
            'getIncrementId' => $orderIncrementId,
            'getTaxAmount' => $taxAmount,
            'getIsNotVirtual' => true,
            'getShippingAddress' => $shippingAddressMock,
            'getBaseShippingAmount' => $shippingAmount,
            'getItems' => []
        ]);

        $orderAdapterMock = $this->createMock(OrderAdapterInterface::class);
        $paymentMock = $this->createMock(Payment::class);
        $paymentMock->method('getOrder')->willReturn($orderMock);
        $paymentDataObject = new PaymentDataObject($orderAdapterMock, $paymentMock);
        $buildSubject = ['payment' => $paymentDataObject, 'order' => $orderMock];
        $result = $this->additionalDataBuilder->build($buildSubject);
        $expectedResult = [
            'body' => [
                'additionalData' => [
                    'enhancedSchemeData.totalTaxAmount' => $formattedAmount,
                    'enhancedSchemeData.customerReference' => $shopperReference,
                    'enhancedSchemeData.freightAmount' => $formattedAmount,
                    'enhancedSchemeData.destinationPostalCode' => $postalCode,
                    'enhancedSchemeData.destinationCountryCode' => $countryId
                ]
            ]
        ];

        $this->assertEquals($expectedResult, $result);
    }

    public function testOrderWithDiscount()
    {
        $storeId = 1;
        $currencyCode = 'USD';
        $customerId = 123;
        $orderIncrementId = '000000123';
        $shopperReference = '000123';
        $taxAmount = 10.00;
        $formattedAmount = '1000';
        $discountAmount = 2.00;

        $this->storeMock->method('getId')->willReturn($storeId);
        $this->configMock->method('sendLevel23AdditionalData')->with($storeId)->willReturn(true);
        $this->chargedCurrencyMock->method('getOrderAmountCurrency')->willReturn(new AdyenAmountCurrency(null, $currencyCode));
        $this->adyenHelperMock->method('formatAmount')->willReturn($formattedAmount);
        $this->adyenRequestHelperMock->method('getShopperReference')->with($customerId, $orderIncrementId)->willReturn($shopperReference);

        $itemMock1 = $this->createConfiguredMock(Item::class, [
            'getPrice' => 10.00,
            'getDiscountAmount' => $discountAmount,
            'getName' => 'Item 1',
            'getQuoteItemId' => 101,
            'getQtyOrdered' => 1,
            'getSku' => 'sku-1',
            'getRowTotal' => 8.00
        ]);

        $itemMock2 = $this->createConfiguredMock(Item::class, [
            'getPrice' => 20.00,
            'getDiscountAmount' => $discountAmount,
            'getName' => 'Item 2',
            'getQuoteItemId' => 102,
            'getQtyOrdered' => 1,
            'getSku' => 'sku-2',
            'getRowTotal' => 18.00
        ]);

        $orderMock = $this->createConfiguredMock(Order::class, [
            'getCustomerId' => $customerId,
            'getIncrementId' => $orderIncrementId,
            'getTaxAmount' => $taxAmount,
            'getItems' => [$itemMock1, $itemMock2]
        ]);

        $orderAdapterMock = $this->createMock(OrderAdapterInterface::class);
        $paymentMock = $this->createMock(Payment::class);
        $paymentMock->method('getOrder')->willReturn($orderMock);
        $paymentDataObject = new PaymentDataObject($orderAdapterMock, $paymentMock);
        $buildSubject = ['payment' => $paymentDataObject, 'order' => $orderMock];
        $result = $this->additionalDataBuilder->build($buildSubject);
        $expectedResult = [
            'body' => [
                'additionalData' => [
                    'enhancedSchemeData.totalTaxAmount' => '1000',
                    'enhancedSchemeData.customerReference' => '000123',
                    'enhancedSchemeData.itemDetailLine0.description' => 'Item 1',
                    'enhancedSchemeData.itemDetailLine0.unitPrice' => '1000',
                    'enhancedSchemeData.itemDetailLine0.discountAmount' => '1000',
                    'enhancedSchemeData.itemDetailLine0.commodityCode' => 101,
                    'enhancedSchemeData.itemDetailLine0.quantity' => 1,
                    'enhancedSchemeData.itemDetailLine0.productCode' => 'sku-1',
                    'enhancedSchemeData.itemDetailLine0.totalAmount' => '1000',
                    'enhancedSchemeData.itemDetailLine1.description' => 'Item 2',
                    'enhancedSchemeData.itemDetailLine1.unitPrice' => '1000',
                    'enhancedSchemeData.itemDetailLine1.discountAmount' => '1000',
                    'enhancedSchemeData.itemDetailLine1.commodityCode' => 102,
                    'enhancedSchemeData.itemDetailLine1.quantity' => 1,
                    'enhancedSchemeData.itemDetailLine1.productCode' => 'sku-2',
                    'enhancedSchemeData.itemDetailLine1.totalAmount' => '1000',
                ]
            ]
        ];

        $this->assertEquals($expectedResult, $result);
    }
}
