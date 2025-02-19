<?php
namespace Adyen\Payment\Model;

use Adyen\Payment\Api\Data\AdyenAnalyticsInterface;
use Magento\Framework\Model\AbstractModel;

class AdyenAnalytics extends AbstractModel implements AdyenAnalyticsInterface
{
    protected function _construct()
    {
        $this->_init('Adyen\Payment\Model\ResourceModel\AdyenAnalytics');
    }

    public function getCheckoutAttemptId()
    {
        return $this->getData('checkoutAttemptId');
    }

    public function setCheckoutAttemptId($checkoutAttemptId)
    {
        return $this->setData('checkoutAttemptId', $checkoutAttemptId);
    }

    public function getEventType()
    {
        return $this->getData('eventType');
    }

    public function setEventType($eventType)
    {
        return $this->setData('eventType', $eventType);
    }

    public function getTopic()
    {
        return $this->getData('topic');
    }

    public function setTopic($topic)
    {
        return $this->setData('topic', $topic);
    }

    public function getMessage()
    {
        return $this->getData('message');
    }

    public function setMessage($message)
    {
        return $this->setData('message', $message);
    }

    public function getErrorCount()
    {
        return $this->getData('errorCount');
    }

    public function setErrorCount($errorCount)
    {
        return $this->setData('errorCount', $errorCount);
    }

    public function getDone()
    {
        return $this->getData('done');
    }

    public function setDone($done)
    {
        return $this->setData('done', $done);
    }

    public function getCreatedAt()
    {
        return $this->getData('createdAt');
    }

    public function setCreatedAt($createdAt)
    {
        return $this->setData('createdAt', $createdAt);
    }

    public function getUpdatedAt()
    {
        return $this->getData('updatedAt');
    }

    public function setUpdatedAt($updatedAt)
    {
        return $this->setData('updatedAt', $updatedAt);
    }
}
