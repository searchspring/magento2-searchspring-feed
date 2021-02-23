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
use \Magento\Framework\View\Config as ViewConfig;

use \Magento\Catalog\Api\ProductRepositoryInterface as ProductRepositoryInterface;
use \Magento\Catalog\Model\Product\Visibility as ProductVisibility;
use \Magento\CatalogInventory\Model\Stock\StockItemRepository as StockItemRepository;
use \Magento\Catalog\Helper\Image as ImageHelper;
use \Magento\Catalog\Model\CategoryRepository as CategoryRepository;
use \Magento\Catalog\Model\Product\Gallery\ReadHandler as GalleryReadHandler;
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
    const CSV_FORMAT = 'csv';
    const JSON_FORMAT = 'json';
    
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

    // Extra image types to include, by default we only include product_thumbnail_image
    protected $imageTypes = array();

    // Add all images to the feed
    protected $includeMediaGallery = false;

    // Fields to load from child products of configurable/grouped products
    protected $childFields = array();

    protected $ignoreFields;
    protected $skipFields;


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
        GalleryReadHandler $galleryReadHandler,
        DirectoryList $directoryList,
        EavConfig $eavConfig,
        ViewConfig $viewConfig
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
        $this->galleryReadHandler = $galleryReadHandler;

        $this->storeManager = $storeManager;

        $this->eavConfig = $eavConfig;
        $this->viewConfig = $viewConfig;

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

        $this->includeMediaGallery = $this->request->getParam('includeMediaGallery', 0);

        // NOTE: Using this option can greatly reduce generation speed. Since
        // requires loading full products for all child products.
        $this->childFields = $this->request->getParam('childFields', array());

        if(!is_array($this->childFields)) {
          throw new \Exception('Child fields must be an array. Example: childFields[]=color_family');
        }

        $this->includeOutOfStock = $this->request->getParam('includeOutOfStock', 0);

        $this->ignoreFields = $this->request->getParam('ignoreFields', array());
        
        $this->skipFields = array(
            "accuracy",
            "accuracy_gauge",
            "accuracy_oximetry",
            "accuracy_pulse",
            "accuracy_thermometry",
            "acquisition_data",
            "acquisition_data_time",
            "adwords_catch",
            "adwords_label",
            "alarms_device",
            "allowed_to_quotemode",
            "allow_backorder",
            "allow_message",
            "allow_open_amount",
            "ameds_latex_normalized",
            "ameds_reusable",
            "ameds_shape_normalized",
            "amtoolkit_canonical",
            "amtoolkit_robots",
            "arm_height_floor_back",
            "arm_height_floor_front",
            "arm_width",
            "attribute_set_id",
            "autoclavable",
            "automatic_operation",
            "avatax_cross_border_type",
            "average_calculation",
            "backorder_text",
            "back_height_floor",
            "back_height_seat",
            "batch_id",
            "best_price_gurantee",
            "biomedical_service_msg",
            "capacity_memory",
            "casters",
            "casters_number",
            "category_ids",
            "ceq_assembly",
            "ceq_brochure_visualized",
            "ceq_color_normalized",
            "ceq_compatibility",
            "ceq_compliance",
            "ceq_dropship",
            "ceq_dropship_fee",
            "ceq_excludes",
            "ceq_featured",
            "ceq_gsa_sin",
            "ceq_gsa_visualized",
            "ceq_includes",
            "ceq_includes_kit",
            "ceq_manufacturer",
            "ceq_manufacturer_block",
            "ceq_manufacturer_code",
            "ceq_min_order_amount",
            "ceq_min_order_fee",
            "ceq_options",
            "ceq_physician_sale",
            "ceq_shipping_ca",
            "ceq_shipping_method",
            "ceq_shipping_ri",
            "ceq_shipping_weight",
            "ceq_size_normalized",
            "ceq_style",
            "ceq_swatches",
            "ceq_type",
            "ceq_uom_purchase",
            "ceq_upholstery_type_normalized",
            "ceq_vendor",
            "ceq_vendor_account",
            "ceq_vendor_block",
            "ceq_vendor_code",
            "ceq_video_visualized",
            "ceq_warranty",
            "certification",
            "color",
            "color_1",
            "color_2",
            "color_base",
            "color_door_drawer",
            "color_laminate",
            "color_normalized",
            "color_upholstery",
            "combatible_with",
            "connection_patient",
            "connection_wall",
            "connectivity",
            "contract",
            "cost",
            "cost_effective_date",
            "cost_last",
            "cost_medassets",
            "cost_tier_price",
            "country_of_manufacture",
            "country_of_origin",
            "created_at",
            "cse_inclusion",
            "cse_select",
            "custom_design",
            "custom_design_from",
            "custom_design_to",
            "custom_layout",
            "custom_layout_update",
            "custom_layout_update_file",
            "custom_specs",
            "deflate_auto",
            "depth",
            "depth_of_field",
            "description_gsa",
            "description_quote_tool",
            "description_raw",
            "detection_distance",
            "determination_bp",
            "device_bp",
            "dial_size",
            "diameter",
            "diameter_pattern",
            "dimensions",
            "dimensions_back",
            "dimensions_base",
            "dimensions_cabinet",
            "dimensions_case",
            "dimensions_chamber",
            "dimensions_detail",
            "dimensions_drawers",
            "dimensions_footrest",
            "dimensions_interior",
            "dimensions_pan",
            "dimensions_screen",
            "dimensions_seat",
            "dimensions_shelf",
            "dimensions_step",
            "dimensions_tray",
            "dimensions_upholstery",
            "dimensions_worksurface",
            "disable_image_cropping",
            "display",
            "display_cuff",
            "display_oximetry",
            "distance_working",
            "distortion",
            "downloads_title",
            "drawers_number",
            "dropdown_1",
            "dropdown_2",
            "dropdown_3",
            "dropdown_4",
            "dropdown_5",
            "duration_tone",
            "electrical_amps",
            "email_template",
            "emi_rfi_protection",
            "environment_operating",
            "erp_freight_string",
            "filters",
            "flow_rate",
            "format_monitoring",
            "format_printing",
            "fowler",
            "freight_class",
            "frequency",
            "functions",
            "gallery",
            "ga_items_per_purchase",
            "ga_item_quantity",
            "ga_item_revenue",
            "ga_product_revenue_per_purchase",
            "giftcard_amounts",
            "giftcard_type",
            "gift_message_available",
            "gift_wrapping_available",
            "gift_wrapping_price",
            "gov_clp_dapa",
            "gov_clp_dapa_discount",
            "gov_clp_dapa_discount_orig",
            "gov_clp_dapa_orig",
            "gov_clp_ecat",
            "gov_clp_ecat_discount",
            "gov_clp_ecat_discount_orig",
            "gov_clp_ecat_orig",
            "gov_clp_fss",
            "gov_clp_fss_orig",
            "gov_clp_fss_ratio",
            "gov_clp_fss_ratio_orig",
            "gov_index_no",
            "gov_sale_only",
            "go_green",
            "group_allow_quotemode",
            "group_price",
            "gtin",
            "head_ophthalmoscope",
            "height",
            "height_rail",
            "height_seat",
            "heparin_sensitivity",
            "homepage_product_carousel",
            "homepage_product_carousel_sort",
            "hom_functions",
            "humidity",
            "humidity_operating",
            "illuminance",
            "iluminance",
            "image",
            "image_label",
            "input_battery",
            "interference",
            "internal_child_id",
            "internal_parent_id",
            "internal_profitability_class",
            "interpretation",
            "in_erp",
            "in_gsa",
            "isi",
            "is_biomedical_service",
            "is_interpretive",
            "is_recurring",
            "is_redeemable",
            "is_returnable",
            "item_class",
            "item_condition",
            "item_updated_date",
            "item_verified_date",
            "lead_sensitivity",
            "length",
            "length_cable",
            "length_leg_extension",
            "lense_filter",
            "lense_number",
            "lifetime",
            "life_battery",
            "light_field",
            "links_exist",
            "links_purchased_separately",
            "links_title",
            "logistics_visualized",
            "magnification_lens",
            "material_blade",
            "material_handle",
            "maximum_altitude",
            "measurement",
            "measurements_pulse",
            "measurements_systolic",
            "measurement_diastolic",
            "measurement_range_oximetry",
            "measurement_repeatability",
            "measurement_systolic",
            "measurement_time_oximetry",
            "media_gallery",
            "memory",
            "merchandised_date",
            "merchant_center_category",
            "meta_description",
            "meta_keyword",
            "meta_title",
            "method_measurement",
            "method_thermometry",
            "minimal_price",
            "model_ref",
            "mpn_out",
            "mpn_out_ref",
            "mpn_ref",
            "msrp_display_actual_price_type",
            "msrp_enabled",
            "must_ship_freight",
            "name_alternate_bundled",
            "name_alternate_external",
            "name_alternate_grouped",
            "name_alternate_gsa",
            "name_alternate_quote",
            "nebulization_rate",
            "news_from_date",
            "news_to_date",
            "not_included",
            "number_apertures",
            "number_bulbs",
            "number_shelves",
            "nurse_calling",
            "old_id",
            "old_product_id",
            "old_sku",
            "open_amount_max",
            "open_amount_min",
            "operating_temperature",
            "optics",
            "option_budget",
            "option_color_s",
            "option_description",
            "option_desired_date_of_delivery",
            "option_mount",
            "option_name",
            "option_placement",
            "option_please_describe_what_you_are_looking_for",
            "option_product_image",
            "option_sku",
            "option_subject",
            "option_upload_additional_file_s",
            "option_warranty",
            "overall_length_seated",
            "overall_length_sleep",
            "page_layout",
            "parameters",
            "patient_surface",
            "period_recording",
            "platform_size",
            "policy_price_match",
            "population",
            "ports_usb",
            "power",
            "power_back",
            "power_cord",
            "power_cord_length",
            "power_supply",
            "power_switch",
            "prediction",
            "price",
            "pricecalcrule_labels",
            "pricecalcrule_last_calculation_date",
            "pricecalcrule_last_calculation_result",
            "pricecalcrule_rules_applied",
            "price_calc_rule",
            "price_gsa_calculated",
            "price_gsa_commercial_imported",
            "price_gsa_compliance_margin_imported",
            "price_gsa_imported",
            "price_key",
            "price_last",
            "price_override",
            "price_override_reason",
            "price_type",
            "print_speed",
            "product_care",
            "product_condition",
            "product_manager",
            "pulse",
            "quantity_and_stock_status",
            "quotemode_conditions",
            "quote_category",
            "quote_category_position",
            "quote_verified",
            "quote_verified_date",
            "quote_verified_manager",
            "ramp_size",
            "range",
            "range_altitude",
            "range_angle_back",
            "range_angle_footrest",
            "range_angle_seat",
            "range_arterial",
            "range_cuff",
            "range_diastolic",
            "range_diopter",
            "range_flow",
            "range_frequency",
            "range_gauge",
            "range_heart",
            "range_height",
            "range_length",
            "range_measurement",
            "range_oximetry",
            "range_pulse",
            "range_systolic",
            "range_thermometry",
            "range_volume",
            "rate_sampling",
            "recurring_profile",
            "regular_price",
            "related_tgtr_position_behavior",
            "related_tgtr_position_limit",
            "reproductibility",
            "request_demo",
            "request_sample",
            "required_options",
            "required_software",
            "requires_prescription",
            "resolution",
            "restocking_fees",
            "returns_tc",
            "return_policy",
            "rotor",
            "rule_applied_date",
            "saleable",
            "samples_title",
            "sa_alternate_parent_id",
            "seat_capacity",
            "seat_depth",
            "seat_width",
            "sensor",
            "shipment_type",
            "shipperhq_declared_value",
            "shipperhq_handling_fee",
            "shipperhq_nmfc_class",
            "shipperhq_poss_boxes",
            "shipperhq_shipping_fee",
            "shipperhq_shipping_group",
            "shipperhq_volume_weight",
            "shipperhq_warehouse",
            "shipperhq_availability_date",
            "shipperhq_dim_group",
            "shipperhq_hs_code",
            "shipperhq_malleable_product",
            "shipperhq_master_boxes",
            "shipperhq_nmfc_sub",
            "shipping_cost",
            "shipping_cost_last",
            "shipping_dimensions",
            "shipping_height",
            "shipping_length",
            "shipping_messaging",
            "shipping_messaging_custom",
            "shipping_type",
            "shipping_width",
            "shipping_zip_origin",
            "ship_separately",
            "ship_height",
            "ship_length",
            "ship_width",
            "size_aperature",
            "size_blade",
            "size_caster",
            "size_commercial",
            "size_consumer",
            "size_countertop",
            "size_cuff",
            "size_diaphram",
            "size_filter_consumer",
            "size_fits",
            "size_sample",
            "size_spot",
            "sku_alternative",
            "sku_type",
            "small_image",
            "small_image_label",
            "source_light",
            "source_power",
            "specialty",
            "special_from_date",
            "special_order",
            "special_price",
            "special_price_gsa",
            "special_to_date",
            "specifications_diagnostic",
            "specifications_handle",
            "specifications_otoscope",
            "specula",
            "stability",
            "status",
            "stock_qty",
            "storage_transport",
            "swatch_image",
            "systolic",
            "tax_class_id",
            "temp_color",
            "temp_operating",
            "temp_safe",
            "temp_storage",
            "tentative_shipping_date",
            "terendelenburg_depth",
            "thermometer_optional",
            "thickness_seat",
            "thumbnail",
            "thumbnail_label",
            "tier_price",
            "time",
            "time_between_tones",
            "time_bp",
            "time_charging",
            "time_measurement",
            "time_on",
            "time_rise_fall",
            "time_sterilization",
            "time_thermometry",
            "title_rewrite",
            "trays_number",
            "tray_mouse",
            "trendelenburg",
            "trendelenburg_reverse",
            "ts_country_of_origin",
            "ts_dimensions_height",
            "ts_dimensions_length",
            "ts_dimensions_width",
            "ts_hs_code",
            "ts_packaging_id",
            "ts_packaging_type",
            "tubing",
            "type_armrest",
            "type_battery",
            "type_bell",
            "type_blade",
            "type_bulb",
            "type_casing",
            "type_caster",
            "type_chamber",
            "type_cooling_system",
            "type_cuff",
            "type_diaphram",
            "type_display",
            "type_door",
            "type_handle",
            "type_illumination",
            "type_lift",
            "type_lock",
            "type_memory",
            "type_mount",
            "type_ophthalmoscope",
            "type_otoscope",
            "type_oximetry",
            "type_plug",
            "type_power",
            "type_report",
            "type_sample",
            "type_test",
            "type_upholstery",
            "udropship_calculate_rates",
            "udropship_vendor",
            "uom",
            "uom_factor",
            "uom_in",
            "upc",
            "updated_at",
            "updated_date",
            "upsell_tgtr_position_behavior",
            "upsell_tgtr_position_limit",
            "url_key",
            "url_path",
            "use_config_allow_message",
            "use_config_email_template",
            "use_config_is_redeemable",
            "use_config_lifetime",
            "use_simple_product_pricing",
            "vendor_parent_id",
            "vendor_profitability_class",
            "video_embed_type_me",
            "video_me",
            "view_field",
            "voltage",
            "volume_chamber",
            "volume_normalized",
            "warranty_tc",
            "weight_type",
            "well_compatible",
            "weltpixel_exclude_from_sitemap",
            "weltpixel_hover_image",
            "wheels",
            "width",
            "width_arms",
            "width_two_down",
            "width_two_up",
            "workflow"
        );

        if(!is_array($this->ignoreFields)) {
          throw new \Exception('Ignore fields must be an array. Example: ignoreFields[]=description');
        }

        $this->showInfo = $this->request->getParam('showInfo', 0);

        $filename = $this->request->getParam('filename', '');
        
        if(!preg_match('/^[a-z0-9]+$/i', $filename)) {
            throw new \Exception('Invalid filename: ' . $filename);
        }

        $this->feedFormat = $this->request->getParam('format', self::CSV_FORMAT);

        $this->feedPath = $this->request->getParam('path', $directoryList->getPath('media') . '/searchspring');
        $this->tmpFilename = 'searchspring-' . $this->storeId . ($filename ? '-' . $filename : '') . '.tmp.' . $this->feedFormat;

        if(!is_dir($this->feedPath)) {
            mkdir($this->feedPath, 0755, true);
        }

        // TODO explore using CSV writer built into Magento, it looks like it can only write whole file and not append
        $this->tmpFile = fopen($this->feedPath . '/' . $this->tmpFilename, 'a');
    }

    public function generate()
    {
        if($this->showInfo) {
            $this->displayInfo();
            exit;
        }

        // Only need all fields for CSV format
        if($this->feedFormat == self::CSV_FORMAT) {
            $this->getFields();

            if($this->page == 1) {
                $this->writeHeader();
            }
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

    protected function displayInfo() {
        print "<h1>Stores</h1><ul>";
        $stores = $this->storeManager->getStores();
        foreach($stores as $store) {
            $name = $store->getName();
            $code = $store->getCode();
            print "<li>$name - $code</li>";
        }
        print "</ul>";

        print "<h1>Images</h1><ul>";
        $config = $this->viewConfig->getViewConfig()->read();
        // print "<pre>";var_dump($config['media']['Magento_Catalog']['images']);
        foreach($config['media']['Magento_Catalog']['images'] as $id => $image) {
            print "<li>$id<ul>";
            foreach($image as $attr => $val) {
                print "<li>$attr = $val</li>";
            }
            print "</ul></li>";
        }
        print "</ul>";
    }

    protected function moveFeed() {
        $filename = 'searchspring-' . $this->storeId . '.' . $this->feedFormat;
        rename($this->feedPath . '/' . $this->tmpFilename, $this->feedPath . '/' . $filename);
    }



    protected function getProductCollection() {
        $collection = $this->productCollectionFactory->create()
            ->addAttributeToSelect('*')
            // TODO COMMENT, FOR TESTING ONLY
            // ->addAttributeToFilter('entity_id', array('eq' => 67))
            ->setVisibility($this->productVisibility->getVisibleInSiteIds())
            ->addAttributeToFilter(
                'status', array('eq' => \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED)
            )
            ->setOrder('entity_id','ASC')
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
            
            // Skip attributes in skip list
            if(in_array($code, $this->skipFields)) {
                continue;
            }
            
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

            $children = $product->getTypeInstance()->getUsedProductCollection($product);

            // If we're pulling non-configurable attributes we need to load the full child product
            if(sizeof($this->childFields) > 0) {
                $children->addAttributeToSelect('*');
            }

            $children->addAttributeToFilter(
                'status', array('eq' => \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED)
            );

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

        if($this->includeMediaGallery) {
            $this->galleryReadHandler->execute($product);
            $images = $product->getMediaGalleryImages();
            $mediaGallery = array();
            foreach($images as $image) {
                if($image->getMediaType() == 'image') {
                    $mediaGallery[] = array(
                        'label' => $image->getLabel(),
                        'position' => $image->getPosition(),
                        'disabled' => $image->getDisabled(),
                        'image' => $this->getThumbnail($product, 'product_thumbnail_image', $image->getFile())
                    );
                }
            }

            $this->setRecordValue('media_gallery_json', json_encode($mediaGallery));
        }
    }

    protected function getThumbnail($product, $type = 'product_thumbnail_image', $imageFile = null) {
        $imageHelper = $this->productImageHelper->init($product, $type);

        if($imageFile) {
            $imageHelper->setImageFile($imageFile);
        }

        if($this->keepAspectRatio) {
            $resizedImage = $imageHelper->constrainOnly(TRUE)
                ->keepAspectRatio(TRUE)
                ->keepTransparency(TRUE)
                ->keepFrame(FALSE)
                ->resize($this->thumbWidth, $this->thumbHeight)
                ->getUrl();
        } else {
            $resizedImage = $imageHelper->resize($this->thumbWidth, $this->thumbHeight)
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
        $finalPrice = $product->getPriceInfo()->getPrice('final_price')->getMinimalPrice()->getValue();
        $this->setRecordValue('final_price', $finalPrice);

        $regularPrice = $product->getPriceInfo()->getPrice('regular_price')->getValue();
        $this->setRecordValue('regular_price', $regularPrice);


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
            'regular_price',
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

        if($this->includeMediaGallery) {
            $this->fields[] = 'media_gallery_json';
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
        $this->fields = array_unique(array_diff($this->fields, $this->ignoreFields));
        $this->fields = array_diff($this->fields, $this->skipFields);        
    }

    protected function textToFieldName($text) {
        return strtolower(preg_replace('/_+/', '_', preg_replace('/[^a-z0-9_]+/i', '_', trim($text))));
    }

    protected function setRecordValue($field, $value) {
        if(in_array($field, $this->ignoreFields)) {
            return;
        }
        
        if(in_array($field, $this->skipFields)) {
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
        if($this->feedFormat == self::CSV_FORMAT) {
            $this->writeCsvRecord();
        } else if($this->feedFormat == self::JSON_FORMAT) {
            $this->writeJsonRecord();
        }
    }

    protected function writeJsonRecord() {
        foreach($this->ignoreFields as $field) {
            unset($this->productRecord[$field]);
        }
        
        fwrite($this->tmpFile, json_encode($this->productRecord) . "\n");
    }

    protected function writeCsvRecord() {
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
