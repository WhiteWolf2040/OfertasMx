@if (in_array($resourceKey, ['deals', 'robots'], true))
    <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th>Imagen</th>
                    <th>Nombre</th>
                    <th>Precio</th>
                    <th>Tienda</th>
                    <th>Estado</th>
                    <th>Fecha</th>
                    <th>Campos</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    <tr>
                        <td>
                            @if ($item['imageUrl'])
                                <img class="thumbnail" src="{{ $item['imageUrl'] }}" alt="Miniatura de {{ $item['name'] }}" loading="lazy" referrerpolicy="no-referrer">
                            @else
                                <span class="thumbnail-placeholder">—</span>
                            @endif
                        </td>
                        <td>
                            @if ($item['externalUrl'])
                                <a href="{{ $item['externalUrl'] }}" target="_blank" rel="noopener noreferrer">{{ $item['name'] }}</a>
                            @else
                                {{ $item['name'] }}
                            @endif
                        </td>
                        <td>{{ $item['price'] }}</td>
                        <td>{{ $item['store'] }}</td>
                        <td>{{ $item['status'] }}</td>
                        <td>{{ $item['date'] }}</td>
                        <td>
                            <details>
                                <summary>Ver campos</summary>
                                <pre>{{ $item['detailsJson'] }}</pre>
                            </details>
                        </td>
                    </tr>
                @empty
                    <tr><td class="empty-cell" colspan="7">No hay registros en esta página.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@else
    <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Dato principal</th>
                    <th>Valor</th>
                    <th>Estado</th>
                    <th>Fecha</th>
                    <th>Campos</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    <tr>
                        <td>{{ $item['id'] }}</td>
                        <td>
                            @if ($item['externalUrl'])
                                <a href="{{ $item['externalUrl'] }}" target="_blank" rel="noopener noreferrer">{{ $item['primary'] }}</a>
                            @else
                                {{ $item['primary'] }}
                            @endif
                        </td>
                        <td>{{ $item['price'] }}</td>
                        <td>{{ $item['status'] }}</td>
                        <td>{{ $item['date'] }}</td>
                        <td>
                            <details>
                                <summary>Ver campos</summary>
                                <pre>{{ $item['detailsJson'] }}</pre>
                            </details>
                        </td>
                    </tr>
                @empty
                    <tr><td class="empty-cell" colspan="6">No hay registros en esta página.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endif
