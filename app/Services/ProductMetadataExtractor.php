<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Str;

class ProductMetadataExtractor
{
    public function extract(
        string $html,
        string $pageUrl,
        ?array &$stages = null,
        ?array &$fieldSources = null,
        ?array &$priceCandidates = null
    ): array {
        $stages = [];
        $fieldSources = [];
        $priceCandidates = [];
        $document = new DOMDocument;
        $previousErrorMode = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorMode);
        $xpath = new DOMXPath($document);

        $data = [
            'title' => null,
            'description' => null,
            'image' => null,
            'price' => null,
            'currency' => null,
        ];

        $this->extractJsonLd($xpath, $data, $pageUrl, $stages, $fieldSources);
        $this->extractMicrodata($xpath, $data, $pageUrl, $stages, $fieldSources);
        $meta = $this->readMetaTags($xpath);
        $domain = preg_replace('/^www\./i', '', (string) parse_url($pageUrl, PHP_URL_HOST)) ?: null;
        $metaTitle = $meta['og:title'] ?? $meta['twitter:title'] ?? $meta['title'] ?? null;
        $heading = $xpath->query('//h1[@id="productTitle"] | //h1')?->item(0);
        $headingText = $heading instanceof DOMElement ? trim($heading->textContent) : null;
        if ($this->isAmazonDomain($domain) && $this->descriptionIsTitleLike($metaTitle, $headingText)) {
            $metaTitle = null;
        }
        $this->fillIfMissing(
            $data,
            'title',
            $metaTitle,
            $stages,
            'open_graph',
            $fieldSources,
            'meta[property="og:title"], meta[name="twitter:title"], title',
            $metaTitle
        );
        if ($data['title'] === null) {
            $this->fillIfMissing(
                $data,
                'title',
                $headingText,
                $stages,
                'product_heading',
                $fieldSources,
                $heading instanceof DOMElement ? $this->selectorForNode($heading) : '//h1',
                $headingText
            );
        }

        $metaDescription = $meta['og:description'] ?? $meta['twitter:description'] ?? $meta['description'] ?? null;
        if (! $this->descriptionIsTitleLike($metaDescription, $data['title'])) {
            $this->fillIfMissing(
                $data,
                'description',
                $metaDescription,
                $stages,
                'open_graph',
                $fieldSources,
                'meta[property="og:description"], meta[name="description"]',
                $metaDescription
            );
        }

        $imageMeta = $meta['og:image'] ?? $meta['og:image:url'] ?? $meta['twitter:image'] ?? $meta['twitter:image:src'] ?? null;
        if (! $this->isThumbnailImage($imageMeta)) {
            $this->fillIfMissing(
                $data,
                'image',
                $this->absoluteUrl($imageMeta, $pageUrl),
                $stages,
                'open_graph',
                $fieldSources,
                'meta[property="og:image"]',
                $imageMeta
            );
        }

        if ($data['price'] === null && $this->isAmazonDomain($domain)) {
            [$data['price'], $currency, $priceCandidates] = $this->extractAmazonPrice($xpath, $data['title'], $pageUrl);
            if ($data['price'] !== null) {
                $stages['price'] = 'amazon_price_container';
                $best = $priceCandidates[0] ?? [];
                $fieldSources['price'] = [
                    'stage' => $stages['price'],
                    'selector' => $best['selector'] ?? null,
                    'raw' => $best['raw'] ?? null,
                ];
            }
            if ($currency !== null) {
                $best = $priceCandidates[0] ?? [];
                $this->fillIfMissing(
                    $data,
                    'currency',
                    $currency,
                    $stages,
                    'amazon_price_container',
                    $fieldSources,
                    $best['selector'] ?? null,
                    $best['raw'] ?? null
                );
            }
        }

        if ($data['price'] === null && ! $this->isAmazonDomain($domain)) {
            $metaPrice = $meta['og:price:amount'] ?? $meta['product:price:amount'] ?? $meta['twitter:data1'] ?? $meta['price'] ?? null;
            $this->fillIfMissing(
                $data,
                'price',
                $this->normalizePrice($metaPrice),
                $stages,
                'open_graph',
                $fieldSources,
                'meta[property="product:price:amount"], meta[property="og:price:amount"]',
                $metaPrice
            );
        }

        if ($data['price'] === null) {
            [$visiblePrice, $visibleCurrency, $visibleCandidates] = $this->extractVisiblePrice($xpath, $data['title'], $pageUrl);
            $priceCandidates = $priceCandidates !== [] ? $priceCandidates : $visibleCandidates;
            if ($visiblePrice !== null) {
                $data['price'] = $visiblePrice;
                $stages['price'] = 'visible_text_scored';
                $best = $visibleCandidates[0] ?? [];
                $fieldSources['price'] = [
                    'stage' => $stages['price'],
                    'selector' => $best['selector'] ?? null,
                    'raw' => $best['raw'] ?? null,
                ];
            }
            if ($visibleCurrency !== null) {
                $best = $visibleCandidates[0] ?? [];
                $this->fillIfMissing(
                    $data,
                    'currency',
                    $visibleCurrency,
                    $stages,
                    'visible_text',
                    $fieldSources,
                    $best['selector'] ?? null,
                    $best['raw'] ?? null
                );
            }
        }

        $this->fillIfMissing(
            $data,
            'price',
            $this->normalizePrice($meta['og:price:amount'] ?? $meta['product:price:amount'] ?? $meta['twitter:data1'] ?? $meta['price'] ?? null),
            $stages,
            'open_graph',
            $fieldSources,
            'meta[property="product:price:amount"], meta[property="og:price:amount"]',
            $meta['og:price:amount'] ?? $meta['product:price:amount'] ?? $meta['twitter:data1'] ?? $meta['price'] ?? null
        );
        $this->fillIfMissing(
            $data,
            'currency',
            $this->normalizeCurrency($meta['product:price:currency'] ?? $meta['og:price:currency'] ?? $meta['pricecurrency'] ?? null),
            $stages,
            'open_graph',
            $fieldSources,
            'meta[property="product:price:currency"], meta[property="og:price:currency"]',
            $meta['product:price:currency'] ?? $meta['og:price:currency'] ?? $meta['pricecurrency'] ?? null
        );

