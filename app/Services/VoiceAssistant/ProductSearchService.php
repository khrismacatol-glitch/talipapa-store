<?php

namespace App\Services\VoiceAssistant;

use App\Models\Product;
use App\Services\Search\SemanticRanker;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProductSearchService
{
    /** @var array{model_version: ?string, mode: ?string, is_milk_query: ?bool} */
    public array $lastRankMeta = [
        'model_version' => null,
        'mode' => null,
        'is_milk_query' => null,
    ];

    /** @var array<string, string>|null */
    private ?array $canonicalProducts = null;

    /** @var array<string, array<int, string>> */
    private array $expandTermsCache = [];

    /** @var array<string, array<int, string>> */
    private array $termsFromNlpCache = [];

    /** @var array<string, array<int, string>> */
    private array $synonymCache = [];

    public function __construct(
        private readonly SemanticRanker $semanticRanker,
    ) {}

    /**
     * @param  array<string, mixed>|null  $nlp
     */
    public function paginate(Request $request, ?array $nearbyStoreIds, int $perPage = 16, ?array $nlp = null): LengthAwarePaginator
    {
        $query = $this->baseQuery($request, $nearbyStoreIds);

        if (! $request->filled('q')) {
            return $query->latest()->paginate($perPage)->withQueryString();
        }

        $text = $request->string('q')->toString();
        $terms = $this->termsFromNlp($nlp, $text);
        $units = $this->unitsFromNlp($nlp, $text);

        return $this->paginateScored($query, $text, $terms, $units, $perPage, (int) $request->input('page', 1), $request->query(), $nlp ?? []);
    }

    /**
     * @param  array<string, mixed>  $nlp
     * @return Collection<int, Product>
     */
    public function searchFromNlp(array $nlp, ?array $nearbyStoreIds = null, ?int $limit = null): Collection
    {
        $limit ??= (int) config('voice-assistant.voice_results_limit', 8);

        $original = (string) ($nlp['original_text'] ?? '');
        $terms = $this->termsFromNlp($nlp, $original);
        $normalizedUnits = $this->unitsFromNlp($nlp, $original);

        $query = Product::query()
            ->available()
            ->with(['store', 'category']);

        if ($nearbyStoreIds !== null) {
            $query->whereIn('store_profile_id', $nearbyStoreIds);
        }

        return $this->semanticRank(clone $query, $original, $limit, $nlp)
            ?? $this->scoreAndTake($query, $terms, $normalizedUnits, $limit, $nlp);
    }

    /**
     * @param  array<string, mixed>  $nlp
     */
    public function keywordScore(Product $product, array $nlp): float
    {
        $text = (string) ($nlp['original_text'] ?? '');

        return $this->scoreProduct(
            $product,
            $this->termsFromNlp($nlp, $text),
            $this->unitsFromNlp($nlp, $text),
            $nlp,
        );
    }

    public function baseQuery(Request $request, ?array $nearbyStoreIds): Builder
    {
        return Product::query()
            ->available()
            ->with(['store', 'category'])
            ->when($request->filled('category'), fn ($q) => $q->where('category_id', $request->integer('category')))
            ->when($request->filled('store'), fn ($q) => $q->where('store_profile_id', $request->integer('store')))
            ->when($nearbyStoreIds !== null, fn ($q) => $q->whereIn('store_profile_id', $nearbyStoreIds));
    }

    /**
     * @param  array<int, string>  $terms
     * @param  array<int, string>  $units
     * @param  array<string, mixed>  $nlp
     */
    private function paginateScored(Builder $baseQuery, string $text, array $terms, array $units, int $perPage, int $page, array $queryParams, array $nlp = []): LengthAwarePaginator
    {
        $scored = $this->semanticRank(clone $baseQuery, $text, 500, $nlp)
            ?? $this->scoreAndTake(clone $baseQuery, $terms, $units, 500, $nlp);
        $total = $scored->count();
        $items = $scored->forPage($page, $perPage)->values();

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => $queryParams],
        );
    }

    /**
     * @param  array<string, mixed>  $nlp
     * @return Collection<int, Product>|null
     */
    private function semanticRank(Builder $query, string $text, int $limit, array $nlp = []): ?Collection
    {
        if (! $this->semanticRanker->enabled() || trim($text) === '') {
            return null;
        }

        $candidates = $query->limit((int) config('semantic-search.candidate_limit', 2000))->get()->keyBy('id');
        $ranked = $this->semanticRanker->rank($this->dialectCatalogQuery($text), $candidates->values(), $limit);

        if ($ranked === null) {
            return null;
        }

        $this->lastRankMeta = [
            'model_version' => $ranked['model_version'] ?? 'legacy',
            'mode' => $ranked['mode'] ?? null,
            'is_milk_query' => isset($ranked['milk_query'])
                ? ((float) $ranked['milk_query'] >= 0.5)
                : null,
            'intents' => $ranked['intents'] ?? [],
        ];

        $scoreMap = $ranked['scores'] ?? [];

        return $this->preferAgeMilkResults(
            collect($ranked['ids'])
                ->map(function (int $id) use ($candidates, $scoreMap) {
                    $product = $candidates->get($id);
                    if ($product !== null && isset($scoreMap[$id])) {
                        $product->rank_scores = $scoreMap[$id];
                    }

                    return $product;
                })
                ->filter()
                ->reject(fn (Product $product) => $this->isExcluded($product, $nlp) || $this->missesCanonicalProduct($product, $nlp))
                ->values(),
            $nlp,
        )->take($limit)->values();
    }

    /**
     * @param  array<int, string>  $terms
     * @return Collection<int, Product>
     */
    private function loadCandidates(Builder $query, array $terms, array $nlp = []): Collection
    {
        $terms = array_values(array_unique(array_filter($terms)));

        if ($terms === []) {
            return $query->limit(200)->get();
        }

        $includeDescription = $this->queryHasAnyPhrase($nlp, config('voc.cooking_use_query_phrases', []))
            || $this->queryHasAnyPhrase($nlp, config('voc.coconut_milk_query_phrases', []));
        $ordered = $this->orderCandidates($this->matchTerms(clone $query, $terms, $includeDescription), $terms, $nlp);
        $primary = $ordered->limit(200)->get();

        if ($primary->isNotEmpty()) {
            return $primary;
        }

        return $this->orderCandidates($this->matchTerms(clone $query, $terms, true), $terms, $nlp)
            ->limit(80)
            ->get();
    }

    /**
     * @param  array<int, string>  $terms
     * @param  array<string, mixed>  $nlp
     */
    private function orderCandidates(Builder $query, array $terms, array $nlp): Builder
    {
        $phrase = collect($terms)->sortByDesc(fn (string $term) => strlen($term))->first();

        if (is_string($phrase) && $phrase !== '') {
            $query->orderByRaw('CASE WHEN name LIKE ? THEN 0 ELSE 1 END', ['%'.$phrase.'%']);
        }

        if (($nlp['price_intent'] ?? null) === 'cheap') {
            $query->orderBy('price');
        }

        return $query;
    }

    /**
     * @param  array<int, string>  $terms
     */
    private function matchTerms(Builder $query, array $terms, bool $includeDescription): Builder
    {
        return $query->where(function (Builder $outer) use ($terms, $includeDescription) {
            foreach ($terms as $term) {
                $like = '%'.$term.'%';
                $outer->orWhere('name', 'like', $like)
                    ->orWhereHas('category', fn (Builder $cat) => $cat->where('name', 'like', $like));

                if ($includeDescription) {
                    $outer->orWhere('description', 'like', $like);
                }
            }
        });
    }

    /**
     * @param  array<int, string>  $terms
     * @param  array<int, string>  $units
     * @param  array<string, mixed>  $nlp
     * @return Collection<int, Product>
     */
    private function scoreAndTake(Builder $query, array $terms, array $units, int $limit, array $nlp = []): Collection
    {
        $products = $this->loadCandidates($query, $terms, $nlp);

        $scored = $products
            ->map(fn (Product $product) => [
                'product' => $product,
                'score' => $this->scoreProduct($product, $terms, $units, $nlp),
            ])
            ->filter(fn (array $row) => $row['score'] > 0);

        if (($nlp['price_intent'] ?? null) === 'cheap' && $scored->isNotEmpty()) {
            $maxPrice = (float) $scored->max(fn (array $row) => (float) $row['product']->price);
            $maxPrice = max($maxPrice, 0.01);

            $scored = $scored->map(function (array $row) use ($maxPrice) {
                $row['score'] += (1 - ((float) $row['product']->price / $maxPrice)) * 30;

                return $row;
            });
        }

        return $this->preferAgeMilkResults(
            $scored
                ->sortByDesc('score')
                ->pluck('product')
                ->values(),
            $nlp,
        )->take($limit)->values();
    }

    /**
     * @param  array<int, string>  $terms
     * @param  array<int, string>  $units
     * @param  array<string, mixed>  $nlp
     */
    private function scoreProduct(Product $product, array $terms, array $units, array $nlp = []): float
    {
        if ($this->isExcluded($product, $nlp) || $this->missesCanonicalProduct($product, $nlp)) {
            return 0.0;
        }

        $name = Str::lower($product->name);
        $description = Str::lower((string) $product->description);
        $category = Str::lower((string) $product->category?->name);
        $haystack = $name.' '.$description;
        $score = 0.0;

        foreach ($terms as $term) {
            $term = Str::lower(trim($term));

            if ($term === '') {
                continue;
            }

            if ($name === $term) {
                $score += 100;
            } elseif (str_contains($name, $term)) {
                $score += 50;
            }

            if (str_contains($description, $term)) {
                $score += 20;
            }

            if (str_contains($category, $term)) {
                $score += 15;
            }
        }

        foreach ($units as $unit) {
            if ($this->productMatchesUnit($name, $unit)) {
                $score += 35;
            }
        }

        $brand = Str::ascii(Str::lower(trim((string) ($nlp['brand'] ?? ''))));
        if ($brand !== '' && str_contains(Str::ascii($haystack), $brand)) {
            $score += 45;
        }

        foreach ($this->dietaryBoostTerms($nlp) as $dietTerm) {
            if (str_contains($haystack, $dietTerm)) {
                $score += 40;
            }
        }

        if ($this->isInfantMilkQuery($nlp)) {
            if ($this->looksLikeStarterInfant($haystack)) {
                $score += 220;
            } elseif ($this->looksLikeInfantFormula($haystack)) {
                $score += 140;
            }
        }

        if ($this->isChildMilkQuery($nlp)) {
            if ($this->looksLikeStarterInfant($haystack) || $this->looksLikeKidsMilk($haystack)) {
                $score += 220;
            } elseif ($this->looksLikeInfantFormula($haystack)) {
                $score += 140;
            }
        }

        if ($this->isAdultMilkQuery($nlp) && $this->looksLikeAdultMilk($haystack)) {
            $score += 220;
        }

        if ($this->isCookingMilkQuery($nlp) && $this->looksLikeCookingUseMilk($this->cookingHaystack($product))) {
            $score += 220;
        }

        return $score;
    }

    /**
     * @param  array<string, mixed>  $nlp
     */
    private function isExcluded(Product $product, array $nlp): bool
    {
        $exclusions = array_map(
            fn ($item) => Str::lower(trim((string) $item)),
            (array) ($nlp['exclusions'] ?? []),
        );
        $dietary = array_map(
            fn ($item) => Str::lower(trim((string) $item)),
            (array) ($nlp['dietary'] ?? []),
        );

        $haystack = Str::lower($product->name.' '.(string) $product->description);

        if (in_array('cow', $exclusions, true) || array_intersect($dietary, ['vegan', 'plant-based', 'non-cow', 'non-dairy']) !== []) {
            if ($this->looksLikeCowDairy($haystack)) {
                return true;
            }
        }

        if (in_array('infant_formula', $exclusions, true) && ($this->looksLikeInfantFormula($haystack) || $this->looksLikeKidsMilk($haystack))) {
            return true;
        }

        if (in_array('infant_formula', $exclusions, true) && $this->looksLikeNonBeverageMilk($haystack)) {
            return true;
        }

        if ((in_array('kids_milk', $exclusions, true) || $this->isAdultMilkQuery($nlp)) && $this->looksLikeKidsMilk($haystack)) {
            return true;
        }

        if ((in_array('adult_milk', $exclusions, true) || $this->isInfantMilkQuery($nlp) || $this->isChildMilkQuery($nlp)) && $this->looksLikeAdultMilk($haystack)) {
            return true;
        }

        if ($this->shouldDropCookingMilk($nlp, $exclusions) && $this->looksLikeCookingMilk($haystack)) {
            return true;
        }

        if ($this->exceedsPriceCap($product, $nlp)) {
            return true;
        }

        foreach ($exclusions as $exclusion) {
            if ($exclusion === '' || $exclusion === 'cow' || $exclusion === 'infant_formula' || $exclusion === 'cooking_milk' || $exclusion === 'adult_milk' || $exclusion === 'kids_milk') {
                continue;
            }

            if ($exclusion === 'sugar') {
                if ($this->looksLikeSweetenedMilk($haystack) && ! $this->looksLikeNoSugarMilk($haystack)) {
                    return true;
                }
                continue;
            }

            if (str_contains($haystack, $exclusion) && ! $this->looksLikePlantMilk($haystack)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeInfantFormula(string $haystack): bool
    {
        foreach (config('voc.infant_formula_terms', []) as $term) {
            $term = Str::lower(trim((string) $term));
            if ($term !== '' && str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeStarterInfant(string $haystack): bool
    {
        foreach (config('voc.starter_infant_terms', []) as $term) {
            $term = Str::lower(trim((string) $term));
            if ($term !== '' && str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeAdultMilk(string $haystack): bool
    {
        foreach (config('voc.adult_milk_terms', []) as $term) {
            $term = Str::lower(trim((string) $term));
            if ($term !== '' && str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeKidsMilk(string $haystack): bool
    {
        foreach (config('voc.kids_milk_terms', []) as $term) {
            $term = Str::lower(trim((string) $term));
            if ($term !== '' && str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $nlp
     */
    private function isInfantMilkQuery(array $nlp): bool
    {
        $dietary = array_map(
            fn ($item) => Str::lower(trim((string) $item)),
            (array) ($nlp['dietary'] ?? []),
        );
        if (in_array('infant', $dietary, true)) {
            return true;
        }

        $original = Str::lower((string) ($nlp['original_text'] ?? ''));
        foreach (config('voc.infant_milk_query_phrases', []) as $phrase) {
            $phrase = Str::lower(trim((string) $phrase));
            if ($phrase !== '' && preg_match('/(?:^|[^\p{L}\p{N}])'.preg_quote($phrase, '/').'(?:[^\p{L}\p{N}]|$)/u', $original)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $nlp
     */
    private function isChildMilkQuery(array $nlp): bool
    {
        if ($this->isInfantMilkQuery($nlp)) {
            return false;
        }

        $dietary = array_map(
            fn ($item) => Str::lower(trim((string) $item)),
            (array) ($nlp['dietary'] ?? []),
        );
        if (in_array('kids', $dietary, true)) {
            return true;
        }

        $original = Str::lower((string) ($nlp['original_text'] ?? ''));
        foreach (config('voc.child_milk_query_phrases', []) as $phrase) {
            $phrase = Str::lower(trim((string) $phrase));
            if ($phrase !== '' && preg_match('/(?:^|[^\p{L}\p{N}])'.preg_quote($phrase, '/').'(?:[^\p{L}\p{N}]|$)/u', $original)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $nlp
     */
    private function isAdultMilkQuery(array $nlp): bool
    {
        $dietary = array_map(
            fn ($item) => Str::lower(trim((string) $item)),
            (array) ($nlp['dietary'] ?? []),
        );
        if (in_array('adult', $dietary, true)) {
            return true;
        }

        $original = Str::lower((string) ($nlp['original_text'] ?? ''));
        foreach (config('voc.adult_milk_query_phrases', []) as $phrase) {
            $phrase = Str::lower(trim((string) $phrase));
            if ($phrase !== '' && preg_match('/(?:^|[^\p{L}\p{N}])'.preg_quote($phrase, '/').'(?:[^\p{L}\p{N}]|$)/u', $original)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $nlp
     */
    private function isCookingMilkQuery(array $nlp): bool
    {
        return $this->queryHasAnyPhrase($nlp, config('voc.cooking_milk_query_phrases', []));
    }

    /**
     * @param  array<string, mixed>  $nlp
     * @param  array<int, mixed>  $phrases
     */
    private function queryHasAnyPhrase(array $nlp, array $phrases): bool
    {
        $original = Str::lower((string) ($nlp['original_text'] ?? ''));
        foreach ($phrases as $phrase) {
            $phrase = Str::lower(trim((string) $phrase));
            if ($phrase !== '' && preg_match('/(?:^|[^\p{L}\p{N}])'.preg_quote($phrase, '/').'(?:[^\p{L}\p{N}]|$)/u', $original)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $nlp
     */
    private function isProbioticMilkQuery(array $nlp): bool
    {
        $original = Str::lower((string) ($nlp['original_text'] ?? ''));
        foreach (config('voc.probiotic_milk_terms', []) as $phrase) {
            $phrase = Str::lower(trim((string) $phrase));
            if ($phrase !== '' && preg_match('/(?:^|[^\p{L}\p{N}])'.preg_quote($phrase, '/').'(?:[^\p{L}\p{N}]|$)/u', $original)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, Product>  $products
     * @param  array<string, mixed>  $nlp
     * @return Collection<int, Product>
     */
    private function preferAgeMilkResults(Collection $products, array $nlp): Collection
    {
        if ($products->isEmpty()) {
            return $products;
        }

        if ($this->isProbioticMilkQuery($nlp)) {
            $probiotic = $products->filter(function (Product $product) {
                return $this->looksLikeProbioticMilk(Str::lower($product->name.' '.(string) $product->description));
            });
            if ($probiotic->isNotEmpty()) {
                return $probiotic->values();
            }
        }

        if ($this->queryHasAnyPhrase($nlp, config('voc.coconut_milk_query_phrases', []))) {
            $coconut = $products->filter(function (Product $product) {
                return $this->looksLikeCoconutMilk(Str::lower($product->name.' '.(string) $product->description));
            });
            if ($coconut->isNotEmpty()) {
                return $coconut->values();
            }
        }

        if ($this->queryHasAnyPhrase($nlp, config('voc.condensed_milk_query_phrases', []))) {
            $condensed = $products->filter(function (Product $product) {
                return $this->looksLikeCondensedMilk(Str::lower($product->name.' '.(string) $product->description));
            });
            if ($condensed->isNotEmpty()) {
                return $condensed->values();
            }
        }

        if ($this->queryHasAnyPhrase($nlp, config('voc.evaporated_milk_query_phrases', []))) {
            $evaporated = $products->filter(function (Product $product) {
                return $this->looksLikeEvaporatedMilk(Str::lower($product->name.' '.(string) $product->description));
            });
            if ($evaporated->isNotEmpty()) {
                return $evaporated->values();
            }
        }

        if ($this->isCookingMilkQuery($nlp)) {
            $cooking = $products->filter(function (Product $product) {
                $haystack = $this->cookingHaystack($product);

                return $this->looksLikeCookingMilk($haystack) || $this->looksLikeCookingUseMilk($haystack);
            });
            if ($cooking->isNotEmpty()) {
                $withCook = $cooking->filter(function (Product $product) {
                    return $this->looksLikeCookingUseMilk($this->cookingHaystack($product));
                });
                if ($withCook->isNotEmpty()) {
                    return $withCook->concat(
                        $cooking->reject(function (Product $product) {
                            return $this->looksLikeCookingUseMilk($this->cookingHaystack($product));
                        })
                    )->values();
                }

                return $cooking->values();
            }
        }

        if ($this->isInfantMilkQuery($nlp)) {
            $starter = $products->filter(function (Product $product) {
                return $this->looksLikeStarterInfant(Str::lower($product->name.' '.(string) $product->description));
            });
            if ($starter->isNotEmpty()) {
                return $starter->values();
            }

            $formula = $products->filter(function (Product $product) {
                return $this->looksLikeInfantFormula(Str::lower($product->name.' '.(string) $product->description));
            });
            if ($formula->isNotEmpty()) {
                return $formula->values();
            }

            return $products;
        }

        if ($this->isChildMilkQuery($nlp)) {
            $child = $products->filter(function (Product $product) {
                $haystack = Str::lower($product->name.' '.(string) $product->description);

                return $this->looksLikeStarterInfant($haystack)
                    || $this->looksLikeKidsMilk($haystack)
                    || $this->looksLikeInfantFormula($haystack);
            });
            if ($child->isNotEmpty()) {
                return $child->values();
            }

            return $products;
        }

        if ($this->isAdultMilkQuery($nlp)) {
            $adult = $products->filter(function (Product $product) {
                return $this->looksLikeAdultMilk(Str::lower($product->name.' '.(string) $product->description));
            });
            if ($adult->isNotEmpty()) {
                return $adult->concat(
                    $products->reject(function (Product $product) {
                        return $this->looksLikeAdultMilk(Str::lower($product->name.' '.(string) $product->description));
                    })
                )->values();
            }
        }

        return $products;
    }

    /**
     * @param  array<string, mixed>  $nlp
     * @param  array<int, string>  $exclusions
     */
    private function shouldDropCookingMilk(array $nlp, array $exclusions): bool
    {
        if (in_array('cooking_milk', $exclusions, true)) {
            return true;
        }

        $original = Str::lower((string) ($nlp['original_text'] ?? ''));
        foreach (config('voc.cooking_milk_query_phrases', []) as $phrase) {
            $phrase = Str::lower(trim((string) $phrase));
            if ($phrase !== '' && str_contains($original, $phrase)) {
                return false;
            }
        }

        foreach (config('voc.carton_milk_query_phrases', []) as $phrase) {
            $phrase = Str::lower(trim((string) $phrase));
            if ($phrase !== '' && str_contains($original, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeNonBeverageMilk(string $haystack): bool
    {
        foreach (config('voc.non_beverage_milk_terms', []) as $term) {
            $term = Str::lower(trim((string) $term));
            if ($term !== '' && str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeProbioticMilk(string $haystack): bool
    {
        foreach (config('voc.probiotic_milk_terms', []) as $term) {
            $term = Str::lower(trim((string) $term));
            if ($term !== '' && str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeCondensedMilk(string $haystack): bool
    {
        foreach (config('voc.condensed_milk_terms', []) as $term) {
            $term = Str::lower(trim((string) $term));
            if ($term !== '' && str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeEvaporatedMilk(string $haystack): bool
    {
        foreach (config('voc.evaporated_milk_terms', []) as $term) {
            $term = Str::lower(trim((string) $term));
            if ($term !== '' && str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeCoconutMilk(string $haystack): bool
    {
        foreach (config('voc.coconut_milk_terms', []) as $term) {
            if ($this->haystackHasTerm($haystack, (string) $term)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeCookingMilk(string $haystack): bool
    {
        foreach (config('voc.cooking_milk_terms', []) as $term) {
            if ($this->haystackHasTerm($haystack, (string) $term)) {
                return true;
            }
        }

        return false;
    }

    private function cookingHaystack(Product $product): string
    {
        return Str::lower($product->name.' '.(string) $product->description.' '.(string) $product->category?->name);
    }

    private function looksLikeCookingUseMilk(string $haystack): bool
    {
        $hasUseTerm = false;
        foreach (config('voc.cooking_milk_rank_aliases', []) as $term) {
            if ($this->haystackHasTerm($haystack, (string) $term)) {
                $hasUseTerm = true;
                break;
            }
        }

        if (! $hasUseTerm) {
            return false;
        }

        return $this->looksLikeCookingMilk($haystack)
            || $this->haystackHasTerm($haystack, 'milk')
            || $this->haystackHasTerm($haystack, 'gatas');
    }

    private function haystackHasTerm(string $haystack, string $term): bool
    {
        $term = Str::lower(trim($term));
        if ($term === '') {
            return false;
        }

        return (bool) preg_match('/(?:^|[^\p{L}\p{N}])'.preg_quote($term, '/').'(?:[^\p{L}\p{N}]|$)/u', $haystack);
    }

    private function looksLikeSweetenedMilk(string $haystack): bool
    {
        foreach (['sweetened', 'condensada', 'kremdensada', 'crema-asada', 'chocolate', 'choco', 'sugar'] as $term) {
            if (str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeNoSugarMilk(string $haystack): bool
    {
        return str_contains($haystack, 'no sugar')
            || str_contains($haystack, 'no added sugar')
            || str_contains($haystack, 'unsweetened')
            || str_contains($haystack, 'diabetasol');
    }

    /**
     * @param  array<string, mixed>  $nlp
     */
    private function exceedsPriceCap(Product $product, array $nlp): bool
    {
        $original = Str::lower((string) ($nlp['original_text'] ?? ''));
        if (! preg_match('/\b(?:dili|hindi|not)\s+molapas\s+(\d+(?:\.\d+)?)/u', $original, $match)) {
            return false;
        }

        return (float) $product->price > (float) $match[1];
    }

    /**
     * @param  array<string, mixed>  $nlp
     */
    private function missesCanonicalProduct(Product $product, array $nlp): bool
    {
        $canonical = Str::lower(trim((string) ($nlp['product'] ?? '')));

        if ($canonical === '') {
            return false;
        }

        $haystack = Str::lower($product->name.' '.(string) $product->category?->name);
        $blob = $haystack.' '.Str::lower((string) $product->description);
        $terms = $this->englishMatchTerms($canonical);

        foreach (array_unique($terms) as $term) {
            $term = Str::lower(trim((string) $term));
            if ($term === '') {
                continue;
            }
            if (str_contains($haystack, $term)) {
                return false;
            }
            if (in_array($canonical, ['milk', 'low fat milk', 'low fat', 'chocolate'], true) && str_contains($blob, $term)) {
                return false;
            }
        }

        if ($canonical === 'salad' && $this->looksLikeSaladDessert($product)) {
            return false;
        }

        if (in_array($canonical, ['milk', 'low fat milk', 'low fat'], true)
            && ($this->looksLikeInfantFormula($blob)
                || $this->looksLikeKidsMilk($blob)
                || $this->looksLikeAdultMilk($blob)
                || $this->looksLikeCookingMilk($blob))) {
            return false;
        }

        return true;
    }

    private function looksLikeSaladDessert(Product $product): bool
    {
        $blob = Str::lower($product->name.' '.(string) $product->description.' '.(string) $product->category?->name);

        foreach (config('voc.salad_exclude_terms', []) as $term) {
            $term = Str::lower(trim((string) $term));
            if ($term !== '' && str_contains($blob, $term)) {
                return false;
            }
        }

        $hasDessert = false;
        foreach (config('voc.salad_dessert_terms', []) as $term) {
            $term = Str::lower(trim((string) $term));
            if ($term !== '' && str_contains($blob, $term)) {
                $hasDessert = true;
                break;
            }
        }

        if (! $hasDessert) {
            return false;
        }

        foreach (config('voc.salad_cream_terms', []) as $term) {
            $term = Str::lower(trim((string) $term));
            if ($term !== '' && str_contains($blob, $term)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeCowDairy(string $haystack): bool
    {
        $isMilk = str_contains($haystack, 'milk')
            || str_contains($haystack, 'gatas')
            || str_contains($haystack, 'dairy');

        if (! $isMilk) {
            return false;
        }

        if ($this->looksLikePlantMilk($haystack)) {
            return false;
        }

        foreach (config('voc.cow_dairy_terms', []) as $term) {
            if (str_contains($haystack, Str::lower((string) $term))) {
                return true;
            }
        }

        return true;
    }

    private function looksLikePlantMilk(string $haystack): bool
    {
        foreach (config('voc.plant_milk_terms', []) as $term) {
            if (str_contains($haystack, Str::lower((string) $term))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $nlp
     * @return array<int, string>
     */
    private function dietaryBoostTerms(array $nlp): array
    {
        $terms = [];

        foreach ((array) ($nlp['dietary'] ?? []) as $diet) {
            $diet = Str::lower(trim((string) $diet));
            if ($diet !== '') {
                $terms[] = $diet;
            }
        }

        if (array_intersect($terms, ['vegan', 'plant-based', 'non-cow', 'non-dairy']) !== []) {
            $terms = array_merge($terms, array_map('strval', config('voc.plant_milk_terms', [])));
        }

        if (in_array('infant', $terms, true)) {
            $terms = array_merge($terms, array_map('strval', config('voc.starter_infant_terms', [])));
        }

        if (in_array('kids', $terms, true)) {
            $terms = array_merge(
                $terms,
                array_map('strval', config('voc.starter_infant_terms', [])),
                array_map('strval', config('voc.kids_milk_terms', [])),
            );
        }

        if (in_array('adult', $terms, true)) {
            $terms = array_merge($terms, array_map('strval', config('voc.adult_milk_terms', [])));
        }

        return array_values(array_unique($terms));
    }

    /**
     * @param  array<string, mixed>|null  $nlp
     * @return array<int, string>
     */
    private function termsFromNlp(?array $nlp, string $text): array
    {
        if ($nlp !== null) {
            $seeds = array_merge(
                array_map('strval', $nlp['keywords'] ?? []),
                $this->tokenize((string) ($nlp['product'] ?? '')),
                $this->englishMatchTerms((string) ($nlp['product'] ?? '')),
                $this->tokenize((string) ($nlp['brand'] ?? '')),
                array_map('strval', $nlp['dietary'] ?? []),
                $this->dietaryBoostTerms($nlp),
            );

            if ($seeds === [] || implode('', $seeds) === '') {
                $seeds[] = $text;
            }

            if ($this->queryHasAnyPhrase($nlp, config('voc.cooking_use_query_phrases', []))) {
                $seeds = array_merge(
                    $seeds,
                    array_map('strval', config('voc.cooking_milk_terms', [])),
                    array_map('strval', config('voc.cooking_milk_rank_aliases', [])),
                );
            }

            if ($this->queryHasAnyPhrase($nlp, config('voc.coconut_milk_query_phrases', []))) {
                $seeds = array_merge(
                    $seeds,
                    array_map('strval', config('voc.coconut_milk_terms', [])),
                );
            }

            $cacheKey = md5(serialize([$seeds, $nlp['original_text'] ?? null, $text]));
            if (isset($this->termsFromNlpCache[$cacheKey])) {
                return $this->termsFromNlpCache[$cacheKey];
            }

            return $this->termsFromNlpCache[$cacheKey] = $this->filterSearchTerms($this->expandTerms($seeds));
        }

        $cacheKey = md5('text:'.$text);
        if (isset($this->termsFromNlpCache[$cacheKey])) {
            return $this->termsFromNlpCache[$cacheKey];
        }

        return $this->termsFromNlpCache[$cacheKey] = $this->filterSearchTerms($this->expandTerms([$text]));
    }

    /**
     * @param  array<string, mixed>|null  $nlp
     * @return array<int, string>
     */
    private function unitsFromNlp(?array $nlp, string $text): array
    {
        $units = $nlp !== null ? array_map('strval', $nlp['units'] ?? []) : [];

        return $this->normalizeUnits(array_merge($units, $this->normalizeUnitsFromText($text)));
    }

    /**
     * @param  array<int, string>  $terms
     * @return array<int, string>
     */
    private function filterSearchTerms(array $terms): array
    {
        $stopwords = config('voc.stopwords', []);
        $price = array_merge(config('voc.price_cheap', []), config('voc.price_premium', []));
        $priceTokens = array_map(fn ($item) => Str::lower((string) $item), $price);

        return array_values(array_filter($terms, function (string $term) use ($stopwords, $priceTokens) {
            $term = Str::lower(trim($term));

            if ($term === '' || strlen($term) < 2 || is_numeric($term)) {
                return false;
            }

            if (in_array($term, $stopwords, true) || in_array($term, $priceTokens, true)) {
                return false;
            }

            return ! in_array($term, ['ml', 'kg', 'pcs', 'cow', 'baka', 'liter', 'litro', 'liters', 'litres', 'kilo'], true);
        }));
    }

    private function productMatchesUnit(string $productName, string $unit): bool
    {
        $unit = Str::lower(trim($unit));
        $patterns = $this->unitPatterns($unit);

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $productName)) {
                return true;
            }
        }

        return str_contains($productName, $unit);
    }

    /**
     * @return array<int, string>
     */
    private function unitPatterns(string $unit): array
    {
        if (preg_match('/^(\d+(?:\.\d+)?)\s*l$/i', $unit, $m)) {
            $n = $m[1];

            return [
                '/\b'.preg_quote($n, '/').'\s*l\b/i',
                '/\b'.preg_quote($n, '/').'\s*liter/i',
                '/\b'.preg_quote($n, '/').'\s*litro/i',
                '/\b'.preg_quote((string) ((int) ((float) $n * 1000)), '/').'\s*ml\b/i',
            ];
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*kg$/i', $unit, $m)) {
            $n = $m[1];

            return [
                '/\b'.preg_quote($n, '/').'\s*kg\b/i',
                '/\b'.preg_quote($n, '/').'\s*kilo/i',
            ];
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*ml$/i', $unit, $m)) {
            $n = (int) ((float) $m[1]);

            return [
                '/\b'.preg_quote((string) $n, '/').'\s*ml\b/i',
                '/\b'.preg_quote((string) ($n / 1000), '/').'\s*l\b/i',
            ];
        }

        return ['/'.preg_quote($unit, '/').'/i'];
    }

    /**
     * @return array<int, string>
     */
    private function englishMatchTerms(string $canonical): array
    {
        $canonical = Str::lower(trim($canonical));
        $terms = array_merge(
            [$canonical],
            array_map('strval', config('voice-assistant.synonyms.'.$canonical, [])),
            array_map('strval', config('voc.catalog_aliases.'.$canonical, [])),
        );

        return array_values(array_unique(array_filter(array_map(
            fn ($term) => Str::lower(trim((string) $term)),
            $terms,
        ))));
    }

    /**
     * @param  array<int, string>  $inputs
     * @return array<int, string>
     */
    public function expandTerms(array $inputs): array
    {
        $cacheKey = md5(serialize($inputs));
        if (isset($this->expandTermsCache[$cacheKey])) {
            return $this->expandTermsCache[$cacheKey];
        }

        $terms = [];
        $map = $this->canonicalProductMap();

        foreach ($inputs as $input) {
            $text = Str::lower(trim((string) $input));
            if ($text === '') {
                continue;
            }

            [$matched, $leftover] = $this->matchCanonicalPhrases($text, $map, true);

            foreach ($matched as $phrase) {
                $english = $map[$phrase];
                $terms[] = $english;

                if ($this->keepSourceTerm($phrase, $english)) {
                    $terms[] = $phrase;
                }

                foreach ($this->synonymsFor($english) as $synonym) {
                    $terms[] = $synonym;
                }
            }

            foreach ($leftover as $token) {
                $terms[] = $token;

                foreach ($this->synonymsFor($token) as $synonym) {
                    $terms[] = $synonym;
                }
            }
        }

        return $this->expandTermsCache[$cacheKey] = array_values(array_unique(array_filter(
            $terms,
            fn ($term) => is_string($term) && strlen($term) >= 2,
        )));
    }

    private function keepSourceTerm(string $phrase, string $english): bool
    {
        if ($phrase === $english) {
            return true;
        }

        $aliases = array_map(
            fn ($alias) => Str::lower((string) $alias),
            config('voc.catalog_aliases.'.$english, []),
        );

        return in_array($phrase, $aliases, true);
    }

    /**
     * @return array<int, string>
     */
    private function synonymsFor(string $term): array
    {
        $key = Str::lower($term);
        if (isset($this->synonymCache[$key])) {
            return $this->synonymCache[$key];
        }

        return $this->synonymCache[$key] = array_values(array_unique(array_merge(
            array_map('strval', config('voice-assistant.synonyms.'.$key, [])),
            array_map('strval', config('voc.catalog_aliases.'.$key, [])),
        )));
    }

    /**
     * @return array<string, string>
     */
    private function canonicalProductMap(): array
    {
        if ($this->canonicalProducts !== null) {
            return $this->canonicalProducts;
        }

        $this->canonicalProducts = [];
        foreach (config('voc.canonical_products', []) as $phrase => $english) {
            $phrase = Str::lower(trim((string) $phrase));
            $english = Str::lower(trim((string) $english));
            if ($phrase === '' || $english === '') {
                continue;
            }
            $this->canonicalProducts[$phrase] = $english;
        }

        return $this->canonicalProducts;
    }

    /**
     * @param  array<string, string>  $map
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function matchCanonicalPhrases(string $text, array $map, bool $consume = true): array
    {
        $tokens = $this->tokenize($text);
        $n = count($tokens);
        if ($n === 0) {
            return [[], []];
        }

        $used = array_fill(0, $n, false);
        $matched = [];

        for ($len = $n; $len >= 1; $len--) {
            for ($i = 0; $i + $len <= $n; $i++) {
                if ($consume) {
                    $spanUsed = false;
                    for ($k = $i; $k < $i + $len; $k++) {
                        if ($used[$k]) {
                            $spanUsed = true;
                            break;
                        }
                    }
                    if ($spanUsed) {
                        continue;
                    }
                }

                $slice = array_slice($tokens, $i, $len);
                $forms = [implode(' ', $slice)];
                if ($len > 1) {
                    $forms[] = implode('-', $slice);
                }

                foreach ($forms as $phrase) {
                    if (! isset($map[$phrase])) {
                        continue;
                    }
                    $matched[] = $phrase;
                    if ($consume) {
                        for ($k = $i; $k < $i + $len; $k++) {
                            $used[$k] = true;
                        }
                    }
                    break;
                }
            }
        }

        $leftover = [];
        for ($i = 0; $i < $n; $i++) {
            if (! $used[$i]) {
                $leftover[] = $tokens[$i];
            }
        }

        return [$matched, $leftover];
    }

    public function dialectCatalogQuery(string $text): string
    {
        $map = $this->canonicalProductMap();
        $lower = Str::lower($text);
        $extra = [];
        [$phrases] = $this->matchCanonicalPhrases($lower, $map, false);
        $broad = $this->isBroadProductQuery($lower);

        foreach ($phrases as $phrase) {
            $english = $map[$phrase] ?? '';
            if ($english === '') {
                continue;
            }
            if ($english !== $phrase && ! str_contains($lower, $english)) {
                $extra[$english] = $english;
            }
            if (! $broad) {
                continue;
            }
            foreach ($this->englishMatchTerms($english) as $alias) {
                if ($alias === $phrase || $alias === $english || str_contains($lower, $alias)) {
                    continue;
                }
                $extra[$alias] = $alias;
            }
        }

        $hasMilkAnchor = (bool) preg_match('/(?:^|[^\p{L}\p{N}])(gatas|gaas|milk|infant|baby|newborn|pangbaby|pangbata|bata|tigulang|edaran|adulto|anlene|ensure)(?:[^\p{L}\p{N}]|$)/u', $lower);
        if ($hasMilkAnchor) {
            foreach (array_map('strval', config('voc.infant_milk_query_phrases', [])) as $phrase) {
                $phrase = Str::lower(trim($phrase));
                if ($phrase !== '' && preg_match('/(?:^|[^\p{L}\p{N}])'.preg_quote($phrase, '/').'(?:[^\p{L}\p{N}]|$)/u', $lower)) {
                    foreach (['infant formula', '0-6 months', 'baby milk', 'starter formula'] as $alias) {
                        if (! str_contains($lower, $alias)) {
                            $extra[$alias] = $alias;
                        }
                    }
                    break;
                }
            }

            foreach (array_map('strval', config('voc.child_milk_query_phrases', [])) as $phrase) {
                $phrase = Str::lower(trim($phrase));
                if ($phrase !== '' && preg_match('/(?:^|[^\p{L}\p{N}])'.preg_quote($phrase, '/').'(?:[^\p{L}\p{N}]|$)/u', $lower)) {
                    foreach (['infant formula', '0-6 months', 'growing-up milk', '1-3 years', 'baby milk'] as $alias) {
                        if (! str_contains($lower, $alias)) {
                            $extra[$alias] = $alias;
                        }
                    }
                    break;
                }
            }

            foreach (array_map('strval', config('voc.adult_milk_query_phrases', [])) as $phrase) {
                $phrase = Str::lower(trim($phrase));
                if ($phrase !== '' && preg_match('/(?:^|[^\p{L}\p{N}])'.preg_quote($phrase, '/').'(?:[^\p{L}\p{N}]|$)/u', $lower)) {
                    foreach (['adult milk', 'anlene', 'ensure', 'glucerna', 'for adults'] as $alias) {
                        if (! str_contains($lower, $alias)) {
                            $extra[$alias] = $alias;
                        }
                    }
                    break;
                }
            }
        }

        foreach (array_map('strval', config('voc.cooking_use_query_phrases', [])) as $phrase) {
            $phrase = Str::lower(trim($phrase));
            if ($phrase !== '' && preg_match('/(?:^|[^\p{L}\p{N}])'.preg_quote($phrase, '/').'(?:[^\p{L}\p{N}]|$)/u', $lower)) {
                foreach (array_map('strval', config('voc.cooking_milk_rank_aliases', [])) as $alias) {
                    $alias = Str::lower(trim($alias));
                    if ($alias !== '' && ! str_contains($lower, $alias)) {
                        $extra[$alias] = $alias;
                    }
                }
                break;
            }
        }

        if ($extra === []) {
            return $text;
        }

        return trim($text.' '.implode(' ', array_values($extra)));
    }

    private function isBroadProductQuery(string $text): bool
    {
        $map = $this->canonicalProductMap();
        $normalized = Str::lower(trim($text));
        if (isset($map[$normalized]) || in_array($normalized, $map, true)) {
            return true;
        }

        [$phrases, $leftover] = $this->matchCanonicalPhrases($normalized, $map, true);
        if ($phrases === []) {
            return false;
        }

        $stop = [];
        foreach (config('voc.stopwords', []) as $word) {
            $stop[Str::lower((string) $word)] = true;
        }
        foreach ($leftover as $token) {
            if (! isset($stop[Str::lower((string) $token)])) {
                return false;
            }
        }

        return true;
    }

    public function tokenize(string $text): array
    {
        $text = Str::lower($text);
        preg_match_all('/[\p{L}\p{N}]+/u', $text, $matches);

        return $matches[0] ?? [];
    }

    /**
     * @param  array<int, string>  $units
     * @return array<int, string>
     */
    public function normalizeUnits(array $units): array
    {
        $normalized = [];

        foreach ($units as $unit) {
            $normalized = array_merge($normalized, $this->normalizeUnitsFromText((string) $unit));
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return array<int, string>
     */
    public function normalizeUnitsFromText(string $text): array
    {
        $text = Str::lower($text);
        $units = [];

        if (preg_match_all('/(\d+(?:\.\d+)?)\s*(l|liter|liters|litro|litres|ml|kg|kilo|kilos|kilogram|g|gram|grams|oz|ounce|ounces|pcs|pc|piece|pieces)\b/i', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $amount = (float) $match[1];
                $rawUnit = Str::lower($match[2]);
                $canonical = config('voice-assistant.unit_aliases.'.$rawUnit, $rawUnit);

                if ($canonical === 'ml' && $amount >= 1000) {
                    $units[] = $this->formatAmount($amount / 1000).'L';
                    $units[] = $this->formatAmount($amount).'ml';
                } elseif ($canonical === 'l') {
                    $units[] = $this->formatAmount($amount).'L';
                    $units[] = $this->formatAmount($amount * 1000).'ml';
                } elseif ($canonical === 'kg') {
                    $units[] = $this->formatAmount($amount).'kg';
                } else {
                    $units[] = $this->formatAmount($amount).$canonical;
                }
            }
        }

        return array_values(array_unique($units));
    }

    private function formatAmount(float $amount): string
    {
        if (abs($amount - round($amount)) < 0.0001) {
            return (string) (int) round($amount);
        }

        return rtrim(rtrim(sprintf('%.4F', $amount), '0'), '.');
    }
}
