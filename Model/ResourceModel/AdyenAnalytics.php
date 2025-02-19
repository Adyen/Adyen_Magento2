<?php
namespace Adyen\Payment\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class AdyenAnalytics extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('adyen_analytics', 'id');
    }
}
