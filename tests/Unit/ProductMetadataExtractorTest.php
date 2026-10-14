<?php

namespace Tests\Unit;

use App\Services\ProductMetadataExtractor;
use Tests\TestCase;

class ProductMetadataExtractorTest extends TestCase
{
    public function test_price_parser_handles_spaced_and_superscript_cents(): void
    {
        $extractor = new ProductMetadataExtractor;

        $this->assertSame('108.00', $extractor->parsePrice('108 00'));
        $this->assertSame('108.00', $extractor->parsePrice('$108⁰⁰'));
    }

    public function test_visible_price_prefers_whole_amount_over_fraction_below_one_in_same_container(): void
    {
        $extractor = new ProductMetadataExtractor;
        $stages = [];
        $result = $extractor->extract(
            '<html><body><h1>Producto</h1><div class="price"><span>$0.99</span><span>$108.00</span></div></body></html>',
            'https://shop.example/product',
            $stages
        );

        $this->assertSame('108.00', $result['price']);
        $this->assertSame('visible_text_scored', $stages['price']);
    }

    public function test_saved_amazon_product_fixture_extracts_real_price_full_image_and_bullets(): void
    {
        $extractor = new ProductMetadataExtractor;
        $html = file_get_contents(base_path('tests/Fixtures/amazon-product-b0c8543s4k.html'));
        $this->assertIsString($html);

        $stages = [];
        $sources = [];
        $priceCandidates = [];
        $result = $extractor->extract(
            $html,
            'https://www.amazon.com.mx/dp/B0C8543S4K',
            $stages,
            $sources,
            $priceCandidates
        );

        $this->assertSame('108.00', $result['price']);
        $this->assertNotSame('1.00', $result['price']);
        $this->assertNotSame('10.98', $result['price']);
        $this->assertSame('MXN', $result['currency']);
        $this->assertStringNotContainsString(': Amazon.com.mx', $result['title']);
        $this->assertSame('product_heading', $stages['title']);
        $this->assertStringContainsString('_AC_SL1500_', $result['image']);
        $this->assertStringNotContainsString('_AC_SS57_', $result['image']);
        $this->assertStringContainsString('Luces LED', $result['description']);
        $this->assertNotSame($result['title'], $result['description']);
        $this->assertSame('amazon_price_container', $stages['price']);
        $this->assertSame('$108.00', $sources['price']['raw']);
        $this->assertNotEmpty($priceCandidates);
        $this->assertContains('108.00', array_column($priceCandidates, 'price'));
        $this->assertNotContains('10.96', array_column($priceCandidates, 'price'));
        $this->assertNotContains('10.98', array_column($priceCandidates, 'price'));
        $this->assertNotContains('5.00', array_column($priceCandidates, 'price'));
    }

    public function test_price_parser_rejects_values_below_one(): void
    {
        $extractor = new ProductMetadataExtractor;

        $this->assertNull($extractor->parsePrice('0.98'));
        $this->assertNull($extractor->parsePrice('0.00'));
    }
}
