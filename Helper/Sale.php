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

    // ##### DATE PARSE/VALIDATION STUFF #####
    // Note that the date input parameter is expected to be in the form of "YYYY-mm-dd,YYYY-mm-dd"
    // ValidateDateRange simply returns true if it's a valid range, false otherwise. It does not
    // compare values to see if they're in the proper order or anything like that. It only checks
    // to see if there are dates, and if they're in the proper format..

    // Tries to parse date to the format, returns true if it can be converted.
    private function validateDate($date, $format = 'Y-m-d')
    {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) == $date;
    }

    // Expands the input into an array. Or not.
    public function getDateRange()
    {
        if ($this->dateRange != 'All')
            return explode(",", $this->dateRange);
        else
            return false;
    }

    // Checks to see if the input parameter provided in the get request contains two strings that
    // can be parsed into valid dates. Returns true if they can be, false otherwise.
    public function validateDateRange()
    {
        $dateRange = $this->getDateRange();
        if ($dateRange){
            if (isset($dateRange[0])){
                if(!$this->validateDate($dateRange[0])){
                    return false;
                }
            }
            if (isset($dateRange[1])){
                if(!$this->validateDate($dateRange[1])){
                    return false;
                }
            }
        }
        return true;
    }

    // ##### ROW RANGE/VALIDATION STUFF #####
    // Row range enables chunking requests for getting sales on the Boost side.
    // Row Range expects two comma seperated integers greater than 0.
    // The first number minus one is the start index (zero-based) in the sale collection.
    // The second number is the number of sales to chunk from the start index. 
    // For example, &dateRange=1,3 creates a chunk starting at sale element 0 and ending at
    // sale element 2. And, &dateRange=10,3 creates a chunk starting at sale element 9
    // and ending at sale element 11. 
    public function getRowRange(){
        if ($this->rowRange != 'All'){
            $result = explode(",", $this->rowRange);
            $result[0] = (int)$result[0] - 1;
            $result[1] = (int)$result[1];
            return $result;
        }
        else
            return false;
    }

    public function validateRowRange() {
        $isValidRowRange = true;
        if ($this->rowRange != 'All'){
            $result = explode(",", $this->rowRange);
            if (count($result) != 2)
                $isValidRowRange = false;

            if (isset($result[0])){
                $result[0] = (int) $result[0];
                if ($result[0] <= 0 )
                    $isValidRowRange = false;
            }
            if (isset($result[1])){
                $result[1] = (int) $result[1];
                if ($result[1] <= 0 )
                    $isValidRowRange = false;
            }
        }
        return $isValidRowRange;
    }

    // ##### ACTUAL SQL QUERY STUFF #####
    function getSales()
    {
        $result = [];
        $collection = $this->saleFactory->create();
        
        $dateRange = $this->getDateRange();

        if ($dateRange) {
            $filterDateRange = [
                'from' => $dateRange[0],
                'date' => true
            ];
            if (isset($dateRange[1])) {
                $plusOneDay = $this->plusOneDay($dateRange[1], $format = 'Y-m-d');
                $filterDateRange['to'] = $plusOneDay;
            }
            $collection->getSelect()
                ->where("(main_table.created_at >= '" . $filterDateRange['from'] . "' && main_table.created_at <= '" . $filterDateRange['to'] . "') "
                    . " || (main_table.updated_at >= '" . $filterDateRange['from'] . "' && main_table.updated_at <= '" . $filterDateRange['to'] . "') ");
        }


        // Chunk sales with row range.
        $rowRange = $this->getRowRange();
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

            $storeId = $item->getData('store_id');
            $zones = $this->getTimezone(array($storeId));
            $zone = $zones[$storeId];
            $dt = new \DateTime($item->getData('created_at'), new \DateTimeZone($zone));
            $createdAt = $dt->format('Y-m-d H:i:sP');

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

    private function plusOneDay($date, $format = 'Y-m-d')
    {
        $d = DateTime::createFromFormat($format, $date);
        //PHP 5 >= 5.2.0
        $d->modify('+1 day');
        return $d->format($format);
    }

    /**
     * Get locale timezone
     *
     * @param array $storeIds
     * @return array
     */
    private function getTimezone($storeIds)
    {
        return $this->storesConfig->getStoresConfigByPath(\Magento\Config\Model\Config\Backend\Admin\Custom::XML_PATH_GENERAL_LOCALE_TIMEZONE);
    }

    public function productTypeRules($callType, $orderItem, $storeIds = null)
    {
        $order = $orderItem->getOrder();
        $skipRow = false;
        $productId = $orderItem->getData('product_id');
        if ($orderItem->getProduct())
            $productTypeReal = $orderItem->getProduct()->getTypeID();
        else {
            $productTypeReal = $orderItem->getProductType();
        }

        switch ($productTypeReal) {
            case \Magento\ConfigurableProduct\Model\Product\Type\Configurable::TYPE_CODE:
                if ($callType == 'sales' || $callType == 'tracking')
                    $skipRow = true;
                break;
            case \Magento\GroupedProduct\Model\Product\Type\Grouped::TYPE_CODE:
                if (!$this->getConfig(self::XML_PATH_ADVANCED_GROUPPROD, is_null($storeIds) ? $storeIds : $storeIds[0]))
                    $skipRow = true;
                break;
            case \Magento\Bundle\Model\Product\Type::TYPE_CODE:
                if (!$this->getConfig(self::XML_PATH_ADVANCED_BUNDLEPROD, is_null($storeIds) ? $storeIds : $storeIds[0]))
                    $skipRow = true;
                break;
            default:
                if ($orderItem->getData('product_options')) {
                    $productOptions = $orderItem->getData('product_options');

                    $parentIdArray = $this->groupedProduct->getParentIdsByChild($productId);
                    if (isset($parentIdArray[0])) {
                        //if the Simple product is associated with a Grouped product (i.e. child).
                        if ($this->getConfig(self::XML_PATH_ADVANCED_GROUPPROD, is_null($storeIds) ? $storeIds : $storeIds[0])) {
                            if (isset($productOptions['info_buyRequest']['super_product_config']['product_id']))
                                if ($productOptions['info_buyRequest']['super_product_config']['product_id'] != $productId)
                                    $productId = $productOptions['info_buyRequest']['super_product_config']['product_id'];
                        }
                    }

                    $parentIdArray = $this->getBundleParentIdsByChildFixed($productId);
                    if (isset($parentIdArray[0])) {
                        //if the Simple product is associated with a Bundle product (i.e. child).
                        if ($this->getConfig(self::XML_PATH_ADVANCED_BUNDLEPROD, is_null($storeIds) ? $storeIds : $storeIds[0])) {
                            if (isset($productOptions['info_buyRequest']['product']))
                                if ($productOptions['info_buyRequest']['product'] != $productId)
                                    $skipRow = true;
                        }
                    }
                }

            //Simple product is not associated with Configurable, Grouped, Bundle

        }
        if ($skipRow)
            return false;
        $qty = $orderItem->getData('qty_ordered') - ($orderItem->getData('qty_canceled') + $orderItem->getData('qty_refunded'));

        if ($order->getData('status') == 'canceled')
            $qty =0;

        $sku = $this->productResource->getProductsSku(array($productId));
        if (empty($sku))
            $sku = $orderItem->getSku();
        else
            $sku = $sku[0]['sku'];

            $res = array(
            'product_id' => $productId,
            'qty' => strval($qty),
            'sku' => $sku
        );

        return $res;
    }

    public function getConfig($key, $store = null)
    {
        return $this->scopeConfig->getValue(
            $key,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $store
        );
    }

   }
?>
