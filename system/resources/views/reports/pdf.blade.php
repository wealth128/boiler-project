<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $sheet['title'] }} · {{ $sheet['period_label'] }}</title>
<style>
    /* A4 portrait, plain black on white. DejaVu Sans shows "·" and "–" correctly. */
    @page { margin: 18mm 16mm 20mm 16mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #000; background: #fff; }
    table { border-collapse: collapse; width: 100%; }
    tr { page-break-inside: avoid; }
    h1, h2, h3, h4 { margin: 0; page-break-after: avoid; }

    .head { text-align: center; border-bottom: 2px solid #000; padding-bottom: 6pt; margin-bottom: 10pt; }
    .logo { max-height: 48pt; max-width: 120pt; margin-bottom: 4pt; }
    .org { font-size: 8pt; letter-spacing: 1pt; text-transform: uppercase; }
    .head h1 { font-size: 14pt; margin: 3pt 0 2pt; }
    .period { font-size: 10pt; }
    .note { border: 1px solid #000; padding: 4pt 6pt; margin-bottom: 8pt; font-size: 8.5pt; }

    .totals td { width: 1%; white-space: nowrap; padding: 0 16pt 4pt 0; vertical-align: top; }
    .totals b { display: block; font-size: 15pt; }
    .totals span { font-size: 8pt; }

    .cat { margin-top: 14pt; }
    .cat h2 { font-size: 12pt; border-bottom: 1px solid #000; padding-bottom: 2pt; margin-bottom: 6pt; }
    h4 { font-size: 8pt; text-transform: uppercase; letter-spacing: 0.5pt; margin: 8pt 0 3pt; }

    .grid { table-layout: fixed; }
    .grid > tbody > tr > td { vertical-align: top; width: 50%; }
    .grid > tbody > tr > td.l { padding-right: 10pt; }
    .grid > tbody > tr > td.r { padding-left: 10pt; }

    .lines td, .lines th { border-bottom: 1px solid #999; padding: 2.5pt 4pt; text-align: left; }
    .lines th { border-bottom: 1px solid #000; font-size: 8pt; }
    .lines .n { text-align: right; width: 22%; }
    .lines .total td { font-weight: bold; border-bottom: 1px solid #000; }
    .lines .br td { font-weight: bold; background: #eee; }
    .lines .rk td:first-child { padding-left: 16pt; }
    .none { font-size: 9pt; }
    .sec { page-break-inside: avoid; }
    .lines .n { width: 14%; white-space: nowrap; }
    .tnote { font-size: 8pt; font-style: italic; margin-top: 3pt; }

    .sign { margin-top: 26pt; page-break-inside: avoid; }
    .sign td { width: 50%; vertical-align: top; padding-right: 30pt; }
    .sign img { display: block; max-height: 40pt; max-width: 140pt; margin-bottom: 2pt; }
    .sign .line { border-top: 1px solid #000; padding-top: 3pt; font-size: 8pt; }
    .sign b { display: block; font-size: 9.5pt; min-height: 13pt; }
    .signote { margin-top: 6pt; font-size: 7.5pt; font-style: italic; }
    .generated { margin-top: 12pt; font-size: 8pt; }
</style>
</head>
@php
    // Pages: this tab only, or the summary and every category (each on its own page).
    $pages = $pages ?? [$sheet['category'] ?? 'summary'];
@endphp
<body>
@foreach ($pages as $pageIndex => $page)
    <div class="page"@if ($pageIndex > 0) style="page-break-before: always"@endif>
        <div class="head">
            @if (! empty($logoData))
                <div><img class="logo" src="{{ $logoData }}" alt=""></div>
            @endif
            <div class="org">{{ $sheet['hospital_name'] }} · {{ $sheet['office'] }}</div>
            <h1>{{ $sheet['title'] }}</h1>
            <div class="period">{{ $sheet['period_label'] }}· {{ $page === 'summary' ? 'Summary' : $page }}</div>
        </div>

        @if ($snapshot)
            <div class="note">Saved copy, taken when {{ $snapshot['month_label'] }} was closed on {{ $snapshot['taken_at']->format('d-M-Y H:i') }}. Later changes to lists or records do not affect it.</div>
        @endif

        @if ($page === 'summary')
            <table class="totals"><tr>
                <td><b>{{ number_format($report['totals']['patients']) }}</b><span>Total patients (all categories)</span></td>
                <td><b>{{ number_format($report['totals']['visits']) }}</b><span>Total visits</span></td>
                @foreach ($report['categories'] as $name => $data)
                    <td><b>{{ number_format($data['patients']) }}</b><span>{{ $name }} patients</span></td>
                @endforeach
                <td></td>
            </tr></table>
            @include('reports._tables', ['tables' => $sections['summary']])
        @endif

        @if ($page !== 'summary')
            @php $name = $page; $data = $report['categories'][$page]; @endphp
            <div class="cat">
                <h2>{{ $name }}</h2>
                <table class="totals"><tr>
                    <td><b>{{ number_format($data['patients']) }}</b><span>Patients</span></td>
                    <td><b>{{ number_format($data['visits']) }}</b><span>Visits</span></td>
                    <td></td>
                </tr></table>

                @if ($data['patients'] === 0)
                    <p class="none">No visits encoded for {{ $name }} in this period.</p>
                @else
                    @include('reports._tables', ['tables' => $sections[$name]])
                @endif
            </div>
        @endif

        <table class="sign"><tr>
            <td>
                @if (! empty($signatureData['prepared']))<img src="{{ $signatureData['prepared'] }}" alt="">@endif
                <div class="line"><b>{{ $sheet['prepared_by'] }}</b>Prepared by · {{ $sheet['prepared_by_title'] }}</div>
            </td>
            <td>
                @if (! empty($signatureData['noted']))<img src="{{ $signatureData['noted'] }}" alt="">@endif
                <div class="line"><b>{{ $sheet['noted_by'] }}</b>Noted by · {{ $sheet['noted_by_title'] }}</div>
            </td>
        </tr></table>

        @if (! empty($sheet['signature_note']))
            <div class="signote">{{ $sheet['signature_note'] }}</div>
        @endif

        <div class="generated">Generated {{ $generatedAt->format('d-M-Y H:i') }}</div>
    </div>
@endforeach
</body>
</html>
