<?php
/**
 *
 * Adyen Payment Module
 *
 * Copyright (c) 2023 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Helper\Webhook;

use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Model\Notification;
use Adyen\Payment\Helper\Vault;
use Exception;
use Magento\Sales\Model\Order as MagentoOrder;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;
use Magento\Vault\Model\PaymentTokenManagement;

class RecurringContractWebhookHandler implements WebhookHandlerInterface
{
    private AdyenLogger $adyenLogger;
    private PaymentTokenManagement $paymentTokenManagement;
    private PaymentTokenRepositoryInterface $paymentTokenRepository;
    private Vault $vaultHelper;

    public function __construct(
        AdyenLogger $adyenLogger,
        PaymentTokenManagement $paymentTokenManagement,
        PaymentTokenRepositoryInterface $paymentTokenRepository,
        Vault $vaultHelper
    ) {
        $this->adyenLogger = $adyenLogger;
        $this->paymentTokenManagement = $paymentTokenManagement;
        $this->paymentTokenRepository = $paymentTokenRepository;
        $this->vaultHelper = $vaultHelper;
    }

    public function handleWebhook(
        MagentoOrder $order,
        Notification $notification,
        string $transitionState
    ): MagentoOrder {
        if (!$notification->isSuccessful()) {
            $this->handleFailedNotification($order, $notification);
        } else{
            $this->handleSuccessNotification($order, $notification);
        }

        return $order;
    }

    private function handleFailedNotification(MagentoOrder $order, Notification $notification): void
    {
        $vaultToken = $this->paymentTokenManagement->getByGatewayToken(
            $notification->getPspreference(),
            $order->getPayment()->getMethodInstance()->getCode(),
            $order->getCustomerId()
        );

        if (isset($vaultToken)) {
            $vaultToken->setIsActive(false);
            $vaultToken->setIsVisible(false);

            $this->paymentTokenRepository->save($vaultToken);

            $this->adyenLogger->addAdyenNotification(
                sprintf(
                    "Vault payment token with entity_id: %s disabled due to the failing %s webhook notification.",
                    $vaultToken->getEntityId(),
                    $notification->getEventCode()
                ),
                [
                    'merchantReference' => $notification->getMerchantReference()
                ]
            );
        }
    }

    private function handleSuccessNotification(MagentoOrder $order, Notification $notification): void
    {
        $paymentMethodInstance = $order->getPayment()->getMethodInstance();
        $paymentMethodCode = $paymentMethodInstance->getCode();
        $storeId = $paymentMethodInstance->getStore();
        $isRecurringEnabled = $this->vaultHelper->getPaymentMethodRecurringActive($paymentMethodCode, $storeId);

        if ($isRecurringEnabled) {
            try {
                $paymentToken = $this->vaultHelper->createVaultToken(
                    $order->getPayment(),
                    $notification->getPspreference()
                );
                $extensionAttributes = $this->vaultHelper->getExtensionAttributes($order->getPayment());
                $extensionAttributes->setVaultPaymentToken($paymentToken);
            } catch (Exception $exception) {
                $this->adyenLogger->error(
                    sprintf(
                        'Failure trying to save payment token in vault for order %s, with exception message %s',
                        $order->getPayment()->getOrder()->getIncrementId(),
                        $exception->getMessage()
                    )
                );
            }
        }
        else{
            $this->adyenLogger->addAdyenNotification(
                'Order is not cancelled because previous notification
                                    was an authorisation that succeeded and payment was captured',
                [
                    'pspReference' => $notification->getPspreference(),
                    'merchantReference' => $notification->getMerchantReference()
                ]
            );
        }

    }
}
