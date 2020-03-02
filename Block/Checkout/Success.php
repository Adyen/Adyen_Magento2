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

namespace Adyen\Payment\Block\Checkout;

/**
 * Billing agreement information on Order success page
 */
class Success extends \Magento\Framework\View\Element\Template
{

    /**
     * @var \Magento\Sales\Model\Order $order
     */
    protected $_order;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $_checkoutSession;

    /**
     * @var \Magento\Checkout\Model\OrderFactory
     */
    protected $_orderFactory;

    /**
     * @var \Magento\Framework\Pricing\Helper\Data
     */
    public $priceHelper;

    /**
     * Success constructor.
     *
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Sales\Model\OrderFactory $orderFactory
     * @param \Magento\Framework\Pricing\Helper\Data $priceHelper
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Magento\Framework\Pricing\Helper\Data $priceHelper,
        array $data = []
    ) {
        $this->_checkoutSession = $checkoutSession;
        $this->_orderFactory = $orderFactory;
        $this->priceHelper = $priceHelper;
        parent::__construct($context, $data);
    }

    /**
     * Return Boleto PDF url
     *
     * @return string
     */
    protected function _toHtml()
    {
        if ($this->isBoletoPayment()) {
            $this->addData(
                [
                    'boleto_pdf_url' => $this->getBoletoPdfUrl()
                ]
            );
        }
        return parent::_toHtml();
    }

    /**
     * Detect if Boleto is used as payment method
     * @return bool
     */
    public function isBoletoPayment()
    {
        if ($this->getOrder()->getPayment() &&
            $this->getOrder()->getPayment()->getMethod() == \Adyen\Payment\Model\Ui\AdyenBoletoConfigProvider::CODE) {
            return true;
        }
        return false;
    }

    /**
     * @return null|\string[]
     */
    public function getBoletoData()
    {
        if ($this->isBoletoPayment()) {
            return $this->getOrder()->getPayment()->getAdditionalInformation();
        }
        return null;
    }

    /**
     * Get Banktransfer additional data
     *
     * @return array|string[]
     */
    public function getBankTransferData()
    {
        $result = [];
        if (!empty($this->getOrder()->getPayment()) &&
            !empty($this->getOrder()->getPayment()->getAdditionalInformation('bankTransfer.owner'))
        ) {
            $result = $this->getOrder()->getPayment()->getAdditionalInformation();
        }

        return $result;
    }

    /**
     * Get multibanco additional data
     *
     * @return array|string[]
     */
    public function getMultibancoData()
    {
        $result = [];
        if (!empty($this->getOrder()->getPayment()) &&
            !empty($this->getOrder()->getPayment()->getAdditionalInformation('paymentMethodType')) &&
            strcmp($this->getOrder()->getPayment()->getAdditionalInformation('paymentMethodType'), 'multibanco') === 0
        ) {
            $result = $this->getOrder()->getPayment()->getAdditionalInformation();
        }

        return $result;
    }



    /**
     * @return \Magento\Sales\Model\Order
     */
    public function getOrder()
    {
        if ($this->_order == null) {
            $this->_order = $this->_orderFactory->create()->load($this->_checkoutSession->getLastOrderId());
        }
        return $this->_order;
    }
}
