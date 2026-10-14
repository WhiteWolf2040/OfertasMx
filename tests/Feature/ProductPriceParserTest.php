<?php

namespace Tests\Feature;

use App\Services\ProductMetadataExtractor;
use Tests\TestCase;

class ProductPriceParserTest extends TestCase
{
    public function test_price_parser_handles_regional_number_formats(): void
    {
        $extractor = app(ProductMetadataExtractor::class);

        $this->assertSame('1299.00', $extractor->parsePrice('1,299.00'));
        $this->assertSame('1299.00', $extractor->parsePrice('1.299,00'));
        $this->assertSame('1299.00', $extractor->parsePrice('$1299'));
        $this->assertSame('1299.00', $extractor->parsePrice('MXN 1,299'));
    }

    public function test_visible_price_scoring_skips_struck_list_and_per_unit_prices(): void
    {
        $extractor = app(ProductMetadataExtractor::class);
        $html = <<<'HTML'
            <html>
                <body>
                    <section id="product">
                        <h1>Producto de prueba</h1>
                        <div class="old-price"><s>$1,899.00</s></div>
                        <p>Precio $800 por kg</p>
                        <div class="product-price">1.299,00</div>
                    </section>
                    <aside>MXN 2,000</aside>
                </body>
            </html>
            HTML;

        $stages = [];
        $result = $extractor->extract($html, 'https://shop.example/product', $stages);

        $this->assertSame('1299.00', $result['price']);
        $this->assertSame('visible_text_scored', $stages['price']);
    }

    public function test_visible_price_uses_first_dom_candidate_when_scores_tie(): void
    {
        $extractor = app(ProductMetadataExtractor::class);
        $html = <<<'HTML'
            <html>
                <body>
                    <div class="product-price">
                        <h1>Producto de prueba</h1>
                        <span>$1,299</span>
                        <span>$1,499</span>
                    </div>
                </body>
            </html>
            HTML;

        $result = $extractor->extract($html, 'https://shop.example/product');

        $this->assertSame('1299.00', $result['price']);
    }
}
