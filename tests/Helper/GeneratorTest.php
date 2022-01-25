<?php

use PHPUnit\Framework\TestCase;
use SearchSpring\Feed\Helper\Generator;

require_once __DIR__ . '/../../Helper/Generator.php';
require_once __DIR__ . '/../../../../../../../vendor/autoload.php';

//require_once __DIR__ . '/../../../../../../../vendor/magento/module-catalog/Model/Product.php';


class GeneratorTest extends TestCase
{

    public function testSetRecordValue()
    {
        $myRecord = array(
            "foo" => ["bar"],
            "bar" => ["foo"],
        );

        $ignoredFields = array(
            "one" => "two",
            "three" => "four",
        );

        Generator::setRecordValue($myRecord,"foo","newValue",$ignoredFields);

        $this->assertContains("newValue",$myRecord["foo"]);


    }

    public function testAddPricesToRecord()
    {
        $product = $this->getProductMock();

        $myRecord = array();
        $ignoreField = array();
        Generator::addPricesToRecord($product,$myRecord,$ignoreField,false);


        $this->assertContains(10.0,$myRecord['final_price']);
        $this->assertContains(20.0,$myRecord['max_price']);
        $this->assertContains(15.0,$myRecord['regular_price']);

    }

    public function testGetProductAttribute()
    {
        $product = $this->getProductMock();
        $attribute = $this->getAttributeMock();

        $productAttribute = Generator::getProductAttribute($product,$attribute);

        $this->assertEquals("attribute data",$productAttribute);
    }

    public function testAddProductAttributesToRecord()
    {
        $product = $this->getProductMock();
        $myRecord = array();
        $ignoreField = array();

        Generator::addProductAttributesToRecord($product,$myRecord,$ignoreField);

        $this->assertContains('attribute data',$myRecord['1']);


    }

    /**
     * @return \Magento\Catalog\Model\Product|\PHPUnit\Framework\MockObject\MockObject
     */
    public function getProductMock()
    {
        $product = $this
            ->createMock(Magento\Catalog\Model\Product::class);
        //Start Price Mock
        $price = $this
            ->createMock(Magento\Framework\Pricing\PriceInfo\Base::class);
        $priceCollection = $this
            ->createMock(Magento\Catalog\Pricing\Price\FinalPrice::class);
        $minAmmountInterface = $this
            ->createMock(\Magento\Framework\Pricing\Amount\AmountInterface::class);
        $minAmmountInterface->method('getValue')
            ->willReturn(10.0);
        $maxAmmountInterface = $this
            ->createMock(\Magento\Framework\Pricing\Amount\AmountInterface::class);
        $maxAmmountInterface->method('getValue')
            ->willReturn(20.0);
        $priceCollection->method('getMinimalPrice')
            ->willReturn($minAmmountInterface);
        $priceCollection->method('getMaximalPrice')
            ->willReturn($maxAmmountInterface);
        $priceCollection->method('getValue')
            ->willReturn(15.0);
        $price
            ->method('getPrice')
            ->willReturn($priceCollection);
        $product->method('getPriceInfo')
            ->willReturn($price);
        //End Price Mock

        //Attribute Mock
        $product->method('getAttributeText')->willReturn("attribute text");
        $product->method('getData')->willReturn("attribute data");

        $product->method('getAttributes')->willReturn(array($this->getAttributeMock()));

        return $product;
    }

    public function getAttributeMock()
    {
        $attribute = $this->createMock(\Magento\Eav\Model\Entity\Attribute\AbstractAttribute::class);
        $attribute->method('getAttributeCode')->willReturn("1");
        $attribute->method('usesSource')->willReturn(false);
        return $attribute;
    }

}
