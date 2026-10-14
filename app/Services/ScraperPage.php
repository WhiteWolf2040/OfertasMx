<?php

namespace App\Services;

final readonly class ScraperPage
{
    public function __construct(
        public string $html,
        public string $finalUrl,
        public int $status,
        public ?string $screenshot = null,
    ) {}
}
