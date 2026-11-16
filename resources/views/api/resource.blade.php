@extends('api.layout')

@section('content')
    <header class="page-heading">
        <h2>{{ $resourceTitle }}</h2>
        <p>{{ $count }} registros</p>
    </header>

    @include('api.record-table', [
        'items' => $items,
        'resourceKey' => $resource,
    ])

    <nav aria-label="Paginación de {{ $resourceTitle }}" class="pager">
        @if ($previous)
            <a href="{{ $previous }}" rel="noopener noreferrer">Anterior</a>
        @endif
        @if ($next)
            <a href="{{ $next }}" rel="noopener noreferrer">Siguiente</a>
        @endif
    </nav>
@endsection
