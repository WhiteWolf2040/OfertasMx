<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Validation\ValidationException;

class RobotSnapshotSanitizer
{
    private const MAX_BYTES = 3 * 1024 * 1024;

    public function reduceOversizedSnapshot(string $html): string
    {
        if (strlen($html) <= self::MAX_BYTES) {
            return $html;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrorMode = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorMode);
        if (! $loaded) {
            return $html;
        }

        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//style') ?: [] as $style) {
            $style->parentNode?->removeChild($style);
        }
        foreach ($xpath->query('//*') ?: [] as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            $attributes = [];
            foreach ($element->attributes as $attribute) {
                if (str_starts_with(strtolower($attribute->name), 'data-')) {
                    $attributes[] = $attribute->name;
                }
            }
            foreach ($attributes as $attribute) {
                $element->removeAttribute($attribute);
            }
        }

        $reduced = $document->saveHTML();

        return is_string($reduced) ? $reduced : $html;
    }

    public function sanitize(string $html, string $originalUrl): string
    {
        $html = $this->reduceOversizedSnapshot($html);
        if (strlen($html) > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'snapshot_html' => 'El snapshot supera 3 MB',
            ]);
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrorMode = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorMode);

        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//script | //iframe | //object | //embed | //noscript | //base') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        foreach ($xpath->query('//link') ?: [] as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }
            $rel = strtolower($link->getAttribute('rel'));
            if (preg_match('/(?:^|\s)(?:preload|modulepreload)(?:\s|$)/', $rel) === 1) {
                $link->parentNode?->removeChild($link);
            }
        }

        foreach ($xpath->query('//*') ?: [] as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }
            $attributes = [];
            foreach ($element->attributes as $attribute) {
                $name = strtolower($attribute->name);
                $value = preg_replace('/[\x00-\x20\x7f]+/u', '', $attribute->value) ?? $attribute->value;
                if (str_starts_with($name, 'on')
                    || (in_array($name, ['href', 'src'], true) && preg_match('/^\s*javascript:/i', $value) === 1)) {
                    $attributes[] = $attribute->name;
                }
            }
            foreach ($attributes as $attribute) {
                $element->removeAttribute($attribute);
            }
        }

        $head = $xpath->query('//head')->item(0);
        if (! $head instanceof DOMElement) {
            $htmlElement = $xpath->query('//html')->item(0);
            if (! $htmlElement instanceof DOMElement) {
                $htmlElement = $document->createElement('html');
                $document->appendChild($htmlElement);
            }
            $head = $document->createElement('head');
            $htmlElement->insertBefore($head, $htmlElement->firstChild);
        }

        $base = $document->createElement('base');
        $base->setAttribute('href', $originalUrl);
        $head->insertBefore($base, $head->firstChild);

        foreach ($xpath->query('//meta[translate(@name, "VIEWPORT", "viewport")="viewport"]') ?: [] as $viewport) {
            $viewport->parentNode?->removeChild($viewport);
        }
        $viewport = $document->createElement('meta');
        $viewport->setAttribute('name', 'viewport');
        $viewport->setAttribute('content', 'width=1280');
        $head->appendChild($viewport);

        $style = $document->createElement('style');
        $style->appendChild($document->createTextNode(
            'html,body{max-width:100%!important;overflow-x:hidden!important}body{overflow-wrap:anywhere!important}img,video,svg,iframe{max-width:100%!important}'
        ));
        $head->appendChild($style);

        $sanitized = $document->saveHTML();
        if (is_string($sanitized)) {
            $sanitized = $this->reduceOversizedSnapshot($sanitized);
        }
        if (! is_string($sanitized) || strlen($sanitized) > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'snapshot_html' => 'El snapshot supera 3 MB',
            ]);
        }

        return $sanitized;
    }
}
