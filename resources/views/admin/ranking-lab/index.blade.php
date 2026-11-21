@extends('layouts.admin')

@section('title', 'Ranking lab')

@section('content')
@php
    $technologies = $lab['technologies'];
    $eval = $lab['eval'] ?? [];
@endphp

<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Ranking lab</h1>
    <p class="mt-1 max-w-3xl text-sm text-gray-500">
        Run live catalog queries. Gold families labeled by a person before the run; product families from
        <code>product_family.csv</code>. A #1 SKU is correct when the gold family is its primary family or one of its
        secondary families. Leading list numbers (e.g. <code>1.</code>) are stripped before ranking.
        Grade milk gold and product families here in Excel, then Run.
    </p>
</div>

@include('admin.ranking-lab._eval_labels')

<section id="eval-csv-load" class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-950">
    <h2 class="text-sm font-semibold">Eval CSV load</h2>
    @foreach (['gold' => 'gold_queries.csv', 'family' => 'product_family.csv'] as $key => $label)
        @php $file = $eval[$key] ?? []; @endphp
        <div class="mt-3 font-mono">
            <p><span class="font-sans font-medium">{{ $label }}</span> · exists: {{ ! empty($file['exists']) ? 'yes' : 'no' }} · readable: {{ ! empty($file['readable']) ? 'yes' : 'no' }} · BOM: {{ ! empty($file['bom']) ? 'yes' : 'no' }} · delimiter: {{ $file['delimiter'] ?? '—' }} · data rows: {{ $file['row_count'] ?? 0 }} · loaded: {{ $file['loaded_rows'] ?? 0 }}</p>
            <p class="mt-1 break-all">read from: {{ $file['path'] ?? '—' }}</p>
            @foreach ($file['candidates'] ?? [] as $candidate)
                <p class="text-amber-800">{{ ! empty($candidate['exists']) ? 'found' : 'missing' }}: {{ $candidate['path'] }}</p>
            @endforeach
            <p class="mt-1">headers raw: {{ implode(' | ', $file['headers_visible'] ?? []) }}</p>
            <p>headers normalized: {{ implode(' | ', $file['headers_normalized'] ?? []) }}</p>
            @foreach ($file['preview'] ?? [] as $i => $previewRow)
                <p>row {{ $i + 1 }}: {{ is_array($previewRow) ? implode(' | ', $previewRow) : $previewRow }}</p>
            @endforeach
        </div>
    @endforeach
    @if (! empty($eval['invalid_gold_families']))
        <p class="mt-3 font-sans text-red-800">Invalid gold_family (not in the 16): {{ implode('; ', $eval['invalid_gold_families']) }}</p>
    @endif
    @if (! empty($eval['invalid_product_families']))
        <p class="font-sans text-red-800">Invalid product family (not in the 16): {{ implode('; ', $eval['invalid_product_families']) }}</p>
    @endif
    @if (! empty($eval['cooking_as_primary']))
        <p class="font-sans text-red-800">Cooking used as product primary (put in secondary_families): {{ implode('; ', $eval['cooking_as_primary']) }}</p>
    @endif
    <p class="mt-3 font-sans">Queries matched to a gold row: {{ $eval['gold_matched'] ?? 0 }}</p>
    <p class="font-sans">Unlabeled queries ({{ count($eval['unlabeled'] ?? []) }}):
        {{ empty($eval['unlabeled']) ? 'none' : implode('; ', $eval['unlabeled']) }}
    </p>
    <p class="font-sans">Unmapped products ({{ count($eval['unmapped_products'] ?? []) }}):
        @if (empty($eval['unmapped_products']))
            none
        @else
            @foreach ($eval['unmapped_products'] as $product)
                {{ $product['id'] }} {{ $product['name'] }}@if (! $loop->last); @endif
            @endforeach
        @endif
    </p>
</section>

<form method="POST" action="{{ route('admin.ranking-lab.index') }}" class="mb-6 space-y-3 rounded-xl border border-gray-200 bg-white p-4">
    @csrf
    <div>
        <label for="ranking-lab-q" class="mb-1 block text-sm font-medium text-gray-700">Step 5 — Queries (one per line), then Run</label>
        <textarea
            id="ranking-lab-q"
            name="q"
            rows="8"
            maxlength="20000"
            placeholder="One query per line, e.g.&#10;1. Gatas gikan sa baka&#10;gatas nga pangluto&#10;condensed nga gatas"
            class="block w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm"
        >{{ $rawQuery }}</textarea>
    </div>
    <div class="flex flex-wrap items-end gap-3">
        <div>
            <label for="ranking-lab-limit" class="mb-1 block text-xs text-gray-500">Results per query</label>
            <input id="ranking-lab-limit" type="number" name="limit" min="8" max="80" value="{{ $limit }}" class="w-28 rounded-lg border border-gray-300 px-3 py-2 text-sm">
        </div>
        <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">Run</button>
        <button
            type="submit"
            formaction="{{ route('admin.ranking-lab.download-gold') }}"
            class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
        >Download blank gold_queries.csv</button>
        @if (! empty($hasScoresExport))
            <a
                href="{{ route('admin.ranking-lab.export') }}"
                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
            >Download CSV</a>
        @endif
        @if (! empty($hasMatricesExport))
            <a
                href="{{ route('admin.ranking-lab.export-matrices') }}"
                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
            >Download matrices CSV</a>
        @endif
    </div>
