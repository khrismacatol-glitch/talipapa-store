@extends('layouts.admin')

@section('title', 'Train labels')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Train labels</h1>
    <p class="mt-1 max-w-3xl text-sm text-gray-500">
        Correct <strong>every training query</strong> in Excel: download the current qrels (grades filled),
        or download catalog rows for the train-split queries in <code>queries.csv</code>.
        Upload <strong>merges</strong> into existing labels (other queries are kept). Then merge into
        <code>ml/data/qrels.csv</code> and train a <strong>candidate</strong>.
        Live <code>ml/models/ranker.txt</code> is never overwritten from this page.
    </p>
</div>

@if (session('status'))
    <p class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</p>
@endif
@if ($errors->any())
    <p class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</p>
@endif

<section class="mb-6 rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-700">
    <h2 class="font-semibold text-gray-900">Catalog</h2>
    <p class="mt-1">Available products in the database: {{ $status['catalog_count'] }} (about {{ $status['milk_sku_count'] }} with “milk” in the name or description). Download includes up to {{ $status['export_cap'] }} SKUs per query.</p>
    <p class="mt-1 text-xs text-gray-500">Uploaded pairs: {{ $status['human_qrels'] }} · uploaded queries: {{ $status['human_queries'] }} · current training qrels: {{ $status['training_qrels'] }}</p>
    <p class="mt-1 break-all font-mono text-xs text-gray-500">staging: {{ $status['human_qrels_path'] }}</p>
    <p class="break-all font-mono text-xs text-gray-500">training qrels: {{ $status['qrels_path'] }}</p>
</section>

<form method="GET" action="{{ route('admin.train-labels.download-existing') }}" class="mb-6 space-y-2 rounded-xl border border-gray-200 bg-white p-4">
    <h2 class="font-semibold text-gray-900">1. Correct every query already in qrels</h2>
    <p class="text-sm text-gray-600">
        Older qrels only stored a small contrast set (~25 SKUs per query), not the full milk catalog.
        This download lists <strong>every query already in qrels</strong> × <strong>every in-stock SKU whose name or description contains <code>milk</code></strong>
        (plus any extra SKUs you already graded). Existing 0/1/2 are filled; new SKUs have a blank grade. Save as CSV UTF-8, then upload. Merge keeps queries you did not touch.
    </p>
    <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">Download all queries × milk catalog ({{ $status['training_qrels'] }} existing pairs, expanded)</button>
</form>

