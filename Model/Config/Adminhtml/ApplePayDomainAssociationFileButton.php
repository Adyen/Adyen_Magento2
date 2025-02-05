<?php
/**
 * Adyen Payment Module
 *
 * Copyright (c) 2022 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Model\Config\Adminhtml;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Helper\Data;
use Magento\Framework\Data\Form\Element\AbstractElement;

class ApplePayDomainAssociationFileButton extends Field
{
    protected $_template = 'Adyen_Payment::config/applepay_domain_association_file_button.phtml';

    /**
     * @var Data
     */
    protected Data $backendHelper;

    /**
     * @param Context $context
     * @param Data $backendHelper
     * @param array $data
     */
     public function __construct(
         Context $context,
         Data $backendHelper,
         array $data = []
    ) {
        $this->backendHelper = $backendHelper;
        parent::__construct($context, $data);
    }

    /**
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        $this->addData([
            'id' => 'addbutton_button',
            'button_label' => null,
            'onclick' => 'javascript:check(); return false;'
        ]);

        return $this->toHtml();
    }

    /**
     * @return string
     */
    public function getActionUrl(): string
    {
        return $this->backendHelper->getUrl("adyen/configuration/DownloadApplePayDomainAssociationFile");
    }
}
