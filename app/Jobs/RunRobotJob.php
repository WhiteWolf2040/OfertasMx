<?php

namespace App\Jobs;

use App\Models\Robot;
use App\Models\RobotNotification;
use App\Models\RobotRun;
use App\Services\ProductPageScraper;
use App\Services\StoreProfileService;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\CssSelector\CssSelectorConverter;
use App\Services\ProductMetadataExtractor;
use Throwable;

class RunRobotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public readonly int $robotId) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('robot-'.$this->robotId))
                ->expireAfter($this->timeout)
                ->dontRelease(),
        ];
    }

    public function handle(ProductPageScraper $scraper, ?StoreProfileService $profiles = null): void
    {
        $profiles ??= app(StoreProfileService::class);
        $robot = Robot::find($this->robotId);
        if ($robot === null) {
            return;
        }

        $startedAt = microtime(true);
        $result = null;
        $error = null;
        $selectorMisses = [];
        $profile = null;
        $usingProfile = false;
        $selectors = is_array($robot->selectors) ? $robot->selectors : [];
        if ($selectors === []) {
            $profile = $profiles->findForUrl($robot->user_id, $robot->url);
            if ($profile?->is_active) {
                $selectors = $profile->selectors;
                $usingProfile = $selectors !== [];
            }
        }

        try {
            $result = $scraper->scrapeDetailed($robot->url, null, $selectors !== []);
            if ($selectors !== []) {
                [$result, $selectorMisses] = $this->applySelectors($result, $selectors, $scraper);

                if ($selectorMisses !== [] && ($result['provider'] ?? null) === 'http') {
                    $browserResult = $scraper->scrapeDetailed($robot->url, 'browser', true);
                    [$browserResult, $browserMisses] = $this->applySelectors(
                        $browserResult,
                        $selectors,
                        $scraper
                    );
                    foreach (['title', 'description', 'image', 'price', 'currency', 'store', 'availability', 'seller', 'original_price'] as $field) {
                        if (($browserResult[$field] ?? null) !== null) {
                            $result[$field] = $browserResult[$field];
                        }
                    }
                    $result['_diagnostics'] = $browserResult['_diagnostics'] ?? $result['_diagnostics'] ?? [];
                    $result['provider'] = $browserResult['provider'] ?? 'browser';
                    $result['blocked'] = $browserResult['blocked'] ?? $result['blocked'] ?? false;
                    $selectorMisses = $browserMisses;
                }
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        if ($usingProfile && $profile !== null) {
            $matched = array_values(array_diff(array_keys($selectors), $selectorMisses));
            $profiles->recordAttempt($profile, $matched, $selectorMisses);
        }

        if (is_array($result)) {
            foreach (['title', 'description', 'image', 'price', 'currency', 'store', 'availability', 'seller', 'original_price'] as $field) {
                if (! array_key_exists($field, $result) || $result[$field] === null || $result[$field] === '') {
                    unset($result[$field]);
                }
            }
        }

        if ($error === null && $selectorMisses !== []) {
            $error = 'selector '.implode(', ', $selectorMisses).' sin resultado';
        }

        if (is_array($result)) {
            $result = $this->hydrateExtraFields($result, $selectors, $scraper);
        }

        if (is_array($result) && is_array($robot->overrides)) {
            foreach (['title', 'description', 'image'] as $field) {
                if (($result[$field] ?? null) === null && is_string($robot->overrides[$field] ?? null)) {
                    $result[$field] = $robot->overrides[$field];
                }
            }
        }

        $durationMs = min((int) ((microtime(true) - $startedAt) * 1000), 4294967295);
        $title = is_array($result) && is_string($result['title'] ?? null)
            ? $result['title']
            : null;
        $price = is_array($result) && is_numeric($result['price'] ?? null)
            ? $result['price']
            : null;
        $blocked = is_array($result) && ($result['blocked'] ?? false) === true;
        $status = $error !== null && $selectorMisses === []
            ? 'failed'
            : ($blocked ? 'blocked' : ($title !== null && $price !== null ? 'success' : 'partial'));
        if ($selectorMisses !== [] && ! $blocked) {
            $status = 'partial';
        }
        $now = now();
        $previousSuccessfulRun = $robot->runs()
            ->where('status', 'success')
            ->whereNotNull('price')
            ->latest('created_at')
            ->first(['price', 'data', 'created_at']);

        RobotRun::create([
            'robot_id' => $robot->id,
            'status' => $status,
            'provider' => is_array($result) && is_string($result['provider'] ?? null)
                ? $result['provider']
                : null,
            'data' => $result,
            'price' => $price,
            'error' => $error,
            'duration_ms' => $durationMs,
            'created_at' => $now,
        ]);

        $robot->forceFill([
            'name' => $robot->name ?: $title,
            'last_price' => $price,
            'last_currency' => is_array($result) && is_string($result['currency'] ?? null)
                ? $result['currency']
                : null,
            'last_status' => $status,
            'last_run_at' => $now,
            'next_run_at' => $now->copy()->addMinutes($robot->interval_minutes),
        ])->save();

        if ($status === 'success' && $price !== null) {
            $currentPrice = number_format((float) $price, 2, '.', '');
            $previousPrice = $previousSuccessfulRun?->price;
            $previousNormalized = $previousPrice === null
                ? null
                : number_format((float) $previousPrice, 2, '.', '');
            $targetReached = $robot->target_price !== null
                && (float) $currentPrice <= (float) $robot->target_price;
            $targetPriceWasAlreadyAlerted = RobotNotification::query()
                ->where('robot_id', $robot->id)
                ->where('type', 'target_reached')
                ->where('price', $currentPrice)
                ->exists();

            if ($targetReached && ! $targetPriceWasAlreadyAlerted) {
                RobotNotification::create([
                    'robot_id' => $robot->id,
                    'user_id' => $robot->user_id,
                    'type' => 'target_reached',
                    'price' => $currentPrice,
                    'previous_price' => $previousPrice,
                    'created_at' => $now,
                ]);
                $robot->forceFill(['alerted_at' => $now])->save();
            }

            if ($robot->notify_on_drop
                && $previousPrice !== null
                && (float) $currentPrice < (float) $previousPrice
                && $previousNormalized !== $currentPrice) {
                RobotNotification::create([
                    'robot_id' => $robot->id,
                    'user_id' => $robot->user_id,
                    'type' => 'price_drop',
                    'price' => $currentPrice,
                    'previous_price' => $previousPrice,
                    'created_at' => $now,
                ]);
            }

            $previousAvailability = is_array($previousSuccessfulRun?->data)
                ? $this->availabilityState($previousSuccessfulRun->data['availability'] ?? null)
                : null;
            $currentAvailability = is_array($result)
                ? $this->availabilityState($result['availability'] ?? null)
                : null;
            if ($robot->notify_on_restock
                && $previousAvailability === 'out'
                && $currentAvailability === 'available'
                && ! RobotNotification::query()
                    ->where('robot_id', $robot->id)
                    ->where('type', 'back_in_stock')
                    ->where('price', $currentPrice)
                    ->exists()) {
                RobotNotification::create([
                    'robot_id' => $robot->id,
                    'user_id' => $robot->user_id,
                    'type' => 'back_in_stock',
                    'price' => $currentPrice,
                    'previous_price' => $previousPrice,
                    'created_at' => $now,
                ]);
            }

            $this->createFieldChangedNotifications($robot, $result, $previousSuccessfulRun?->data, $now);
        }
    }

    private function hydrateExtraFields(array $result, array $selectors, ProductPageScraper $scraper): array
    {
        $extraSelectors = $selectors['extras'] ?? [];
        if (! is_array($extraSelectors) || $extraSelectors === []) {
            return $result;
        }

        $html = $result['_diagnostics']['html'] ?? null;
        if (! is_string($html) || $html === '') {
            return $result;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrorMode = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorMode);
        $xpath = new DOMXPath($document);
        $converter = new CssSelectorConverter;
        $extras = [];
        $warnings = [];

        foreach ($extraSelectors as $extra) {
            if (! is_array($extra) || ! is_string($extra['key'] ?? null) || ! is_string($extra['css'] ?? null) || ! is_string($extra['attr'] ?? null)) {
                continue;
            }

            try {
                $nodes = $xpath->query($converter->toXPath($extra['css']));
            } catch (Throwable) {
                $nodes = false;
            }

            $node = $nodes instanceof \DOMNodeList ? $nodes->item(0) : null;
            if (! $node instanceof DOMElement) {
                $warnings[$extra['key']] = 'selector sin resultado';
                continue;
            }

            $attribute = $extra['attr'];
            $value = match ($attribute) {
                'src', 'content', 'href' => trim($node->getAttribute($attribute)),
                default => trim($node->textContent),
            };
            if ($value === '') {
                $warnings[$extra['key']] = 'selector sin resultado';
                continue;
            }

            $extras[$extra['key']] = $value;
        }

        if ($extras !== []) {
            $result['extras'] = $extras;
        }
        if ($warnings !== []) {
            $result['extras_warnings'] = $warnings;
        }

        return $result;
    }

    private function createFieldChangedNotifications(Robot $robot, array $result, ?array $previousData, $now): void
    {
        $extras = is_array($result['extras'] ?? null) ? $result['extras'] : [];
        if ($extras === []) {
            return;
        }

        $previousExtras = is_array($previousData['extras'] ?? null) ? $previousData['extras'] : [];
        foreach ($extras as $key => $value) {
            $previousValue = $previousExtras[$key] ?? null;
            if ($previousValue === null || (string) $previousValue === (string) $value) {
                continue;
            }

            $label = $key;
            $selectorMeta = ($robot->selectors['extras'] ?? []) ?: [];
            foreach ($selectorMeta as $meta) {
                if (is_array($meta) && ($meta['key'] ?? null) === $key && is_string($meta['label'] ?? null)) {
                    $label = $meta['label'];
                    break;
                }
            }

            if ($robot->selectors['extras'] ?? null) {
                $notify = false;
                foreach (($robot->selectors['extras'] ?? []) as $meta) {
                    if (is_array($meta) && ($meta['key'] ?? null) === $key && (($meta['notify'] ?? false) === true)) {
                        $notify = true;
                        break;
                    }
                }
                if (! $notify) {
                    continue;
                }
            }

            RobotNotification::query()->firstOrCreate([
                'robot_id' => $robot->id,
                'user_id' => $robot->user_id,
                'type' => 'field_changed',
                'key' => $key,
                'label' => $label,
                'value' => (string) $value,
                'previous_value' => (string) $previousValue,
            ], [
                'created_at' => $now,
            ]);
        }
    }

    private function applySelectors(array $result, array $selectors, ProductPageScraper $scraper): array
    {
        $html = $result['_diagnostics']['html'] ?? null;
        if (! is_string($html) || $html === '') {
            return [$result, array_keys($selectors)];
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrorMode = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorMode);
        $xpath = new DOMXPath($document);
        $converter = new CssSelectorConverter;
        $missing = [];

        foreach ($selectors as $field => $selector) {
            if (! is_array($selector) || ! is_string($selector['css'] ?? null) || ! in_array(
                $selector['attr'] ?? null,
                ['text', 'src', 'content'],
                true
            )) {
                continue;
            }

            try {
                $nodes = $xpath->query($converter->toXPath($selector['css']));
            } catch (Throwable) {
                $nodes = false;
            }
            $node = $nodes instanceof \DOMNodeList ? $nodes->item(0) : null;
            if (! $node instanceof DOMElement) {
                $missing[] = $field;

                continue;
            }

            $attribute = $selector['attr'];
            $value = match ($attribute) {
                'src', 'content' => trim($node->getAttribute($attribute)),
                default => trim($node->textContent),
            };
            if ($value === '') {
                $missing[] = $field;

                continue;
            }
         if (in_array($field, ['price', 'original_price'], true)) {
    $value = app(ProductMetadataExtractor::class)->parsePrice($value);
                if ($value === null) {
                    $missing[] = $field;

                    continue;
                }
            }

            $result[$field] = $value;
        }

        return [$result, $missing];
    }

    private function availabilityState(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $normalized = mb_strtolower(trim($value));
        if (preg_match('/agotad|sin existencias|no disponible|out of stock|sold out|unavailable|no hay stock/', $normalized) === 1) {
            return 'out';
        }
        if (preg_match('/disponible|en stock|in stock|available|hay existencias/', $normalized) === 1) {
            return 'available';
        }

        return null;
    }
}
