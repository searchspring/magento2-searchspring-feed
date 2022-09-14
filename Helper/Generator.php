<?php
/**
 * Helper to fetch all data and write feed
 * Copyright (C) 2017  SearchSpring
 * This file is part of SearchSpring/Feed.
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 */

namespace SearchSpring\Feed\Helper;

use Magento\Catalog\Model\Product;

use \Magento\Framework\App\Request\Http as RequestHttp;
use \Magento\Framework\App\Response\Http as ResponseHttp;
use Magento\Framework\Phrase;
use \Magento\Framework\View\Config as ViewConfig;
use \Magento\Catalog\Api\ProductRepositoryInterface as ProductRepositoryInterface;
use \Magento\Catalog\Model\Product\Visibility as ProductVisibility;
use \Magento\Catalog\Helper\Image as ImageHelper;
use \Magento\Catalog\Model\CategoryRepository as CategoryRepository;
use \Magento\Catalog\Model\Product\Gallery\ReadHandler as GalleryReadHandler;
use \Magento\Catalog\Model\Product\OptionFactory as ProductOptionFactory;
use \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use \Magento\Customer\Model\CustomerFactory as CustomerFactory;
use \Magento\Customer\Model\SessionFactory as SessionFactory;
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

class Generator extends \Magento\Framework\App\Helper\AbstractHelper
{
    const CSV_FORMAT  = 'csv';
    const JSON_FORMAT = 'json';

    protected $categoryCache = [];
    protected $fields = [];

    protected $request;
    protected $response;

    protected $productCollectionFactory;
    protected $productOptionFactory;
    protected $productVisibility;
    protected $productImageHelper;
    protected $categoryRepository;
    protected $attributeFactory;
    protected $stockFilter;
    protected $stockRegistryInterface;
    protected $layoutInterface;
    protected $galleryReadHandler;

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

    protected $customerId;

    // Extra image types to include, by default we only include product_thumbnail_image
    protected $imageTypes = [];

    // Add all images to the feed
    protected $includeMediaGallery = false;

    // Fields to load from child products of configurable/grouped products
    protected $childFields = [];

    protected $ignoreFields;

    // Show M2 install info instead of generating feed
    protected $showInfo = false;

    protected $filename = '';
    protected $feedPath;
    protected $feedFormat = self::CSV_FORMAT;

    protected $tmpFile;
    protected $tmpFilename;

