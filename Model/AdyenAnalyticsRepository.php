<?php
namespace Adyen\Payment\Model;

use Adyen\Payment\Api\AdyenAnalyticsRepositoryInterface;
use Adyen\Payment\Api\Data\AdyenAnalyticsInterface;
use Adyen\Payment\Model\ResourceModel\AdyenAnalytics as ResourceModel;
use Adyen\Payment\Model\AdyenAnalyticsFactory;
use Magento\Framework\Exception\NoSuchEntityException;

class AdyenAnalyticsRepository implements AdyenAnalyticsRepositoryInterface
{
    protected ResourceModel $resourceModel;
    protected \Adyen\Payment\Model\AdyenAnalyticsFactory $analyticsFactory;

    public function __construct(
        ResourceModel $resourceModel,
        AdyenAnalyticsFactory $analyticsFactory
    ) {
        $this->resourceModel = $resourceModel;
        $this->analyticsFactory = $analyticsFactory;
    }

    public function save(AdyenAnalyticsInterface $analytics)
    {
        $this->resourceModel->save($analytics);
    }

    public function getById($id)
    {
        $analytics = $this->analyticsFactory->create();
        $this->resourceModel->load($analytics, $id);
        if (!$analytics->getId()) {
            throw new NoSuchEntityException(__('Unable to find analytics with ID "%1"', $id));
        }
        return $analytics;
    }

    public function delete(AdyenAnalyticsInterface $analytics)
    {
        $this->resourceModel->delete($analytics);
    }

    public function deleteById($id)
    {
        $analytics = $this->getById($id);
        $this->delete($analytics);
    }
}
