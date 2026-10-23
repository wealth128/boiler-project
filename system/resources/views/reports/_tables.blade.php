{{-- The tables of one report page (ReportSections), each with its chart above it (ReportCharts), kept together on one page where it fits. --}}
@foreach ($tables as $table)
    @continue($table['key'] === \App\Services\ReportSections::TOTALS)
    <div class="sec">
        @php $chart = ($charts ?? [])[$table['key']] ?? null; @endphp
        @if ($chart)
            {{-- The same SVG as the screen, as one picture: dompdf draws it as vector, so it stays sharp. --}}
            <div class="chart"><img src="data:image/svg+xml;base64,{{ base64_encode(\App\Services\Charts\PdfSvg::prepare($chart['svg'])) }}" alt="{{ $chart['aria'] }}"></div>
        @endif
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