    public function __construct(
        RequestHttp $request,
        ResponseHttp $response,
        ProductVisibility $productVisibility,
        ProductOptionFactory $productOptionFactory,
        ProductCollectionFactory $productCollectionFactory,
        CustomerFactory $customerFactory,
        SessionFactory $sessionFactory,
        ProductRepositoryInterface $productRepository,
        ImageHelper $productImageHelper,
        CategoryRepository $categoryRepository,
        AttributeFactory $attributeFactory,
        RatingFactory $ratingFactory,
        StockFilter $stockFilter,
        StockRegistryInterface $stockRegistryInterface,
        LayoutInterface $layoutInterface,
        StoreManagerInterface $storeManager,
        GalleryReadHandler $galleryReadHandler,
        DirectoryList $directoryList,
        EavConfig $eavConfig,
        ViewConfig $viewConfig
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->productOptionFactory = $productOptionFactory;
        $this->productRepository = $productRepository;
        $this->productVisibility = $productVisibility;
        $this->productImageHelper = $productImageHelper;
        $this->categoryRepository = $categoryRepository;
        $this->attributeFactory = $attributeFactory;
        $this->ratingFactory = $ratingFactory;
        $this->stockFilter = $stockFilter;
        $this->stockRegistryInterface = $stockRegistryInterface;
        $this->layoutInterface = $layoutInterface;
        $this->galleryReadHandler = $galleryReadHandler;
        $this->storeManager = $storeManager;
        $this->eavConfig = $eavConfig;
        $this->viewConfig = $viewConfig;

        $this->productEntityTypeId = $this->eavConfig->getEntityType(Product::ENTITY)->getEntityTypeId();
        $this->storeId = $this->request->getParam('store', 'default');
        $this->storeManager->setCurrentStore($this->storeId);

        $this->count = $this->request->getParam('count', 100);
        $this->page = $this->request->getParam('page');

        if (is_null($this->page)) {
            throw new \Exception('Page parameter is required.');
        }

        if ($this->page === '0' || $this->page === 0) {
            $this->page = 1;
        }

        $this->thumbWidth = $this->request->getParam('thumbWidth', 250);
        $this->thumbHeight = $this->request->getParam('thumbHeight', 250);
        $this->keepAspectRatio = $this->request->getParam('keepAspectRatio', 1);

        $this->hierarchySeparator = $this->request->getParam('hierarchySeparator', '/');
        $this->multiValuedSeparator = $this->request->getParam('multiValuedSeparator', '|');
        $this->includeUrlHierarchy = $this->request->getParam('includeUrlHierarchy', 0);

        $this->includeMenuCategories = $this->request->getParam('includeMenuCategories', 0);

        $this->includeJSONConfig = $this->request->getParam('includeJSONConfig', 0);
        $this->includeChildPrices = $this->request->getParam('includeChildPrices', 0);
        $this->includeTierPricing = $this->request->getParam('includeTierPricing', 0);

        $this->customerId = $this->request->getParam('customerId');

        $this->imageTypes = $this->request->getParam('imageTypes', []);

        if (!is_array($this->imageTypes)) {
            throw new \Exception('Image types must be an array. Example: imageTypes[]=product_small_image');
        }

        $this->includeMediaGallery = $this->request->getParam('includeMediaGallery', 0);

        // NOTE: Using this option can greatly reduce generation speed. Since
        // requires loading full products for all child products.
        $this->childFields = $this->request->getParam('childFields', []);

        if (!is_array($this->childFields)) {
            throw new \Exception('Child fields must be an array. Example: childFields[]=color_family');
        }

        $this->includeOutOfStock = $this->request->getParam('includeOutOfStock', 0);

        $this->ignoreFields = $this->request->getParam('ignoreFields', []);

        if (!is_array($this->ignoreFields)) {
            throw new \Exception('Ignore fields must be an array. Example: ignoreFields[]=description');
        }

        $this->showInfo = $this->request->getParam('showInfo', 0);

        $filename = $this->request->getParam('filename', '');

        if (!preg_match('/^[a-z0-9]+$/i', $filename)) {
            throw new \Exception('Invalid filename: ' . $filename);
        }

        $this->feedFormat = $this->request->getParam('format', self::CSV_FORMAT);

        $this->feedPath = $this->request->getParam('path', $directoryList->getPath('media') . '/searchspring');
        $this->tmpFilename = 'searchspring-' . $this->storeId . ($filename ? '-' . $filename : '') . '.tmp.' . $this->feedFormat;

        if (!is_dir($this->feedPath)) {
            mkdir($this->feedPath, 0755, true);
        }

        // TODO explore using CSV writer built into Magento, it looks like it can only write whole file and not append
        $this->tmpFile = fopen($this->feedPath . '/' . $this->tmpFilename, 'a');

        // If a customerId is passed act as a certain user for product/category permissions
        if ($this->customerId) {
            // Load customer based upon ID
            $customer = $customerFactory->create()->load($this->customerId);

            // Create session
            $sessionManager = $sessionFactory->create();

            // Log in as customer
            $sessionManager->setCustomerAsLoggedIn($customer);
        }
    }

