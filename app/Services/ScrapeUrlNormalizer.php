<?php

namespace App\Services;

class ScrapeUrlNormalizer
{
    public function normalize(string $url): string
    {
        $url = preg_replace('/#.*$/s', '', trim($url)) ?? trim($url);
        $queryPosition = strpos($url, '?');
        if ($queryPosition === false) {
            return $url;
        }

        $base = substr($url, 0, $queryPosition);
        $query = substr($url, $queryPosition + 1);
        $trackingNames = [
            'fbclid', 'gclid', 'ref', 'crid', 'dib', 'dib_tag', 'sp_csd', 'qid',
            'sr', 'sprefix', 'keywords', 'psc', 'th', 'polycard_client', 'wid',
            'sid', 'is_advertising',
        ];
        $remaining = [];

        foreach (explode('&', $query) as $parameter) {
            if ($parameter === '') {
                continue;
            }

            $key = strtolower(rawurldecode(explode('=', $parameter, 2)[0]));
            $isTracking = in_array($key, $trackingNames, true)
                || str_starts_with($key, 'utm_')
                || str_starts_with($key, 'reco_')
                || str_starts_with($key, 'ad_');

            if (! $isTracking) {
                $remaining[] = $parameter;
            }
        }

        return $remaining === [] ? $base : $base.'?'.implode('&', $remaining);
    }
}
