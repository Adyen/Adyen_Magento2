<?php

namespace Adyen\Payment\Test\Unit\Model\Config\Backend;

use Adyen\Payment\Helper\BaseUrlHelper;
use Adyen\Payment\Helper\ManagementHelper;
use Adyen\Payment\Model\Config\Backend\AutoConfiguration;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;

class AutoConfigurationTest extends AbstractAdyenTestCase
{
    protected AutoConfiguration $autoConfiguration;
    private Context $contextMock;
    private Registry $registryMock;
    private ScopeConfigInterface $scopeConfigMock;
    private TypeListInterface $typeListtMock;
    private ManagementHelper $managementHelperMock;
    private UrlInterface $urlMock;
    private BaseUrlHelper $baseUrlHelperMock;

    protected function setUp(): void
    {
        $this->contextMock = $this->createMock(Context::class);
        $this->registryMock = $this->createMock(Registry::class);
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $this->typeListtMock = $this->createMock(TypeListInterface::class);
        $this->managementHelperMock = $this->createMock(ManagementHelper::class);

        $this->urlMock = $this->createConfiguredMock(UrlInterface::class, [
            'getBaseUrl' => 'my base url'
        ]);
        $this->baseUrlHelperMock = $this->createConfiguredMock(BaseUrlHelper::class, [
            'getDomainFromUrl' => 'mydomain'
        ]);

        $this->autoConfiguration = new AutoConfiguration(
            $this->contextMock,
            $this->registryMock,
            $this->scopeConfigMock,
            $this->typeListtMock,
            $this->managementHelperMock,
            $this->urlMock,
            $this->baseUrlHelperMock,
            null,
            null,
            [
                'fieldset_data' => [
                    'demo_mode' => 1,
                    'api_key_live' => '2',
                    'api_key_test' => '2'
                ]
            ]
        );
    }

    public function testSaveAllowedOrigins()
    {
        $this->managementHelperMock->expects($this->once())->method('saveAllowedOrigin');
        $this->invokeMethod($this->autoConfiguration, 'saveAllowedOrigins');
    }
}
