@extends('layouts.admin')

@section('title', 'Train labels')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Train labels</h1>
    <p class="mt-1 max-w-3xl text-sm text-gray-500">
        Download in-stock catalog rows for human grades (0 / 1 / 2), upload the same CSV, then optionally
        copy it into <code>ml/data/qrels.csv</code> and train a <strong>candidate</strong> model.
        Live <code>ml/models/ranker.txt</code> is never overwritten from this page.
        Ranking-lab gold CSVs are evaluation only and are not used here.
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
    <p class="mt-1">Available products in the database: {{ $status['catalog_count'] }}. Download includes up to {{ $status['export_cap'] }} SKUs per query.</p>
    <p class="mt-1 text-xs text-gray-500">Uploaded pairs: {{ $status['human_qrels'] }} · uploaded queries: {{ $status['human_queries'] }} · current training qrels: {{ $status['training_qrels'] }}</p>
    <p class="mt-1 break-all font-mono text-xs text-gray-500">staging: {{ $status['human_qrels_path'] }}</p>
    <p class="break-all font-mono text-xs text-gray-500">training qrels: {{ $status['qrels_path'] }}</p>
</section>

<form method="POST" action="{{ route('admin.train-labels.download') }}" class="mb-6 space-y-3 rounded-xl border border-gray-200 bg-white p-4">
    @csrf
    <div>
        <label for="train-labels-q" class="mb-1 block text-sm font-medium text-gray-700">Queries to judge</label>
        <textarea
            id="train-labels-q"
            name="q"
            rows="6"
            required
            maxlength="8000"
            placeholder="One query per line, e.g.&#10;gatas nga pangluto&#10;gatas gikan sa baka"
            class="block w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm"
        >{{ old('q') }}</textarea>
        <p class="mt-1 text-xs text-gray-500">Max {{ \App\Services\Search\HumanTrainLabelService::MAX_QUERIES }} queries. Grade column is left blank. Intent: {{ implode(', ', $intents) }}.</p>
    </div>
    <fieldset class="space-y-2">
        <legend class="text-sm font-medium text-gray-700">SKUs to include</legend>
        <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="radio" name="sku_scope" value="all" {{ old('sku_scope', 'all') === 'all' ? 'checked' : '' }}>
            All in-stock SKUs (up to {{ $status['export_cap'] }})
        </label>
        <label class="flex flex-wrap items-center gap-2 text-sm text-gray-700">
            <input type="radio" name="sku_scope" value="contains" {{ old('sku_scope') === 'contains' ? 'checked' : '' }}>
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

<section class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
    <h2 class="font-semibold">How to fill the CSV</h2>
    <p class="mt-2">There is no in-page editor. Download the file, open it on your computer, fill <code>grade</code> and <code>intent</code>, save as CSV, then upload it below.</p>
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

<form method="POST" action="{{ route('admin.train-labels.upload') }}" enctype="multipart/form-data" class="mb-6 space-y-3 rounded-xl border border-gray-200 bg-white p-4">
    @csrf
    <div>
        <label for="train-labels-file" class="mb-1 block text-sm font-medium text-gray-700">Upload graded CSV</label>
        <input id="train-labels-file" type="file" name="grades" accept=".csv,text/csv" required class="block w-full text-sm">
        <p class="mt-1 text-xs text-gray-500">Required columns: query, product_key, grade (0, 1, or 2). Fill intent (default cow) and split=train. Rows with empty grade are skipped.</p>
    </div>
    <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Save upload (does not train)</button>
</form>

<div class="flex flex-wrap gap-3">
    <form method="POST" action="{{ route('admin.train-labels.apply') }}">
        @csrf
        <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Copy upload into ml/data/qrels.csv</button>
    </form>
    <form method="POST" action="{{ route('admin.train-labels.train') }}" onsubmit="return confirm('Train a candidate model? This can take many minutes. Live ranker.txt will not be replaced.');">
        @csrf
        <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">Train candidate (slow)</button>
    </form>
</div>
<p class="mt-3 text-xs text-gray-500">After a candidate trains, copy it into <code>ml/models</code> only when you intend to change live ranking, then restart uvicorn on port 8088.</p>
@endsection
