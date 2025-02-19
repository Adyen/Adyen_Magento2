<?php
namespace Adyen\Payment\Api\Data;

interface AdyenAnalyticsInterface
{
    const ID = 'id';
    const CHECKOUT_ATTEMPT_ID = 'checkoutAttemptId';
    const EVENT_TYPE = 'eventType';
    const TOPIC = 'topic';
    const MESSAGE = 'message';
    const ERROR_COUNT = 'errorCount';
    const DONE = 'done';
    const CREATED_AT = 'createdAt';
    const UPDATED_AT = 'updatedAt';

    public function getId();

    public function getCheckoutAttemptId();
    public function setCheckoutAttemptId($checkoutAttemptId);

    public function getEventType();
    public function setEventType($eventType);

    public function getTopic();
    public function setTopic($topic);

    public function getMessage();
    public function setMessage($message);

    public function getErrorCount();
    public function setErrorCount($errorCount);

    public function getDone();
    public function setDone($done);

    public function getCreatedAt();
    public function setCreatedAt($createdAt);

    public function getUpdatedAt();
    public function setUpdatedAt($updatedAt);
}
