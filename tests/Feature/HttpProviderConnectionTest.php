<?php

namespace Tests\Feature;

use App\Services\HttpProvider;
use App\Services\PublicUrlValidator;
use App\Services\ScrapeUrlNormalizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HttpProviderConnectionTest extends TestCase
{
    public function test_connection_failure_logs_the_original_cause_and_safe_destination_host(): void
    {
        $originalMessage = 'cURL error 7: Failed to connect to 203.0.113.10 port 443';
        $validator = Mockery::mock(PublicUrlValidator::class);
        $validator->shouldReceive('validate')
            ->once()
            ->with('https://shop.example/item?campaign=private')
            ->andReturn(['203.0.113.10', '2001:db8::10']);

        Http::fake(static function () use ($originalMessage): never {
            throw new ConnectionException('Connection failed', 0, new RuntimeException($originalMessage));
        });

        Log::shouldReceive('warning')
            ->once()
            ->with('HTTP provider connection failed', Mockery::on(
                static fn (array $context): bool => $context['destination_host'] === 'shop.example'
                    && $context['exception_class'] === RuntimeException::class
                    && $context['exception_message'] === $originalMessage
                    && $context['connection_exception_class'] === ConnectionException::class
                    && ! array_key_exists('proxy', $context),
            ));

        $provider = new HttpProvider($validator, new ScrapeUrlNormalizer);
        try {
            $provider->fetch('https://shop.example/item?campaign=private');
            $this->fail('Se esperaba que el proveedor informara el fallo de conexión.');
        } catch (HttpException $exception) {
            $this->assertSame('No fue posible conectar con la tienda.', $exception->getMessage());
            $this->assertInstanceOf(ConnectionException::class, $exception->getPrevious());
        }
    }

    public function test_http_provider_and_diagnostics_force_ipv4_using_the_same_curl_option(): void
    {
        $options = HttpProvider::ipv4CurlOptions();

        $this->assertSame(CURL_IPRESOLVE_V4, $options[CURLOPT_IPRESOLVE]);
    }
}