        $data['title'] = $this->cleanMetadataText($data['title'], $domain);
        if ($data['title'] === null) {
            unset($stages['title']);
            unset($fieldSources['title']);
        }
        $data['description'] = $this->cleanMetadataText($data['description'], $domain);
        if ($data['description'] === null || $this->descriptionIsTitleLike($data['description'], $data['title'])) {
            $data['description'] = null;
            unset($stages['description']);
            unset($fieldSources['description']);
        }

        if ($data['description'] === null) {
            [$data['description'], $descriptionSelector, $descriptionRaw] = $this->extractUsefulDescription($xpath, $data['title']);
            if ($data['description'] !== null) {
                $stages['description'] = 'product_description_block';
                $fieldSources['description'] = [
                    'stage' => $stages['description'],
                    'selector' => $descriptionSelector,
                    'raw' => $descriptionRaw,
                ];
            }
        }

        if ($data['image'] === null) {
            [$data['image'], $imageSelector, $imageRaw] = $this->extractAmazonImage($xpath, $pageUrl);
            if ($data['image'] !== null) {
                $stages['image'] = 'product_gallery_image';
                $fieldSources['image'] = [
                    'stage' => $stages['image'],
                    'selector' => $imageSelector,
                    'raw' => $imageRaw,
                ];
            }
        }

        if ($data['image'] === null) {
            $data['image'] = $this->largestContentImage($xpath, $pageUrl);
            if ($data['image'] !== null) {
                $stages['image'] = 'main_content_image';
                $fieldSources['image'] = [
                    'stage' => $stages['image'],
                    'selector' => '//main//img | //*[@role="main"]//img | //article//img',
                    'raw' => $data['image'],
                ];
            }
        }

        foreach ($this->domainSelectors($domain) as $field => $selector) {
            if ($data[$field] !== null || ! is_string($selector) || $selector === '') {
                continue;
            }

            $value = $this->selectCssValue($xpath, $selector, $field);
            $value = match ($field) {
                'title' => $this->cleanMetadataText($value, $domain),
                'price' => $this->normalizePrice($value),
                'image' => $this->absoluteUrl($value, $pageUrl),
                default => null,
            };

            if ($value !== null) {
                $data[$field] = $value;
                $stages[$field] = 'domain_selector';
                $fieldSources[$field] = [
                    'stage' => 'domain_selector',
                    'selector' => $selector,
                    'raw' => $value,
                ];
            }
        }

        if ($data['currency'] === null) {
            $inferredCurrency = $this->inferCurrency($pageUrl, $html);
            if ($inferredCurrency !== null) {
                $data['currency'] = $inferredCurrency;
                $stages['currency'] = 'inferred';
                $fieldSources['currency'] = [
                    'stage' => 'inferred',
                    'selector' => null,
                    'raw' => $inferredCurrency,
                ];
            }
        }

        if ($data['price'] !== null && (float) $data['price'] < 1) {
            $data['price'] = null;
            unset($stages['price'], $fieldSources['price']);
        }

        [, , $visiblePriceCandidates] = $this->extractVisiblePrice($xpath, $data['title'], $pageUrl);
        $priceCandidates = array_merge($priceCandidates, $visiblePriceCandidates);
        if ($data['price'] !== null) {
            $priceSource = $fieldSources['price'] ?? [];
            $selectedCandidateFound = false;
            foreach ($priceCandidates as $candidate) {
                if (($candidate['price'] ?? null) === $data['price']
                    && ($candidate['raw'] ?? null) === ($priceSource['raw'] ?? $data['price'])
                    && ($candidate['selector'] ?? null) === ($priceSource['selector'] ?? null)) {
                    $selectedCandidateFound = true;
                    break;
                }
            }
            if (! $selectedCandidateFound) {
                array_unshift($priceCandidates, [
                    'price' => $data['price'],
                    'currency' => $data['currency'],
                    'score' => match ($stages['price'] ?? null) {
                        'json_ld' => 1000,
                        'microdata' => 900,
                        'open_graph' => 800,
                        'amazon_price_container' => 500,
                        default => 0,
                    },
                    'selector' => $priceSource['selector'] ?? $stages['price'] ?? null,
                    'raw' => $priceSource['raw'] ?? $data['price'],
                    'order' => -1,
                ]);
            }
        }
        usort($priceCandidates, static fn (array $left, array $right): int => ($right['score'] <=> $left['score']) ?: (($left['order'] ?? 0) <=> ($right['order'] ?? 0)));
        $uniqueCandidates = [];
        $seenPrices = [];
        foreach ($priceCandidates as $candidate) {
            $key = (string) ($candidate['price'] ?? '');
            if (isset($seenPrices[$key])) {
                continue;
            }
            $seenPrices[$key] = true;
            $uniqueCandidates[] = $candidate;
        }
        $priceCandidates = array_slice($uniqueCandidates, 0, 5);

