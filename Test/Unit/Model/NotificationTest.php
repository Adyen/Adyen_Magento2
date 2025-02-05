<?php

namespace Adyen\Payment\Test\Unit\Model;

use Adyen\Payment\Model\Notification;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;

class NotificationTest extends AbstractAdyenTestCase
{
    private Context $contextMock;
    private Registry $registryMock;
    private Notification $notification;

    protected function setUp(): void
    {
        $this->contextMock = $this->getMockBuilder(Context::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->registryMock = $this->getMockBuilder(Registry::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->notification = $this->createPartialMock(Notification::class, ['getData', 'setData']);
    }

    public function testGetCreatedAt()
    {
        $this->notification->expects($this->once())
            ->method('getData')
            ->with(Notification::CREATED_AT);
        $this->notification->getCreatedAt();
    }

    public function testSetCreatedAt()
    {
        $dateTime = date('Y-m-d H:i:s');
        $this->notification->expects($this->once())
            ->method('setData')
            ->with(Notification::CREATED_AT, $dateTime)
            ->willReturn($this->notification);
        $this->notification->setCreatedAt($dateTime);
    }

    public function testGetUpdatedAt()
    {
        $this->notification->expects($this->once())
            ->method('getData')
            ->with(Notification::UPDATED_AT);
        $this->notification->getUpdatedAt();
    }

    public function testSetUpdatedAt()
    {
        $dateTime = date('Y-m-d H:i:s');
        $this->notification->expects($this->once())
            ->method('setData')
            ->with(Notification::UPDATED_AT, $dateTime)
            ->willReturn($this->notification);
        $this->notification->setUpdatedAt($dateTime);
    }
}
