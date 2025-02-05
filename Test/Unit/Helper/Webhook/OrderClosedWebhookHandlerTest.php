<?php

namespace Adyen\Payment\Test\Unit\Helper;

use Adyen\Payment\Model\ResourceModel\Order\Payment\Collection;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Framework\Serialize\SerializerInterface;
use Adyen\Payment\Helper\Webhook\OrderClosedWebhookHandler;
use Adyen\Payment\Helper\AdyenOrderPayment;
use Adyen\Payment\Helper\Config;
use Adyen\Payment\Helper\Order as OrderHelper;
use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Model\Notification;
use Magento\Sales\Model\Order as MagentoOrder;
use Adyen\Payment\Model\ResourceModel\Order\Payment\CollectionFactory as OrderPaymentCollectionFactory;
use Adyen\Payment\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface as MagentoOrderPaymentInterface;
use Magento\Sales\Model\Order;

class OrderClosedWebhookHandlerTest extends AbstractAdyenTestCase
{
    private OrderClosedWebhookHandler $orderClosedWebhookHandler;
    private AdyenOrderPayment $adyenOrderPaymentHelperMock;
    private OrderHelper $orderHelperMock;
    private Config $configHelperMock;
    private AdyenLogger $adyenLoggerMock;
    private OrderPaymentCollectionFactory $adyenOrderPaymentCollectionFactoryMock;
    private SerializerInterface $serializerMock;
    private Order $orderMock;
    private Notification $notificationMock;
    private Collection $adyenOrderPaymentCollectionMock;
    private Order\Payment $adyenOrderPaymentMock;
    private OrderPaymentInterface $adyenOrderPaymentInterfaceMock;

    protected function setUp(): void
    {
        $this->orderMock = $this->createMock(MagentoOrder::class);
        $this->notificationMock = $this->createMock(Notification::class);
        $this->adyenOrderPaymentCollectionMock = $this->createMock(Collection::class);
        $this->adyenOrderPaymentHelperMock = $this->createMock(AdyenOrderPayment::class);
        $this->orderHelperMock = $this->createMock(OrderHelper::class);
        $this->configHelperMock = $this->createMock(Config::class);
        $this->adyenLoggerMock = $this->createMock(AdyenLogger::class);
        $this->adyenOrderPaymentCollectionFactoryMock = $this->createGeneratedMock(OrderPaymentCollectionFactory::class, ['create']);
        $this->adyenOrderPaymentMock = $this->createConfiguredMock(Order\Payment::class, [
            'getId' => 123
        ]);
        $this->serializerMock = $this->createMock(SerializerInterface::class);
        $this->adyenOrderPaymentInterfaceMock = $this->createMock(OrderPaymentInterface::class);
        $this->orderClosedWebhookHandler = new OrderClosedWebhookHandler(
            $this->adyenOrderPaymentHelperMock,
            $this->orderHelperMock,
            $this->configHelperMock,
            $this->adyenOrderPaymentCollectionFactoryMock,
            $this->adyenLoggerMock,
            $this->serializerMock
        );
    }

    public function testHandleWebhookSuccessfulNotificationWithMatchingAdyenOrderPayment()
    {
        $additionalDataString = 'a:1:{s:20:"order-1-pspReference";s:16:"testPspReference";}';
        $additionalData = array (
            'order-1-pspReference' => 'testPspReference',
        );
        $this->notificationMock->expects($this->once())->method('isSuccessful')->willReturn(true);
        $this->notificationMock->expects($this->once())->method('getAdditionalData')->willReturn($additionalDataString);
        $this->serializerMock
            ->method('unserialize')
            ->with($additionalDataString)
            ->willReturn($additionalData);

        $this->adyenOrderPaymentCollectionMock->method('addFieldToFilter')->willReturnSelf();
        $this->adyenOrderPaymentCollectionMock->method('getFirstItem')->willReturn($this->adyenOrderPaymentMock);

        $this->adyenOrderPaymentCollectionFactoryMock->method('create')->willReturn($this->adyenOrderPaymentCollectionMock);

        $this->adyenLoggerMock->method('addAdyenNotification')
            ->with('Updated adyen_order_payment with order status 1 for pspReference testPspReference', [
                'pspReference' => 'testPspReference',
                'status' => 1,
                'merchantReference' => $this->notificationMock->getMerchantReference()
            ]);

        $this->orderMock->expects($this->once())->method('addCommentToStatusHistory')
            ->with(__('This order has been successfully completed.'));

        $result = $this->orderClosedWebhookHandler->handleWebhook($this->orderMock, $this->notificationMock, 'completed');
        $this->assertSame($this->orderMock, $result);
    }