        return $data;
    }

    private function extractJsonLd(
        DOMXPath $xpath,
        array &$data,
        string $pageUrl,
        array &$stages,
        array &$fieldSources
    ): void {
        $scripts = $xpath->query('//script[contains(translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "ld+json")]');

        foreach ($scripts ?: [] as $script) {
            $decoded = json_decode($script->textContent, true);
            foreach ($this->flattenJsonLd($decoded) as $item) {
                $types = (array) ($item['@type'] ?? []);
                if (! in_array('Product', $types, true) && ! in_array('Product', array_map(
                    static fn ($type) => is_string($type) ? basename(str_replace('#', '/', $type)) : '',
                    $types
                ), true)) {
                    continue;
                }

                $this->fillIfMissing($data, 'title', $item['name'] ?? null, $stages, 'json_ld', $fieldSources, 'JSON-LD Product.name', $item['name'] ?? null);
                $this->fillIfMissing($data, 'description', $item['description'] ?? null, $stages, 'json_ld', $fieldSources, 'JSON-LD Product.description', $item['description'] ?? null);
                $image = $this->imageValue($item['image'] ?? null);
                if (! $this->isThumbnailImage($image)) {
                    $this->fillIfMissing($data, 'image', $this->absoluteUrl($image, $pageUrl), $stages, 'json_ld', $fieldSources, 'JSON-LD Product.image', $image);
                }

                [$price, $currency] = $this->offerValues($item['offers'] ?? null);
                $this->fillIfMissing($data, 'price', $this->normalizePrice($price), $stages, 'json_ld', $fieldSources, 'JSON-LD Product.offers.price', $price);
                $this->fillIfMissing($data, 'currency', $this->normalizeCurrency($currency), $stages, 'json_ld', $fieldSources, 'JSON-LD Product.offers.priceCurrency', $currency);
            }
        }
    }

    private function flattenJsonLd(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        if (array_is_list($value)) {
            return array_merge([], ...array_map(fn ($item) => $this->flattenJsonLd($item), $value));
        }

        $items = [];
        if (isset($value['@type'])) {
            $items[] = $value;
        }

        if (isset($value['@graph'])) {
            $items = array_merge($items, $this->flattenJsonLd($value['@graph']));
        }

        return $items;
    }

    private function offerValues(mixed $offers): array
    {
        $offerList = is_array($offers) && array_is_list($offers) ? $offers : [$offers];
        foreach ($offerList as $offer) {
            if (! is_array($offer)) {
                continue;
            }

            $types = (array) ($offer['@type'] ?? []);
            $isAggregate = in_array('AggregateOffer', $types, true);
            $price = $isAggregate
                ? ($offer['lowPrice'] ?? $offer['price'] ?? null)
                : ($offer['price'] ?? $offer['lowPrice'] ?? null);
            if (is_array($price)) {
                $price = $price['value'] ?? null;
            }
            if ($price !== null) {
                return [$price, $offer['priceCurrency'] ?? null];
            }
        }

        return [null, null];
    }

    private function imageValue(mixed $image): ?string
    {
        if (is_string($image)) {
            return $image;
        }

        if (is_array($image)) {
            if (array_is_list($image)) {
                return $this->imageValue($image[0] ?? null);
            }

            foreach (['url', 'contentUrl', '@id'] as $key) {
                if (isset($image[$key]) && is_string($image[$key])) {
                    return $image[$key];
                }
            }
        }

        return null;
    }

    private function readMetaTags(DOMXPath $xpath): array
    {
        $meta = [];
        foreach ($xpath->query('//meta[@property or @name]') ?: [] as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            $key = strtolower(trim($element->getAttribute('property') ?: $element->getAttribute('name')));
            $content = trim($element->getAttribute('content'));
            if ($key !== '' && $content !== '') {
                $meta[$key] ??= $content;
            }
        }

        if (! isset($meta['title'])) {
            $title = $xpath->query('//title')->item(0)?->textContent;
            if (is_string($title) && trim($title) !== '') {
                $meta['title'] = trim($title);
            }
        }

        return $meta;
    }

    private function extractMicrodata(
        DOMXPath $xpath,
        array &$data,
        string $pageUrl,
        array &$stages,
        array &$fieldSources
    ): void {
        $properties = [
            'title' => 'name',
            'description' => 'description',
            'image' => 'image',
            'price' => 'price',
            'currency' => 'priceCurrency',
        ];

        foreach ($properties as $field => $itemprop) {
            if ($data[$field] !== null) {
                continue;
            }

            $nodes = $xpath->query(
                '//*[@itemprop and contains(concat(" ", normalize-space(@itemprop), " "), " '.$itemprop.' ")]'
            );
            foreach ($nodes ?: [] as $node) {
                $value = $this->microdataValue($node);
                if ($value === null || trim($value) === '') {
                    continue;
                }

                if ($field === 'image' && $this->isThumbnailImage($value)) {
                    continue;
                }

                $value = match ($field) {
                    'price' => $this->normalizePrice($value),
                    'currency' => $this->normalizeCurrency($value),
                    'image' => $this->absoluteUrl($value, $pageUrl),
                    default => $value,
                };

                if ($value !== null) {
                    $data[$field] = $value;
                    $stages[$field] = 'microdata';
                    $fieldSources[$field] = [
                        'stage' => 'microdata',
                        'selector' => '//*[@itemprop="'.$itemprop.'"]',
                        'raw' => $this->microdataValue($node),
                    ];
                    break;
                }
            }
        }
    }

    private function microdataValue(DOMElement $node): ?string
    {
        foreach (['content', 'src', 'data-src', 'href', 'value'] as $attribute) {
            if ($node->hasAttribute($attribute)) {
                return trim($node->getAttribute($attribute));
            }
        }

        return trim($node->textContent);
    }

    private function extractVisiblePrice(DOMXPath $xpath, ?string $title = null, ?string $pageUrl = null): array
    {
        $currencyCodes = 'MXN|USD|CAD|EUR|GBP|JPY|ARS|BRL|CLP|COP|PEN|CHF|AUD|NZD';
        $amountPattern = '[0-9][0-9.,\s⁰¹²³⁴⁵⁶⁷⁸⁹]*';
        $pattern = '/(?:\b('.$currencyCodes.')\s*)?([$€£¥])\s*('.$amountPattern.')(?:\s*('.$currencyCodes.'))?|(?:\b('.$currencyCodes.')\s+)('.$amountPattern.')|('.$amountPattern.')/iu';
        $titleNodes = $xpath->query('//h1 | //*[@id="productTitle"]');
        $titleNode = $titleNodes?->item(0);
        $candidates = [];
        $domOrder = 0;
        $nodes = $xpath->query(
            '//body//text()[not(ancestor::script or ancestor::style or ancestor::noscript or ancestor::template or ancestor::svg or ancestor::*[@hidden])]'
        );

        foreach ($nodes ?: [] as $node) {
            $text = preg_replace('/\s+/u', ' ', trim($node->textContent)) ?? trim($node->textContent);
            if ($text === '' || preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            $parent = $node->parentNode;
            if (! $parent instanceof DOMElement
                || $this->insideDeprecatedPriceNode($parent)
                || $this->insideExcludedPriceContainer($parent)) {
                continue;
            }

            foreach ($matches as $match) {
                $amount = isset($match[3]) && $match[3][1] >= 0
                    ? $match[3][0]
                    : (isset($match[6]) && $match[6][1] >= 0
                        ? $match[6][0]
                        : ($match[7][0] ?? null));
                if (! is_string($amount)) {
                    continue;
                }

                if (isset($match[7]) && $match[7][1] >= 0 && $this->nearestPriceContainer($parent) === null) {
                    continue;
                }
                if ($this->hasExcludedPriceContext($parent, $match[0][0])) {
                    continue;
                }

                $parentClass = strtolower($parent->getAttribute('class'));
                if (str_contains($parentClass, 'a-price-fraction')) {
                    continue;
                }

                $raw = $match[0][0];
                if (str_contains($parentClass, 'a-price-whole')) {
                    $priceNode = $this->ancestorWithClass($parent, 'a-price');
                    if ($priceNode !== null) {
                        $fraction = $this->descendantTextByClass($priceNode, 'a-price-fraction');
                        $whole = preg_replace('/\D/u', '', $amount) ?? '';
                        if ($whole !== '' && $fraction !== null && preg_match('/^\d{1,2}$/', trim($fraction)) === 1) {
                            $raw = $whole.' '.str_pad(trim($fraction), 2, '0', STR_PAD_LEFT);
                            $amount = $raw;
                        }
                    }
                }

                $currencies = array_values(array_filter(
                    [$match[1][0] ?? null, $match[4][0] ?? null, $match[5][0] ?? null],
                    static fn ($value) => is_string($value) && $value !== ''
                ));
                $currency = $currencies[0] ?? match ($match[2][0] ?? '') {
                    '€' => 'EUR',
                    '£' => 'GBP',
                    '¥' => 'JPY',
                    '$' => $this->dollarCurrency($pageUrl),
                    default => null,
                };

                $priceContainer = $this->nearestPriceContainer($parent);
                $anchor = $priceContainer ?? $parent;
                $distance = $this->elementDistance($anchor, $titleNode);
                $normalizedPrice = $this->normalizePrice($amount);
                if ($normalizedPrice === null || (float) $normalizedPrice < 1) {
                    continue;
                }
                $selector = $this->selectorForNode($parent);
                $score = $this->priceCandidateScore($anchor, $titleNode, $xpath);
                $candidates[] = [
                    'price' => $normalizedPrice,
                    'currency' => $this->normalizeCurrency($currency),
                    'score' => $score,
                    'selector' => $selector,
                    'raw' => $raw,
                    'distance' => $distance,
                    'order' => $domOrder++,
                    'container' => spl_object_id($anchor),
                ];
            }
        }

        $containersWithWholePrices = [];
        foreach ($candidates as $candidate) {
            if ($candidate['price'] !== null && (float) $candidate['price'] >= 1) {
                $containersWithWholePrices[$candidate['container']] = true;
            }
        }
        $candidates = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => ! isset($containersWithWholePrices[$candidate['container']])
                || $candidate['price'] === null
                || (float) $candidate['price'] >= 1
        ));
        usort($candidates, static fn (array $left, array $right): int => ($right['score'] <=> $left['score']) ?: ($left['order'] <=> $right['order']));
        $candidates = array_slice($candidates, 0, 5);
        $best = $candidates[0] ?? null;

        return [$best['price'] ?? null, $best['currency'] ?? null, $candidates];
    }

    public function parsePrice(mixed $value): ?string
    {
        $price = $this->normalizePrice($value);

        return $price !== null && (float) $price >= 1 ? $price : null;
    }

    private function insideDeprecatedPriceNode(DOMElement $node): bool
    {
        for ($element = $node; $element instanceof DOMElement; $element = $element->parentNode) {
            if (in_array(strtolower($element->tagName), ['s', 'del', 'strike'], true)) {
                return true;
            }

            $identity = strtolower($element->getAttribute('class').' '.$element->getAttribute('id'));
            if (preg_match('/(?:strike|list|was|old)/i', $identity) === 1) {
                return true;
            }
        }

        return false;
    }

    private function hasExcludedPriceContext(DOMElement $node, string $match): bool
    {
        for ($element = $node, $depth = 0; $element instanceof DOMElement && $depth < 4; $element = $element->parentNode, $depth++) {
            $context = preg_replace('/\s+/u', ' ', $element->textContent) ?? $element->textContent;
            $offset = mb_strpos($context, trim($match));
            if ($offset === false) {
                continue;
            }

            $before = mb_strtolower(mb_substr($context, max(0, $offset - 60), min(60, $offset)));
            $after = mb_strtolower(mb_substr($context, $offset + mb_strlen($match), 45));
            if (preg_match('/(?:precio\s+de\s+lista|antes|was|list\s+price|cup[oó]n|coupon)[^0-9$€£¥]{0,35}$/iu', $before) === 1
                || preg_match('/(?:x\s*12|mensualidades|cuotas?|al\s+mes|por\s+mes)[^0-9$€£¥]{0,35}$/iu', $before) === 1
                || preg_match('/^\s*(?:\/\s*(?:unidad|pieza)|por\s+(?:pieza|kg)|env[ií]o|shipping|x\s*12|al\s+mes|mensualidades|cuotas?|coupon|cup[oó]n)\b/iu', $after) === 1) {
                return true;
            }
        }

        return false;
    }

    private function nearestPriceContainer(DOMElement $node): ?DOMElement
    {
        for ($element = $node; $element instanceof DOMElement; $element = $element->parentNode) {
            $identity = strtolower($element->getAttribute('class').' '.$element->getAttribute('id'));
            if (preg_match('/price/i', $identity) === 1) {
                return $element;
            }
        }

        return null;
    }

    private function ancestorWithClass(DOMElement $node, string $class): ?DOMElement
    {
        for ($element = $node; $element instanceof DOMElement; $element = $element->parentNode) {
            if (preg_match('/(?:^|\s)'.preg_quote($class, '/').'(?:\s|$)/', $element->getAttribute('class')) === 1) {
                return $element;
            }
        }

        return null;
    }

    private function descendantTextByClass(DOMElement $node, string $class): ?string
    {
        foreach ($node->getElementsByTagName('*') as $element) {
            if ($element instanceof DOMElement
                && preg_match('/(?:^|\s)'.preg_quote($class, '/').'(?:\s|$)/', $element->getAttribute('class')) === 1) {
                return trim($element->textContent);
            }
        }

        return null;
    }

    private function priceCandidateScore(DOMElement $node, ?\DOMNode $titleNode, DOMXPath $xpath): int
    {
        $identity = '';
        for ($element = $node, $depth = 0; $element instanceof DOMElement && $depth < 12; $element = $element->parentNode, $depth++) {
            $identity .= ' '.$element->getAttribute('id').' '.$element->getAttribute('class');
        }
        $identity = strtolower($identity);
        $score = preg_match('/coreprice|pricetopay|apex|buybox|price/i', $identity) === 1 ? 30 : 0;
        if (preg_match('/coreprice_feature_div|apex-core-price-identifier|apex-pricetopay-value|priceToPay/i', $identity) === 1) {
            $score += 120;
        }
        if (preg_match('/qualifiedbuybox|desktop_qualifiedbuybox/i', $identity) === 1) {
            $score += 50;
        }
        if (preg_match('/buybox|coreprice|pricetopay|apex/i', $identity) === 1) {
            $score += 30;
        }

        $titleDistance = $this->elementDistance($node, $titleNode);
        $score += max(0, 25 - min(25, $titleDistance));
        $buttons = $xpath->query('//button[contains(translate(normalize-space(.), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "agregar al carrito") or contains(translate(normalize-space(.), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "comprar ahora") or contains(translate(normalize-space(.), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "add to cart") or contains(translate(normalize-space(.), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "buy now")] | //*[@role="button"][contains(translate(normalize-space(.), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "agregar al carrito") or contains(translate(normalize-space(.), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "comprar ahora")]');
        $buttonDistance = PHP_INT_MAX;
        foreach ($buttons ?: [] as $button) {
            if ($button instanceof DOMElement) {
                $buttonDistance = min($buttonDistance, $this->elementDistance($node, $button));
            }
        }
        if ($buttonDistance !== PHP_INT_MAX) {
            $score += max(0, 30 - min(30, $buttonDistance));
        }

        return $score;
    }

    private function insideExcludedPriceContainer(DOMElement $node): bool
    {
        for ($element = $node; $element instanceof DOMElement; $element = $element->parentNode) {
            $identity = strtolower($element->getAttribute('id').' '.$element->getAttribute('class'));
            if (preg_match('/installment|number-of-payments|promomessage|coupon|aok-hidden|monthly/i', $identity) === 1) {
                return true;
            }
        }

        return false;
    }

    private function selectorForNode(DOMElement $node): string
    {
        $parts = [];
        for ($element = $node, $depth = 0; $element instanceof DOMElement && $depth < 10; $element = $element->parentNode, $depth++) {
            if ($element->getAttribute('id') !== '') {
                array_unshift($parts, '#'.$element->getAttribute('id'));
                break;
            }
            $class = trim($element->getAttribute('class'));
            if ($class !== '') {
                $firstClass = preg_split('/\s+/', $class)[0] ?? '';
                if (preg_match('/^[a-zA-Z_][\w-]*$/', $firstClass) === 1) {
                    array_unshift($parts, '.'.$firstClass);
                }
            }
        }

        return implode(' ', $parts);
    }

    private function extractAmazonPrice(DOMXPath $xpath, ?string $title, string $pageUrl): array
    {
        $titleNode = $xpath->query('//h1 | //*[@id="productTitle"]')?->item(0);
        $containers = $xpath->query(
            '//*[@id or @class][contains(translate(concat(@id, " ", @class), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "price") or contains(translate(concat(@id, " ", @class), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "buybox")]'
        );
        $candidates = [];
        $order = 0;
        $seen = [];

        foreach ($containers ?: [] as $container) {
            if (! $container instanceof DOMElement) {
                continue;
            }
            $identity = strtolower($container->getAttribute('id').' '.$container->getAttribute('class'));
            if (! preg_match('/coreprice|pricetopay|apex|buybox|price/i', $identity)) {
                continue;
            }

            $nodes = $container->getElementsByTagName('*');
            foreach ($nodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }
                if ($this->insideExcludedPriceContainer($node)) {
                    continue;
                }
                $isOffscreen = preg_match('/(?:^|\s)a-offscreen(?:\s|$)/', $node->getAttribute('class')) === 1;
                $isPriceItem = preg_match('/(?:^|\s)price(?:\s|$)/', $node->getAttribute('itemprop')) === 1;
                if (! $isOffscreen && ! $isPriceItem) {
                    continue;
                }
                $raw = $isPriceItem && $node->hasAttribute('content')
                    ? trim($node->getAttribute('content'))
                    : trim($node->textContent);
                $price = $this->parsePrice($raw);
                if ($price === null || isset($seen[$price.'|'.$raw])) {
                    continue;
                }
                $seen[$price.'|'.$raw] = true;
                $anchor = $this->nearestPriceContainer($node) ?? $container;
                $candidates[] = [
                    'price' => $price,
                    'currency' => $this->currencyFromText($raw, $pageUrl),
                    'score' => $this->priceCandidateScore($anchor, $titleNode, $xpath),
                    'selector' => $this->selectorForNode($node),
                    'raw' => $raw,
                    'order' => $order++,
                ];
            }

            $priceNodes = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " a-price ")]', $container);
            foreach ($priceNodes ?: [] as $priceNode) {
                if (! $priceNode instanceof DOMElement || $this->insideExcludedPriceContainer($priceNode)) {
                    continue;
                }
                $whole = $this->descendantTextByClass($priceNode, 'a-price-whole');
                $fraction = $this->descendantTextByClass($priceNode, 'a-price-fraction');
                if ($whole === null || $fraction === null) {
                    continue;
                }
                $wholeDigits = preg_replace('/\D/u', '', $whole) ?? '';
                $fractionDigits = preg_replace('/\D/u', '', $fraction) ?? '';
                if ($wholeDigits === '' || $fractionDigits === '') {
                    continue;
                }
                $raw = $wholeDigits.' '.str_pad(substr($fractionDigits, 0, 2), 2, '0', STR_PAD_LEFT);
                $price = $this->parsePrice($raw);
                if ($price === null || isset($seen[$price.'|'.$raw])) {
                    continue;
                }
                $seen[$price.'|'.$raw] = true;
                $anchor = $this->nearestPriceContainer($priceNode) ?? $container;
                $candidates[] = [
                    'price' => $price,
                    'currency' => $this->currencyFromText($priceNode->textContent, $pageUrl),
                    'score' => $this->priceCandidateScore($anchor, $titleNode, $xpath),
                    'selector' => $this->selectorForNode($priceNode),
                    'raw' => $raw,
                    'order' => $order++,
                ];
            }
        }

        usort($candidates, static fn (array $left, array $right): int => ($right['score'] <=> $left['score']) ?: ($left['order'] <=> $right['order']));
        $uniqueCandidates = [];
        $seenPrices = [];
        foreach ($candidates as $candidate) {
            $key = (string) $candidate['price'];
            if (isset($seenPrices[$key])) {
                continue;
            }
            $seenPrices[$key] = true;
            $uniqueCandidates[] = $candidate;
        }
        $candidates = array_slice($uniqueCandidates, 0, 5);
        $best = $candidates[0] ?? null;

        return [$best['price'] ?? null, $best['currency'] ?? null, $candidates];
    }

    private function extractAmazonImage(DOMXPath $xpath, string $pageUrl): array
    {
        $nodes = $xpath->query('//img[@id="landingImage"] | //*[@data-a-image-name="landingImage"] | //*[@id="imgTagWrapperId"]//img');
        foreach ($nodes ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $oldHires = trim($node->getAttribute('data-old-hires'));
            if ($oldHires !== '' && ! $this->isThumbnailImage($oldHires)) {
                return [$this->absoluteUrl($this->originalImageUrl($oldHires), $pageUrl), $this->selectorForNode($node).'[data-old-hires]', $oldHires];
            }
        }

        $dynamicCandidates = [];
        foreach ($nodes ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $dynamic = json_decode($node->getAttribute('data-a-dynamic-image'), true);
            if (is_array($dynamic)) {
                foreach ($dynamic as $url => $dimensions) {
                    if (! is_string($url)) {
                        continue;
                    }
                    $width = is_array($dimensions) ? (int) ($dimensions[0] ?? 0) : 0;
                    $height = is_array($dimensions) ? (int) ($dimensions[1] ?? 0) : 0;
                    $dynamicCandidates[] = compact('url', 'width', 'height', 'node');
                }
            }
        }
        usort($dynamicCandidates, static fn (array $left, array $right): int => ($right['width'] * $right['height']) <=> ($left['width'] * $left['height']));
        foreach ($dynamicCandidates as $candidate) {
            if ($this->isThumbnailImage($candidate['url'], $candidate['width'], $candidate['height'])) {
                continue;
            }

            return [
                $this->absoluteUrl($this->originalImageUrl($candidate['url']), $pageUrl),
                $this->selectorForNode($candidate['node']).'[data-a-dynamic-image]',
                $candidate['url'],
            ];
        }

        foreach ($nodes ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $srcset = trim($node->getAttribute('srcset'));
            if ($srcset === '') {
                continue;
            }
            $candidates = [];
            foreach (explode(',', $srcset) as $entry) {
                if (preg_match('/^\s*(\S+)\s+(\d+)w\s*$/', $entry, $match) === 1) {
                    $candidates[] = ['url' => $match[1], 'width' => (int) $match[2]];
                }
            }
            usort($candidates, static fn (array $a, array $b): int => $b['width'] <=> $a['width']);
            foreach ($candidates as $candidate) {
                if (! $this->isThumbnailImage($candidate['url'], $candidate['width'])) {
                    return [
                        $this->absoluteUrl($this->originalImageUrl($candidate['url']), $pageUrl),
                        $this->selectorForNode($node).'[srcset]',
                        $candidate['url'],
                    ];
                }
            }
        }

        foreach ($nodes ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $src = trim($node->getAttribute('src'));
            if ($src !== '' && ! $this->isThumbnailImage($src, (int) $node->getAttribute('width'), (int) $node->getAttribute('height'))) {
                return [$this->absoluteUrl($src, $pageUrl), $this->selectorForNode($node).'[src]', $src];
            }
        }

        return [null, null, null];
    }

    private function extractUsefulDescription(DOMXPath $xpath, ?string $title): array
    {
        $queries = [
            '//*[@id="feature-bullets" or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "feature-bullets")]',
            '//*[@id or @class][contains(translate(concat(@id, " ", @class), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "about") or contains(translate(concat(@id, " ", @class), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "description") or contains(translate(concat(@id, " ", @class), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "product-details")]',
            '//*[@itemprop="description"]',
        ];
        foreach ($queries as $query) {
            foreach ($xpath->query($query) ?: [] as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }
                $lines = [];
                foreach ($node->getElementsByTagName('li') as $item) {
                    $line = $this->cleanText($item->textContent);
                    if ($line !== null && ! preg_match('/^(?:ver los detalles|see more|show more)/iu', $line)) {
                        $lines[] = $line;
                    }
                }
                $raw = $lines !== [] ? implode("\n", array_unique($lines)) : trim($node->textContent);
                $description = $this->cleanDescription($raw);
                if ($description !== null && ! $this->descriptionIsTitleLike($description, $title)) {
                    return [$description, $this->selectorForNode($node), $raw];
                }
            }
        }

        return [null, null, null];
    }

    private function cleanDescription(string $description): ?string
    {
        $lines = preg_split('/\R/u', html_entity_decode(strip_tags($description), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: [];
        $lines = array_values(array_filter(array_map(
            static fn (string $line): string => trim(preg_replace('/\s+/u', ' ', $line) ?? $line),
            $lines
        ), static fn (string $line): bool => $line !== ''));
        $description = mb_substr(implode("\n", array_unique($lines)), 0, 1000);

        return trim($description) === '' ? null : $description;
    }

    private function descriptionIsTitleLike(mixed $description, mixed $title): bool
    {
        if (! is_string($description) || ! is_string($title) || trim($description) === '' || trim($title) === '') {
            return false;
        }
        $description = preg_replace('/\s*:\s*(?:amazon|mercado\s+libre|walmart|liverpool)\b.*$/iu', '', trim($description)) ?? trim($description);
        $normalize = static fn (string $value): string => strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', Str::ascii($value)) ?? $value));
        $normalizedDescription = $normalize($description);
        $normalizedTitle = $normalize($title);
        if ($normalizedDescription === $normalizedTitle) {
            return true;
        }
        similar_text($normalizedDescription, $normalizedTitle, $similarity);
        $descriptionWords = array_unique(array_filter(explode(' ', $normalizedDescription)));
        $titleWords = array_unique(array_filter(explode(' ', $normalizedTitle)));
        $sharedWords = count(array_intersect($descriptionWords, $titleWords));
        $overlap = $sharedWords / max(1, count($descriptionWords));

        return $similarity >= 88 || (strlen($normalizedDescription) < strlen($normalizedTitle) * 1.15 && $overlap >= 0.85);
    }

    private function isThumbnailImage(?string $url, int $width = 0, int $height = 0): bool
    {
        if ($url === null) {
            return true;
        }
        if (($width > 0 && $width < 200) || ($height > 0 && $height < 200)) {
            return true;
        }

        return preg_match('/(?:_SS\d+_|_AC_US\d+_|_SX\d{2,3}_|_SY\d{2,3}_|thumb|sprite)/i', $url) === 1;
    }

    private function originalImageUrl(string $url): string
    {
        return preg_replace('/\._AC_(?:SS\d+|US\d+|SX\d{2,3}|SY\d{2,3})[^.]*_/i', '', $url) ?? $url;
    }

    private function isAmazonDomain(?string $domain): bool
    {
        return is_string($domain) && preg_match('/(^|\.)amazon\.[a-z.]+$/i', $domain) === 1;
    }

    private function currencyFromText(string $text, ?string $pageUrl = null): ?string
    {
        if (preg_match('/\b(MXN|USD|CAD|EUR|GBP|JPY|ARS|BRL|CLP|COP|PEN)\b/i', $text, $match) === 1) {
            return strtoupper($match[1]);
        }

        return match (true) {
            str_contains($text, '€') => 'EUR',
            str_contains($text, '£') => 'GBP',
            str_contains($text, '¥') => 'JPY',
            str_contains($text, '$') => $this->dollarCurrency($pageUrl),
            default => null,
        };
    }

    private function dollarCurrency(?string $pageUrl): string
    {
        $host = strtolower((string) parse_url((string) $pageUrl, PHP_URL_HOST));
        if (str_ends_with($host, '.ca')) {
            return 'CAD';
        }
        if ($host === 'amazon.com' || str_ends_with($host, '.amazon.com') || str_ends_with($host, '.com')) {
            return 'USD';
        }

        return 'MXN';
    }

    private function inferCurrency(string $pageUrl, string $html): ?string
    {
        $host = strtolower((string) parse_url($pageUrl, PHP_URL_HOST));
        if (str_ends_with($host, '.com.mx') || str_ends_with($host, '.mx')) {
            return 'MXN';
        }
        if (str_ends_with($host, '.ca')) {
            return 'CAD';
        }
        if (str_ends_with($host, '.co.uk')) {
            return 'GBP';
        }
        if ($host === 'amazon.com' || str_ends_with($host, '.amazon.com')) {
            return 'USD';
        }

        return $this->currencyFromText($html, $pageUrl);
    }

    private function elementDistance(?DOMElement $first, ?\DOMNode $second): int
    {
        if ($first === null || $second === null) {
            return PHP_INT_MAX;
        }

        $firstPath = explode('/', trim($first->getNodePath(), '/'));
        $secondPath = explode('/', trim($second->getNodePath(), '/'));
        $sharedSegments = 0;
        $limit = min(count($firstPath), count($secondPath));
        while ($sharedSegments < $limit && $firstPath[$sharedSegments] === $secondPath[$sharedSegments]) {
            $sharedSegments++;
        }

        return count($firstPath) + count($secondPath) - (2 * $sharedSegments);
    }

    private function largestContentImage(DOMXPath $xpath, string $pageUrl): ?string
    {
        $nodes = $xpath->query('//main//img | //*[@role="main"]//img | //article//img');

        $candidates = [];
        foreach ($nodes ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $source = $node->getAttribute('src')
                ?: $node->getAttribute('data-src')
                ?: explode(',', $node->getAttribute('srcset'))[0];
            $source = trim((string) preg_replace('/\s+\d+(?:\.\d+)?[wx]$/', '', $source));
            $resolved = $this->absoluteUrl($source, $pageUrl);
            if ($resolved === null) {
                continue;
            }

            $width = (int) preg_replace('/\D/', '', $node->getAttribute('width'));
            $height = (int) preg_replace('/\D/', '', $node->getAttribute('height'));
            $candidates[] = ['url' => $resolved, 'area' => $width * $height];
        }

        usort($candidates, static fn (array $first, array $second) => $second['area'] <=> $first['area']);

        return $candidates[0]['url'] ?? null;
    }

    private function domainSelectors(?string $domain): array
    {
        if ($domain === null) {
            return [];
        }

        foreach ((array) config('scraper.domains', []) as $suffix => $selectors) {
            if ($domain === $suffix || str_ends_with($domain, '.'.$suffix)) {
                return (array) $selectors;
            }
        }

        return [];
    }

    private function selectCssValue(DOMXPath $xpath, string $selector, string $field): ?string
    {
        $segments = preg_split('/\s+/', trim($selector)) ?: [];
        $xpathSegments = [];
        foreach ($segments as $segment) {
            if (preg_match('/^([a-z][a-z0-9-]*)?(#[a-zA-Z_][\w-]*)?((?:\.[a-zA-Z_][\w-]*)*)$/i', $segment, $matches) !== 1) {
                return null;
            }

            $tag = $matches[1] !== '' ? $matches[1] : '*';
            $conditions = [];
            if (($matches[2] ?? '') !== '') {
                $conditions[] = '@id='.json_encode(substr($matches[2], 1), JSON_THROW_ON_ERROR);
            }
            if (($matches[3] ?? '') !== '') {
                foreach (explode('.', ltrim($matches[3], '.')) as $class) {
                    $conditions[] = 'contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")';
                }
            }

            $xpathSegments[] = $tag.($conditions === [] ? '' : '['.implode(' and ', $conditions).']');
        }

        $node = $xpath->query('//'.implode('//', $xpathSegments))->item(0);
        if (! $node instanceof DOMElement) {
            return null;
        }

        if ($field === 'image') {
            return $node->getAttribute('src')
                ?: $node->getAttribute('data-src')
                ?: $node->getAttribute('data-old-hires');
        }

        return trim($node->getAttribute('content') ?: $node->textContent);
    }

    private function normalizePrice(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $rawValue = (string) $value;
        $superscriptCents = preg_match('/(-?\d+)[⁰¹²³⁴⁵⁶⁷⁸⁹]{2}$/u', trim($rawValue), $cents);
        $number = strtr($rawValue, [
            '⁰' => '0', '¹' => '1', '²' => '2', '³' => '3', '⁴' => '4',
            '⁵' => '5', '⁶' => '6', '⁷' => '7', '⁸' => '8', '⁹' => '9',
        ]);
        if ($superscriptCents === 1) {
            $number = $cents[1].'.'.substr($number, -2);
        } elseif (preg_match('/^-?\d+\s+\d{2}$/u', trim($number)) === 1) {
            $number = preg_replace('/\s+/', '.', trim($number));
        } else {
            $number = preg_replace('/\s+/u', '', $number);
        }
        $number = preg_replace('/[^0-9,.-]/', '', $number);
        if ($number === null || $number === '' || preg_match('/[0-9]/', $number) !== 1) {
            return null;
        }

        $lastComma = strrpos($number, ',');
        $lastDot = strrpos($number, '.');
        $decimalSeparator = null;

        if ($lastComma !== false && $lastDot !== false) {
            $decimalSeparator = $lastComma > $lastDot ? ',' : '.';
        } elseif ($lastComma !== false || $lastDot !== false) {
            $separator = $lastComma !== false ? ',' : '.';
            $lastSeparator = strrpos($number, $separator);
            $digitsAfter = strlen($number) - $lastSeparator - 1;
            $separatorCount = substr_count($number, $separator);
            if ($digitsAfter > 0 && $digitsAfter <= 2 && $separatorCount === 1) {
                $decimalSeparator = $separator;
            }
        }

        if ($decimalSeparator !== null) {
            $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';
            $number = str_replace($thousandsSeparator, '', $number);
            $number = str_replace($decimalSeparator, '.', $number);
        } else {
            $number = str_replace([',', '.'], '', $number);
        }

        if (! is_numeric($number)) {
            return null;
        }

        $normalized = number_format((float) $number, 2, '.', '');

        return (float) $normalized < 1 ? null : $normalized;
    }

    private function normalizeCurrency(mixed $currency): ?string
    {
        if (! is_string($currency) || trim($currency) === '') {
            return null;
        }

        $currency = strtoupper(trim($currency));

        return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : null;
    }

    private function absoluteUrl(?string $url, string $pageUrl): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        try {
            $resolved = (string) UriResolver::resolve(new Uri($pageUrl), new Uri(trim($url)));
        } catch (\InvalidArgumentException) {
            return null;
        }

        return in_array(strtolower((string) parse_url($resolved, PHP_URL_SCHEME)), ['http', 'https'], true)
            ? $resolved
            : null;
    }

    private function cleanText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? $value);

        return $value === '' ? null : $value;
    }

    private function cleanMetadataText(mixed $value, ?string $domain): ?string
    {
        $value = $this->cleanText($value);
        if ($value === null) {
            return null;
        }

        $blockedPhrases = '/robot check|access denied|just a moment|captcha|página no encontrada/iu';
        if (preg_match($blockedPhrases, $value) === 1) {
            return null;
        }

        if ($domain !== null && $this->normalizeStoreIdentity($value) !== ''
            && $this->normalizeStoreIdentity($value) === $this->normalizeStoreIdentity($domain)) {
            return null;
        }

        return $value;
    }

    private function normalizeStoreIdentity(string $value): string
    {
        $value = Str::lower(Str::ascii(trim($value)));
        $value = preg_replace('/^www\./', '', $value) ?? $value;
        $value = preg_replace('/\.(?:com|net|org|co|gov|edu|ac)(?:\.[a-z]{2})?$/', '', $value) ?? $value;
        $value = preg_replace('/\.[a-z]{2,}$/', '', $value) ?? $value;

        return preg_replace('/[^a-z0-9]/', '', $value) ?? '';
    }

    private function fillIfMissing(
        array &$data,
        string $key,
        mixed $value,
        ?array &$stages = null,
        string $stage = 'unknown',
        ?array &$fieldSources = null,
        ?string $selector = null,
        mixed $raw = null
    ): void {
        if ($data[$key] === null && $value !== null && $value !== '') {
            $data[$key] = $value;
            if ($stages !== null) {
                $stages[$key] = $stage;
            }
            if ($fieldSources !== null) {
                $fieldSources[$key] = [
                    'stage' => $stage,
                    'selector' => $selector,
                    'raw' => is_scalar($raw) ? (string) $raw : $raw,
                ];
            }
        }
    }
}
