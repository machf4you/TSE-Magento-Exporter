<?php
declare(strict_types=1);

namespace TSE\MagentoExporter\Controller\Adminhtml\Export;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use TSE\MagentoExporter\Model\Exporter;
use TSE\MagentoExporter\Model\ZipBuilder;

class Download extends Action
{
    public const ADMIN_RESOURCE = 'Magento_Backend::admin';

    /** @var Exporter */
    private $exporter;

    /** @var ZipBuilder */
    private $zipBuilder;

    /** @var FileFactory */
    private $fileFactory;

    public function __construct(
        Context $context,
        Exporter $exporter,
        ZipBuilder $zipBuilder,
        FileFactory $fileFactory
    ) {
        parent::__construct($context);
        $this->exporter    = $exporter;
        $this->zipBuilder  = $zipBuilder;
        $this->fileFactory = $fileFactory;
    }

    public function execute()
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');

        try {
            // Build filters from request params:
            //   store=hf                       single store
            //   stores[]=hf&stores[]=cbs       multi-store
            //   sections[]=products&sections[]=seo
            $req = $this->getRequest();
            $stores = (array) ($req->getParam('stores') ?: array_filter([(string) $req->getParam('store', '')]));
            $stores = array_values(array_filter(array_map('strval', $stores), 'strlen'));

            $sections = (array) ($req->getParam('sections') ?: []);
            $sections = array_values(array_filter(array_map('strval', $sections), 'strlen'));

            $bundle  = $this->exporter->buildBundle([
                'stores'   => $stores,
                'sections' => $sections,
            ]);
            $zipPath = $this->zipBuilder->build($bundle);

            // Filename reflects scope for easier downstream handling.
            $scope = $stores ? implode('-', $stores) : 'all-stores';
            $secScope = $sections ? implode('-', $sections) : 'full';
            $filename = sprintf('tse-magento-export-%s-%s-%s.zip', $scope, $secScope, gmdate('Ymd-His'));

            return $this->fileFactory->create(
                $filename,
                [
                    'type'  => 'filename',
                    'value' => $zipPath,
                    'rm'    => true,
                ],
                DirectoryList::TMP,
                'application/zip'
            );
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('TSE export failed: %1', $e->getMessage()));
            return $this->resultRedirectFactory->create()->setPath('tsemagento/export/index');
        }
    }
}
