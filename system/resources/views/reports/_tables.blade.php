{{-- The tables of one report page (ReportSections), each kept together on one page where it fits. --}}
@foreach ($tables as $table)
    @continue($table['key'] === \App\Services\ReportSections::TOTALS)
    <div class="sec">
        <h4>{{ $table['title'] }}</h4>
        <table class="lines">
            <tr>
                @foreach ($table['columns'] as $column)
                    <th class="{{ $column['type'] === 'text' ? '' : 'n' }}">{{ $column['label'] }}</th>
                @endforeach
            </tr>
            @foreach ($table['rows'] as $row)
                <tr class="{{ ['group' => 'br', 'child' => 'rk', 'total' => 'total'][$row['style']] ?? '' }}">
                    @foreach ($row['cells'] as $index => $cell)
                        <td class="{{ $table['columns'][$index]['type'] === 'text' ? '' : 'n' }}">{{ \App\Services\ReportSections::format($cell, $table['columns'][$index]['type']) }}</td>
                    @endforeach
                </tr>
            @endforeach
        </table>
        @if ($table['note'] !== null)
            <div class="tnote">{{ $table['note'] }}</div>
        @endif
    </div>
@endforeach
