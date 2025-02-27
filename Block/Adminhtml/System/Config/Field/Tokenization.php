<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2023 Adyen N.V. (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Block\Adminhtml\System\Config\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;
use Magento\Framework\View\Element\BlockInterface;

class Tokenization extends AbstractFieldArray
{
    protected array $columns = [];
    protected ?SelectYesNo $enabledRenderer = null;
    protected ?InputReadonly $nameRenderer = null;
    protected ?InputHidden $paymentMethodCodeRenderer = null;
    protected ?RecurringProcessingModel $recurringProcessingModelRenderer = null;
    protected $_template = 'Adyen_Payment::config/token_type_table_array.phtml';

    protected function _prepareToRender(): void
    {
        $this->addColumn(
            'name', [
                'label' => __('Payment Method'),
                'class' => 'required-entry',
                'style' => 'width:100%',
                'renderer' => $this->getNameRenderer()
            ]
        );
        $this->addColumn(
            'enabled', [
                'label' => __('Enabled'),
                'class' => 'required-entry',
                'style' => 'width:130px',
                'renderer' => $this->getEnabledRenderer()
            ]
        );
        $this->addColumn(
            'recurring_processing_model', [
                'label' => __('Recurring Processing Model'),
                'class' => 'required-entry',
                'style' => 'width:130px',
                'renderer' => $this->getRecurringProcessingModelRenderer()
            ]
        );
        $this->addColumn(
            'payment_method_code', [
                'label' => ' ',
                'style' => 'visibility: hidden',
                'class' => 'required-entry',
                'renderer' => $this->getPaymentMethodCodeRenderer()
            ]
        );
    }

    private function getEnabledRenderer(): BlockInterface
    {
        if (!$this->enabledRenderer) {
            $this->enabledRenderer = $this->getLayout()->createBlock(
                SelectYesNo::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }
        return $this->enabledRenderer;
    }

    private function getNameRenderer(): BlockInterface
    {
        if (!$this->nameRenderer) {
            $this->nameRenderer = $this->getLayout()->createBlock(
                InputReadonly::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }
        return $this->nameRenderer;
    }

    private function getPaymentMethodCodeRenderer(): BlockInterface
    {
        if (!$this->paymentMethodCodeRenderer) {
            $this->paymentMethodCodeRenderer = $this->getLayout()->createBlock(
                InputHidden::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }
        return $this->paymentMethodCodeRenderer;
    }

    private function getRecurringProcessingModelRenderer(): BlockInterface
    {
        if (!$this->recurringProcessingModelRenderer) {
            $this->recurringProcessingModelRenderer = $this->getLayout()->createBlock(
                RecurringProcessingModel::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }
        return $this->recurringProcessingModelRenderer;
    }

    protected function _prepareArrayRow(DataObject $row): void
    {
        $options = [];
        $enabled = $row->getData('enabled');
        $recurringProcessingModel = $row->getData('recurring_processing_model');

        if ($enabled) {
            $options['option_' . $this->getEnabledRenderer()->calcOptionHash($enabled)] = 'selected=\"selected\"';
        }

        if ($recurringProcessingModel) {
            $key = sprintf(
                "option_%s",
                $this->getRecurringProcessingModelRenderer()->calcOptionHash($recurringProcessingModel)
            );

            $options[$key] = 'selected=\"selected\"';
        }

        $row->setData('option_extra_attrs', $options);
    }
}
