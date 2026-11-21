@props([
    'product',
    'position' => null,
])

@php
    $canAddToCart = $product->is_available && $product->stock > 0;
@endphp

<article {{ $attributes->merge(['class' => 'group flex h-full flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm transition-shadow hover:shadow-md']) }}>
    <div class="relative aspect-[4/3] overflow-hidden bg-gray-100">
        <a
            href="{{ route('products.show', $product) }}"
            class="block h-full w-full"
            data-search-click
            data-product-id="{{ $product->id }}"
        >
            <x-img
                :src="$product->imageUrl()"
                :alt="$product->name"
                type="product"
                class="h-full w-full object-cover transition-transform duration-300 hover:scale-105"
                loading="lazy"
            />
        </a>

        @unless ($canAddToCart)
            <span class="absolute left-2 top-2 rounded-md bg-gray-900/75 px-2 py-1 text-xs font-medium text-white">
                Unavailable
            </span>
        @endunless

    </div>

    <div class="flex flex-1 flex-col p-4">
        <a href="{{ route('stores.show', $product->store) }}" class="mb-1 text-xs font-medium text-brand-600 hover:text-brand-700">
            {{ $product->store->store_name }}
        </a>

        <h3 class="mb-1 line-clamp-2 text-sm font-semibold text-gray-900">
            <a href="{{ route('products.show', $product) }}" class="hover:text-brand-600" data-search-click data-product-id="{{ $product->id }}">
                {{ $product->name }}
            </a>
        </h3>

        <p class="text-lg font-bold text-gray-900">
            ₱{{ number_format($product->price, 2) }}
        </p>

        @if (! empty($showRankScores) && ! empty($product->rank_scores) && is_array($product->rank_scores))
            @php
                $s = $product->rank_scores;
                $fmt = fn ($key) => array_key_exists($key, $s) && $s[$key] !== null
                    ? number_format((float) $s[$key], 3)
                    : '—';
            @endphp
            <dl class="mt-2 space-y-0.5 font-mono text-[10px] leading-4 text-gray-500">
                <div class="flex justify-between gap-2"><dt>LaBSE</dt><dd>{{ $fmt('labse') }}</dd></div>
                <div class="flex justify-between gap-2"><dt>BM25</dt><dd>{{ $fmt('bm25') }}</dd></div>
                <div class="flex justify-between gap-2"><dt>LTR</dt><dd>{{ $fmt('lambdamart') }}</dd></div>
                <div class="flex justify-between gap-2 font-semibold text-gray-800"><dt>Total</dt><dd>{{ $fmt('total') }}</dd></div>
            </dl>
        @endif

        <div class="mt-auto grid grid-cols-2 gap-2 pt-3">
            <form method="POST" action="{{ route('cart.store') }}">
                @csrf
                <input type="hidden" name="product_id" value="{{ $product->id }}">
                <input type="hidden" name="quantity" value="1">
                @if (! empty($searchSessionToken ?? null))
                    <input type="hidden" name="search_session" value="{{ $searchSessionToken }}">
                @endif
                <button
                    type="submit"
                    @disabled(! $canAddToCart)
                    @class([
                        'inline-flex w-full items-center justify-center gap-1 rounded-lg border px-2 py-2 text-xs font-semibold transition',
                        'border-brand-600 bg-white text-brand-600 hover:bg-brand-50' => $canAddToCart,
                        'cursor-not-allowed border-gray-200 bg-gray-100 text-gray-400' => ! $canAddToCart,
                    ])
                >
                    <i class="ri-shopping-cart-2-line" aria-hidden="true"></i>
                    Add to cart
                </button>
            </form>
            <form method="POST" action="{{ route('cart.store') }}">
                @csrf
                <input type="hidden" name="product_id" value="{{ $product->id }}">
                <input type="hidden" name="quantity" value="1">
                <input type="hidden" name="buy_now" value="1">
                @if (! empty($searchSessionToken ?? null))
                    <input type="hidden" name="search_session" value="{{ $searchSessionToken }}">
                @endif
                <button
                    type="submit"
                    @disabled(! $canAddToCart)
                    @class([
                        'inline-flex w-full items-center justify-center gap-1 rounded-lg px-2 py-2 text-xs font-semibold text-white transition',
                        'bg-brand-600 hover:bg-brand-700' => $canAddToCart,
                        'cursor-not-allowed bg-gray-300' => ! $canAddToCart,
                    ])
                >
                    Buy
                </button>
            </form>
        </div>
    </div>
</article>