    public function generate()
    {
        if ($this->showInfo) {
            $this->displayInfo();
            exit;
        }

        // Only need all fields for CSV format
        if ($this->feedFormat == self::CSV_FORMAT) {
            $this->getFields();

            if ($this->page == 1) {
                $this->writeHeader();
            }
        }

        $collection = $this->getProductCollection();

        foreach ($collection as $product) {
            $productRecord = [];
            Generator::addProductAttributesToRecord($product, $productRecord, $this->ignoreFields);
            $this->addChildAttributesToRecord($product, $productRecord);
            $this->addOptionsToRecord($product, $productRecord);
            $this->addImagesToRecord($product, $productRecord);
            $this->addStockInfoToRecord($product, $productRecord);
            $this->addCategoriesToRecord($product, $productRecord);
            $this->addRatingsToRecord($product, $productRecord);
            $this->addPricesToRecord($product, $productRecord, $this->ignoreFields, $this->includeTierPricing);

            if ($this->includeJSONConfig) {
                $this->addJSONConfig($product, $productRecord);
            }

            Generator::setRecordValue($productRecord, 'saleable', $product->isSaleable(), $this->ignoreFields);
            Generator::setRecordValue($productRecord, 'url', $product->getProductUrl(), $this->ignoreFields);

            $this->writeRecord($productRecord);
        }

        // Check if we're on last page
        if ($collection->getSize() <= $this->page * $this->count) {
            // If on last page write feed file and send complete
            $this->moveFeed();
            $this->response->setBody('Complete');
        } else {
            // Else let regenerator know to request next page
            $this->response->setBody('Continue|' . ($this->page + 1));
        }
        $this->response->setHttpResponseCode(200);

        fclose($this->tmpFile);
    }

    /**
     * Display info
     *
     * @return void
     */
    private function displayInfo()
    {
        $output = '<h1>Stores</h1><ul>';
        $stores = $this->storeManager->getStores();
        foreach ($stores as $store) {
            $name = $store->getName();
            $code = $store->getCode();
            $output .= '<li>' . $name . ' - ' . $code . '</li>';
        }
        $output .= '</ul>';

        $output .= '<h1>Images</h1><ul>';
        $config = $this->viewConfig->getViewConfig()->read();
        foreach ($config['media']['Magento_Catalog']['images'] as $id => $image) {
            $output .= '<li>' . $id . '<ul>';
            foreach ($image as $attr => $val) {
                $output .= '<li>' . $attr . ' = ' . $val . '</li>';
            }
            $output .= '</ul></li>';
        }
        $output .= '</ul>';
        print $output;
    }

    protected function moveFeed()
    {
        $filename = 'searchspring-' . $this->storeId . '.' . $this->feedFormat;
        rename($this->feedPath . '/' . $this->tmpFilename, $this->feedPath . '/' . $filename);
    }

    protected function getProductCollection()
    {
        $collection = $this->productCollectionFactory->create()
            ->addAttributeToSelect('*')
            // TODO COMMENT, FOR TESTING ONLY
            // ->addAttributeToFilter('entity_id', array('eq' => 67))
            ->setVisibility($this->productVisibility->getVisibleInSiteIds())
            ->addAttributeToFilter(
                'status',
                ['eq' => \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED]
            )
            ->setOrder('entity_id', 'ASC')
            ->setPageSize($this->count)
            ->setCurPage($this->page);

        if (!$this->includeOutOfStock) {
            $this->stockFilter->addInStockFilterToCollection($collection);
        }

        return $collection;
    }

    public static function addProductAttributesToRecord($product, &$productRecord, $ignoreFields)
    {
        $attributes = $product->getAttributes();

        foreach ($attributes as $attribute) {
            $code = $attribute->getAttributeCode();
            $value = Generator::getProductAttribute($product, $attribute);
            Generator::setRecordValue($productRecord, $code, $value, $ignoreFields);
        }
    }

    public static function getProductAttribute($product, $attribute)
    {
        $code = $attribute->getAttributeCode();
        if ($attribute->usesSource()) {
            $value = $product->getAttributeText($code);
        } else {
            $value = $product->getData($code);
        }

        if (is_object($value)) {
            if ($value instanceof Phrase) {
                $value = $value->getText();
            } else {
                throw new \Exception('Unknown value object type ' . get_class($value));
            }
        }

        return $value;
    }

