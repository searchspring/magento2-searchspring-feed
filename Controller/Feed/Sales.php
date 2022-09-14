<?php
/**
 * Module to fetch sales data.
 *
 * This file is part of SearchSpring/Feed.
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 */

namespace SearchSpring\Feed\Controller\Feed;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory as JsonFactory;
use SearchSpring\Feed\Helper\Sale as Sale;
use \Magento\Framework\App\Request\Http as Request;
use SearchSpring\Feed\Helper\Utils;

class Sales implements HttpGetActionInterface
{
    /**
     * @var Request
     */
    protected $request;

    /**
     * @var Sale
     */
    protected $helper;

    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @param Request     $request
     * @param Sale        $helper
     * @param JsonFactory $resultJsonFactory
     */
    public function __construct(
        Request $request,
        Sale $helper,
        JsonFactory $resultJsonFactory
    ) {
        $this->request = $request;
        $this->helper = $helper;
        $this->resultJsonFactory = $resultJsonFactory;
    }

    /**
     * Execute sales action
     *
     * Example query:
     * http://localhost/searchspring/feed/sales?dateRange=2021-10-01,2021-11-01&rowRange=1,25
     */
    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();

        // Validate date range
        $isValidDateRange = Utils::validateDateRange($this->request->getParam('dateRange', 'All'));
        if (!$isValidDateRange) {
            $response = [
                'success' => false,
                'message' => "Invalid date range"
            ];
            $resultJson->setHttpResponseCode(400);
            return $resultJson->setData($response);
        }

        // Validate row range
        $isValidRowRange = Utils::validateRowRange($this->request->getParam('rowRange', 'All'));
        if (!$isValidRowRange) {
            $response = [
                'success' => false,
                'message' => "Invalid row range"
            ];
            $resultJson->setHttpResponseCode(400);
            return $resultJson->setData($response);
        }

        $data = $this->helper->getSales();
        return $resultJson->setData($data);
    }
}
