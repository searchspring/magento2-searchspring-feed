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
use \DateTime;

class Customer extends \Magento\Framework\App\Helper\AbstractHelper
{
    protected $request;
    protected $customerFactory;
    protected $dateRange;
    protected $rowRange;

    public function __construct(
        RequestHttp $request,
        CollectionFactory $customerFactory,
    ) {
        $this->request = $request;
        $this->customerFactory = $customerFactory;
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
    // Row range enables chunking requests for getting customers on the Boost side.
    // Row Range expects two comma seperated integers greater than 0.
    // The first number minus one is the start index (zero-based) in the customer collection.
    // The second number is the number of customers to chunk from the start index. 
    // For example, &dateRange=1,3 creates a chunk starting at customer element 0 and ending at
    // customer element 2. And, &dateRange=10,3 creates a chunk starting at customer element 9
    // and ending at customer element 11. 
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
    // Returns an array of arrays with the first sub array containing a header (['CustomerID', 'Email']).
    // The rest of the array contains arrays containing a customerID and a customer Email.
    public function getCustomers()
    {
        $_result = [];
        $customerCollection = $this->customerFactory->create();
        
        // Build date range query.
        $dateRange = $this->getDateRange();
        if ($dateRange) {
            $filterDateRange = ['from' => $dateRange[0]];
            
            if (isset($dateRange[1])) {
                $plusOneDay = $this->plusOneDay($dateRange[1], $format = 'Y-m-d');
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
        $rowRange = $this->getRowRange();
        if (isset($rowRange[0]) && isset($rowRange[1])) 
            $customerCollection->getSelect()->limit((int)$rowRange[1], (int)$rowRange[0]);

        // Items is an array of 'Items' which should be customers in this case.
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

    private function plusOneDay($date, $format = 'Y-m-d')
    {
        $d = DateTime::createFromFormat($format, $date);
        //PHP 5 >= 5.2.0
        $d->modify('+1 day');
        return $d->format($format);
    }
}
?>