    protected function addChildAttributesToRecord($product, &$productRecord)
    {
        if (Configurable::TYPE_CODE === $product->getTypeId()) {
            $childAttributes = [];

            $attributes = $product->getTypeInstance(true)->getConfigurableAttributes($product);
            foreach ($attributes as $attribute) {
                $productAttribute = $attribute->getProductAttribute();
                if ($productAttribute) {
                    $childAttributes[] = $productAttribute;
                }
            }

            foreach ($this->childFields as $attribute) {
                $productAttribute = $this->eavConfig->getAttribute('catalog_product', $attribute);
                if ($productAttribute) {
                    $childAttributes[] = $productAttribute;
                }
            }

            $children = $product->getTypeInstance()->getUsedProductCollection($product);

            // If we're pulling non-configurable attributes we need to load the full child product
            if (sizeof($this->childFields) > 0) {
                $children->addAttributeToSelect('*');
            }

            $children->addAttributeToFilter(
                'status',
                ['eq' => \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED]
            );

            foreach ($children as $child) {
                foreach ($childAttributes as $childAttribute) {
                    $code = $childAttribute->getAttributeCode();
                    $value = $this->getProductAttribute($child, $childAttribute);
                    Generator::setRecordValue($productRecord, $code, $value, $this->ignoreFields);
                }

                // NOTE We're not using child_qty anymore as that should be
                // taken care of by saleable. If there is a need adding it here
                // should be easy.
                Generator::setRecordValue($productRecord, 'child_sku', $child->getSku(), $this->ignoreFields);
                Generator::setRecordValue($productRecord, 'child_name', $child->getName(), $this->ignoreFields);

                if ($this->includeChildPrices) {
                    $price = $child->getPriceInfo()->getPrice('final_price')->getMinimalPrice()->getValue();
                    Generator::setRecordValue($productRecord, 'child_final_price', $price, $this->ignoreFields);
                }
            }
        }

        if (Grouped::TYPE_CODE === $product->getTypeId()) {
            foreach ($this->childFields as $attribute) {
                $productAttribute = $this->eavConfig->getAttribute('catalog_product', $attribute);
                if ($productAttribute) {
                    $childAttributes[] = $productAttribute;
                }
            }

            $children = $product->getTypeInstance()->getAssociatedProducts($product);
            foreach ($children as $child) {
                // If we're pulling non-configurable attributes we need to load the full child product
                if (sizeof($this->childFields) > 0) {
                    $child = $this->productRepository->getById($child->getId());
                    foreach ($childAttributes as $childAttribute) {
                        $code = $childAttribute->getAttributeCode();
                        $value = $this->getProductAttribute($child, $childAttribute);
                        Generator::setRecordValue($productRecord, $code, $value, $this->ignoreFields);
                    }
                }

                Generator::setRecordValue($productRecord, 'child_sku', $child->getSku(), $this->ignoreFields);
                Generator::setRecordValue($productRecord, 'child_name', $child->getName(), $this->ignoreFields);

                if ($this->includeChildPrices) {
                    $price = $child->getPriceInfo()->getPrice('final_price')->getMinimalPrice()->getValue();
                    Generator::setRecordValue($productRecord, 'child_final_price', $price, $this->ignoreFields);
                }
            }
        };
    }

    protected function addOptionsToRecord($product, &$productRecord)
    {
        $options = $this->productOptionFactory->create()->getProductOptionCollection($product);
        foreach ($options as $option) {
            // Add drop down options to data
            if ($option->getType() == 'drop_down') {
                // Clean up option title for a field name
                $field = 'option_' . $this->textToFieldName($option->getTitle());
                $values = $option->getValues();
                if ($values) {
                    foreach ($values as $value) {
                        Generator::setRecordValue($productRecord, $field, $value->getTitle(), $this->ignoreFields);
                    }
                }
            }
        }
    }