</form>

@if (! empty($lab['runs']) && ! empty($lab['matrices']))
    @php $classes = $lab['classes']; @endphp
    <section class="mb-8 rounded-xl border border-gray-200 bg-white p-4">
        <h2 class="font-semibold text-gray-900">Confusion matrices (top-1 class)</h2>
        <p class="mt-1 text-xs text-gray-500">Rows = gold query family. Columns = family of the SKU each scorer ranked #1. Darker diagonal is better.</p>
        @if (! empty($lab['unlabeled']))
            <p class="mt-2 text-xs text-amber-800">No gold label (skipped in metrics): {{ implode('; ', $lab['unlabeled']) }}</p>
        @endif
        @if (($lab['n_excluded'] ?? 0) > 0)
            <p class="mt-1 text-xs text-gray-500">Excluded <code>no_relevant_item</code>: {{ $lab['n_excluded'] }}</p>
        @endif
        @if (($lab['kappa']['n'] ?? 0) > 0)
            <p class="mt-1 text-xs text-gray-500">Second-grader kappa: {{ number_format((float) $lab['kappa']['kappa'], 3) }} (n={{ $lab['kappa']['n'] }})</p>
        @endif
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-left text-xs">
                <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-2 py-1">Scorer</th>
                        <th class="px-2 py-1 text-right">n</th>
                        <th class="px-2 py-1 text-right">Accuracy</th>
                        <th class="px-2 py-1 text-right">Macro recall</th>
                        <th class="px-2 py-1 text-right">Macro F1</th>
                        <th class="px-2 py-1 text-right">MCC</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lab['matrices'] as $tech => $matrix)
                        <tr class="border-t border-gray-100">
                            <td class="px-2 py-1">{{ $lab['technologies'][$tech] ?? $tech }}</td>
                            <td class="px-2 py-1 text-right font-mono">{{ $matrix['n'] }}</td>
                            <td class="px-2 py-1 text-right font-mono">{{ number_format(100 * ($matrix['accuracy'] ?? 0), 1) }}%</td>
                            <td class="px-2 py-1 text-right font-mono">{{ number_format($matrix['macro_recall'] ?? 0, 3) }}</td>
                            <td class="px-2 py-1 text-right font-mono">{{ number_format($matrix['macro_f1'] ?? 0, 3) }}</td>
                            <td class="px-2 py-1 text-right font-mono">{{ number_format($matrix['mcc'] ?? 0, 3) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4 grid gap-6 xl:grid-cols-2">
            @foreach ($lab['matrices'] as $tech => $matrix)
                <div class="overflow-x-auto">
                    <p class="mb-2 text-sm font-medium text-gray-800">
                        {{ $lab['technologies'][$tech] ?? $tech }}
                    </p>
                    <table class="min-w-full border-collapse text-[10px]">
                        <thead>
                            <tr>
                                <th class="border border-gray-200 bg-gray-50 px-1 py-1 text-left">Gold \ Pred</th>
                                @foreach ($classes as $predKey => $predLabel)
                                    <th class="border border-gray-200 bg-gray-50 px-1 py-1 font-normal" title="{{ $predLabel }}">{{ \Illuminate\Support\Str::limit($predLabel, 8, '') }}</th>
                                @endforeach
                                <th class="border border-gray-200 bg-gray-50 px-1 py-1 font-medium">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($classes as $goldKey => $goldLabel)
                                <tr>
                                    <th class="border border-gray-200 bg-gray-50 px-1 py-1 text-left font-medium">{{ $goldLabel }}</th>
                                    @foreach ($classes as $predKey => $predLabel)
                                        @php
                                            $count = $matrix['grid'][$goldKey][$predKey] ?? 0;
                                            $max = max(1, $matrix['n']);
                                            $alpha = $count === 0 ? 0 : 0.12 + 0.7 * ($count / $max);
                                            $isDiag = $goldKey === $predKey;
                                        @endphp
                                        <td
                                            class="border border-gray-200 px-1 py-1 text-center font-mono {{ $count === 0 ? 'text-gray-300' : 'text-gray-900' }}"
                                            style="background: rgba({{ $isDiag ? '16,185,129' : '245,158,11' }}, {{ $alpha }})"
                                        >{{ $count ?: '' }}</td>
                                    @endforeach
                                    <td class="border border-gray-200 bg-gray-50 px-1 py-1 text-center font-mono">{{ ($matrix['row_totals'][$goldKey] ?? 0) ?: '' }}</td>
                                </tr>
                            @endforeach
                            <tr>
                                <th class="border border-gray-200 bg-gray-50 px-1 py-1 text-left font-medium">Total</th>
                                @foreach ($classes as $predKey => $predLabel)
                                    <td class="border border-gray-200 bg-gray-50 px-1 py-1 text-center font-mono">{{ ($matrix['col_totals'][$predKey] ?? 0) ?: '' }}</td>
                                @endforeach
                                <td class="border border-gray-200 bg-gray-50 px-1 py-1 text-center font-mono">{{ $matrix['n'] ?? '' }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="mt-2 text-xs text-gray-600">
                        Accuracy {{ number_format(100 * ($matrix['accuracy'] ?? 0), 1) }}%
                        · Macro recall {{ number_format($matrix['macro_recall'] ?? 0, 3) }}
                        · Macro F1 {{ number_format($matrix['macro_f1'] ?? 0, 3) }}
                        · MCC {{ number_format($matrix['mcc'] ?? 0, 3) }}
                    </p>
                </div>
            @endforeach
        </div>
        <p class="mt-4 text-xs text-gray-500">Gold families labeled by a person before the run; product families from product_family.csv.</p>
    </section>
@endif

@forelse ($lab['runs'] as $run)
    <section class="mb-8 overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="border-b border-gray-200 px-4 py-3">
            <h2 class="font-semibold text-gray-900">“{{ $run['query'] }}”</h2>
            <p class="mt-1 text-xs text-gray-600">
                Gold:
                <span class="font-medium">
                    @if (($run['status'] ?? null) === 'no_relevant_item')
                        excluded (no_relevant_item)
                    @elseif (($run['gold'] ?? null) === null)
                        unlabeled
                    @else
                        {{ $run['gold'] }}
                    @endif
                </span>
                @foreach ($lab['technologies'] as $tech => $label)
                    · {{ $label }} #1:
                    <span @class(['font-medium', 'text-amber-700' => empty($run['top1_correct'][$tech])])>
                        {{ $run['top1'][$tech] ?? '—' }}
                    </span>
                @endforeach
            </p>
            <p class="mt-1 font-mono text-xs text-gray-500">Ranker query: {{ $run['catalog_query'] }}</p>
            <p class="mt-1 text-xs text-gray-500">
                Mode: {{ $run['ranker']['mode'] ?? 'keyword' }}
                · version: {{ $run['ranker']['model_version'] ?? '—' }}
                · product: {{ $run['nlp']['product'] ?? '—' }}
                @if (! empty($run['nlp']['exclusions']))
                    · exclude: {{ implode(', ', $run['nlp']['exclusions']) }}
                @endif
            </p>
            <div class="mt-2 flex flex-wrap gap-1">
                @foreach ($run['nlp']['keywords'] as $keyword)
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-700">{{ $keyword }}</span>
                @endforeach
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-xs">
                <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="sticky left-0 bg-gray-50 px-3 py-2">Live #</th>
                        <th class="sticky left-10 bg-gray-50 px-3 py-2">SKU</th>
                        @foreach ($technologies as $label)
                            <th class="px-3 py-2 text-right">{{ $label }}</th>
                            <th class="px-3 py-2 text-right"># {{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($run['rows'] as $row)
                        <tr class="hover:bg-gray-50">
                            <td class="sticky left-0 bg-white px-3 py-2 font-semibold">{{ $row['live_rank'] }}</td>
                            <td class="sticky left-10 max-w-xs bg-white px-3 py-2">
                                <a href="{{ $row['url'] }}" class="font-medium text-gray-900 hover:text-brand-700">{{ $row['name'] }}</a>
                                <p class="text-[11px] text-gray-500">{{ $row['store'] }} · {{ $row['category'] }} · ₱{{ number_format($row['price'], 2) }}</p>
                                <p class="text-[11px] text-gray-500">Family: {{ $row['family'] ?? 'Unmapped' }}@if (! empty($row['secondary_families'])) · also {{ implode(', ', $row['secondary_families']) }}@endif</p>
                            </td>
                            @foreach ($technologies as $key => $label)
                                @php
                                    $score = $row['scores'][$key] ?? null;
                                    $rank = $row['ranks'][$key] ?? null;
                                    $disagrees = is_int($rank) && $rank !== $row['live_rank'];
                                @endphp
                                <td class="px-3 py-2 text-right font-mono {{ $score === null ? 'text-gray-300' : 'text-gray-800' }}">
                                    {{ $score === null ? '—' : number_format((float) $score, 3) }}
                                </td>
                                <td @class([
                                    'px-3 py-2 text-right font-mono',
                                    'font-semibold text-amber-700' => $disagrees,
                                    'text-gray-400' => ! $disagrees,
                                ])>
                                    {{ $rank ?? '—' }}
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ 2 + (count($technologies) * 2) }}" class="px-3 py-6 text-sm text-gray-500">No live results for this query.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="border-t border-gray-100 px-4 py-2 text-[11px] text-gray-500">Amber rank numbers disagree with live order — those cells are the confusion-matrix contrasts.</p>
    </section>
@empty
    @if ($rawQuery !== '')
        <p class="rounded-xl border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-800">No queries to run.</p>
    @endif
@endforelse
@endsection
