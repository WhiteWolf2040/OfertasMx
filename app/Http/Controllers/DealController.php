<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DealController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Deal::query()
            ->with(['user:id,name', 'category:id,name,slug'])
            ->withSum('votes', 'value')
            ->withCount('comments');

        $category = $request->query('category_id', $request->query('category'));
        if (is_string($category) && $category !== '') {
            $query->whereHas('category', static function (Builder $builder) use ($category): void {
                if (ctype_digit($category)) {
                    $builder->whereKey((int) $category);
                } else {
                    $builder->where('slug', $category);
                }
            });
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(static function (Builder $builder) use ($search): void {
                $builder->where('title', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%')
                    ->orWhere('store', 'like', '%'.$search.'%');
            });
        }

        if (in_array($request->query('sort'), ['popular', 'most-voted', 'votes'], true)) {
            $query->orderByDesc('votes_sum_value')->orderByDesc('created_at');
        } else {
            $query->latest();
        }

        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);

        return response()->json($query->paginate($perPage));
    }

    public function show(Deal $deal): JsonResponse
    {
        return response()->json($deal->load([
            'user:id,name',
            'category:id,name,slug',
            'comments.user:id,name',
        ])->loadSum('votes', 'value'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:2048'],
            'price' => ['required', 'numeric', 'min:0'],
            'store' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'url', 'max:2048'],
            'original_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $deal = $request->user()->deals()->create($data);

        return response()->json($deal->load(['user:id,name', 'category:id,name,slug']), 201);
    }

    public function myDeals(Request $request): JsonResponse
    {
        $deals = $request->user()->deals()
            ->with(['user:id,name', 'category:id,name,slug'])
            ->withSum('votes', 'value')
            ->withCount('comments')
            ->latest()
            ->paginate(min(max((int) $request->query('per_page', 15), 1), 50));

        return response()->json($deals);
    }

    public function update(Request $request, Deal $deal): JsonResponse
    {
        $this->authorize('update', $deal);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'url' => ['sometimes', 'required', 'url', 'max:2048'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'store' => ['sometimes', 'required', 'string', 'max:255'],
            'category_id' => ['sometimes', 'required', 'integer', 'exists:categories,id'],
            'description' => ['sometimes', 'nullable', 'string'],
            'image' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'original_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $deal->update($data);

        return response()->json($deal->fresh()->load(['user:id,name', 'category:id,name,slug']));
    }

    public function destroy(Deal $deal): JsonResponse
    {
        $this->authorize('delete', $deal);
        $deal->delete();

        return response()->json(['message' => 'Oferta eliminada correctamente.']);
    }
}
