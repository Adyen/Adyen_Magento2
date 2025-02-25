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

use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Model\Ui\AdyenCcConfigProvider;
use DateInterval;
use DateTime;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Vault\Api\Data\PaymentTokenFactoryInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;

class VaultMigration implements DataPatchInterface
{
    private ModuleDataSetupInterface $moduleDataSetup;
    private PaymentTokenManagementInterface $tokenManagement;
    private PaymentTokenFactoryInterface $tokenFactory;
    private PaymentTokenRepositoryInterface $tokenRepository;
    private EncryptorInterface $encryptor;
    private AdyenLogger $adyenLogger;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        PaymentTokenManagementInterface $tokenManagement,
        PaymentTokenFactoryInterface $tokenFactory,
        PaymentTokenRepositoryInterface $tokenRepository,
        EncryptorInterface $encryptor,
        AdyenLogger $adyenLogger
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->tokenManagement = $tokenManagement;
        $this->tokenFactory = $tokenFactory;
        $this->tokenRepository = $tokenRepository;
        $this->encryptor = $encryptor;
        $this->adyenLogger = $adyenLogger;
    }

    public function apply(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        $this->migrateBillingAgreementsToVault($this->moduleDataSetup);
        $this->moduleDataSetup->getConnection()->endSetup();
    }

    private function migrateBillingAgreementsToVault(ModuleDataSetupInterface $setup): void
    {
        if (!$setup->tableExists('paypal_billing_agreement')) {
            $this->adyenLogger->addAdyenInfoLog('Table paypal_billing_agreement does not exist, skipping migration');
            return;
        }

        $columns = $setup->getConnection()->describeTable('paypal_billing_agreement');
        if (!isset($columns['method_code']) || !isset($columns['status'])) {
            $this->adyenLogger->addAdyenInfoLog('Columns method_code and status do not exist, skipping migration');
            return;
        }

        $paypalTable = $setup->getTable('paypal_billing_agreement');
        $connection = $setup->getConnection();

        $select = $connection->select()
            ->from($paypalTable)
            ->where(
                'method_code = ?',
                'adyen_oneclick'
            )
            ->where(
                'status = ?',
                'active'
            );

        $adyenBillingAgreements = $connection->fetchAll($select);

        foreach ($adyenBillingAgreements as $adyenBillingAgreement) {
            $paymentToken = $this->tokenManagement->getByGatewayToken(
                $adyenBillingAgreement['reference_id'],
                AdyenCcConfigProvider::CODE,
                $adyenBillingAgreement['customer_id']
            );

            if (is_null($paymentToken)) {
                try {
                    $this->saveVaultToken(
                        intval($adyenBillingAgreement['customer_id']),
                        $adyenBillingAgreement['reference_id'],
                        $adyenBillingAgreement['created_at'],
                        $adyenBillingAgreement['agreement_data']
                    );
                } catch (\Exception $e) {
                    $this->adyenLogger->addAdyenWarning(sprintf(
                        'Unable to migrate token w/agreement_id %s, due to exception: %s',
                        $adyenBillingAgreement['agreement_id'],
                        $e->getMessage()
                    ));
                }
            }
        }
    }

    private function saveVaultToken(
        int $customerId,
        string $gatewayToken,
        string $createdAt,
        string $tokenDetails
    ): void {

        $expirationDate = new DateTime();
        $expirationDate->add(new DateInterval('P10Y'));

        $paymentToken = $this->tokenFactory->create(
            PaymentTokenFactoryInterface::TOKEN_TYPE_CREDIT_CARD
        );
        $paymentToken->setCustomerId($customerId);
        $paymentToken->setPaymentMethodCode(AdyenCcConfigProvider::CODE);
        $paymentToken->setGatewayToken($gatewayToken);
        $paymentToken->setIsActive(true);
        $paymentToken->setIsVisible(true);
        $paymentToken->setCreatedAt($createdAt);
        $paymentToken->setTokenDetails($this->transformTokenDetails($tokenDetails));
        $paymentToken->setExpiresAt($expirationDate);
        $paymentToken->setPublicHash($this->generatePublicHash($customerId, $tokenDetails));

        $this->tokenRepository->save($paymentToken);

    }

    private function generatePublicHash(int $customerId, string $tokenDetails): string
    {
        $hashKey = $customerId;

        $hashKey .= AdyenCcConfigProvider::CODE
            . PaymentTokenFactoryInterface::TOKEN_TYPE_CREDIT_CARD
            . $tokenDetails;

        return $this->encryptor->getHash($hashKey);
    }

    /**
     *
     * @param string $baAgreementData
     * @return string
     */
    private function transformTokenDetails(string $baAgreementData): string
    {
        $baJson = json_decode($baAgreementData, true);
        $contractType = $baJson['contractTypes'][0];

        // If contract type was defined in early v7, it can be (ONECLICK), (RECURRING) or (ONECLICK,RECURRING)
        if (str_contains($contractType, 'ONECLICK')) {
            $contractType = 'CardOnFile';
        } elseif ($contractType === 'RECURRING') {
            $contractType = 'Subscription';
        }

        $vaultDetails = [
            'type' => $baJson['variant'],
            'maskedCC' => $baJson['card']['number'],
            'expirationDate' => $baJson['card']['expiryMonth'] . '/' . $baJson['card']['expiryYear'],
            'tokenType' => $contractType
        ];

        return json_encode($vaultDetails);
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
