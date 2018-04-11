<?php

namespace Searchspring\Feed\Helper;

use \Magento\Framework\AppInterface as AppInterface;
use \Magento\Framework\App\Http as Http;

use \Magento\Framework\App\Request\Http as RequestHttp;
use \Magento\Framework\App\Response\Http as ResponseHttp;
use \Magento\Framework\App\State as State;

use \Magento\Catalog\Model\Product\Visibility as ProductVisibility;
use \Magento\CatalogInventory\Model\Stock\StockItemRepository as StockItemRepository;
use \Magento\Catalog\Helper\Image as ImageHelper;
use \Magento\Catalog\Model\CategoryRepository as CategoryRepository;
use \Magento\Catalog\Model\Product\OptionFactory as ProductOptionFactory;
use \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use \Magento\Catalog\Model\ResourceModel\Eav\Attribute as AttributeFactory;
use \Magento\CatalogInventory\Api\StockRegistryInterface as StockRegistryInterface;
use \Magento\CatalogInventory\Helper\Stock as StockFilter;

use \Magento\Framework\App\Filesystem\DirectoryList as DirectoryList;

use \Magento\Eav\Model\Config as EavConfig;


use \Magento\Store\Model\StoreManagerInterface as StoreManagerInterface;

use \Magento\Review\Model\RatingFactory as RatingFactory;
use \Magento\ConfigurableProduct\Model\Product\Type\Configurable as Configurable;


class Generator extends \Magento\Framework\App\Helper\AbstractHelper {

    protected $productRecord = array();
    protected $categoryCache = array();
    protected $fields = array();

    protected $objectManager;
    protected $request;
    protected $response;
    protected $state;

    protected $productCollectionFactory;
    protected $productOptionFactory;
    protected $productVisibility;
    protected $stockItemRepository;
    protected $productImageHelper;
    protected $categoryRepository;
    protected $attributeFactory;
    protected $rating;
    protected $stockFilter;
    protected $stockRegistryInterface;

    protected $storeManager;

    protected $storeId;

    protected $count = 100;
    protected $page = 1;

    protected $thumbWidth = 250;
    protected $thumbHeight = 250;
    protected $keepAspectRatio = 1;

    protected $hierarchySeparator = '/';
    protected $multiValuedSeparator = '|';

    protected $filename = '';
    protected $feedPath;

    protected $includeOutOfStock;

    protected $ignoreFields;

    protected $tmpFile;
    protected $tmpFilename;

    public function __construct(
        RequestHttp $request,
        ResponseHttp $response,
        State $state,
        ProductVisibility $productVisibility,
        ProductOptionFactory $productOptionFactory,
        ProductCollectionFactory $productCollectionFactory,
        StockItemRepository $stockItemRepository,
        ImageHelper $productImageHelper,
        CategoryRepository $categoryRepository,
        AttributeFactory $attributeFactory,
        RatingFactory $ratingFactory,
        StockFilter $stockFilter,
        StockRegistryInterface $stockRegistryInterface,
        StoreManagerInterface $storeManager,
        DirectoryList $directoryList,
        EavConfig $eavConfig
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->_state = $state;

        $this->productCollectionFactory = $productCollectionFactory;
        $this->productOptionFactory = $productOptionFactory;
        $this->productVisibility = $productVisibility;
        $this->stockItemRepository = $stockItemRepository;
        $this->productImageHelper = $productImageHelper;
        $this->categoryRepository = $categoryRepository;
        $this->attributeFactory = $attributeFactory;
        $this->rating = $ratingFactory->create();
        $this->stockFilter = $stockFilter;
        $this->stockRegistryInterface = $stockRegistryInterface;
        $this->storeManager = $storeManager;

        $this->productEntityTypeId = $eavConfig->getEntityType(\Magento\Catalog\Model\Product::ENTITY)->getEntityTypeId();

        $this->storeId = $this->request->getParam('store', 'default');
        $this->storeManager->setCurrentStore($this->storeId);

        $this->count = $this->request->getParam('count', 100);
        $this->page = $this->request->getParam('page', 1);

        if($this->page == 0) {
            $this->page = 1;
        }

        $this->thumbWidth  = $this->request->getParam('thumbWidth', 250);
        $this->thumbHeight = $this->request->getParam('thumbHeight', 250);
        $this->keepAspectRatio = $this->request->getParam('keepAspectRatio', 1);

        $this->hierarchySeparator = $this->request->getParam('hierarchySeparator', '/');
        $this->multiValuedSeparator = $this->request->getParam('multiValuedSeparator', '|');

        $this->includeOutOfStock = $this->request->getParam('includeOutOfStock', 0);

        $this->ignoreFields = $this->request->getParam('ignoreFields', array());

        if(!is_array($this->ignoreFields)) {
          throw new \Exception('Ignore fields must be an array. Example: ignoreFields[]=description');
        }

        $filename = $this->request->getParam('filename', '');

        $this->feedPath = $this->request->getParam('path', $directoryList->getPath('media') . '/searchspring');
        $this->tmpFilename = 'searchspring-' . $this->storeId . ($filename ? '-' . $filename : '') . '.tmp.csv';

        if(!is_dir($this->feedPath)) {
            mkdir($this->feedPath, 0755, true);
        }

        // TODO explore using CSV writer built into Magento, it looks like it can only write whole file and not append
        $this->tmpFile = fopen($this->feedPath . '/' . $this->tmpFilename, 'a');
    }

