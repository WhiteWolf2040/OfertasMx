<?php

namespace App\Services;

use App\Models\StoreProfile;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Throwable;

class StoreProfileService
{
    public function domainForUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return preg_replace('/^www\./i', '', strtolower(rtrim($host, '.')));
    }

    public function findForUrl(User|int $user, string $url): ?StoreProfile
    {
        $domain = $this->domainForUrl($url);
        if ($domain === null) {
            return null;
        }

        return StoreProfile::query()
            ->where('user_id', $user instanceof User ? $user->id : $user)
            ->where('domain', $domain)
            ->first();
    }

    public function remember(User $user, string $url, array $selectors): ?StoreProfile
    {
        $domain = $this->domainForUrl($url);
        if ($domain === null || $selectors === []) {
            return null;
        }

        return StoreProfile::query()->updateOrCreate(
            ['user_id' => $user->id, 'domain' => $domain],
            ['selectors' => $selectors, 'is_active' => true]
        );
    }

    /**
     * Applies selectors without discarding values found by the generic extractor.
     *
     * @return array{0: array, 1: array<int, string>, 2: array<int, string>}
     */
    public function apply(array $result, array $selectors, ProductPageScraper $scraper): array
    {
        $html = $result['_diagnostics']['html'] ?? null;
        if (! is_string($html) || $html === '') {
            return [$result, [], array_keys($selectors)];
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrorMode = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorMode);
        $xpath = new DOMXPath($document);
        $converter = new CssSelectorConverter;
        $matched = [];
        $missed = [];

        foreach ($selectors as $field => $selector) {
            if (! is_array($selector)
                || ! is_string($selector['css'] ?? null)
                || ! in_array($selector['attr'] ?? null, ['text', 'src', 'content'], true)) {
                $missed[] = $field;

                continue;
            }

            try {
                $nodes = $xpath->query($converter->toXPath($selector['css']));
            } catch (Throwable) {
                $nodes = false;
            }

            $node = $nodes instanceof \DOMNodeList ? $nodes->item(0) : null;
            if (! $node instanceof DOMElement) {
                $missed[] = $field;

                continue;
            }

            $attribute = $selector['attr'];
            $value = $attribute === 'text'
                ? trim($node->textContent)
                : trim($node->getAttribute($attribute));
            if ($value === '') {
                $missed[] = $field;

                continue;
            }

         if (in_array($field, ['price', 'original_price'], true)) {
                    $value = app(ProductMetadataExtractor::class)->parsePrice($value);
                    if ($value === null) {
                        $missed[] = $field;

                        continue;
                    }
                }
            $result[$field] = $value;
            $matched[] = $field;
            $result['_diagnostics']['field_stages'][$field] = 'store_profile_selector';
        }

        return [$result, $matched, $missed];
    }

    public function recordAttempt(StoreProfile $profile, array $matched, array $missed): bool
    {
        $wasActive = $profile->is_active;
        $profile->increment('success_count', count($matched));
        $profile->increment('fail_count', count($missed));
        $profile->refresh();

        if ($profile->fail_count > 5 && $profile->success_count < ($profile->fail_count / 2)) {
            $profile->forceFill(['is_active' => false])->save();
        }

        return $wasActive && ! $profile->is_active;
    }
}
