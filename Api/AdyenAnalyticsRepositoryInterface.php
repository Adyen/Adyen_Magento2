<?php
namespace Adyen\Payment\Api;

use Adyen\Payment\Api\Data\AdyenAnalyticsInterface;

interface AdyenAnalyticsRepositoryInterface
{
    public function save(AdyenAnalyticsInterface $analytics);

    public function getById($id);

    public function delete(AdyenAnalyticsInterface $analytics);

    public function deleteById($id);
}
