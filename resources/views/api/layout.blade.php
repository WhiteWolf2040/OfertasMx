<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>{{ $pageTitle }} | API OfertasMX</title>
    <style>
        :root {
            color-scheme: light dark;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            font-size: 16px;
            line-height: 1.5;
            background: #f4f6f8;
            color: #202b36;
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-width: 320px; background: #f4f6f8; color: #202b36; }
        a { color: #075e9b; overflow-wrap: anywhere; word-break: break-all; }
        .layout-header, main {
            width: min(100%, 1200px);
            margin: 0 auto;
            padding-right: max(16px, env(safe-area-inset-right, 0px));
            padding-left: max(16px, env(safe-area-inset-left, 0px));
        }
        .layout-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding-top: max(16px, env(safe-area-inset-top, 0px));
            padding-bottom: 16px;
            border-bottom: 1px solid #dce2e7;
        }
        .layout-header h1 { margin: 0; font-size: 20px; }
        main { padding-top: 20px; padding-bottom: max(28px, env(safe-area-inset-bottom, 0px)); }
        .page-heading { margin: 0 0 20px; }
        .page-heading h2, .resource-section h2 { margin: 0 0 6px; font-size: 22px; }
        .page-heading p, .resource-count { margin: 0; color: #56636f; }
        .button, .pager a {
            display: inline-flex;
            min-height: 48px;
            align-items: center;
            justify-content: center;
            padding: 10px 16px;
            border: 1px solid #c9d2da;
            border-radius: 10px;
            background: #fff;
            color: #202b36;
            font-weight: 650;
            text-decoration: none;
        }
        .resource-grid { display: grid; grid-template-columns: 1fr; gap: 20px; }
        .resource-section, .resource-list-item, .detail-card {
            min-width: 0;
            padding: 16px;
            border: 1px solid #dce2e7;
            border-radius: 14px;
            background: #fff;
        }
        .table-scroll { width: 100%; overflow-x: auto; overscroll-behavior-x: contain; }
        table { width: 100%; min-width: 760px; border-collapse: collapse; text-align: left; }
        th, td { padding: 12px 10px; border-bottom: 1px solid #e6eaee; vertical-align: top; }
        th { font-size: 16px; }
        td { max-width: 340px; overflow-wrap: anywhere; word-break: break-word; }
        .thumbnail { display: block; width: 64px; height: 64px; object-fit: cover; border-radius: 8px; }
        .thumbnail-placeholder { display: grid; width: 64px; height: 64px; place-items: center; border-radius: 8px; background: #edf1f4; color: #64717d; }
        details { min-width: 140px; }
        summary { min-height: 32px; cursor: pointer; }
        pre { max-width: min(80vw, 600px); overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere; }
        .pager { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; }
        .api-url { display: block; margin-top: 6px; overflow-wrap: anywhere; word-break: break-all; }
        .empty-cell { color: #64717d; }
        @media (max-width: 599px) {
            .layout-header { align-items: flex-start; flex-direction: column; }
            .resource-grid { grid-template-columns: 1fr; }
            .resource-section, .resource-list-item, .detail-card { padding: 14px; }
            table { min-width: 760px; }
        }
        @media (prefers-color-scheme: dark) {
            :root, body { background: #111820; color: #e8edf2; }
            a { color: #7dc8ff; }
            .layout-header { border-color: #35414c; }
            .page-heading p, .resource-count, .empty-cell { color: #b4c0ca; }
            .button, .pager a, .resource-section, .resource-list-item, .detail-card {
                border-color: #35414c;
                background: #1b2630;
                color: #e8edf2;
            }
            th, td { border-color: #35414c; }
            .thumbnail-placeholder { background: #2a3742; color: #c4cdd5; }
            pre { color: #e8edf2; }
        }
    </style>
</head>
<body>
    <header class="layout-header">
        <h1>API OfertasMX</h1>
        <a class="button" href="{{ $jsonUrl }}" rel="noopener noreferrer">Ver JSON</a>
    </header>
    <main>
        @yield('content')
    </main>
</body>
</html>
