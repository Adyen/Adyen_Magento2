<?php
/**
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2023 Adyen N.V. (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */
declare(strict_types=1);

namespace Adyen\Payment\Setup\Patch\Data;

use Adyen\Payment\Helper\DataPatch;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\Patch\PatchInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\StatusFactory;
use Magento\Sales\Model\ResourceModel\Order\StatusFactory as StatusResourceFactory;
use Magento\Sales\Model\ResourceModel\Order\Status as StatusResource;

class CreateStatusAuthorized implements DataPatchInterface
{
    private ModuleDataSetupInterface $moduleDataSetup;
    private WriterInterface $configWriter;
    private ReinitableConfigInterface $reinitableConfig;
    private DataPatch $dataPatchHelper;
    private StatusFactory $statusFactory;
    private StatusResourceFactory $statusResourceFactory;

    const ADYEN_AUTHORIZED_STATUS = 'adyen_authorized';
    const ADYEN_AUTHORIZED_STATUS_LABEL = 'Authorized';

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        WriterInterface $configWriter,
        ReinitableConfigInterface $reinitableConfig,
        DataPatch $dataPatchHelper,
        StatusFactory $statusFactory,
        StatusResourceFactory $statusResourceFactory
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->configWriter = $configWriter;
        $this->reinitableConfig = $reinitableConfig;
        $this->dataPatchHelper = $dataPatchHelper;
        $this->statusFactory = $statusFactory;
        $this->statusResourceFactory = $statusResourceFactory;
    }

    public function apply(): PatchInterface
    {
        /** @var StatusResource $statusResource */
        $statusResource = $this->statusResourceFactory->create();

        $status = $this->statusFactory->create();
        $status->setData([
            'status' => self::ADYEN_AUTHORIZED_STATUS,
            'label' => self::ADYEN_AUTHORIZED_STATUS_LABEL,
        ]);

        try {
            $statusResource->save($status);
        } catch (AlreadyExistsException $exception) {
            return $this;
        }

        $status->assignState(self::ADYEN_AUTHORIZED_STATUS, true, true);

        $setup = $this->moduleDataSetup;
        $path = 'payment/adyen_abstract/payment_pre_authorized';

        // Processing status was assigned mistakenly. It shouldn't be used on payment_pre_authorized.
        $config = $this->dataPatchHelper->findConfig($setup, $path, Order::STATE_PROCESSING);
        if (isset($config)) {
            $this->configWriter->save(
                $path,
                self::ADYEN_AUTHORIZED_STATUS,
                $config['scope'],
                $config['scope_id']
            );
        }

        // re-initialize otherwise it will cause errors
        $this->reinitableConfig->reinit();

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [];
    }
}
