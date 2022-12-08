<?php
namespace SearchSpring\Feed\Helper;


use \DateTime;
use Magento\Framework\HTTP\PhpEnvironment\Request;


// ##### DATE PARSE/VALIDATION STUFF #####
// Note that the date input parameter is expected to be in the form of "YYYY-mm-dd,YYYY-mm-dd"
// ValidateDateRange simply returns true if it's a valid range, false otherwise. It does not
// compare values to see if they're in the proper order or anything like that. It only checks
// to see if there are dates, and if they're in the proper format..

class Utils
{
    // Tries to parse date to the format, returns true if it can be converted.
    public static function validateDate($date, $format = 'Y-m-d')
    {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) == $date;
    }

    // Expands the input into an array. Or not.
    public static function getDateRange($dateRange)
    {
        if ($dateRange != 'All')
            return explode(",", $dateRange);
        else
            return false;
    }

    // Checks to see if the input parameter provided in the get request contains two strings that
    // can be parsed into valid dates. Returns true if they can be, false otherwise.
    public static function validateDateRange($date_param)
    {
        $dateRange = Utils::getDateRange($date_param);
        if ($dateRange){
            if (isset($dateRange[0])){
                if(!Utils::validateDate($dateRange[0])){
                    return false;
                }
            }
            if (isset($dateRange[1])){
                if(!Utils::validateDate($dateRange[1])){
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
    public static function getRowRange($row_param){
        if ($row_param != 'All'){
            $result = explode(",", $row_param);
            $result[0] = (int)$result[0] - 1;
            $result[1] = (int)$result[1];
            return $result;
        }
        else
            return false;
    }

    public static function validateRowRange($row_param) {
        $isValidRowRange = true;
        if ($row_param != 'All'){
            $result = explode(",", $row_param);
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

    public static function plusOneDay($date, $format = 'Y-m-d')
    {
        $d = DateTime::createFromFormat($format, $date);
        //PHP 5 >= 5.2.0
        $d->modify('+1 day');
        return $d->format($format);
    }

    public static function validateClientIp(){
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $remote = $objectManager->get('Magento\Framework\HTTP\PhpEnvironment\Request');
        $remoteIp = $remote->getClientIp();
        print_r($remote);

        // echo($remoteIp . "\n");

        $allowListJson = file_get_contents('../app/code/SearchSpring/Feed/etc/config.json');
        $allowList = json_decode($allowListJson, true)["allowList"];
        
        $isAllowed = false;
        if (in_array($remoteIp, $allowList, true)){
            $isAllowed = true;
        }

        return $isAllowed;
    }
}
