<?php

namespace App\Http\Controllers;

use App\Services\ProductPageScraper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScrapeController extends Controller
{
    public function __invoke(Request $request, ProductPageScraper $scraper): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
        ]);

        return response()->json($scraper->scrape($data['url']));
    }
}
