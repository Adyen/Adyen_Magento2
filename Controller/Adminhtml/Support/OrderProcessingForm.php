<?php declare(strict_types=1);
/**
 * Adyen Payment Module
 *
 * Copyright (c) 2022 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Controller\Adminhtml\Support;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Adyen\Payment\Helper\SupportFormHelper;

class OrderProcessingForm extends Action
{
    const ORDER_PROCESSING = 'order_processing_email_template';
    /**
     * @var SupportFormHelper
     */
    private $supportFormHelper;

    public function __construct(Context $context, SupportFormHelper $supportFormHelper)
    {
        $this->supportFormHelper = $supportFormHelper;
        parent::__construct($context);
    }

    public function execute()
    {
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->setActiveMenu('Adyen_Payment::support')
            ->getConfig()->getTitle()->prepend(__('Order processing'));

        if ('POST' === $this->getRequest()->getMethod()){
            try {
                $request = $this->getRequest()->getParams();
                $formData = [
                    'topic' => $request['topic'],
                    'subject' => $request['subject'],
                    'email' => $request['email'],
                    'pspReference' => $request['pspReference'],
                    'merchantReference' => $request['merchantReference'],
                    'headless' => $request['headless'],
                    'paymentMethod' => $request['paymentMethod'],
                    'terminalId' => $request['terminalId'],
                    'orderHistoryComments' => $request['orderHistoryComments'],
                    'orderDescription' => $request['orderDescription'],
                    'attachment' => $this->getRequest()->getFiles('logs'),
                ];
                $this->supportFormHelper->handleSubmit($formData, self::ORDER_PROCESSING);
                return $this->_redirect('*/*/success');


            } catch (\Exception $e) {
                $this->messageManager->addErrorMessage(__('Unable to send support message. ' . $e->getMessage()));
                $this->_redirect($this->_redirect->getRefererUrl());
            }
        }

        return $resultPage;
    }
}