    protected function addImagesToRecord($product, &$productRecord)
    {
        Generator::setRecordValue(
            $productRecord,
            'cached_thumbnail',
            $this->getThumbnail($product),
            $this->ignoreFields
        );
        foreach ($this->imageTypes as $type) {
            Generator::setRecordValue(
                $productRecord,
                'cached_' . $type,
                $this->getThumbnail($product, $type),
                $this->ignoreFields
            );
        }

        if ($this->includeMediaGallery) {
            $this->galleryReadHandler->execute($product);
            $images = $product->getMediaGalleryImages();
            $mediaGallery = [];
            foreach ($images as $image) {
                if ($image->getMediaType() == 'image') {
                    $mediaGallery[] = [
                        'label'    => $image->getLabel(),
                        'position' => $image->getPosition(),
                        'disabled' => $image->getDisabled(),
                        'image'    => $this->getThumbnail($product, 'product_thumbnail_image', $image->getFile())
                    ];
                }
            }

            Generator::setRecordValue(
                $productRecord,
                'media_gallery_json',
                json_encode($mediaGallery),
                $this->ignoreFields
            );
        }
    }

    protected function getThumbnail($product, $type = 'product_thumbnail_image', $imageFile = null)
    {
        $imageHelper = $this->productImageHelper->init($product, $type);

        if ($imageFile) {
            $imageHelper->setImageFile($imageFile);
        }

        if ($this->keepAspectRatio) {
            $resizedImage = $imageHelper->constrainOnly(true)
                ->keepAspectRatio(true)
                ->keepTransparency(true)
                ->keepFrame(false)
                ->resize($this->thumbWidth, $this->thumbHeight)
                ->getUrl();
        } else {
            $resizedImage = $imageHelper->resize($this->thumbWidth, $this->thumbHeight)
                ->getUrl();
        }

        return $resizedImage;
    }

    protected function addStockInfoToRecord($product, &$productRecord)
    {
        $stockItem = $this->stockRegistryInterface->getStockItem($product->getId());
        Generator::setRecordValue($productRecord, 'in_stock', $stockItem->getIsInStock(), $this->ignoreFields);
        Generator::setRecordValue($productRecord, 'stock_qty', $stockItem->getQty(), $this->ignoreFields);
    }

    protected function addCategoriesToRecord($product, &$productRecord)
    {
        $categoryIds = $product->getCategoryIds();

        $categoryNames = [];
        $categoryHierarchy = [];
        $menuHierarchy = [];

        if ($this->includeUrlHierarchy) {
            $urlHierarchy = [];
        }

        foreach ($categoryIds as $categoryId) {
            try {
                $category = $this->loadCategory($categoryId);
            } catch (\Exception $e) {
                continue;
            }

            if (!$category['is_active']) {
                continue;
            }

            $categoryNames[] = $category['name'];
            foreach ($category['hierarchy'] as $hierarchy) {
                $categoryHierarchy[] = $hierarchy;
            }

            if ($this->includeMenuCategories && $category['include_menu']) {
                foreach ($category['hierarchy'] as $hierarchy) {
                    $menuHierarchy[] = $hierarchy;
                }
            }

            if ($this->includeUrlHierarchy) {
                foreach ($category['url_hierarchy'] as $url) {
                    $urlHierarchy[] = $url;
                }
            }
        }

        Generator::setRecordValue($productRecord, 'categories', $categoryNames, $this->ignoreFields);
        Generator::setRecordValue($productRecord, 'category_ids', $categoryIds, $this->ignoreFields);
        Generator::setRecordValue(
            $productRecord,
            'category_hierarchy',
            array_unique($categoryHierarchy),
            $this->ignoreFields
        );

        if ($this->includeMenuCategories) {
            Generator::setRecordValue(
                $productRecord,
                'menu_hierarchy',
                array_unique($menuHierarchy),
                $this->ignoreFields
            );
        }

        if ($this->includeUrlHierarchy) {
            Generator::setRecordValue(
                $productRecord,
                'url_hierarchy',
                array_unique($urlHierarchy),
                $this->ignoreFields
            );
        }
    }

