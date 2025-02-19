<?php
namespace Adyen\Payment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Adyen\Payment\Api\AdyenAnalyticsRepositoryInterface;
use Adyen\Payment\Api\Data\AdyenAnalyticsInterfaceFactory;
use Psr\Log\LoggerInterface;

class ProcessAnalyticsEvent implements ObserverInterface
{
    protected AdyenAnalyticsRepositoryInterface $adyenAnalyticsRepository;
    protected AdyenAnalyticsInterfaceFactory $adyenAnalyticsFactory;
    protected $logger;

    public function __construct(
        AdyenAnalyticsRepositoryInterface $adyenAnalyticsRepository,
        AdyenAnalyticsInterfaceFactory $adyenAnalyticsFactory,
        LoggerInterface $logger
    ) {
        $this->adyenAnalyticsRepository = $adyenAnalyticsRepository;
        $this->adyenAnalyticsFactory = $adyenAnalyticsFactory;
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        try {
            // Get the event data
            $eventData = $observer->getEvent()->getData('data');

            // Log the event data for debugging (optional)
            $this->logger->info('Received event data for payment_method_adyen_analytics: ', $eventData);

            // Create a new instance of the AdyenAnalytics model
            $analytics = $this->adyenAnalyticsFactory->create();

            // Populate the model with event data
            $analytics->setCheckoutAttemptId($eventData['checkoutAttemptId']);
            $analytics->setEventType($eventData['eventType']);
            $analytics->setTopic($eventData['topic']);
            $analytics->setMessage($eventData['message']);
            $analytics->setErrorCount($eventData['errorCount']);
            $analytics->setDone($eventData['done']);

            // Save the event data to the database
            $this->adyenAnalyticsRepository->save($analytics);

        } catch (\Exception $e) {
            // Log any exceptions
            $this->logger->error('Error processing payment_method_adyen_analytics event: ' . $e->getMessage());
        }
    }
}