    public function testHandleWebhookUnsuccessfulNotificationWithRefund()
    {
        $this->notificationMock->expects($this->once())->method('isSuccessful')->willReturn(false);

        $this->orderMock->expects($this->once())->method('getPayment')->willReturn(
            $this->createConfiguredMock(MagentoOrderPaymentInterface::class, ['getEntityId' => 123])
        );

        $this->adyenOrderPaymentCollectionMock->method('addFieldToFilter')
            ->willReturnSelf();

        $this->adyenOrderPaymentCollectionMock->expects($this->once())->method('getItems')->willReturn([$this->adyenOrderPaymentInterfaceMock]);

        $this->adyenOrderPaymentCollectionFactoryMock->expects($this->once())->method('create')->willReturn($this->adyenOrderPaymentCollectionMock);

        $this->adyenOrderPaymentHelperMock->expects($this->once())->method('refundFullyAdyenOrderPayment')->with($this->adyenOrderPaymentInterfaceMock);

        $this->orderMock->expects($this->once())->method('addCommentToStatusHistory')
            ->with(__('All the funds captured/settled will be refunded by Adyen.'));

        $this->adyenLoggerMock->expects($this->once())->method('addAdyenNotification')
            ->with('All the funds captured/settled will be refunded by Adyen.', [
                'pspReference' => $this->notificationMock->getPspreference(),
                'merchantReference' => $this->notificationMock->getMerchantReference()
            ]);

        $this->orderHelperMock->method('holdCancelOrder')->with($this->orderMock, true);

        $this->configHelperMock->method('getNotificationsCanCancel')->with($this->orderMock->getStoreId())->willReturn(true);

        $result = $this->orderClosedWebhookHandler->handleWebhook($this->orderMock, $this->notificationMock, 'completed');
        $this->assertSame($this->orderMock, $result);
    }

    public function testHandleWebhookLogsMessageWhenNoMatchingRecordFound(): void
    {
        $pspReference = 'testPspReference';
        $merchantReference = 'testMerchantReference';
        $additionalData = [
            'order-1-pspReference' => $pspReference,
        ];
        $adyenOrderPaymentMock = $this->createConfiguredMock(Order\Payment::class, [
            'getId' => null
        ]);

        $this->notificationMock->expects($this->once())
            ->method('getAdditionalData')
            ->willReturn(json_encode($additionalData));
        $this->notificationMock->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $this->notificationMock->expects($this->once())
            ->method('getMerchantReference')
            ->willReturn($merchantReference);

        $this->serializerMock->expects($this->once())
            ->method('unserialize')
            ->with(json_encode($additionalData))
            ->willReturn($additionalData);

        $this->adyenOrderPaymentCollectionFactoryMock->expects($this->once())->method('create')->willReturn($this->adyenOrderPaymentCollectionMock);

        $this->adyenOrderPaymentCollectionMock
            ->method('addFieldToFilter')
            ->willReturnSelf();
        $this->adyenOrderPaymentCollectionMock->expects($this->once())
            ->method('getFirstItem')
            ->willReturn($adyenOrderPaymentMock);

        // Log message assertion
        $this->adyenLoggerMock->expects($this->once())
            ->method('addAdyenNotification')
            ->with(
                sprintf("No adyen_order_payment record found for pspReference %s", $pspReference),
                [
                    'pspReference' => $pspReference,
                    'merchantReference' => $merchantReference
                ]
            );

        $this->orderClosedWebhookHandler->handleWebhook($this->orderMock, $this->notificationMock, 'someTransitionState');
    }
}
