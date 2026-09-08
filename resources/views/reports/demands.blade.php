<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Relatório de demandas</title>
    <style>
        @page { margin: 22px 28px 30px; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #1e293b; font-family: DejaVu Sans, sans-serif; font-size: 8px; }
        h1, h2, p { margin: 0; }
        .header { border-bottom: 2px solid {{ $primaryColor }}; margin-bottom: 12px; min-height: 42px; overflow: hidden; padding-bottom: 9px; }
        .header h1 { color: #0f172a; font-size: 17px; }
        .header-logo { float: left; margin-right: 10px; max-height: 38px; max-width: 100px; }
        .header p { color: #64748b; font-size: 8px; margin-top: 3px; }
        .summary { display: table; margin-bottom: 12px; table-layout: fixed; width: 100%; }
        .summary-row { display: table-row; }
        .metric { border-right: 4px solid white; display: table-cell; padding: 7px 8px; background: #f1f5f9; }
        .metric:last-child { border-right: 0; }
        .metric strong { display: block; color: #0f172a; font-size: 15px; }
        .metric span { color: #64748b; font-size: 7px; }
        .section { margin-top: 12px; page-break-inside: avoid; }
        .section h2 { border-bottom: 1px solid #cbd5e1; color: #0f172a; font-size: 10px; margin-bottom: 6px; padding-bottom: 4px; }
        table { border-collapse: collapse; table-layout: fixed; width: 100%; }
        th { background: {{ $primaryColor }}; color: {{ $primaryForeground }}; font-size: 7px; font-weight: 600; padding: 5px 4px; text-align: left; }
        td { border-bottom: 1px solid #e2e8f0; padding: 4px; vertical-align: top; word-wrap: break-word; }
        tr:nth-child(even) td { background: #f8fafc; }
        .number { text-align: right; }
        .danger { color: #b91c1c; font-weight: bold; }
        .muted { color: #64748b; }
        .footer { bottom: -20px; color: #64748b; font-size: 7px; left: 0; position: fixed; right: 0; text-align: center; }
        .page-number:after { content: counter(page); }
        .page-break { page-break-before: always; }
    </style>
</head>
<body>
    <div class="footer">
        Documento interno - {{ $office->nome }} - página <span class="page-number"></span>
    </div>

    <header class="header">
        @if($logoDataUri)
            <img src="{{ $logoDataUri }}" alt="" class="header-logo">
        @endif
        <h1>{{ $office->cabecalho_relatorios ?: $office->nome }}</h1>
        <p>
            Relatório de demandas de {{ \Illuminate\Support\Carbon::parse($filters['inicio'])->format('d/m/Y') }}
            a {{ \Illuminate\Support\Carbon::parse($filters['fim'])->format('d/m/Y') }}
            · Gerado em {{ $generatedAt->format('d/m/Y H:i') }}
        </p>
    </header>

    <div class="summary">
        <div class="summary-row">
            <div class="metric"><strong>{{ $summary['total'] }}</strong><span>Demandas no período</span></div>
            <div class="metric"><strong>{{ $summary['open'] }}</strong><span>Demandas abertas</span></div>
            <div class="metric"><strong>{{ $summary['resolved'] }}</strong><span>Resolvidas</span></div>
            <div class="metric"><strong>{{ number_format($summary['resolution_rate'], 1, ',', '.') }}%</strong><span>Taxa de resolução</span></div>
            <div class="metric"><strong>{{ $summary['overdue'] }}</strong><span>Atrasadas</span></div>
            <div class="metric"><strong>{{ $summary['average_resolution_hours'] === null ? '—' : number_format($summary['average_resolution_hours'] / 24, 1, ',', '.') . 'd' }}</strong><span>Tempo médio</span></div>
            <div class="metric"><strong>{{ $summary['waiting_referrals'] }}</strong><span>Encaminhamentos aguardando</span></div>
        </div>
    </div>

    <section class="section">
        <h2>Distribuição por status</h2>
        <table>
            <thead><tr><th>Status</th><th class="number">Quantidade</th><th>Status</th><th class="number">Quantidade</th></tr></thead>
            <tbody>
            @foreach(collect($charts['status'])->chunk(2) as $pair)
                <tr>
                    @foreach($pair as $item)
                        <td>{{ $item['label'] }}</td><td class="number">{{ $item['total'] }}</td>
                    @endforeach
                    @if($pair->count() === 1)<td></td><td></td>@endif
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>

    <section class="section">
        <h2>Produtividade da equipe</h2>
        <table>
            <thead><tr><th>Responsável</th><th class="number">Atribuídas</th><th class="number">Resolvidas</th><th class="number">Tempo médio</th></tr></thead>
            <tbody>
            @forelse($productivity as $member)
                <tr>
                    <td>{{ $member['name'] }}</td>
                    <td class="number">{{ $member['assigned'] }}</td>
                    <td class="number">{{ $member['resolved'] }}</td>
                    <td class="number">{{ $member['average_resolution_hours'] === null ? '—' : number_format($member['average_resolution_hours'] / 24, 1, ',', '.') . ' dias' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">Sem dados de produtividade no período.</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>

    <section class="section">
        <h2>Encaminhamentos aguardando resposta</h2>
        <table>
            <thead><tr><th style="width:14%">Protocolo</th><th>Demanda</th><th style="width:24%">Órgão</th><th style="width:13%">Prazo</th></tr></thead>
            <tbody>
            @forelse($waitingReferrals as $referral)
                <tr>
                    <td>{{ $referral['demand']['protocol'] ?? '—' }}</td>
                    <td>{{ $referral['demand']['title'] ?? 'Demanda indisponível' }}</td>
                    <td>{{ $referral['recipient'] }}</td>
                    <td class="{{ $referral['overdue'] ? 'danger' : '' }}">{{ $referral['deadline'] ? \Illuminate\Support\Carbon::parse($referral['deadline'])->format('d/m/Y') : 'Sem prazo' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">Nenhum encaminhamento aguardando resposta.</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>

    <section class="section page-break">
        <h2>Demandas do período</h2>
        <table>
            <thead>
                <tr>
                    <th style="width:11%">Protocolo</th>
                    <th style="width:23%">Título</th>
                    <th style="width:15%">Cidadão</th>
                    <th style="width:12%">Status</th>
                    <th style="width:10%">Prioridade</th>
                    <th style="width:13%">Responsável</th>
                    <th style="width:8%">Abertura</th>
                    <th style="width:8%">Prazo</th>
                </tr>
            </thead>
            <tbody>
            @forelse($demands as $demand)
                <tr>
                    <td>{{ $demand['protocol'] }}</td>
                    <td>{{ $demand['title'] }}</td>
                    <td>{{ $demand['citizen'] ?? 'Não informado' }}</td>
                    <td>{{ $demand['status_label'] }}</td>
                    <td>{{ $demand['priority_label'] }}</td>
                    <td>{{ $demand['responsible'] ?? 'Não atribuído' }}</td>
                    <td>{{ \Illuminate\Support\Carbon::parse($demand['opened_at'])->format('d/m/Y') }}</td>
                    <td class="{{ $demand['overdue'] ? 'danger' : '' }}">{{ $demand['deadline'] ? \Illuminate\Support\Carbon::parse($demand['deadline'])->format('d/m/Y') : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="muted">Nenhuma demanda encontrada para os filtros selecionados.</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
</body>
</html>
