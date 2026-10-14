<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(Category::query()->orderBy('name')->get(['id', 'name', 'slug']));
    }
}
