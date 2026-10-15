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

    .sign { margin-top: 26pt; page-break-inside: avoid; }
    .sign td { width: 50%; vertical-align: top; padding-right: 30pt; }
    .sign .line { border-top: 1px solid #000; padding-top: 3pt; font-size: 8pt; }
    .sign b { display: block; font-size: 9.5pt; min-height: 13pt; }
    .generated { margin-top: 12pt; font-size: 8pt; }
</style>
</head>
<body>
    <div class="head">
        <div class="org">{{ $sheet['hospital_name'] }} · {{ $sheet['office'] }}</div>
        <h1>{{ $sheet['title'] }}</h1>
        <div class="period">{{ $sheet['period_label'] }}@if ($sheet['category']) · {{ $sheet['category'] }} only @endif</div>
    </div>

    @if ($snapshot)
        <div class="note">Saved copy, taken when {{ $snapshot['month_label'] }} was closed on {{ $snapshot['taken_at']->format('d-M-Y H:i') }}. Later changes to lists or records do not affect it.</div>
    @endif

    @if (! $sheet['category'])
        <table class="totals"><tr>
            <td><b>{{ number_format($report['totals']['patients']) }}</b><span>Total patients (all categories)</span></td>
            <td><b>{{ number_format($report['totals']['visits']) }}</b><span>Total visits</span></td>
            @foreach ($report['categories'] as $name => $data)
                <td><b>{{ number_format($data['patients']) }}</b><span>{{ $name }} patients</span></td>
            @endforeach
            <td></td>
        </tr></table>
    @endif

    @foreach ($report['categories'] as $name => $data)
        <div class="cat">
            <h2>{{ $name }}</h2>
            <table class="totals"><tr>
                <td><b>{{ number_format($data['patients']) }}</b><span>Patients</span></td>
                <td><b>{{ number_format($data['visits']) }}</b><span>Visits</span></td>
                <td></td>
            </tr></table>

            @if ($data['patients'] === 0)
                <p class="none">No {{ $name }} patients in this period.</p>
            @else
                <table class="grid"><tr>
                    <td class="l">
                        <h4>Sex</h4>
                        <table class="lines">
                            @foreach ($data['sex'] as $line)
                                <tr><td>{{ $line['label'] }}</td><td class="n">{{ number_format($line['count']) }}</td></tr>
                            @endforeach
                            <tr class="total"><td>Total</td><td class="n">{{ number_format($data['patients']) }}</td></tr>
                        </table>
                    </td>
                    <td class="r">
                        <h4>Age range</h4>
                        <table class="lines">
                            @foreach ($data['age'] as $line)
                                <tr><td>{{ $line['label'] }}</td><td class="n">{{ number_format($line['count']) }}</td></tr>
                            @endforeach
                        </table>
                    </td>
                </tr></table>

                <h4>Diagnosis (per visit)</h4>
                <table class="lines">
                    @foreach ($data['diagnoses'] as $line)
                        <tr><td>{{ $line['label'] }}</td><td class="n">{{ number_format($line['count']) }}</td></tr>
                    @endforeach
                    <tr class="total"><td>Total</td><td class="n">{{ number_format($data['visits']) }}</td></tr>
                </table>

                <h4>Branch of service and rank</h4>
                <table class="lines">
                    <tr><th>Branch / Rank</th><th class="n">Patients</th></tr>
                    @foreach ($data['branches'] as $branch)
                        <tr class="br"><td>{{ $branch['name'] }}</td><td class="n">{{ number_format($branch['total']) }}</td></tr>
                        @foreach ($branch['ranks'] as $rank)
                            <tr class="rk"><td>{{ $rank['label'] }}</td><td class="n">{{ number_format($rank['count']) }}</td></tr>
                        @endforeach
                    @endforeach
                </table>
            @endif
        </div>
    @endforeach

    <table class="sign"><tr>
        <td><div class="line"><b>{{ $sheet['prepared_by'] }}</b>Prepared by · {{ $sheet['prepared_by_title'] }}</div></td>
        <td><div class="line"><b>{{ $sheet['noted_by'] }}</b>Noted by · {{ $sheet['noted_by_title'] }}</div></td>
    </tr></table>

    <div class="generated">Generated {{ $generatedAt->format('d-M-Y H:i') }}</div>
</body>
</html>
