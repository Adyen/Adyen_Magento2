<?php
/**
 *
 * Adyen Payment Module
 *
 * @author Adyen BV <support@adyen.com>
 * @copyright (c) 2023 Adyen N.V.
 * @license https://opensource.org/licenses/MIT MIT license
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 */

namespace Adyen\Payment\Helper;

use Adyen\Payment\Exception\InvalidAdditionalDataException;
use Adyen\Payment\Exception\PaymentMethodException;
use Adyen\Payment\Model\Method\PaymentMethodInterface;
use Adyen\Payment\Model\Method\TxVariant;
use Adyen\Payment\Logger\AdyenLogger;
use DateInterval;
use DateTime;
use DateTimeZone;
use Exception;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\MethodInterface;
use Magento\Sales\Api\Data\OrderPaymentExtensionInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Model\Order\Payment;
use Magento\Vault\Api\Data\PaymentTokenFactoryInterface;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;
use Magento\Vault\Model\PaymentTokenManagement;
use Magento\Vault\Model\Ui\VaultConfigProvider;

class Vault
{
    const RECURRING_DETAIL_REFERENCE = 'recurring.recurringDetailReference';
    const CARD_SUMMARY = 'cardSummary';
    const EXPIRY_DATE = 'expiryDate';
    const PAYMENT_METHOD = 'paymentMethod';
    const TOKEN_TYPE = 'tokenType';
    const TOKEN_LABEL = 'tokenLabel';
    const ADDITIONAL_DATA_ERRORS = [
        self::RECURRING_DETAIL_REFERENCE => 'Missing Token in Result please enable in ' .
            'Settings -> API URLs and Response menu in the Adyen Customer Area Recurring details setting',
        self::CARD_SUMMARY => 'Missing cardSummary in Result please login to the adyen portal ' .
            'and go to Settings -> API URLs and Response and enable the Card summary property',
        self::EXPIRY_DATE => 'Missing expiryDate in Result please login to the adyen portal and go to ' .
            'Settings -> API URLs and Response and enable the Expiry date property',
        self::PAYMENT_METHOD => 'Missing paymentMethod in Result please login to the adyen portal and go to ' .
            'Settings -> API URLs and Response and enable the Variant property'
    ];
    const CARD_ON_FILE = 'CardOnFile';
    const SUBSCRIPTION = 'Subscription';
    const UNSCHEDULED_CARD_ON_FILE = 'UnscheduledCardOnFile';
    const MODE_MAGENTO_VAULT = 'Magento Vault';
    const MODE_ADYEN_TOKENIZATION = 'Adyen Tokenization';

    private AdyenLogger $adyenLogger;
    private PaymentTokenManagement $paymentTokenManagement;
    private PaymentTokenFactoryInterface $paymentTokenFactory;
    private PaymentTokenRepositoryInterface $paymentTokenRepository;
    private Config $config;

    public function __construct(
        AdyenLogger $adyenLogger,
        PaymentTokenManagement $paymentTokenManagement,
        PaymentTokenFactoryInterface $paymentTokenFactory,
        PaymentTokenRepositoryInterface $paymentTokenRepository,
        Config $config
    ) {
        $this->adyenLogger = $adyenLogger;
        $this->paymentTokenManagement = $paymentTokenManagement;
        $this->paymentTokenFactory = $paymentTokenFactory;
        $this->paymentTokenRepository = $paymentTokenRepository;
        $this->config = $config;
    }

    /**
     * @return string[]
     */
    public static function getRecurringTypes(): array
    {
        return [
            self::CARD_ON_FILE,
            self::SUBSCRIPTION,
            self::UNSCHEDULED_CARD_ON_FILE
        ];
    }

    public function getPaymentMethodRecurringActive(string $paymentMethodCode, int $storeId): ?bool
    {
        $recurringConfiguration = $this->config->getConfigData(
            Config::XML_RECURRING_CONFIGURATION,
            Config::XML_ADYEN_ABSTRACT_PREFIX, $storeId
        );

        if (!isset($recurringConfiguration)) {
            return false;
        } else {
            $recurringConfiguration = json_decode($recurringConfiguration, true);
            return isset($recurringConfiguration[$paymentMethodCode]['enabled']) &&
                $recurringConfiguration[$paymentMethodCode]['enabled'];
        }
    }