    protected function loadCategory($categoryId, $skipLevels = false)
    {
        // TODO Ignore root categories? ex. Root Catalog
        // TODO Use a Magento 2 cache instead of a variable that is cleared on page 1.
        if (!isset($this->categoryCache[$categoryId])) {
            $category = $this->categoryRepository->get($categoryId);
            $categoryName = $category->getName();
            $categoryPath = $category->getPath();

            $categoryHierarchy = [];

            if ($this->includeUrlHierarchy) {
                $categoryUrl = $category->getUrl();
                $urlHierarchy = [];
            }

            if (!$skipLevels) {
                $levels = explode('/', $categoryPath);
                $currentHierarchy = [];
                foreach ($levels as $level) {
                    if ($level == $categoryId) {
                        $currentCategoryName = $categoryName;

                        if ($this->includeUrlHierarchy) {
                            $currentCategoryUrl = $categoryUrl;
                        }
                    } else {
                        $levelCategory = $this->loadCategory($level, true);
                        $currentCategoryName = $levelCategory['name'];

                        if ($this->includeUrlHierarchy) {
                            $currentCategoryUrl = $levelCategory['url'];
                        }
                    }
                    $currentHierarchy[] = $currentCategoryName;
                    $hierarchy = implode($this->hierarchySeparator, $currentHierarchy);
                    $categoryHierarchy[] = $hierarchy;

                    if ($this->includeUrlHierarchy) {
                        $urlHierarchy[] = $hierarchy . '[' . $currentCategoryUrl . ']';
                    }
                }
            }

            $catCache = [
                'name'         => $categoryName,
                'hierarchy'    => $categoryHierarchy,
                'is_active'    => $category->getIsActive(),
                'include_menu' => $category->getIncludeInMenu()
            ];

            if ($this->includeUrlHierarchy) {
                $catCache['url'] = $categoryUrl;
                $catCache['url_hierarchy'] = $urlHierarchy;
            }

            $this->categoryCache[$categoryId] = $catCache;
        }

        return $this->categoryCache[$categoryId];
    }

    protected function addRatingsToRecord($product, &$productRecord)
    {
        $rating = $this->ratingFactory->create()->getEntitySummary($product->getId(), $this->storeId);
        if ($rating && $rating->getCount() > 0) {
            Generator::setRecordValue(
                $productRecord,
                'rating',
                5 * ($rating->getSum() / $rating->getCount() / 100),
                $this->ignoreFields
            );
            Generator::setRecordValue($productRecord, 'rating_count', $rating->getCount(), $this->ignoreFields);
        }
    }

    protected function addJSONConfig($product, &$productRecord)
    {
        if (Configurable::TYPE_CODE === $product->getTypeId()) {
            $configBlock = $this->layoutInterface->createBlock(
                \Magento\ConfigurableProduct\Block\Product\View\Type\Configurable::class
            )->setData(
                'product',
                $product
            );
            Generator::setRecordValue(
                $productRecord,
                'json_config',
                $configBlock->getJsonConfig(),
                $this->ignoreFields
            );

            $swatchBlock = $this->layoutInterface->createBlock(
                \Magento\Swatches\Block\Product\Renderer\Configurable::class
            )->setData(
                'product',
                $product
            );
            Generator::setRecordValue(
                $productRecord,
                'swatch_json_config',
                $swatchBlock->getJsonSwatchConfig(),
                $this->ignoreFields
            );
        }
    }

    public static function addPricesToRecord($product, &$productRecord, $ignoreFields, $includeTierPricing)
    {
        $finalPrice = $product->getPriceInfo()->getPrice('final_price')->getMinimalPrice()->getValue();
        Generator::setRecordValue($productRecord, 'final_price', $finalPrice, $ignoreFields);

        $regularPrice = $product->getPriceInfo()->getPrice('regular_price')->getValue();
        Generator::setRecordValue($productRecord, 'regular_price', $regularPrice, $ignoreFields);

        $maxPrice = $product->getPriceInfo()->getPrice('final_price')->getMaximalPrice()->getValue();
        Generator::setRecordValue($productRecord, 'max_price', $maxPrice, $ignoreFields);

        if ($includeTierPricing) {
            $tierPrice = $product->getTierPrice();
            Generator::setRecordValue($productRecord, 'tier_pricing', json_encode($tierPrice), $ignoreFields);
        }
    }

