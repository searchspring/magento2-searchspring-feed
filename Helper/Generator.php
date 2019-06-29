<?php
/**
 * Helper to fetch all data and write feed
 * Copyright (C) 2017  SearchSpring
 *
 * This file is part of SearchSpring/Feed.
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 */

namespace SearchSpring\Feed\Helper;

use \Magento\Framework\AppInterface as AppInterface;
use \Magento\Framework\App\Http as Http;

use \Magento\Framework\App\Request\Http as RequestHttp;
use \Magento\Framework\App\Response\Http as ResponseHttp;
use \Magento\Framework\App\State as State;

use \Magento\Catalog\Api\ProductRepositoryInterface as ProductRepositoryInterface;
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

use \Magento\Framework\View\LayoutInterface as LayoutInterface;

use \Magento\Eav\Model\Config as EavConfig;

use \Magento\Store\Model\StoreManagerInterface as StoreManagerInterface;

use \Magento\Review\Model\RatingFactory as RatingFactory;

use \Magento\ConfigurableProduct\Model\Product\Type\Configurable as Configurable;
use \Magento\GroupedProduct\Model\Product\Type\Grouped as Grouped;

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
    protected $productRepositoryInterface;
    protected $productVisibility;
    protected $stockItemRepository;
    protected $productImageHelper;
    protected $categoryRepository;
    protected $attributeFactory;
    protected $rating;
    protected $stockFilter;
    protected $stockRegistryInterface;
    protected $layoutInterface;

    protected $storeManager;

    protected $storeId;

    protected $count = 100;
    protected $page = 1;

    protected $thumbWidth = 250;
    protected $thumbHeight = 250;
    protected $keepAspectRatio = 1;

    protected $hierarchySeparator = '/';
    protected $multiValuedSeparator = '|';
    protected $includeUrlHierarchy = false;

    protected $includeMenuCategories = false;

    protected $includeOutOfStock = false;

    protected $includeJSONConfig = false;
    protected $includeChildPrices = false;
    protected $includeTierPricing = false;

    // Extra image types to include, by default we only include product_thumbnail_image
    protected $imageTypes = array();

    // Fields to load from child products of configurable/grouped products
    protected $childFields = array();

    protected $ignoreFields;

    protected $filename = '';
    protected $feedPath;

    protected $tmpFile;
    protected $tmpFilename;

    public function __construct(
        RequestHttp $request,
        ResponseHttp $response,
        State $state,
        ProductVisibility $productVisibility,
        ProductOptionFactory $productOptionFactory,
        ProductCollectionFactory $productCollectionFactory,
        ProductRepositoryInterface $productRepository,
        StockItemRepository $stockItemRepository,
        ImageHelper $productImageHelper,
        CategoryRepository $categoryRepository,
        AttributeFactory $attributeFactory,
        RatingFactory $ratingFactory,
        StockFilter $stockFilter,
        StockRegistryInterface $stockRegistryInterface,
        LayoutInterface $layoutInterface,
        StoreManagerInterface $storeManager,
        DirectoryList $directoryList,
        EavConfig $eavConfig
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->_state = $state;

        $this->productCollectionFactory = $productCollectionFactory;
        $this->productOptionFactory = $productOptionFactory;
        $this->productRepository = $productRepository;
        $this->productVisibility = $productVisibility;
        $this->stockItemRepository = $stockItemRepository;
        $this->productImageHelper = $productImageHelper;
        $this->categoryRepository = $categoryRepository;
        $this->attributeFactory = $attributeFactory;
        $this->ratingFactory = $ratingFactory;
        $this->stockFilter = $stockFilter;
        $this->stockRegistryInterface = $stockRegistryInterface;
        $this->layoutInterface = $layoutInterface;

        $this->storeManager = $storeManager;

        $this->eavConfig = $eavConfig;

        $this->productEntityTypeId = $this->eavConfig->getEntityType(\Magento\Catalog\Model\Product::ENTITY)->getEntityTypeId();

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
        $this->includeUrlHierarchy = $this->request->getParam('includeUrlHierarchy', 0);

        $this->includeMenuCategories = $this->request->getParam('includeMenuCategories', 0);

        $this->includeJSONConfig = $this->request->getParam('includeJSONConfig', 0);
        $this->includeChildPrices = $this->request->getParam('includeChildPrices', 0);
        $this->includeTierPricing = $this->request->getParam('includeTierPricing', 0);

        $this->imageTypes = $this->request->getParam('imageTypes', array());

        if(!is_array($this->imageTypes)) {
          throw new \Exception('Image types must be an array. Example: imageTypes[]=product_small_image');
        }

        // NOTE: Using this option can greatly reduce generation speed. Since
        // requires loading full products for all child products.
        $this->childFields = $this->request->getParam('childFields', array());

        if(!is_array($this->childFields)) {
          throw new \Exception('Child fields must be an array. Example: childFields[]=color_family');
        }

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
            $this->addImagesToRecord($product);
            $this->addStockInfoToRecord($product);
            $this->addCategoriesToRecord($product);
            $this->addRatingsToRecord($product);
            $this->addPricesToRecord($product);

            if($this->includeJSONConfig) {
                $this->addJSONConfig($product);
            }

            $this->setRecordValue('saleable', $product->isSaleable());
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
            ->addAttributeToFilter('entity_id', array('eq' => 67))
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

            foreach($this->childFields as $attribute) {
                $productAttribute = $this->eavConfig->getAttribute("catalog_product", $attribute);
                if($productAttribute) {
                    $childAttributes[] = $productAttribute;
                }
            }

            $children = $product->getTypeInstance()->getUsedProducts($product);
            foreach($children as $child) {
                // If we're pulling non-configurable attributes we need to load the full child product
                if(sizeof($this->childFields) > 0) {
                    $child = $this->productRepository->getById($child->getId());
                }

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

                if($this->includeChildPrices) {
                    $price = $child->getPriceInfo()->getPrice('final_price')->getMinimalPrice()->getValue();
                    $this->setRecordValue('child_final_price', $price);
                }
            }
        }

        if(Grouped::TYPE_CODE === $product->getTypeId()) {
            foreach($this->childFields as $attribute) {
                $productAttribute = $this->eavConfig->getAttribute("catalog_product", $attribute);
                if($productAttribute) {
                    $childAttributes[] = $productAttribute;
                }
            }

            $children = $product->getTypeInstance()->getAssociatedProducts($product);
            foreach($children as $child) {
                // If we're pulling non-configurable attributes we need to load the full child product
                if(sizeof($this->childFields) > 0) {
                    $child = $this->productRepository->getById($child->getId());
                    foreach($childAttributes as $childAttribute) {
                        $code = $childAttribute->getAttributeCode();
                        $value = $this->getProductAttribute($child, $childAttribute);
                        $this->setRecordValue($code, $value);
                    }
                }

                $this->setRecordValue('child_sku', $child->getSku());
                $this->setRecordValue('child_name', $child->getName());

                if($this->includeChildPrices) {
                    $price = $child->getPriceInfo()->getPrice('final_price')->getMinimalPrice()->getValue();
                    $this->setRecordValue('child_final_price', $price);
                }
            }
        };
    }

    protected function addOptionsToRecord($product) {
        $options = $this->productOptionFactory->create()->getProductOptionCollection($product);
        foreach($options as $option) {
            // Add drop down options to data
            if($option->getType() == 'drop_down') {
                // Clean up option title for a field name
                $field = 'option_' . $this->textToFieldName($option->getTitle());
                $values = $option->getValues();
                if($values) {
                    foreach($values as $value) {
                        $this->setRecordValue($field, $value->getTitle());
                    }
                }
            }
        }
    }

    protected function addImagesToRecord($product) {
        $this->setRecordValue('cached_thumbnail', $this->getThumbnail($product));
        foreach($this->imageTypes as $type) {
            $this->setRecordValue('cached_'.$type, $this->getThumbnail($product, $type));
        }
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
        $menuHierarchy = array();

        if($this->includeUrlHierarchy) {
            $urlHierarchy = array();
        }

        foreach($categoryIds as $categoryId) {
            try {
                $category = $this->loadCategory($categoryId);
            } catch (\Exception $e) {
                continue;
            }

            if(!$category['is_active']) {
                continue;
            }

            $categoryNames[] = $category['name'];
            foreach($category['hierarchy'] as $hierarchy) {
                $categoryHierarchy[] = $hierarchy;
            }

            if($this->includeMenuCategories && $category['include_menu']) {
                foreach($category['hierarchy'] as $hierarchy) {
                    $menuHierarchy[] = $hierarchy;
                }
            }

            if($this->includeUrlHierarchy) {
                foreach($category['url_hierarchy'] as $url) {
                    $urlHierarchy[] = $url;
                }
            }
        }

        var_dump($categoryNames);
        $this->setRecordValue('categories', $categoryNames);
        $this->setRecordValue('category_ids', $categoryIds);
        $this->setRecordValue('category_hierarchy', array_unique($categoryHierarchy));

        if($this->includeMenuCategories) {
            $this->setRecordValue('menu_hierarchy', array_unique($menuHierarchy));
        }

        if($this->includeUrlHierarchy) {
            $this->setRecordValue('url_hierarchy', array_unique($urlHierarchy));
        }
    }

    protected function loadCategory($categoryId, $skipLevels = false) {
        // TODO Ignore root categories? ex. Root Catalog
        // TODO Use a Magento 2 cache instead of a variable that is cleared on page 1.
        if(!isset($this->categoryCache[$categoryId])) {
            $category = $this->categoryRepository->get($categoryId);
            $categoryName = $category->getName();
            $categoryPath = $category->getPath();

            $categoryHierarchy = array();

            if($this->includeUrlHierarchy) {
                $categoryUrl = $category->getUrl();
                $urlHierarchy = array();
            }

            if(!$skipLevels) {
                $levels = explode('/', $categoryPath);
                $currentHierarchy = array();
                foreach($levels as $level) {
                    if($level == $categoryId) {
                        $currentCategoryName = $categoryName;

                        if($this->includeUrlHierarchy) {
                            $currentCategoryUrl = $categoryUrl;
                        }
                    } else {
                        $levelCategory = $this->loadCategory($level, true);
                        $currentCategoryName = $levelCategory['name'];

                        if($this->includeUrlHierarchy) {
                            $currentCategoryUrl = $levelCategory['url'];
                        }
                    }
                    $currentHierarchy[] = $currentCategoryName;
                    $hierarchy = implode($this->hierarchySeparator, $currentHierarchy);
                    $categoryHierarchy[] = $hierarchy;

                    if($this->includeUrlHierarchy) {
                        $urlHierarchy[] = $hierarchy . '[' . $currentCategoryUrl . ']';
                    }
                }
            }

            $catCache = array(
                'name' => $categoryName,
                'hierarchy' => $categoryHierarchy,
                'is_active' => $category->getIsActive(),
                'include_menu' => $category->getIncludeInMenu()
            );

            if($this->includeUrlHierarchy) {
                $catCache['url'] = $categoryUrl;
                $catCache['url_hierarchy'] = $urlHierarchy;
            }

            $this->categoryCache[$categoryId] = $catCache;
        }

        return $this->categoryCache[$categoryId];
    }

    protected function addRatingsToRecord($product) {
        $rating = $this->ratingFactory->create()->getEntitySummary($product->getId(), $this->storeId);
        if($rating && $rating->getCount() > 0) {
            $this->setRecordValue('rating', 5 * ($rating->getSum() / $rating->getCount()/100));
            $this->setRecordValue('rating_count', $rating->getCount());
        }
    }

    protected function addJSONConfig($product) {
        if(Configurable::TYPE_CODE === $product->getTypeId()) {
            $configBlock = $this->layoutInterface->createBlock("\Magento\ConfigurableProduct\Block\Product\View\Type\Configurable")->setData('product', $product);
            $this->setRecordValue('json_config', $configBlock->getJsonConfig());

            $swatchBlock = $this->layoutInterface->createBlock("\Magento\Swatches\Block\Product\Renderer\Configurable")->setData('product', $product);
            $this->setRecordValue('swatch_json_config', $swatchBlock->getJsonSwatchConfig());
        }
    }

    protected function addPricesToRecord($product) {
        $price = $product->getPriceInfo()->getPrice('final_price')->getMinimalPrice()->getValue();
        $this->setRecordValue('final_price', $price);

        if($this->includeTierPricing) {
            $tierPrice = $product->getTierPrice();
            $this->setRecordValue('tier_pricing', json_encode($tierPrice));

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
            'rating_count',
            'child_sku',
            'child_name'
        );

        if($this->includeMenuCategories) {
            $this->fields[] = 'menu_hierarchy';
        }

        if($this->includeUrlHierarchy) {
            $this->fields[] = 'url_hierarchy';
        }

        if($this->includeChildPrices) {
            $this->fields[] = 'child_final_price';
        }

        if($this->includeJSONConfig) {
            $this->fields[] = 'json_config';
            $this->fields[] = 'swatch_json_config';
        }

        if($this->includeTierPricing) {
            $this->fields[] = 'tier_pricing';
        }

        foreach($this->imageTypes as $type) {
            $this->fields[] = 'cached_'.$type;
        }

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
                // If value is an array of arrays or objects then json encode value
                if(is_array(current($value)) || is_object(current($value))) {
                    $row[]  = json_encode($value);
                } else {
                    $row[] = implode($this->multiValuedSeparator, array_unique($value));
                }
            } else {
                $row[] = '';
            }
        }

        // Start custom CSV write to handle escaped JSON
        $delimiter = ",";
        $delimiter_esc = preg_quote($delimiter, '/');
        $enclosure = '"';
        $enclosure_esc = preg_quote($enclosure, '/');

        $output = array();
        foreach ($row as $field) {
            $output[] = preg_match("/(?:${delimiter_esc}|${enclosure_esc}|\s)/", $field) ? (
                $enclosure . str_replace($enclosure, $enclosure . $enclosure, $field) . $enclosure
            ) : $field;
        }

        fwrite($this->tmpFile, join($delimiter, $output) . "\n");
        // End custom CSV write
    }
}

?>
