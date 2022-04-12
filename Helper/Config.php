<?php
/**
 *                       ######
 *                       ######
 * ############    ####( ######  #####. ######  ############   ############
 * #############  #####( ######  #####. ######  #############  #############
 *        ######  #####( ######  #####. ######  #####  ######  #####  ######
 * ###### ######  #####( ######  #####. ######  #####  #####   #####  ######
 * ###### ######  #####( ######  #####. ######  #####          #####  ######
 * #############  #############  #############  #############  #####  ######
 *  ############   ############  #############   ############  #####  ######
 *                                      ######
 *                               #############
 *                               ############
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2020 Adyen BV (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Helper;

use Magento\Deploy\Model\ConfigWriter;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;

class Config
{
    const XML_PAYMENT_PREFIX = "payment";
    const XML_ADYEN_ABSTRACT_PREFIX = "adyen_abstract";
    const XML_NOTIFICATIONS_CAN_CANCEL_FIELD = "notifications_can_cancel";
    const XML_NOTIFICATIONS_HMAC_CHECK = "notifications_hmac_check";
    const XML_NOTIFICATIONS_IP_CHECK = "notifications_ip_check";
    const XML_NOTIFICATIONS_HMAC_KEY_LIVE = "notification_hmac_key_live";
    const XML_NOTIFICATIONS_HMAC_KEY_TEST = "notification_hmac_key_test";
    const XML_CHARGED_CURRENCY = "charged_currency";
    const XML_HAS_HOLDER_NAME = "has_holder_name";
    const XML_HOLDER_NAME_REQUIRED = "holder_name_required";
    const XML_HOUSE_NUMBER_STREET_LINE = "house_number_street_line";
    const XML_ADYEN_ONECLICK = 'adyen_oneclick';
    const XML_ADYEN_HPP = 'adyen_hpp';
    const XML_ADYEN_HPP_VAULT = 'adyen_hpp_vault';
    const XML_PAYMENT_ORIGIN_URL = 'payment_origin_url';
    const XML_PAYMENT_RETURN_URL = 'payment_return_url';
    const XML_STATUS_FRAUD_MANUAL_REVIEW = 'fraud_manual_review_status';
    const XML_STATUS_FRAUD_MANUAL_REVIEW_ACCEPT = 'fraud_manual_review_accept_status';

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var WriterInterface
     */
    private $configWriter;

    /**
     * @var ReinitableConfigInterface
     */
    private $reinitableConfig;

    /**
     * Config constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     * @param WriterInterface $configWriter
     * @param ReinitableConfigInterface $reinitableConfig
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor,
        WriterInterface $configWriter,
        ReinitableConfigInterface $reinitableConfig
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
        $this->configWriter = $configWriter;
        $this->reinitableConfig = $reinitableConfig;
    }

    /**
     * Retrieve flag for notifications_can_cancel
     *
     * @param int $storeId
     * @return bool
     */
    public function getNotificationsCanCancel($storeId = null)
    {
        return (bool)$this->getConfigData(
            self::XML_NOTIFICATIONS_CAN_CANCEL_FIELD,
            self::XML_ADYEN_ABSTRACT_PREFIX,
            $storeId,
            true
        );
    }

    /**
     * Retrieve flag for notifications_hmac_check
     *
     * @param int $storeId
     * @return bool
     */
    public function getNotificationsHmacCheck($storeId = null)
    {
        return (bool)$this->getConfigData(
            self::XML_NOTIFICATIONS_HMAC_CHECK,
            self::XML_ADYEN_ABSTRACT_PREFIX,
            $storeId,
            true
        );
    }

    /**
     * Retrieve flag for notifications_ip_check
     *
     * @param int $storeId
     * @return bool
     */
    public function getNotificationsIpCheck($storeId = null)
    {
        return (bool)$this->getConfigData(
            self::XML_NOTIFICATIONS_IP_CHECK,
            self::XML_ADYEN_ABSTRACT_PREFIX,
            $storeId,
            true
        );
    }

    /**
     * Retrieve key for notifications_hmac_key
     *
     * @param int $storeId
     * @return string
     */
    public function getNotificationsHmacKey($storeId = null)
    {
        if ($this->isDemoMode($storeId)) {
            $key = $this->getConfigData(
                self::XML_NOTIFICATIONS_HMAC_KEY_TEST,
                self::XML_ADYEN_ABSTRACT_PREFIX,
                $storeId,
                false
            );
        } else {
            $key = $this->getConfigData(
                self::XML_NOTIFICATIONS_HMAC_KEY_LIVE,
                self::XML_ADYEN_ABSTRACT_PREFIX,
                $storeId,
                false
            );
        }
        return $this->encryptor->decrypt(trim($key));
    }

    public function isDemoMode($storeId = null)
    {
        return $this->getConfigData('demo_mode', self::XML_ADYEN_ABSTRACT_PREFIX, $storeId, true);
    }

    /**
     * Get how the alternative payment should be tokenized
     *
     * @param null|int|string $storeId
     * @return mixed
     */
    public function getAlternativePaymentMethodTokenType($storeId = null)
    {
        return $this->getConfigData('token_type', self::XML_ADYEN_HPP, $storeId);
    }

    /**
     * Check if alternative payment methods vault is enabled
     *
     * @param null|int|string $storeId
     * @return mixed
     */
    public function isStoreAlternativePaymentMethodEnabled($storeId = null)
    {
        return $this->getConfigData('active', self::XML_ADYEN_HPP_VAULT, $storeId, true);
    }

    /**
     * Retrieve charged currency selection (base or display)
     *
     * @param null|int|string $storeId
     * @return mixed
     */
    public function getChargedCurrency($storeId = null)
    {
        return $this->getConfigData(self::XML_CHARGED_CURRENCY, self::XML_ADYEN_ABSTRACT_PREFIX, $storeId);
    }

    /**
     * Retrieve has_holder_name config
     *
     * @param null|int|string $storeId
     * @return mixed
     */
    public function getHasHolderName($storeId = null)
    {
        return $this->getConfigData(self::XML_HAS_HOLDER_NAME, self::XML_ADYEN_ABSTRACT_PREFIX, $storeId);
    }

    /**
     * Retrieve house_number_street_line config
     *
     * @param null|int|string $storeId
     * @return mixed
     */
    public function getHouseNumberStreetLine($storeId = null)
    {
        return $this->getConfigData(self::XML_HOUSE_NUMBER_STREET_LINE, self::XML_ADYEN_ABSTRACT_PREFIX, $storeId);
    }

    /**
     * Retrieve holder_name_required config
     *
     * @param null|int|string $storeId
     * @return mixed
     */
    public function getHolderNameRequired($storeId = null)
    {
        return $this->getConfigData(self::XML_HOLDER_NAME_REQUIRED, self::XML_ADYEN_ABSTRACT_PREFIX, $storeId);
    }

    /**
     * Retrieve payment_origin_url config
     *
     * @param int|string $storeId
     * @return mixed
     */
    public function getPWAOriginUrl($storeId)
    {
        return $this->getConfigData(self::XML_PAYMENT_ORIGIN_URL, self::XML_ADYEN_ABSTRACT_PREFIX, $storeId);
    }

    /**
     * Retrieve the passed fraud status config
     *
     * @param int|string $storeId
     * @return mixed
     */
    public function getFraudStatus($fraudStatus, $storeId)
    {
        return $this->getConfigData(
            $fraudStatus,
            Config::XML_ADYEN_ABSTRACT_PREFIX,
            $storeId
        );
    }

    /**
     * @param $storeId
     * @return string|null
     */
    public function getCardRecurringMode($storeId): ?string
    {
        return $this->getConfigData('card_mode', self::XML_ADYEN_ONECLICK, $storeId);
    }

    /**
     * @param $storeId
     * @return string|null
     */
    public function getCardRecurringType($storeId): ?string
    {
        return $this->getConfigData('card_type', self::XML_ADYEN_ONECLICK, $storeId);
    }

    /**
     * Retrieve information from payment configuration
     *
     * @param string $field
     * @param string $xmlPrefix
     * @param int $storeId
     * @param bool|false $flag
     * @return bool|mixed
     */
    public function getConfigData($field, $xmlPrefix, $storeId, $flag = false)
    {
        $path = implode("/", [self::XML_PAYMENT_PREFIX, $xmlPrefix, $field]);

        if (!$flag) {
            return $this->scopeConfig->getValue($path, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
        } else {
            return $this->scopeConfig->isSetFlag($path, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
        }
    }

    /**
     * Update all configs which have a specific path and a specific value
     *
     * @param ModuleDataSetupInterface $setup
     * @param string $path
     * @param string $valueToUpdate
     * @param string $updatedValue
     */
    public function updateConfigValue(ModuleDataSetupInterface $setup, string $path, string $valueToUpdate, string $updatedValue): void
    {
        $config = $this->findConfig($setup, $path, $valueToUpdate);
        if (isset($config)) {
            $this->configWriter->save(
                $path,
                $updatedValue,
                $config['scope'],
                $config['scope_id']
            );
        }

        // re-initialize otherwise it will cause errors
        $this->reinitableConfig->reinit();
    }

    public function addConfig()
    {

    }

    /**
     * Return the config based on the passed path and value. If value is null, return the first item in array
     *
     * @param ModuleDataSetupInterface $setup
     * @param string $path
     * @param string|null $value
     * @return array|null
     */
    public function findConfig(ModuleDataSetupInterface $setup, string $path, ?string $value): ?array
    {
        $config = null;
        $configDataTable = $setup->getTable('core_config_data');
        $connection = $setup->getConnection();

        $select = $connection->select()
            ->from($configDataTable)
            ->where(
                'path = ?',
                $path
            );

        $matchingConfigs = $connection->fetchAll($select);

        if (!empty($matchingConfigs) && is_null($value)) {
            $config = reset($matchingConfigs);
        } else {
            foreach ($matchingConfigs as $matchingConfig) {
                if ($matchingConfig['value'] === $value) {
                    $config = $matchingConfig;
                }
            }
        }

        return $config;
    }
}