    public function generate()
    {
        $this->getFields();

        if($this->page == 1) {
            $this->writeHeader();
        }

        $collection = $this->getProductCollection();

        foreach($collection as $product) {
            $this->productRecord = array();

            $this->addProductAttributesToRecord($product);
            $this->addChildAttributesToRecord($product);
            $this->addOptionsToRecord($product);
            $this->addThumbnailToRecord($product);
            $this->addStockInfoToRecord($product);
            $this->addCategoriesToRecord($product);
            $this->addRatingsToRecord($product);

            $this->setRecordValue('saleable', $product->isSaleable());
            $this->setRecordValue('final_price', $product->getFinalPrice());
            $this->setRecordValue('url', $product->getProductUrl());

            $this->writeRecord();
        }

        // Check if we're on last page
        if($collection->getSize() <= $this->page * $this->count) {
            // If on last page write feed file and send complete
            $this->moveFeed();
            $this->response->setBody('Complete');
        } else {
            // Else let regenerator know to request next page
            $this->response->setBody('Continue|'. ($this->page+1));
        }
        $this->response->setHttpResponseCode(200);

        fclose($this->tmpFile);
    }

    protected function moveFeed() {
        $filename = 'searchspring-' . $this->storeId . '.csv';
        rename($this->feedPath . '/' . $this->tmpFilename, $this->feedPath . '/' . $filename);
    }



    protected function getProductCollection() {
        $collection = $this->productCollectionFactory->create()
            ->addAttributeToSelect('*')
            // TODO COMMENT, FOR TESTING ONLY
            // ->addAttributeToFilter('entity_id', array('eq' => 2))
            ->setVisibility($this->productVisibility->getVisibleInSiteIds())
            ->addAttributeToFilter(
                'status', array('eq' => \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED)
            )
            ->setPageSize($this->count)
            ->setCurPage($this->page);

        if(!$this->includeOutOfStock) {
            $this->stockFilter->addInStockFilterToCollection($collection);
        }

        return $collection;
    }

    protected function addProductAttributesToRecord($product) {
        $attributes = $product->getAttributes();
        foreach($attributes as $attribute) {
            $code = $attribute->getAttributeCode();
            $value = $this->getProductAttribute($product, $attribute);
            $this->setRecordValue($code, $value);
        }
    }

    protected function getProductAttribute($product, $attribute) {
        $code = $attribute->getAttributeCode();
        if($attribute->usesSource()) {
            $value = $product->getAttributeText($code);
        } else {
            $value = $product->getData($code);
        }

        if(is_object($value)) {
            if($value instanceof \Magento\Framework\Phrase) {
                $value = $value->getText();
            } else {
                throw new \Exception("Unknown value object type " . get_class($value));
            }
        }

        return $value;
    }

    protected function addChildAttributesToRecord($product) {
        if(Configurable::TYPE_CODE === $product->getTypeId()) {
            $childAttributes = array();
            $attributes = $product->getTypeInstance(true)->getConfigurableAttributes($product);
            foreach($attributes as $attribute) {
                $productAttribute = $attribute->getProductAttribute();
                if($productAttribute) {
                    $childAttributes[] = $productAttribute;
                }
            }

            $children = $product->getTypeInstance()->getUsedProducts($product);
            foreach($children as $child) {
                foreach($childAttributes as $childAttribute) {
                    $code = $childAttribute->getAttributeCode();
                    $value = $this->getProductAttribute($child, $childAttribute);
                    $this->setRecordValue($code, $value);
                }

                // NOTE We're not using child_qty anymore as that should be
                // taken care of by saleable. If there is a need adding it here
                // should be easy.

                $this->setRecordValue('child_sku', $child->getSku());
                $this->setRecordValue('child_name', $child->getName());
            }
        }
    }

    protected function addOptionsToRecord($product) {
        $options = $this->productOptionFactory->create()->getProductOptionCollection($product);
        foreach($options as $option) {
            // Add drop down options to data
            if($option->getType() == 'drop_down') {
                // Clean up option title for a field name
                $field = 'option_' . $this->textToFieldName($option->getTitle());
                $values = $option->getValues();
                foreach($values as $value) {
                    $this->setRecordValue($field, $value->getTitle());
                }
            }
        }
    }

    protected function addThumbnailToRecord($product) {
        $this->setRecordValue('cached_thumbnail', $this->getThumbnail($product));
    }

