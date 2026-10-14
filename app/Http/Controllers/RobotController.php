<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRobotRequest;
use App\Http\Requests\UpdateRobotRequest;
use App\Http\Resources\RobotResource;
use App\Jobs\RunRobotJob;
use App\Models\Robot;
use App\Models\RobotRun;
use App\Models\User;
use App\Services\ProductPageScraper;
use App\Services\RobotSnapshotSanitizer;
use App\Services\StoreProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RobotController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return RobotResource::collection(
            $request->user()->robots()
                ->latest()
                ->paginate(min(max((int) $request->query('per_page', 15), 1), 50))
        );
    }

    public function store(
        StoreRobotRequest $request,
        RobotSnapshotSanitizer $snapshotSanitizer,
        StoreProfileService $profiles
    ): JsonResponse {
        $data = $request->validated();
        $interval = $data['interval_minutes'] ?? 10;
        $overrides = $data['overrides'] ?? null;
        $snapshot = isset($data['snapshot_html'])
            ? $snapshotSanitizer->sanitize($data['snapshot_html'], $data['url'])
            : null;
        $robot = $request->user()->robots()->create([
            'url' => $data['url'],
            'name' => $data['name'] ?? $overrides['title'] ?? null,
            'interval_minutes' => $interval,
            'is_active' => true,
            'selectors' => $data['selectors'] ?? null,
            'overrides' => $overrides,
            'target_price' => $data['target_price'] ?? null,
            'notify_on_drop' => $data['notify_on_drop'] ?? false,
            'notify_on_restock' => $data['notify_on_restock'] ?? false,
            'next_run_at' => now()->addMinutes($interval),
        ]);
        if (is_array($data['selectors'] ?? null) && $data['selectors'] !== []) {
            $profiles->remember($request->user(), $robot->url, $data['selectors']);
        }

        if ($snapshot !== null) {
            $this->saveSnapshot($robot, $snapshot);
        }

        if (is_array($overrides)) {
            $this->savePreviewRun($robot, $overrides);
        } else {
            RunRobotJob::dispatch($robot->id)->onConnection('database');
        }

        return (new RobotResource($robot->fresh()))->response()->setStatusCode(201);
    }

    public function show(Robot $robot): RobotResource
    {
        $this->authorize('view', $robot);

        return new RobotResource($robot->load([
            'runs' => fn ($query) => $query->latest('created_at')->limit(50),
        ]));
    }

    public function update(
        UpdateRobotRequest $request,
        Robot $robot,
        RobotSnapshotSanitizer $snapshotSanitizer,
        StoreProfileService $profiles
    ): RobotResource {
        $this->authorize('update', $robot);
        $data = $request->validated();
        $snapshot = isset($data['snapshot_html'])
            ? $snapshotSanitizer->sanitize($data['snapshot_html'], $robot->url)
            : null;
        unset($data['snapshot_html']);
        $robot->update($data);
        if ($snapshot !== null) {
            $this->saveSnapshot($robot, $snapshot);
        }
        if (is_array($data['selectors'] ?? null) && $data['selectors'] !== []) {
            $profiles->remember($request->user(), $robot->url, $data['selectors']);
        }

        return new RobotResource($robot->fresh());
    }

    public function preview(
        Request $request,
        ProductPageScraper $scraper,
        RobotSnapshotSanitizer $sanitizer,
        StoreProfileService $profiles
    ): JsonResponse {
        $this->prepareSnapshotInput($request, $sanitizer);
        $data = $request->validate([
            'url' => ['required', 'string', 'url:http,https', 'max:2048'],
            'provider' => ['sometimes', 'in:browser'],
            'snapshot_html' => $this->snapshotHtmlRules(),
        ]);

        return response()->json($this->analyze(
            $data['url'],
            $scraper,
            $sanitizer,
            $data['provider'] ?? null,
            $request->user(),
            null,
            $profiles
        ));
    }

    public function repreview(
        Request $request,
        Robot $robot,
        ProductPageScraper $scraper,
        RobotSnapshotSanitizer $sanitizer,
        StoreProfileService $profiles
    ): JsonResponse {
        $this->authorize('update', $robot);
        $this->prepareSnapshotInput($request, $sanitizer);
        $data = $request->validate([
            'provider' => ['sometimes', 'in:browser'],
            'snapshot_html' => $this->snapshotHtmlRules(),
        ]);
        $result = $this->analyze(
            $robot->url,
            $scraper,
            $sanitizer,
            $data['provider'] ?? null,
            $request->user(),
            $robot->selectors,
            $profiles
        );
        $this->saveSnapshot($robot, $result['snapshot_html']);
        $robot->refresh();

        return response()->json([
            ...$result,
            'snapshot_path' => $robot->snapshot_path,
        ]);
    }

    public function snapshot(Robot $robot): Response
    {
        $this->authorize('view', $robot);
        abort_unless(
            is_string($robot->snapshot_path) && Storage::disk('local')->exists($robot->snapshot_path),
            404,
            'La vista previa todavía no está disponible.'
        );

        return response(Storage::disk('local')->get($robot->snapshot_path), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src https: data:; script-src 'none'; object-src 'none'; base-uri https:",
        ]);
    }

    public function destroy(Robot $robot): JsonResponse
    {
        $this->authorize('delete', $robot);
        if ($robot->snapshot_path !== null) {
            Storage::disk('local')->delete($robot->snapshot_path);
        }
        $robot->delete();

        return response()->json(['message' => 'Robot eliminado correctamente.']);
    }

    public function run(Robot $robot): JsonResponse
    {
        $this->authorize('run', $robot);
        $robot->forceFill([
            'next_run_at' => now()->addMinutes($robot->interval_minutes),
        ])->save();

        RunRobotJob::dispatch($robot->id)->onConnection('database');

        return response()->json(['message' => 'Ejecución del robot encolada.'], 202);
    }

    public function prices(Robot $robot): JsonResponse
    {
        $this->authorize('view', $robot);

        $runs = $robot->runs()
            ->where('status', 'success')
            ->whereNotNull('price')
            ->orderBy('created_at')
            ->get(['price', 'created_at']);
        $prices = $this->filterPricePoints($runs->map(fn ($run): array => [
            'date' => $run->created_at,
            'price' => $run->price,
        ])->all());

        return response()->json($prices);
    }

    public function stats(Request $request, Robot $robot): JsonResponse
    {
        $this->authorize('view', $robot);
        $data = $request->validate([
            'range' => ['sometimes', 'in:24h,7d,30d,all'],
        ]);
        $range = $data['range'] ?? 'all';
        $query = $robot->runs()
            ->where('status', 'success')
            ->whereNotNull('price')
            ->orderBy('created_at');
        if ($range !== 'all') {
            $since = now()->sub(match ($range) {
                '24h' => '24 hours',
                '7d' => '7 days',
                '30d' => '30 days',
            });
            $query->where('created_at', '>=', $since);
        }
        $runs = $query->get(['price', 'created_at']);
        $points = $this->filterPricePoints($runs->map(fn ($run): array => [
            'date' => $run->created_at,
            'price' => $run->price,
        ])->all());
        $values = array_column($points, 'price');
        $current = $points === [] ? null : $points[array_key_last($points)]['price'];
        $minimum = $values === [] ? null : min($values);
        $maximum = $values === [] ? null : max($values);
        $first = $points[0]['price'] ?? null;
        $minPoint = $minimum === null
            ? null
            : collect($points)->first(fn ($point): bool => (float) $point['price'] === (float) $minimum);
        $maxPoint = $maximum === null
            ? null
            : collect($points)->first(fn ($point): bool => (float) $point['price'] === (float) $maximum);

        return response()->json([
            'current' => $current,
            'min' => $minimum === null ? null : number_format((float) $minimum, 2, '.', ''),
            'max' => $maximum === null ? null : number_format((float) $maximum, 2, '.', ''),
            'avg' => $values === [] ? null : number_format(array_sum(array_map('floatval', $values)) / count($values), 2, '.', ''),
            'first' => $first,
            'drop_pct_from_max' => $maximum === null || (float) $maximum === 0.0 || $current === null
                ? null
                : round((((float) $maximum - (float) $current) / (float) $maximum) * 100, 2),
            'min_at' => $minPoint['date'] ?? null,
            'max_at' => $maxPoint['date'] ?? null,
            'points' => $points,
        ]);
    }

    /**
     * Keep only price changes plus a six-hour heartbeat, preserving run records.
     *
     * @param  array<int, array{date: mixed, price: mixed}>  $points
     * @return array<int, array{date: mixed, price: string}>
     */
    private function filterPricePoints(array $points): array
    {
        $filtered = [];
        $lastPrice = null;
        $lastStoredAt = null;
        foreach ($points as $point) {
            $price = number_format((float) $point['price'], 2, '.', '');
            $date = $point['date'];
            if ($lastPrice !== null
                && $price === $lastPrice
                && $lastStoredAt->diffInSeconds($date) < 21600) {
                continue;
            }
            $filtered[] = ['date' => $date, 'price' => $price];
            $lastPrice = $price;
            $lastStoredAt = $date;
        }

        return $filtered;
    }

    private function analyze(
        string $url,
        ProductPageScraper $scraper,
        RobotSnapshotSanitizer $sanitizer,
        ?string $provider,
        User $user,
        ?array $robotSelectors,
        StoreProfileService $profiles
    ): array {
        $result = $scraper->scrapeDetailed($url, $provider, true);
        $profile = $profiles->findForUrl($user, $url);
        $fieldSources = [];
        $profileNotice = null;
        $effectiveSelectors = [];
        if (is_array($robotSelectors) && $robotSelectors !== []) {
            $effectiveSelectors = $robotSelectors;
            [$result, $matched] = $profiles->apply($result, $robotSelectors, $scraper);
            foreach ($matched as $field) {
                $fieldSources[$field] = 'robot_selector';
            }
        } elseif ($profile !== null && $profile->is_active) {
            $effectiveSelectors = $profile->selectors;
            [$result, $matched, $missed] = $profiles->apply($result, $profile->selectors, $scraper);
            foreach ($matched as $field) {
                $fieldSources[$field] = 'store_profile';
            }
            if ($profiles->recordAttempt($profile, $matched, $missed)) {
                $profileNotice = 'El perfil de selectores de esta tienda se desactivó por fallos repetidos.';
            }
        } elseif ($profile !== null) {
            $profileNotice = 'El perfil de selectores de esta tienda está desactivado por fallos repetidos.';
        }

        $diagnostics = $result['_diagnostics'] ?? [];
        $fields = [];
        foreach (['title', 'description', 'image', 'price', 'currency', 'store', 'availability', 'seller', 'original_price'] as $field) {
            $fields[$field] = $result[$field] ?? null;
        }

        $baseFields = ['title', 'description', 'image', 'price', 'currency'];
        $optionalFields = ['availability', 'seller', 'original_price'];
        $missing = array_values(array_filter(
            $baseFields,
            static fn (string $field): bool => $fields[$field] === null || $fields[$field] === ''
        ));
        $optionalMissing = array_values(array_filter(
            $optionalFields,
            static fn (string $field): bool => $fields[$field] === null || $fields[$field] === ''
        ));
        $html = $diagnostics['html'] ?? null;
        if (! is_string($html) || $html === '') {
            throw ValidationException::withMessages([
                'url' => 'No fue posible obtener HTML para crear la vista previa.',
            ]);
        }

        return [
            'fields' => $fields,
            'provider' => $diagnostics['provider'] ?? $result['provider'] ?? null,
            'blocked' => (bool) ($result['blocked'] ?? false),
            'missing' => $missing,
            'optional_missing' => $optionalMissing,
            'stages' => $diagnostics['field_stages'] ?? [],
            'stage_details' => $diagnostics['field_sources'] ?? [],
            'price_candidates' => $diagnostics['price_candidates'] ?? [],
            'field_sources' => $fieldSources,
            'selectors' => $effectiveSelectors,
            'has_store_profile' => $profile !== null,
            'store_profile_active' => (bool) ($profile?->fresh()->is_active ?? false),
            'profile_notice' => $profileNotice,
            'snapshot_html' => $sanitizer->sanitize($html, $url),
        ];
    }

    private function saveSnapshot(Robot $robot, string $html): void
    {
        $path = 'robots/'.$robot->id.'.html';
        Storage::disk('local')->put($path, $html);
        $robot->forceFill(['snapshot_path' => $path])->save();
    }

    private function prepareSnapshotInput(Request $request, RobotSnapshotSanitizer $sanitizer): void
    {
        $snapshot = $request->input('snapshot_html');
        if (is_string($snapshot)) {
            $request->merge([
                'snapshot_html' => $sanitizer->reduceOversizedSnapshot($snapshot),
            ]);
        }
    }

    private function snapshotHtmlRules(): array
    {
        return [
            'sometimes',
            'nullable',
            'string',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (strlen($value) > 3 * 1024 * 1024) {
                    $fail('El snapshot supera 3 MB');
                }
            },
        ];
    }

    private function savePreviewRun(Robot $robot, array $overrides): void
    {
        $title = $overrides['title'] ?? null;
        $price = isset($overrides['price']) && is_numeric($overrides['price'])
            ? $overrides['price']
            : null;
        $status = is_string($title) && trim($title) !== '' && $price !== null ? 'success' : 'partial';
        $now = now();

        RobotRun::create([
            'robot_id' => $robot->id,
            'status' => $status,
            'provider' => 'preview',
            'data' => $overrides,
            'price' => $price,
            'created_at' => $now,
        ]);

        $robot->forceFill([
            'name' => $robot->name ?: $title,
            'last_price' => $price,
            'last_currency' => $overrides['currency'] ?? null,
            'last_status' => $status,
            'last_run_at' => $now,
            'next_run_at' => $now->copy()->addMinutes($robot->interval_minutes),
        ])->save();
    }
}
