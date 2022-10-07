<?php
/**
 * Module to fetch version data.
 *
 * This file is part of SearchSpring/Feed.
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 */

namespace SearchSpring\Feed\Controller\Feed;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory as JsonFactory;
use SearchSpring\Feed\Helper\VersionInfo as VersionInfo;
use \Magento\Framework\App\Request\Http as Request;

class Version implements HttpGetActionInterface
{
    const MODULE_NAME = 'SearchSpring_Feed';
    /**
     * @var Request
     */
    protected $request;

    /**
     * @var VersionInfo
     */
    protected $helper;

    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @param Request     $request
     * @param VersionInfo $helper
     * @param JsonFactory $resultJsonFactory
     */
    public function __construct(
        Request $request,
        VersionInfo $helper,
        JsonFactory $resultJsonFactory
    ) {
        $this->request = $request;
        $this->helper = $helper;
        $this->resultJsonFactory = $resultJsonFactory;
    }

    /**
     * Execute version action
     *
     * Example query:
     * http://localhost/searchspring/feed/version
     */
    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();
        $data = $this->helper->getVersion();
        return $resultJson->setData($data);
    }
}
