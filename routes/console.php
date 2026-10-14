<?php

use App\Jobs\RunRobotJob;
use App\Models\Robot;
use App\Models\RobotRun;
use App\Services\ProductPageScraper;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('scrape:debug {url} {--provider=} {--save}', function (string $url, ProductPageScraper $scraper) {
    $startedAt = microtime(true);
    $provider = $this->option('provider');
    $save = (bool) $this->option('save');
    $result = $scraper->scrapeDetailed($url, is_string($provider) && $provider !== '' ? $provider : null, $save);
    $diagnostics = $result['_diagnostics'];

    $this->line('Cadena configurada: '.implode(',', (array) config('scraper.chain', ['http', 'browser'])));
    foreach ($diagnostics['attempts'] as $attempt) {
        $summary = sprintf(
            '%s: %d ms, %s',
            $attempt['provider'],
            $attempt['elapsed_ms'],
            $attempt['blocked'] ? 'bloqueado' : 'sin bloqueo'
        );
        if ($attempt['reason'] !== null) {
            $summary .= ' (señal: '.$attempt['reason'].')';
        }
        if ($attempt['error'] !== null) {
            $summary .= ' (error: '.$attempt['error'].')';
        }
        if ($attempt['http_status'] !== null) {
            $summary .= ', HTTP '.$attempt['http_status'];
        }
        $summary .= ', HTML '.$attempt['html_bytes'].' bytes';
        $this->line($summary);
    }
    $this->line('Proveedor resuelto: '.($diagnostics['provider'] ?? 'ninguno'));
    $this->line('Tiempo: '.number_format(microtime(true) - $startedAt, 2).' s');
    $this->line('Tamaño HTML: '.number_format($diagnostics['html_bytes']).' bytes');
    $this->line('Bloqueado: '.($result['blocked'] ? 'sí' : 'no'));
    $this->line('Precio: '.($result['price'] ?? '—'));
    $this->line('Moneda: '.($result['currency'] ?? '—'));
    $this->line('Imagen: '.($result['image'] ?? '—'));
    $description = is_string($result['description'] ?? null)
        ? mb_substr($result['description'], 0, 80)
        : '—';
    $this->line('Descripción: '.$description);
    $this->line('Campos encontrados: '.($diagnostics['fields_found'] === []
        ? 'ninguno'
        : implode(', ', $diagnostics['fields_found'])));
    if ($diagnostics['field_stages'] !== []) {
        $this->line('Etapas: '.json_encode($diagnostics['field_stages'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
    if (($diagnostics['field_sources'] ?? []) !== []) {
        $this->line('Fuentes: '.json_encode($diagnostics['field_sources'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
    $this->line('Mejores candidatos de precio (hasta 5):');
    if (($diagnostics['price_candidates'] ?? []) === []) {
        $this->line('  Ninguno');
    } else {
        foreach (array_slice($diagnostics['price_candidates'], 0, 5) as $candidate) {
            $this->line(sprintf(
                '  %s (puntaje %s) desde %s, texto: %s',
                $candidate['price'] ?? '—',
                $candidate['score'] ?? '—',
                $candidate['selector'] ?? 'selector no disponible',
                $candidate['raw'] ?? '—'
            ));
        }
    }

    if ($save) {
        $directory = storage_path('app/debug');
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            $this->error('No se pudo crear storage/app/debug/.');

            return self::FAILURE;
        }

        $baseName = date('Ymd-His').'-'.bin2hex(random_bytes(4));
        $htmlPath = $directory.DIRECTORY_SEPARATOR.$baseName.'.html';
        if (! is_string($diagnostics['html']) || file_put_contents($htmlPath, $diagnostics['html']) === false) {
            $this->error('No se pudo guardar el HTML de depuración.');

            return self::FAILURE;
        }
        $this->line('HTML guardado: '.$htmlPath);

        if (is_string($diagnostics['screenshot']) && $diagnostics['screenshot'] !== '') {
            $screenshotPath = $directory.DIRECTORY_SEPARATOR.$baseName.'.png';
            if (file_put_contents($screenshotPath, $diagnostics['screenshot']) === false) {
                $this->error('No se pudo guardar la captura.');

                return self::FAILURE;
            }
            $this->line('Captura guardada: '.$screenshotPath);
        } else {
            $this->line('Sin captura (el proveedor "'.($diagnostics['provider'] ?? 'ninguno').'" no la genera; usa --provider=browser para obtenerla).');
        }
    }
})->purpose('Diagnostica la extracción de una página de producto');

Artisan::command('scrape:check {--file=storage/app/scrape-urls.txt}', function (ProductPageScraper $scraper) {
    $file = (string) $this->option('file');
    $isAbsolute = preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\|\/)/', $file) === 1;
    $path = $isAbsolute ? $file : base_path($file);
    if (! is_file($path) || ! is_readable($path)) {
        $this->error('No se puede leer el archivo de URLs: '.$path);

        return self::FAILURE;
    }

    $urls = array_values(array_filter(
        array_map('trim', file($path, FILE_IGNORE_NEW_LINES) ?: []),
        static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#')
    ));
    if ($urls === []) {
        $this->warn('El archivo no contiene URLs para revisar.');

        return self::SUCCESS;
    }

    $rows = [];
    $complete = 0;
    $partial = 0;
    $blocked = 0;
    $failed = false;

    foreach ($urls as $index => $url) {
        $startedAt = microtime(true);
        try {
            $result = $scraper->scrapeDetailed($url, null, false);
            $diagnostics = $result['_diagnostics'] ?? [];
            $hasTitleAndPrice = ! empty($result['title']) && $result['price'] !== null;
            $isBlocked = (bool) ($result['blocked'] ?? false);

            if ($hasTitleAndPrice) {
                $complete++;
            } elseif ($isBlocked) {
                $blocked++;
            } else {
                $partial++;
            }

            $rows[] = [
                $result['store'] ?? parse_url($url, PHP_URL_HOST) ?? '—',
                $diagnostics['provider'] ?? $result['provider'] ?? 'ninguno',
                $isBlocked ? 'Sí' : 'No',
                implode(', ', $diagnostics['fields_found'] ?? []) ?: 'ninguno',
                json_encode($diagnostics['field_sources'] ?? $diagnostics['field_stages'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $result['price'] ?? '—',
                (string) ($diagnostics['elapsed_ms'] ?? (int) ((microtime(true) - $startedAt) * 1000)).' ms',
            ];
        } catch (Throwable $exception) {
            $failed = true;
            $partial++;
            $rows[] = [
                parse_url($url, PHP_URL_HOST) ?: 'URL inválida',
                'error',
                'No',
                'ninguno',
                $exception->getMessage(),
                '—',
                (string) ((int) ((microtime(true) - $startedAt) * 1000)).' ms',
            ];
        }

        if ($index < count($urls) - 1) {
            sleep(3);
        }
    }

    $this->table(
        ['Tienda', 'Proveedor', 'Bloqueado', 'Campos', 'Etapas / error', 'Precio', 'Tiempo'],
        $rows
    );
    $this->line("Resumen: {$complete} completas (título y precio), {$partial} parciales, {$blocked} bloqueadas.");

    return $failed ? self::FAILURE : self::SUCCESS;
})->purpose('Comprueba la extracción de precios en una lista de URLs');

Artisan::command('robots:dispatch-due', function () {
    $dueRobotIds = Robot::query()
        ->where('is_active', true)
        ->where(function ($query) {
            $query->whereNull('next_run_at')
                ->orWhere('next_run_at', '<=', now());
        })
        ->orderBy('id')
        ->pluck('id');

    $dispatched = 0;
    foreach ($dueRobotIds as $robotId) {
        DB::transaction(function () use ($robotId, &$dispatched): void {
            $robot = Robot::query()
                ->whereKey($robotId)
                ->where('is_active', true)
                ->where(function ($query) {
                    $query->whereNull('next_run_at')
                        ->orWhere('next_run_at', '<=', now());
                })
                ->lockForUpdate()
                ->first();

            if ($robot === null) {
                return;
            }

            $robot->next_run_at = now()->addMinutes($robot->interval_minutes);
            $robot->save();

            RunRobotJob::dispatch($robot->id)->onConnection('database');
            $dispatched++;
        });
    }

    $this->info("Despachados: {$dispatched}");
})->purpose('Despacha robots activos que están listos para ejecutarse');

Artisan::command('robots:prune-runs', function () {
    $deleted = RobotRun::query()
        ->where('created_at', '<', now()->subDays(30))
        ->delete();

    $this->info("Ejecuciones eliminadas: {$deleted}");
})->purpose('Elimina ejecuciones de robots con más de 30 días');

Schedule::command('robots:dispatch-due')->everyMinute()->withoutOverlapping();
Schedule::command('robots:prune-runs')->daily();
