<?php
/**
 * Module to fetch customer data.
 *
 * This file is part of SearchSpring/Feed.
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 */

// namespace SearchSpring\Feed\Controller\Feed;

// use Magento\Framework\App\Action\Action;
// use Magento\Framework\App\Action\Context as Context;
// use Magento\Framework\Controller\Result\JsonFactory as JsonFactory;
// use SearchSpring\Feed\Helper\Customer as Customer;
// use \Magento\Framework\App\Request\Http as RequestHttp;
// use SearchSpring\Feed\Helper\Utils;

// class Customers extends Action
// {
//     protected $request;
//     protected $helper;
//     protected $resultJsonFactory;

//     public function __construct(
//         RequestHttp $request,
//         Context $context,
//         Customer $helper,
//         JsonFactory $resultJsonFactory
//     ) {
//         parent::__construct($context);
//         $this->request = $request;
//         $this->helper = $helper;
//         $this->resultJsonFactory = $resultJsonFactory;
//     }

//     /**
//      * Execute customers action
//      *
//      * Example query:
//      * http://localhost/searchspring/feed/customers?dateRange=2021-10-01,2021-11-01&rowRange=1,25
//      */
//     public function execute()
//     {
//         $resultJson = $this->resultJsonFactory->create();

//         // Validate date range
//         $isValidDateRange = Utils::validateDateRange($this->request->getParam('dateRange', 'All'));
//         if (!$isValidDateRange){
//             $response = [
//                 'success' => false,
//                 'message' => "Invalid date range"
//             ];
//             $resultJson->setHttpResponseCode(400);
//             return $resultJson->setData($response);
//         }

//         // Validate row range
//         $isValidRowRange = Utils::validateRowRange($this->request->getParam('rowRange', 'All'));
//         if (!$isValidRowRange){
//             $response = [
//                 'success' => false,
//                 'message' => "Invalid row range"
//             ];
//             $resultJson->setHttpResponseCode(400);
//             return $resultJson->setData($response);
//         }

//         $data = $this->helper->getCustomers();

//         return $resultJson->setData($data);
//     }
// }