    protected function getFields()
    {
        // TODO Cache this in a magento cache instead of building it each time. Clear on page 1.
        $this->fields = [
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
            'regular_price',
            'max_price',
            'rating',
            'rating_count',
            'child_sku',
            'child_name'
        ];

        if ($this->includeMenuCategories) {
            $this->fields[] = 'menu_hierarchy';
        }

        if ($this->includeUrlHierarchy) {
            $this->fields[] = 'url_hierarchy';
        }

        if ($this->includeChildPrices) {
            $this->fields[] = 'child_final_price';
        }

        if ($this->includeJSONConfig) {
            $this->fields[] = 'json_config';
            $this->fields[] = 'swatch_json_config';
        }

        if ($this->includeTierPricing) {
            $this->fields[] = 'tier_pricing';
        }

        if ($this->includeMediaGallery) {
            $this->fields[] = 'media_gallery_json';
        }

        foreach ($this->imageTypes as $type) {
            $this->fields[] = 'cached_' . $type;
        }

        $attributes = $this->attributeFactory->getCollection();
        $attributes->addFieldToFilter('entity_type_id', $this->productEntityTypeId);
        foreach ($attributes as $attribute) {
            $field = $attribute->getAttributeCode();
            $this->fields[] = $field;
        }

        $options = $this->productOptionFactory->create()
            ->getCollection()
            ->addTitleToResult($this->storeId);

        foreach ($options as $option) {
            $this->fields[] = 'option_' . $this->textToFieldName($option->getTitle());
        }

        // Remove ignored fields
        $this->fields = array_unique(array_diff($this->fields, $this->ignoreFields));
    }

    protected function textToFieldName($text)
    {
        return strtolower(preg_replace('/_+/', '_', preg_replace('/[^a-z0-9_]+/i', '_', trim($text))));
    }

    /**
     * @param $productRecord
     * @param $field
     * @param $value
     * @param $ignoreFields
     *
     * @return void
     */
    public static function setRecordValue(&$productRecord, $field, $value, $ignoreFields)
    {
        if (in_array($field, $ignoreFields)) {
            return;
        }

        if ($value == [] || $value == '') {
            return;
        }

        if (!isset($productRecord[$field])) {
            $productRecord[$field] = [];
        }

        if (!is_array($value)) {
            $productRecord[$field][] = $value;
        } else {
            $productRecord[$field] = array_merge($productRecord[$field], $value);
        }
    }

    protected function writeHeader()
    {
        fputcsv($this->tmpFile, $this->fields);
    }

    protected function writeRecord($productRecord)
    {
        if ($this->feedFormat == self::CSV_FORMAT) {
            $this->writeCsvRecord($productRecord);
        } else {
            if ($this->feedFormat == self::JSON_FORMAT) {
                $this->writeJsonRecord($productRecord);
            }
        }
    }

    protected function writeJsonRecord($productRecord)
    {
        foreach ($this->ignoreFields as $field) {
            unset($this->productRecord[$field]);
        }

        fwrite($this->tmpFile, json_encode($productRecord) . "\n");
    }

    protected function writeCsvRecord($productRecord)
    {
        $row = [];
        foreach ($this->fields as $field) {
            if (isset($productRecord[$field])) {
                $value = $productRecord[$field];
                // If value is an array of arrays or objects then json encode value
                if (is_array(current($value)) || is_object(current($value))) {
                    $row[] = json_encode($value);
                } else {
                    $row[] = implode($this->multiValuedSeparator, array_unique($value));
                }
            } else {
                $row[] = '';
            }
        }

        // Start custom CSV write to handle escaped JSON
        $delimiter = ',';
        $delimiter_esc = preg_quote($delimiter, '/');
        $enclosure = '"';
        $enclosure_esc = preg_quote($enclosure, '/');

        $output = [];
        foreach ($row as $field) {
            $output[] = preg_match("/(?:${delimiter_esc}|${enclosure_esc}|\s)/", $field) ? (
                $enclosure . str_replace($enclosure, $enclosure . $enclosure, $field) . $enclosure
            ) : $field;
        }

        fwrite($this->tmpFile, join($delimiter, $output) . "\n");
        // End custom CSV write
    }
}
