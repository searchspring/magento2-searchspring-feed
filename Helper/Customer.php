<?php
/**
 * Helper to fetch customer data.
 *
 * This file is part of SearchSpring/Feed.
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 */

namespace SearchSpring\Feed\Helper;

use \Magento\Framework\App\Request\Http as RequestHttp;
use \Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CollectionFactory;
use SearchSpring\Feed\Helper\Utils;
use \DateTime;

class Customer extends \Magento\Framework\App\Helper\AbstractHelper
{
    protected $request;
    protected $customerFactory;
    protected $dateRange;
    protected $rowRange;

    public function __construct(
        RequestHttp $request,
        CollectionFactory $customerFactory
    ) {
        $this->request = $request;
        $this->customerFactory = $customerFactory;
        $this->dateRange = $this->request->getParam('dateRange', 'All');
        $this->rowRange = $this->request->getParam('rowRange', 'All');
    }

    public function getCustomers()
    {
        $_result = [];
        $customerCollection = $this->customerFactory->create();

        // Build date range query.
        $dateRange = Utils::getDateRange($this->dateRange);
        if ($dateRange) {
            $filterDateRange = ['from' => $dateRange[0]];

            if (isset($dateRange[1])) {
                $plusOneDay = Utils::plusOneDay($dateRange[1], $format = 'Y-m-d');
                $filterDateRange['to'] = $plusOneDay;
            }

            $whereCreatedAt = "e.created_at >= '" . $filterDateRange['from'] . "'";
            $whereUpdatedAt = "e.updated_at >= '" . $filterDateRange['from'] . "'";
            if (isset($filterDateRange['to'])) {
                $whereCreatedAt .= " && e.created_at <= '" . $filterDateRange['to'] . "'";
                $whereUpdatedAt .= " && e.updated_at <= '" . $filterDateRange['to'] . "'";
            }

            $customerCollection->getSelect()->where("($whereCreatedAt) || ($whereUpdatedAt)"); // Query string
        }

        // Chunk customers with row range.
        $rowRange = Utils::getRowRange($this->rowRange);
        if (isset($rowRange[0]) && isset($rowRange[1]))
            $customerCollection->getSelect()->limit((int)$rowRange[1], (int)$rowRange[0]);

        $items = $customerCollection->getItems(); // Make query
        foreach ($items as $item) {
            $res = [
                'id' => $item->getId(),
                'email' => $item->getEmail()
            ];

            $_result[] = $res;
        }

        return ['customers' => $_result];
    }
}
