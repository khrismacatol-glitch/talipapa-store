<section id="eval-labels" class="mb-6 space-y-4">
    @if (session('status'))
        <p class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</p>
    @endif

    <section class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
        <h2 class="font-semibold text-gray-900">How to relabel and run</h2>
        <p class="mt-2">
            The <strong>16 family names are fixed</strong>. You may only <strong>move products</strong> among those 16
            and <strong>change which of the 16 a query is gold for</strong>. You cannot add a new family
            (for example <code>Milk for kids</code> — use <code>Kids</code>).
        </p>
        <p class="mt-2 font-medium">Allowed names (exact):</p>
        <p class="mt-1 font-mono text-xs">{{ implode(' · ', $evalLabels['families']) }}</p>
        <ol class="mt-3 list-decimal space-y-2 pl-5">
            <li>
                <strong>Download the current product file</strong>
                (<code>product_family.csv</code>). Open it in Excel or Notepad++.
                Do not change <code>product_id</code> or <code>name</code>.
                Change only <code>family</code> and optional <code>secondary_families</code> (separate names with <code>; </code>).
                <strong>Cooking</strong> is not a primary product family — put it in <code>secondary_families</code>.
                Save as <strong>CSV UTF-8</strong>.
            </li>
            <li>
                <strong>Download the current gold queries file</strong>
                (<code>gold_queries.csv</code>). Do not change <code>query_id</code> or <code>query</code>.
                Change only <code>gold_family</code> to one of the 16.
                <code>status</code>: <code>graded</code> (counts in the matrix) or <code>no_relevant_item</code> (skipped).
                Cooking is allowed here as query gold. Save as <strong>CSV UTF-8</strong>.
            </li>
            <li>
                <strong>Upload <code>product_family.csv</code></strong>, then <strong>upload <code>gold_queries.csv</code></strong>.
                Labels that are not in the 16 are rejected. Previous files are kept as <code>.backup.csv</code>.
            </li>
            <li>
                Check <strong>Eval CSV load</strong> below: both files exists/readable, data rows and loaded counts,
                headers include <code>family</code> and <code>gold_family</code>. Wrong labels are listed there.
            </li>
            <li>
                Confirm the <strong>Queries</strong> box (filled from the gold file when you open this page; one query per line), then <strong>Run</strong>.
                The confusion matrix rebuilds. Live ranking is not trained.
            </li>
        </ol>
        <p class="mt-3 text-xs">
            Gold and product strings must match exactly (<code>Kids</code> is not <code>Milk for kids</code>).
            This is not Train labels (<code>0</code>/<code>1</code>/<code>2</code>).
        </p>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="space-y-3 rounded-xl border border-gray-200 bg-white p-4">
            <h2 class="font-semibold text-gray-900">Step 1 — Current product_family.csv</h2>
            <p class="break-all font-mono text-xs text-gray-500">{{ $evalLabels['family_path'] }} ({{ $evalLabels['family_rows'] }} rows)</p>
            @if (! empty($evalLabels['has_family_file']))
                <a href="{{ route('admin.ranking-lab.current-family') }}" class="inline-flex rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">Download current product_family.csv</a>
            @else
                <p class="text-sm text-amber-800">No saved family file yet. Download a blank milk template below, fill families, then upload.</p>
            @endif
            <form method="POST" action="{{ route('admin.ranking-lab.upload-family') }}" enctype="multipart/form-data" class="space-y-2 border-t border-gray-100 pt-3">
                @csrf
                <label class="block text-sm font-medium text-gray-700">Step 3a — Upload product_family.csv</label>
                <input type="file" name="family" accept=".csv,text/csv" required class="block w-full text-sm">
                <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Save family file</button>
            </form>
        </div>
        <div class="space-y-3 rounded-xl border border-gray-200 bg-white p-4">
            <h2 class="font-semibold text-gray-900">Step 2 — Current gold_queries.csv</h2>
            <p class="break-all font-mono text-xs text-gray-500">{{ $evalLabels['gold_path'] }} ({{ $evalLabels['gold_rows'] }} rows)</p>
            @if (! empty($evalLabels['has_gold_file']))
                <a href="{{ route('admin.ranking-lab.current-gold') }}" class="inline-flex rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">Download current gold_queries.csv</a>
            @else
                <p class="text-sm text-amber-800">No saved gold file yet. Type queries below and download a blank gold template, fill gold_family, then upload.</p>
            @endif
            <form method="POST" action="{{ route('admin.ranking-lab.upload-gold') }}" enctype="multipart/form-data" class="space-y-2 border-t border-gray-100 pt-3">
                @csrf
                <label class="block text-sm font-medium text-gray-700">Step 3b — Upload gold_queries.csv</label>
                <input type="file" name="gold" accept=".csv,text/csv" required class="block w-full text-sm">
                <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Save gold file</button>
            </form>
        </div>
    </div>

    <details class="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-600">
        <summary class="cursor-pointer font-medium text-gray-900">Blank templates (new rows only)</summary>
        <p class="mt-2 text-xs">These leave family columns empty. Prefer the current-file downloads above when you are regrouping existing labels.</p>
        <form method="POST" action="{{ route('admin.ranking-lab.download-family') }}" class="mt-3">
            @csrf
            <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Download blank milk product_family.csv</button>
        </form>
        <p class="mt-2 text-xs">Blank gold template: type queries in the box below, then use “Download blank gold_queries.csv”.</p>
    </details>
</section>
