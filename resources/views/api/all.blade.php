@extends('api.layout')

@section('content')
    <header class="page-heading">
        <h2>Todos los recursos</h2>
        <p>Consulta los datos disponibles con tu enlace de solo lectura.</p>
    </header>

    <div class="resource-grid">
        @foreach ($resources as $resource)
            <section class="resource-section">
                <h2>{{ $resource['title'] }}</h2>
                <p class="resource-count">{{ $resource['count'] }} registros</p>
                @include('api.record-table', [
                    'items' => $resource['items'],
                    'resourceKey' => $resource['resourceKey'],
                ])
                <nav aria-label="Paginación de {{ $resource['title'] }}" class="pager">
                    @if ($resource['previous'])
                        <a href="{{ $resource['previous'] }}" rel="noopener noreferrer">Anterior</a>
                    @endif
                    @if ($resource['next'])
                        <a href="{{ $resource['next'] }}" rel="noopener noreferrer">Siguiente</a>
                    @endif
                </nav>
            </section>
        @endforeach
    </div>
@endsection
