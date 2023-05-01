<?php
/**
 * Helper to fetch sale data.
 *
 * This file is part of SearchSpring/Feed.
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 */

namespace SearchSpring\Feed\Helper;

use \Magento\Framework\App\Request\Http as RequestHttp;
use \Magento\Store\Model\StoresConfig as StoresConfig;
use \Magento\Sales\Model\ResourceModel\Order\Item\CollectionFactory as CollectionFactory;
use SearchSpring\Feed\Helper\Utils;

use \DateTime;

class Sale extends \Magento\Framework\App\Helper\AbstractHelper
{
    protected $request;
    protected $storesConfig;
    protected $saleFactory;
    protected $dateRange;
    protected $rowRange;

    public function __construct(
        RequestHttp $request,
        StoresConfig $storesConfig,
        CollectionFactory $saleFactory
    ) {
        $this->request = $request;
        $this->storesConfig = $storesConfig;
        $this->saleFactory = $saleFactory;
        $this->dateRange = $this->request->getParam('dateRange', 'All');
        $this->rowRange = $this->request->getParam('rowRange', 'All');
    }

    function getSales()
    {
        $result = [];
        $collection = $this->saleFactory->create();

        // Build date range query.
        $dateRange = Utils::getDateRange($this->dateRange);
        if ($dateRange) {
            $filterDateRange = [
                'from' => $dateRange[0],
                'date' => true
            ];
            if (isset($dateRange[1])) {
                $plusOneDay = Utils::plusOneDay($dateRange[1], $format = 'Y-m-d');
                $filterDateRange['to'] = $plusOneDay;
            }
            $collection->getSelect()
                ->where("(main_table.created_at >= '" . $filterDateRange['from'] . "' && main_table.created_at <= '" . $filterDateRange['to'] . "') "
                    . " || (main_table.updated_at >= '" . $filterDateRange['from'] . "' && main_table.updated_at <= '" . $filterDateRange['to'] . "') ");
        }

        // Chunk sales with row range.
        $rowRange = Utils::getRowRange($this->rowRange);
        if (isset($rowRange[0]) && isset($rowRange[1]))
            $collection->getSelect()->limit((int)$rowRange[1], (int)$rowRange[0]);

        foreach($collection as $item){
            $orderID = $item->getOrderID();

            $order = $item->getOrder();
            $customerID = $order->getData('customer_id');
            if (empty($customerID)) {
                $customerID = $order->getData('customer_email');
            }

            $productID = $item->getData('product_id');
            $quantity = (string)($item->getData('qty_ordered') - ($item->getData('qty_canceled') + $item->getData('qty_refunded')));
            
            // This has returned "" in the wild
            $storeId = $item->getData('store_id');

            $createdAt = null;
            if(!empty($storeId)){
                $zones = $this->getTimeZones();
                $zone = $zones[$storeId];
                $dt = new \DateTime($item->getData('created_at'), new \DateTimeZone($zone));
                $createdAt = $dt->format('Y-m-d H:i:sP');
            } else {
                $dt = new \DateTime($item->getData('created_at'));
                $createdAt = $dt->format('Y-m-d H:i:sP');
            }

            $res = ['order_id' => $orderID,
                    'customer_id' => $customerID,
                    'product_id' => $productID,
                    'quantity' => $quantity,
                    'createdAt' => $createdAt
                ];
            $result[] = $res;
        }
        return ['sales' => $result];
    }

    /**
     * Get timezones used by the stores in this Magento setup.
     *
     * @return array
     */
    private function getTimeZones()
    {
        return $this->storesConfig->getStoresConfigByPath(\Magento\Config\Model\Config\Backend\Admin\Custom::XML_PATH_GENERAL_LOCALE_TIMEZONE);
    }

    private function getConfig($key, $store = null)
    {
        return $this->scopeConfig->getValue(
            $key,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $store
        );
    }
}