    protected function getThumbnail($product, $type = 'product_thumbnail_image') {
        if($this->keepAspectRatio) {
            $resizedImage = $this->productImageHelper->init($product, $type)
                ->constrainOnly(TRUE)
                ->keepAspectRatio(TRUE)
                ->keepTransparency(TRUE)
                ->keepFrame(FALSE)
                ->resize($this->thumbWidth, $this->thumbHeight)
                ->getUrl();
        } else {
            $resizedImage = $this->productImageHelper->init($product, $type)
                ->resize($this->thumbWidth, $this->thumbHeight)
                ->getUrl();
        }

        return $resizedImage;
    }

    protected function addStockInfoToRecord($product) {
        $stockItem = $this->stockRegistryInterface->getStockItem($product->getId());
        $this->setRecordValue('in_stock', $stockItem->getIsInStock());
        $this->setRecordValue('stock_qty', $stockItem->getQty());
    }

    protected function addCategoriesToRecord($product) {
        $categoryIds = $product->getCategoryIds();
        $categoryNames = array();
        $categoryHierarchy = array();


        foreach($categoryIds as $categoryId) {
            $category = $this->loadCategory($categoryId);

            if(!$category['is_active']) {
                continue;
            }

            // TODO Ignore Root Level?

            $categoryNames[] = $category['name'];
            $categoryHierarchy[] = $category['hierarchy'];
        }

        $this->setRecordValue('categories', $categoryNames);
        $this->setRecordValue('category_ids', $categoryIds);
        $this->setRecordValue('category_hierarchy', $categoryHierarchy);
    }

    protected function loadCategory($categoryId) {
        // TODO Ignore root categories? ex. Root Catalog
        // TODO Use a Magento 2 cache instead of a variable that is cleared on page 1.
        if(!isset($this->categoryCache[$categoryId])) {
            $category = $this->categoryRepository->get($categoryId);
            $categoryName = $category->getName();
            $categoryPath = $category->getPath();

            $levels = explode('/', $categoryPath);
            $categoryHierarchy = array();
            foreach($levels as $level) {
                if($level == $categoryId) {
                    $categoryHierarchy[] = $categoryName;
                } else {
                    $levelCategory = $this->loadCategory($level);
                    $categoryHierarchy[] = $levelCategory['name'];
                }
            }

            $this->categoryCache[$categoryId] = array(
                'name' => $categoryName,
                'hierarchy' => implode($this->hierarchySeparator, $categoryHierarchy),
                'is_active' => $category->getIsActive()
            );
        }

        return $this->categoryCache[$categoryId];

    }

    protected function addRatingsToRecord($product) {
        $rating = $this->rating->getEntitySummary($product->getId(), $this->storeId);
        if($rating && $rating->getCount() > 0) {
            $this->setRecordValue('rating', 5 * ($rating->getSum() / $rating->getCount()/100));
            $this->setRecordValue('rating_count', $rating->getCount());
        }
    }

    protected function getFields() {
        // TODO Cache this in a magento cache instead of building it each time. Clear on page 1.
        $this->fields = array(
            // Core Magento ID Fields
            'entity_id',
            'type_id',
            'attribute_set_id',
            // SearchSpring Generated Fields
            'cached_thumbnail',
            'stock_qty',
            'in_stock',
            'categories',
            'category_hierarchy',
            'saleable',
            'url',
            'final_price',
            'rating',
            'rating_count'
        );

        $attributes = $this->attributeFactory->getCollection();
        $attributes->addFieldToFilter('entity_type_id', $this->productEntityTypeId);
        foreach($attributes as $attribute) {
            $field = $attribute->getAttributeCode();
            $this->fields[] = $field;
        }


        $options = $this->productOptionFactory->create()
                        ->getCollection()
                        ->addTitleToResult($this->storeId);

        foreach($options as $option) {
            $this->fields[] = 'option_' . $this->textToFieldName($option->getTitle());
        }

        // Remove ignored fields
        $this->fields = array_diff($this->fields, $this->ignoreFields);
    }

    protected function textToFieldName($text) {
        return strtolower(preg_replace('/_+/', '_', preg_replace('/[^a-z0-9_]+/i', '_', trim($text))));
    }

    protected function setRecordValue($field, $value) {
        if(in_array($field, $this->ignoreFields)) {
            return;
        }

        // Don't bother adding if the value is empty
        if(is_null($value) || $value == array() || $value == '') {
            return;
        }

        if(!isset($this->productRecord[$field])) {
            $this->productRecord[$field] = array();
        }

        if(!is_array($value)) {
            $this->productRecord[$field][] = $value;
        } else {
            $this->productRecord[$field] = array_merge($this->productRecord[$field], $value);
        }
    }

    protected function writeHeader() {
        fputcsv($this->tmpFile, $this->fields);
    }

    protected function writeRecord() {
        $row = array();
        foreach($this->fields as $field) {
            if(isset($this->productRecord[$field])) {
                $value = $this->productRecord[$field];
                $row[] = implode($this->multiValuedSeparator, array_unique($value));
            } else {
                $row[] = '';
            }
        }
        fputcsv($this->tmpFile, $row);
    }
}

?>