    public function getPaymentMethodRecurringProcessingModel(string $paymentMethodCode, int $storeId): ?string
    {
        $recurringConfiguration = $this->config->getConfigData(
            Config::XML_RECURRING_CONFIGURATION,
            Config::XML_ADYEN_ABSTRACT_PREFIX, $storeId
        );

        if (!isset($recurringConfiguration)) {
            return null;
        } else {
            $recurringConfiguration = json_decode($recurringConfiguration, true);
            return $recurringConfiguration[$paymentMethodCode]['recurringProcessingModel'] ?? null;
        }
    }

    public function hasRecurringDetailReference(array $response): bool
    {
        if (array_key_exists('additionalData', $response) &&
            array_key_exists(self::RECURRING_DETAIL_REFERENCE, $response['additionalData'])) {
            return true;
        }

        return false;
    }

    /**
     * Build the recurring data when payment is done through a payment method (not card)
     *
     */
    public function buildPaymentMethodRecurringData(InfoInterface $payment, int $storeId): array
    {
        $request = [];
        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $payment->getMethodInstance();
        if (!$this->getPaymentMethodRecurringActive($paymentMethod->getCode(), $storeId)) {
            return $request;
        }

        $requestRpm = $payment->getAdditionalInformation('recurringProcessingModel');
        $configuredRpm = $this->getPaymentMethodRecurringProcessingModel($paymentMethod->getCode(), $storeId);

        $recurringProcessingModel = $requestRpm ?? $configuredRpm;

        if (isset($recurringProcessingModel) &&
            $this->paymentMethodSupportsRpm($paymentMethod, $recurringProcessingModel)) {
            $request['storePaymentMethod'] = true;
            $request['recurringProcessingModel'] = $recurringProcessingModel;
        }

        return $request;
    }

    public function getAdyenTokenType(PaymentTokenInterface $paymentToken): ?string
    {
        $details = json_decode($paymentToken->getTokenDetails() ?: '{}', true);
        if (array_key_exists(self::TOKEN_TYPE, $details)) {
            return $details[self::TOKEN_TYPE];
        }

        return null;
    }

    public function createVaultToken(OrderPaymentInterface $payment, string $detailReference): PaymentTokenInterface
    {
        $paymentMethodInstance = $payment->getMethodInstance();
        $paymentMethodCode = $paymentMethodInstance->getCode();
        $paymentTokenSaveRequired = false;

        // Check if paymentToken exists already
        $paymentToken = $this->paymentTokenManagement->getByGatewayToken(
            $detailReference,
            $paymentMethodCode,
            $payment->getOrder()->getCustomerId()
        );

        if ($paymentToken === null) {
            $paymentToken = $this->paymentTokenFactory->create();
            $paymentToken->setGatewayToken($detailReference);
        } else {
            $paymentTokenSaveRequired = true;
        }

        $additionalData = $payment->getAdditionalInformation('additionalData');

        $storeId = $payment->getOrder()->getStoreId();
        $requestRpm = $payment->getAdditionalInformation('recurringProcessingModel');
        $configuredRpm = $this->getPaymentMethodRecurringProcessingModel($paymentMethodCode, $storeId);
        $recurringProcessingModel = $requestRpm ?? $configuredRpm;

        // If wallet payment method
        if ($paymentMethodInstance instanceof PaymentMethodInterface && $paymentMethodInstance->isWallet()) {
            $paymentToken->setType(PaymentTokenFactoryInterface::TOKEN_TYPE_CREDIT_CARD);
            $txVariant = new TxVariant($payment->getCcType());
            $details = [
                'type' => $txVariant->getCard(),
                'walletType' => $txVariant->getPaymentMethod(),
                'expirationDate' => $additionalData['expiryDate']
            ];
            $paymentToken->setExpiresAt($this->getExpirationDate($additionalData['expiryDate']));
        } elseif ($paymentMethodCode == PaymentMethods::ADYEN_CC) {
            $paymentToken->setType(PaymentTokenFactoryInterface::TOKEN_TYPE_CREDIT_CARD);
            $details = [
                'type' => $payment->getCcType(),
                'maskedCC' => $additionalData['cardSummary'],
                'expirationDate' => $additionalData['expiryDate']
            ];
            $paymentToken->setExpiresAt($this->getExpirationDate($additionalData['expiryDate']));
        } elseif ($paymentMethodInstance instanceof PaymentMethodInterface) {
            $paymentToken->setType(PaymentTokenFactoryInterface::TOKEN_TYPE_ACCOUNT);
            $today = new DateTime();
            $details = [
                'type' => $payment->getCcType(),
                self::TOKEN_LABEL => $paymentMethodInstance->getPaymentMethodName() . ' token created on ' . $today->format('Y-m-d'),
                'expirationDate' => $today->add(new DateInterval('P1Y'))
            ];
            $paymentToken->setExpiresAt($this->getExpirationDate($today->add(new DateInterval('P1Y'))));
        }

        $details[self::TOKEN_TYPE] = $recurringProcessingModel;

        $paymentToken->setTokenDetails(json_encode($details, JSON_FORCE_OBJECT));

        // If the token is updated, it needs to be saved to keep the changes
        if ($paymentTokenSaveRequired) {
            $this->paymentTokenRepository->save($paymentToken);
        }

        return $paymentToken;
    }

