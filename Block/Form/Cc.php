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
 * Copyright (c) 2015 Adyen BV (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Block\Form;

class Cc extends \Magento\Payment\Block\Form\Cc
{
    /**
     * @var string
     */
    protected $_template = 'Adyen_Payment::form/cc.phtml';

    /**
     * @var \Adyen\Payment\Helper\Data
     */
    protected $adyenHelper;

    /**
     * @var \Magento\Framework\App\State
     */
    protected $appState;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $checkoutSession;

    /**
     * @var \Adyen\Payment\Helper\CardAvailableTypes
     */
    protected $cardAvailableTypesHelper;

    /**
     * Cc constructor.
     *
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Magento\Payment\Model\Config $paymentConfig
     * @param \Adyen\Payment\Helper\Data $adyenHelper
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Adyen\Payment\Helper\CardAvailableTypes $cardAvailableTypesHelper
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Payment\Model\Config $paymentConfig,
        \Adyen\Payment\Helper\Data $adyenHelper,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Adyen\Payment\Helper\CardAvailableTypes $cardAvailableTypesHelper,
        array $data = []
    ) {
        parent::__construct($context, $paymentConfig);
        $this->adyenHelper = $adyenHelper;
        $this->appState = $context->getAppState();
        $this->checkoutSession = $checkoutSession;
        $this->cardAvailableTypesHelper = $cardAvailableTypesHelper;
    }

	/**
	 * @return string
	 */
    public function getCheckoutCardComponentJs()
	{
		return $this->adyenHelper->getCheckoutCardComponentJs($this->checkoutSession->getQuote()->getStore()->getId());
	}

	/**
	 * @return string
	 * @throws \Adyen\AdyenException
	 */
	public function getCheckoutOriginKeys()
	{
		return $this->adyenHelper->getOriginKeyForBaseUrl();
	}

	/**
	 * @return string
	 */
	public function getCheckoutEnvironment()
	{
		return $this->adyenHelper->getCheckoutEnvironment($this->checkoutSession->getQuote()->getStore()->getId());
	}

    /**
     * Retrieve has verification configuration
     *
     * @return bool
     */
    public function hasVerification()
    {
        // On Backend always use MOTO
        if ($this->appState->getAreaCode() === \Magento\Backend\App\Area\FrontNameResolver::AREA_CODE) {
            return false;
        }
        return true;
    }

	/**
	 * @return string
	 */
	public function getLocale()
	{
		return $this->adyenHelper->getStoreLocale($this->checkoutSession->getQuote()->getStore()->getId());
	}

	/**
	 * Retrieve available credit card type codes by alt code
	 *
	 * @return array
	 * @deprecated Use Adyen\Payment\Helper\CardAvailableTypes getCardAvailableTypes() instead.
	 * This method will be removed in version 6.0.0
	 *
	 */
	public function getCcAvailableTypesByAlt()
	{
		$types = [];
		$ccTypes = $this->adyenHelper->getAdyenCcTypes();

		$availableTypes = $this->adyenHelper->getAdyenCcConfigData('cctypes');
		if ($availableTypes) {
			$availableTypes = explode(',', $availableTypes);
			foreach (array_keys($ccTypes) as $code) {
				if (in_array($code, $availableTypes)) {
					$types[$ccTypes[$code]['code_alt']] = $code;
				}
			}
		}

		return $types;
	}

    /**
     * Retrieve available credit card type codes by alt code
     *
     * @return array
     *
     */
    public function getCardAvailableTypes()
    {
        return $this->cardAvailableTypesHelper->getCardAvailableTypes('code_alt');
    }

    /**
     * Allow checkbox for MOTO payments to be saved as RECURRING
     *
     * @return bool
     */
	public function allowRecurring()
    {
        if ($this->adyenHelper->getAdyenAbstractConfigData('enable_recurring', null)) {
            return true;
        }
        return false;
    }

    /**
     * @return mixed
     */
    public function isVaultEnabled()
    {
        return $this->adyenHelper->isCreditCardVaultEnabled();
    }

}
