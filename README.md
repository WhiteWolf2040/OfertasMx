<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Product scraper

The product scraper tries providers in the order configured by `SCRAPER_CHAIN` (default: `http,browser,api`) and caches results for one hour. The browser provider is limited to one concurrent process; use a shared lock-capable cache store such as database or Redis when running multiple app workers. It requires Node.js:

```bash
npm install playwright
npx playwright install chromium
```

Set `SCRAPER_CHAIN=http` to disable browser/API fallbacks. The optional API provider is enabled only when `SCRAPER_API_URL` is configured; it expects JSON containing `html` and may return `final_url` and `status`. Configure its key and URL parameter with `SCRAPER_API_KEY` and `SCRAPER_API_URL_PARAMETER`.

Store-specific selectors live in `config/scraper.php`. Store markup changes over time, so test and adjust those selectors when a store changes its product page.

The browser opens a minimized visible window by default (`SCRAPER_BROWSER_HEADLESS=false`). Set it to `true` for headless mode. Optionally set `SCRAPER_BROWSER_CHANNEL=chrome` to use installed Google Chrome; Playwright Chromium is used if Chrome is unavailable. Only one browser process may run at a time. To inspect a scrape from the terminal, run `php artisan scrape:debug "https://example.com/product"`.

The debug command lists all provider attempts and block signals. Use `--provider=browser` to test only the browser, and add `--save` to write its rendered HTML and screenshot under `storage/app/debug/`.

## API REST de solo lectura (v1)

La API de lectura está versionada bajo `/api/v1` para conservar sin cambios las respuestas y rutas que ya utiliza la web.

| Recurso | Acceso |
| --- | --- |
| `categories`, `deals`, `comments`, `votes` | Público |
| `robots`, `robot-runs`, `store-profiles`, `robot-notifications`, `users` | Requiere token Sanctum |

Cada recurso tiene una ruta de lista (`GET /api/v1/{recurso}?limit=20&offset=0`) y una ruta de detalle (`GET /api/v1/{recurso}/{id}`). La lista devuelve `count`, `next`, `previous` y `results`; `limit` debe estar entre 1 y 100. Las relaciones se serializan como URLs. Los recursos privados solo incluyen registros del usuario autenticado, excepto perfiles de usuario: únicamente exponen `id` y `name` y requieren autenticación. No se devuelven emails, contraseñas ni tokens.

Para comprobar la API:

1. Inicia el backend con `php artisan serve` desde `scraping_back/scraping_app`.
2. Prueba `http://127.0.0.1:8000/api/v1/categories?limit=2` y abre un detalle usando un ID devuelto, por ejemplo `http://127.0.0.1:8000/api/v1/categories/1`.
3. En Postman, inicia sesión con `POST /api/login`, copia el token devuelto y úsalo como `Authorization: Bearer <token>` para probar los recursos privados.
4. Ejecuta `php artisan test --filter=ReadOnlyApiTest` para validar paginación, relaciones, autenticación, errores y compatibilidad con rutas antiguas.

Para mostrar evidencia, captura en Postman (o en las herramientas de red del navegador) el URL y método, el código HTTP y el JSON de una lista paginada, un detalle, una petición privada sin token (`401`) y una petición con token. Si pruebas CORS, incluye también el origen de la aplicación cliente y los encabezados de la respuesta en la captura.

### PostgreSQL en Render

El contenedor de Render usa el `Dockerfile`, que instala `pdo_pgsql`. Si ejecutas Laravel localmente en Windows contra PostgreSQL, también necesitas habilitar `extension=pdo_pgsql` en el `php.ini` del PHP con el que ejecutas `artisan`. Para una base vinculada en Render, Laravel acepta `DB_URL` o, como alternativa, la variable `DATABASE_URL`. En el servicio web de Render utiliza la URL interna de la base; desde un equipo local, utiliza la URL externa y permite la IP pública del equipo en la lista de acceso de la base.