    private function getExpirationDate($expirationDate): string
    {
        $expirationDate = explode('/', (string) $expirationDate);

        $expDate = new DateTime(
        //add leading zero to month
            sprintf("%s-%02d-01 00:00:00", $expirationDate[1], $expirationDate[0]),
            new DateTimeZone('UTC')
        );

        // add one month
        $expDate->add(new DateInterval('P1M'));
        return $expDate->format('Y-m-d H:i:s');
    }

    public function getExtensionAttributes(InfoInterface $payment): OrderPaymentExtensionInterface
    {
        $extensionAttributes = $payment->getExtensionAttributes();
        if (null === $extensionAttributes) {
            $extensionAttributes = $this->paymentExtensionFactory->create();
            $payment->setExtensionAttributes($extensionAttributes);
        }
        return $extensionAttributes;
    }

    public function validateRecurringProcessingModel(string $recurringProcessingModel): bool
    {
        return in_array($recurringProcessingModel, self::getRecurringTypes());
    }

    public function isAdyenPaymentCode(string $paymentCode): bool
    {
        return str_contains($paymentCode, 'adyen_');
    }

    public function paymentMethodSupportsRpm(PaymentMethodInterface|MethodInterface $paymentMethod, string $rpm): bool
    {
        if (($rpm === Vault::SUBSCRIPTION && $paymentMethod->supportsSubscription()) ||
            ($rpm === Vault::CARD_ON_FILE && $paymentMethod->supportsCardOnFile()) ||
            ($rpm === Vault::UNSCHEDULED_CARD_ON_FILE && $paymentMethod->supportsUnscheduledCardOnFile())) {
            return true;
        }

        return false;
    }

    public function handlePaymentResponseRecurringDetails(Payment $payment, array $response): void
    {
        $paymentMethodInstance = $payment->getMethodInstance();
        $paymentMethodCode = $paymentMethodInstance->getCode();
        $storeId = $paymentMethodInstance->getStore();
        $isRecurringEnabled = $this->getPaymentMethodRecurringActive($paymentMethodCode, $storeId);

        if ($this->hasRecurringDetailReference($response) && $isRecurringEnabled) {
            try {
                $paymentToken = $this->createVaultToken($payment, $response['additionalData'][self::RECURRING_DETAIL_REFERENCE]);
                $extensionAttributes = $this->getExtensionAttributes($payment);
                $extensionAttributes->setVaultPaymentToken($paymentToken);
            } catch (Exception $exception) {
                $this->adyenLogger->error(
                    sprintf(
                        'Failure trying to save payment token in vault for order %s, with exception message %s',
                        $payment->getOrder()->getIncrementId(),
                        $exception->getMessage()
                    )
                );
            }
        }
    }
}
