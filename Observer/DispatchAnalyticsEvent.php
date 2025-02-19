<?php
namespace Adyen\Payment\Observer;

use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Adyen\Payment\Api\AdyenAnalyticsRepositoryInterface;
use Adyen\Payment\Api\Data\AdyenAnalyticsInterfaceFactory;

class DispatchAnalyticsEvent implements ObserverInterface
{
    protected ManagerInterface $eventManager;
    protected AdyenAnalyticsRepositoryInterface $adyenAnalyticsRepository;
    protected AdyenAnalyticsInterfaceFactory $analyticsFactory;

    public function __construct(
        ManagerInterface $eventManager,
        AdyenAnalyticsRepositoryInterface $adyenAnalyticsRepository,
        AdyenAnalyticsInterfaceFactory $analyticsFactory
    ) {
        $this->eventManager = $eventManager;
        $this->adyenAnalyticsRepository = $adyenAnalyticsRepository;
        $this->analyticsFactory = $analyticsFactory;
    }

    public function execute(Observer $observer)
    {
        // Sample data for dispatching the event
        $eventData = [
            'checkoutAttemptId' => '12345',
            'eventType' => 'payment_attempt',
            'topic' => 'payment_method_adyen_analytics',
            'message' => 'Sample payment analytics message.',
            'errorCount' => 0,
            'done' => false,
        ];

        // Dispatch the event
        $this->eventManager->dispatch('payment_method_adyen_analytics', ['data' => $eventData]);

        // Create an instance of AdyenAnalyticsInterface
        $analytics = $this->analyticsFactory->create();

        // Set data to the analytics object
        $analytics->setCheckoutAttemptId($eventData['checkoutAttemptId']);
        $analytics->setEventType($eventData['eventType']);
        $analytics->setTopic($eventData['topic']);
        $analytics->setMessage($eventData['message']);
        $analytics->setErrorCount($eventData['errorCount']);
        $analytics->setDone($eventData['done']);

        // Save the analytics data to the database
        $this->adyenAnalyticsRepository->save($analytics);
    }
}
