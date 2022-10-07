<?php
/**
 * Helper to fetch version data.
 *
 * This file is part of SearchSpring/Feed.
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 */

namespace SearchSpring\Feed\Helper;

use \Magento\Framework\App\Request\Http as RequestHttp;

class VersionInfo extends \Magento\Framework\App\Helper\AbstractHelper
{
    protected $request;
    const MODULE_NAME = 'SearchSpring_Feed';

    public function __construct(
        RequestHttp $request,
        \Magento\Framework\App\ProductMetadataInterface $productMetadata
    ) {
        $this->request = $request;
        $this->productMetadata = $productMetadata;
    }

    public function getVersion()
    {
        $result = [];
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $version = $objectManager->get('\Magento\Framework\Module\ModuleListInterface')->getOne(self::MODULE_NAME)['setup_version'];
        $result[] = [
            'extension' => $version,
            'magento' => $this->productMetadata->getName() . '/' . $this->productMetadata->getVersion() . ' (' . $this->productMetadata->getEdition() . ')',
            'memLimit' => $this->getMemoryLimit(),
            'OSType' => php_uname($mode = "s"),
            'OSVersion' => php_uname($mode = "v"),
            'maxExecutionTime' => ini_get("max_execution_time")
        ];

        return $result;
    }

    public function getMemoryLimit()
    {
        $memoryLimit = trim(strtoupper(ini_get('memory_limit')));

        if (!isSet($memoryLimit[0])) {
            $memoryLimit = "128M";
        }

        if (substr($memoryLimit, -1) == 'K') {
            return substr($memoryLimit, 0, -1) * 1024;
        }
        if (substr($memoryLimit, -1) == 'M') {
            return substr($memoryLimit, 0, -1) * 1024 * 1024;
        }
        if (substr($memoryLimit, -1) == 'G') {
            return substr($memoryLimit, 0, -1) * 1024 * 1024 * 1024;
        }
        return $memoryLimit;
    }
}
