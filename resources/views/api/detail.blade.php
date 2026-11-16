@extends('api.layout')

@section('content')
    <header class="page-heading">
        <h2>{{ $resourceTitle }}</h2>
    </header>

    <div class="detail-card">
        @include('api.record-table', [
            'items' => [$item],
            'resourceKey' => $resource,
        ])
    </div>
@endsection
