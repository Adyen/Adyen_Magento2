<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2021 Adyen N.V. (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Phrase;
use Magento\Framework\Registry;
use Magento\Framework\Validator\Exception;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Class DonationAmounts
 * @package Adyen\Payment\Model\Config\Source
 */
class DonationAmounts extends Value
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param StoreManagerInterface $storeManager
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        protected readonly StoreManagerInterface $storeManager,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * @return $this
     * @throws Exception
     */
    public function validateBeforeSave(): DonationAmounts
    {
        if (
            $this->getFieldsetDataValue('active') &&
            !$this->validateDonationAmounts(explode(',', $this->getValue()))
        ) {
            throw new Exception(
                new Phrase(
                    'The Adyen Giving donation amounts are not valid, please enter amounts higher than zero separated by commas.'
                )
            );
        }

        return $this;
    }

    /**
     * @param array $amounts
     * @return bool
     */
    public function validateDonationAmounts(array $amounts = []): bool
    {
        // Fail if the field is empty, the array is associative
        if (empty($amounts) || array_values($amounts) !== $amounts) {
            return false;
        }

        foreach ($amounts as $amount) {
            // Fail if one of the amounts is empty, not numeric, or less than zero
            if ($amount === '' || !is_numeric($amount) || $amount <= 0) {
                return false;
            }
        }
        return true;
    }
}
