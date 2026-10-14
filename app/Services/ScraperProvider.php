<?php

namespace App\Services;

interface ScraperProvider
{
    public function name(): string;

    public function fetch(string $url): ?ScraperPage;
}