<form method="POST" action="{{ route('admin.train-labels.download') }}" class="mb-6 space-y-3 rounded-xl border border-gray-200 bg-white p-4">
    @csrf
    <h2 class="font-semibold text-gray-900">2. Add or re-grade catalog rows</h2>
    <fieldset class="space-y-2">
        <legend class="text-sm font-medium text-gray-700">Which queries</legend>
        <label class="flex items-start gap-2 text-sm text-gray-700">
            <input type="radio" name="query_source" value="pasted" {{ old('query_source', 'pasted') === 'pasted' ? 'checked' : '' }} class="mt-1">
            <span>Only the queries I paste (small file). If the box below has any lines, those are used even if you leave “all train” selected.</span>
        </label>
        <label class="flex items-start gap-2 text-sm text-gray-700">
            <input type="radio" name="query_source" value="all_train" {{ old('query_source') === 'all_train' ? 'checked' : '' }} class="mt-1">
            <span>All <code>train</code> queries from <code>queries.csv</code> ({{ count($trainQueries) }} loaded, cap {{ \App\Services\Search\HumanTrainLabelService::MAX_QUERIES }}). Huge: about {{ number_format(count($trainQueries) * max(1, (int) $status['catalog_count'])) }} rows if you also pick all SKUs. Clear the paste box to use this. Existing grades are filled in when a pair is already in qrels.</span>
        </label>
    </fieldset>
    <div>
        <label for="train-labels-q" class="mb-1 block text-sm font-medium text-gray-700">Queries to judge (if “only pasted”)</label>
        <textarea
            id="train-labels-q"
            name="q"
            rows="6"
            maxlength="20000"
            placeholder="One query per line, e.g.&#10;gatas nga pangluto&#10;gatas gikan sa baka"
            class="block w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm"
        >{{ old('q') }}</textarea>
        <p class="mt-1 text-xs text-gray-500">Intent values: {{ implode(', ', $intents) }}.</p>
    </div>
    <fieldset class="space-y-2">
        <legend class="text-sm font-medium text-gray-700">SKUs to include</legend>
        <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="radio" name="sku_scope" value="all" {{ old('sku_scope') === 'all' ? 'checked' : '' }}>
            All in-stock SKUs (up to {{ $status['export_cap'] }})
        </label>
        <label class="flex flex-wrap items-center gap-2 text-sm text-gray-700">
            <input type="radio" name="sku_scope" value="contains" {{ old('sku_scope', 'contains') === 'contains' ? 'checked' : '' }}>
            Name or description contains
            <input
                type="text"
                name="contains"
                value="{{ old('contains', 'milk') }}"
                maxlength="200"
                placeholder="canned, noodles"
                class="w-56 rounded-lg border border-gray-300 px-2 py-1 font-mono text-sm"
            >
        </label>
        <p class="text-xs text-gray-500">One word or several, separated by commas. <code>canned, noodles</code> keeps a SKU if name <em>or</em> description contains <code>canned</code> <strong>or</strong> <code>noodles</code> (not the whole phrase). Spaces around commas are ignored.</p>
        <label class="flex flex-wrap items-center gap-2 text-sm text-gray-700">
            <input type="radio" name="sku_scope" value="category" {{ old('sku_scope') === 'category' ? 'checked' : '' }}>
            Category
            <select name="category_id" class="rounded-lg border border-gray-300 px-2 py-1 text-sm">
                <option value="">Select category</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected((string) old('category_id') === (string) $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </label>
    </fieldset>
    <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">Download judgment CSV</button>
</form>

<form method="POST" action="{{ route('admin.train-labels.upload') }}" enctype="multipart/form-data" class="mb-6 space-y-3 rounded-xl border border-gray-200 bg-white p-4">
    @csrf
    <h2 class="font-semibold text-gray-900">3. Upload graded CSV</h2>
    <div>
        <label for="train-labels-file" class="mb-1 block text-sm font-medium text-gray-700">Graded file</label>
        <input id="train-labels-file" type="file" name="grades" accept=".csv,text/csv" required class="block w-full text-sm">
        <p class="mt-1 text-xs text-gray-500">Required columns: query, product_key, grade (0, 1, or 2). Upload <strong>merges</strong> by query+SKU. Rows with empty grade are skipped. Live ranker is not trained.</p>
    </div>
    <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Save upload (merge, does not train)</button>
</form>

<section class="mb-6 rounded-xl border border-gray-900 bg-white p-4">
    <h2 class="font-semibold text-gray-900">4. Merge, then train</h2>
    <p class="mt-1 text-sm text-gray-600">You already merged. Next is train. This can take several minutes. Live <code>ranker.txt</code> is not replaced.</p>
    <div class="mt-3 flex flex-wrap gap-3">
        <form method="POST" action="{{ route('admin.train-labels.apply') }}">
            @csrf
            <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Merge upload into ml/data/qrels.csv</button>
        </form>
        <form method="POST" action="{{ route('admin.train-labels.train') }}" onsubmit="return confirm('Train a candidate model? This can take many minutes. Live ranker.txt will not be replaced.');">
            @csrf
            <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">Train candidate</button>
        </form>
    </div>
</section>

<section class="mb-6 rounded-xl border-2 border-brand-600 bg-brand-50 p-4">
    <h2 class="font-semibold text-gray-900">5. Promote candidate to live</h2>
    <p class="mt-1 text-sm text-gray-700">
        Copies the latest candidate into <code>ml/models</code> (archives the current live files first).
        @if ($status['latest_candidate'])
            Ready: <code>{{ basename($status['latest_candidate']) }}</code>
        @else
            Train first — no candidate folder yet.
        @endif
    </p>
    <form method="POST" action="{{ route('admin.train-labels.promote') }}" class="mt-3" onsubmit="return confirm('Copy the latest candidate into ml/models? Current live files are archived first. Then restart uvicorn.');">
        @csrf
        <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">Promote candidate to live</button>
    </form>
    <p class="mt-2 text-xs text-gray-600">Then restart uvicorn on port 8088. Promote does not restart uvicorn. If Train candidate fails on this page, use Git Bash in <code>ml/</code>: <code>.venv/Scripts/python.exe train.py --out-dir models/candidates/manual</code></p>
</section>

<section class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
    <h2 class="font-semibold">How to fill the CSV</h2>
    <p class="mt-2">There is no in-page editor. To <strong>correct every query</strong>, use “Download all current qrels”, edit grades, upload; merge will not delete other queries. Or download catalog rows for all train queries (prefer the contains filter so the file stays small).</p>
    <ol class="mt-2 list-decimal space-y-1 pl-5">
        <li>Open <code>train-judgment.csv</code> in <strong>Excel</strong>, <strong>Google Sheets</strong>, <strong>LibreOffice Calc</strong>, or <strong>Notepad++</strong> (any text editor is fine).</li>
        <li>Do not rename or delete columns. Do not change <code>query</code>, <code>product_id</code>, or <code>product_key</code>.</li>
        <li>Edit only <code>grade</code> and <code>intent</code> (and <code>split</code> if you need a test row). Leave a row’s <code>grade</code> empty to skip that SKU.</li>
        <li>In Excel: File → Save As → CSV UTF-8 (Comma delimited). In Notepad++: Encoding → UTF-8, then save. Keep the <code>.csv</code> extension.</li>
    </ol>
    <div class="mt-3 overflow-x-auto">
        <table class="min-w-full text-left text-xs">
            <thead>
                <tr class="border-b border-amber-200">
                    <th class="py-1 pr-3 font-semibold">Column</th>
                    <th class="py-1 pr-3 font-semibold">Allowed values</th>
                    <th class="py-1 font-semibold">Meaning</th>
                </tr>
            </thead>
            <tbody>
                <tr class="border-b border-amber-100 align-top">
                    <td class="py-1 pr-3 font-mono">grade</td>
                    <td class="py-1 pr-3 font-mono">0 &nbsp; 1 &nbsp; 2</td>
                    <td class="py-1">
                        <strong>2</strong> = this SKU matches the query (want it near the top).<br>
                        <strong>1</strong> = related milk / wrong attribute, still somewhat useful.<br>
                        <strong>0</strong> = not relevant. Blank = skip this row.
                    </td>
                </tr>
                <tr class="border-b border-amber-100 align-top">
                    <td class="py-1 pr-3 font-mono">intent</td>
                    <td class="py-1 pr-3 font-mono">{{ implode(', ', $intents) }}</td>
                    <td class="py-1">
                        One intent per query (same value on every row of that query).
                        Use <code>cow</code> for drinking cow milk, <code>low_fat</code>, <code>diabetic</code>,
                        <code>organic</code>, <code>powder</code>, or <code>other</code> if it is not a milk-intent query.
                        If you leave intent blank, upload treats it as <code>cow</code>.
                    </td>
                </tr>
                <tr class="align-top">
                    <td class="py-1 pr-3 font-mono">split</td>
                    <td class="py-1 pr-3 font-mono">train &nbsp; test</td>
                    <td class="py-1">Leave as <code>train</code> so the row can be used to fit the model. <code>test</code> is held out and never trained on.</td>
                </tr>
            </tbody>
        </table>
    </div>
    <p class="mt-3 font-mono text-xs">Example: gatas nga pangluto, …, alaska-evaporada, …, <strong>2</strong>, <strong>cow</strong>, train</p>
</section>
@endsection
