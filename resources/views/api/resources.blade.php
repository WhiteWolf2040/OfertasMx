@extends('api.layout')

@section('content')
    <header class="page-heading">
        <h2>Recursos disponibles</h2>
        <p>Elige una colección para consultar sus registros.</p>
    </header>

    <div class="resource-grid">
        @foreach ($resources as $resource)
            <section class="resource-list-item">
                @if ($resource['safeUrl'])
                    <a href="{{ $resource['safeUrl'] }}" rel="noopener noreferrer">{{ $resource['name'] }}</a>
                @else
                    <strong>{{ $resource['name'] }}</strong>
                @endif
                <code class="api-url">{{ $resource['url'] }}</code>
            </section>
        @endforeach
    </div>
@endsection
