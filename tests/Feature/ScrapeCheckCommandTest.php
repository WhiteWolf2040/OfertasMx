<?php

namespace Tests\Feature;

use App\Services\ProductPageScraper;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ScrapeCheckCommandTest extends TestCase
{
    public function test_scrape_check_reads_urls_and_reports_fields_stages_and_summary(): void
    {
        $file = storage_path('app/scrape-check-test.txt');
        file_put_contents($file, "# Comentario\nhttps://shop.example/product\n");
        $scraper = $this->mock(ProductPageScraper::class);
        $scraper->shouldReceive('scrapeDetailed')
            ->once()
            ->with('https://shop.example/product', null, false)
            ->andReturn([
                'store' => 'shop.example',
                'title' => 'Producto',
                'price' => '1200.00',
                'blocked' => false,
                'provider' => 'http',
                '_diagnostics' => [
                    'provider' => 'http',
                    'elapsed_ms' => 125,
                    'fields_found' => ['title', 'price'],
                    'field_stages' => ['title' => 'json_ld', 'price' => 'json_ld'],
                ],
            ]);

        try {
            Artisan::call('scrape:check', ['--file' => 'storage/app/scrape-check-test.txt']);
            $output = Artisan::output();

            $this->assertStringContainsString('shop.example', $output);
            $this->assertStringContainsString('http', $output);
            $this->assertStringContainsString('json_ld', $output);
            $this->assertStringContainsString('1200.00', $output);
            $this->assertStringContainsString('1 completas (título y precio), 0 parciales, 0 bloqueadas', $output);
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
