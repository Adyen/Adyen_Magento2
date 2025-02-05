<?php

namespace Adyen\Payment\Test\Unit\Model\Config\Adminhtml;

use Adyen\Payment\Model\Config\Adminhtml\ApplePayDomainAssociationFileButton;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Helper\Data;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\State;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\View\Element\Template\File\Resolver;
use Magento\Framework\View\Element\Template\File\Validator;
use Magento\Framework\View\LayoutInterface;
use Magento\Framework\View\TemplateEngineInterface;
use Magento\Framework\View\TemplateEnginePool;
use PHPUnit\Framework\MockObject\MockObject;

class ApplePayDomainAssociationFileButtonTest extends AbstractAdyenTestCase
{
    protected ?ApplePayDomainAssociationFileButton $applePayDomainAssociationFileButton;
    protected MockObject|Context $contextMock;
    protected MockObject|Data $backendHelperMock;
    protected MockObject|LayoutInterface $layoutMock;
    protected MockObject|ManagerInterface $managerMock;
    protected MockObject|ScopeConfigInterface $scopeConfigMock;

    /**
     * @return void
     */
    public function setUp(): void
    {
        $objectManagerMock = $this->createMock(ObjectManagerInterface::class);
        ObjectManager::setInstance($objectManagerMock);

        $this->managerMock = $this->createMock(ManagerInterface::class);
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);

        $resolverMock = $this->createMock(Resolver::class);

        $readInterfaceMock = $this->createMock(ReadInterface::class);

        $templateEngineMock = $this->createMock( TemplateEngineInterface::class);
        $templateEngineMock->method('render')->willReturn('<button>Apple Pay</button>');
        $templateEnginePoolMock = $this->createMock(TemplateEnginePool::class);
        $templateEnginePoolMock->method('get')->willReturn($templateEngineMock);

        $filesystemMock = $this->createMock(Filesystem::class);
        $filesystemMock->method('getDirectoryRead')->willReturn($readInterfaceMock);

        $validatorMock = $this->createMock(Validator::class);
        $validatorMock->method('isValid')->willReturn(true);

        $appStateMock = $this->createMock(State::class);

        $this->contextMock = $this->createMock(Context::class);
        $this->contextMock->method('getScopeConfig')->willReturn($this->scopeConfigMock);
        $this->contextMock->method('getEventManager')->willReturn($this->managerMock);
        $this->contextMock->method('getResolver')->willReturn($resolverMock);
        $this->contextMock->method('getFilesystem')->willReturn($filesystemMock);
        $this->contextMock->method('getValidator')->willReturn($validatorMock);
        $this->contextMock->method('getAppState')->willReturn($appStateMock);
        $this->contextMock->method('getEnginePool')->willReturn($templateEnginePoolMock);

        $this->backendHelperMock = $this->createMock(Data::class);

        // Prepare test data argument
        $data = [
            'area' => 'backend'
        ];

        $this->applePayDomainAssociationFileButton = new ApplePayDomainAssociationFileButton(
            $this->contextMock,
            $this->backendHelperMock,
            $data
        );

        // $this->applePayDomainAssociationFileButton->setTemplate('Adyen_Payment::config/applepay_domain_association_file_button.phtml');
    }

    /**
     * @return void
     */
    public function tearDown(): void
    {
        $this->applePayDomainAssociationFileButton = null;
    }

    /**
     * Asserts default HTML template value
     *
     * @return void
     */
    public function testGetElementHtml()
    {
        $expected = '<tr id="row_"><td class="label"><label for=""><span></span></label></td><td class="value"><button>Apple Pay</button></td><td class=""></td></tr>';

        $result = $this->applePayDomainAssociationFileButton
            ->render($this->createMock(AbstractElement::class));

        $this->assertEquals($expected, $result);
    }

    /**
     * Asserts file download controller URL
     *
     * @return void
     */
    public function testGetActionUrl()
    {
        $expected = 'https://www.magento.demo/adyen/configuration/DownloadApplePayDomainAssociationFile';
        $this->backendHelperMock->expects($this->once())
            ->method('getUrl')
            ->with('adyen/configuration/DownloadApplePayDomainAssociationFile', [])
            ->willReturn($expected);

        $url = $this->applePayDomainAssociationFileButton->getActionUrl();

        $this->assertEquals($expected, $url);
    }
}
