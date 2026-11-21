<?php

namespace App\Services\Voc;

use App\Enums\VocAttributeType;
use App\Enums\VocPolarity;
use App\Services\VoiceAssistant\ProductSearchService;
use Illuminate\Support\Str;

class QueryAttributeExtractor
{
    public function __construct(
        private readonly ProductSearchService $productSearchService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function extract(string $text): array
    {
        $original = trim($text);
        $lower = Str::lower($original);

        $priceIntent = $this->detectPriceIntent($lower);
        $brand = $this->detectBrand($lower);
        $dietary = $this->detectDietary($lower);
        $exclusions = $this->detectExclusions($lower);
        $units = $this->productSearchService->normalizeUnitsFromText($original);
        $product = $this->detectProduct($lower);

        if ($this->impliesNonCowMilk($dietary, $exclusions, $product)) {
            $exclusions[] = 'cow';
            if (! in_array('non-cow', $dietary, true) && $product === 'milk') {
                $dietary[] = 'non-cow';
            }
        }

        if ($this->impliesInfantMilkQuery($lower)) {
            $product = $this->canonicalizeProduct($product) ?? 'milk';
            $dietary[] = 'infant';
            $exclusions[] = 'adult_milk';
            $exclusions = array_values(array_filter(
                $exclusions,
                fn (string $exclusion) => $exclusion !== 'infant_formula',
            ));
        } elseif ($this->impliesChildMilkQuery($lower)) {
            $product = $this->canonicalizeProduct($product) ?? 'milk';
            $dietary[] = 'kids';
            $exclusions[] = 'adult_milk';
            $exclusions = array_values(array_filter(
                $exclusions,
                fn (string $exclusion) => ! in_array($exclusion, ['infant_formula', 'kids_milk'], true),
            ));
        } elseif ($this->impliesAdultMilkQuery($lower)) {
            $product = $this->canonicalizeProduct($product) ?? 'milk';
            $dietary[] = 'adult';
            $exclusions[] = 'infant_formula';
            $exclusions[] = 'kids_milk';
        }

        if ($this->impliesDrinkingMilkNotFormula($lower, $product)) {
            $exclusions[] = 'infant_formula';
        }

        if ($this->impliesDrinkingMilkNotCooking($lower, $product)) {
            $exclusions[] = 'cooking_milk';
        }

        if (in_array('vegan', $dietary, true) && $product === 'milk' && ! in_array('plant-based', $dietary, true)) {
            $dietary[] = 'plant-based';
        }

        $dietary = array_values(array_unique($dietary));
        $exclusions = array_values(array_unique($exclusions));

        $keywords = $this->alignKeywordsToProduct(
            $this->buildKeywords($original, $product, $brand, $dietary, $exclusions),
            $product,
            $original,
        );

        return $this->toNlpArray(
            originalText: $original,
            language: $this->detectLanguage($lower),
            keywords: $keywords,
            units: $units,
            product: $product,
            brand: $brand,
            dietary: $dietary,
            priceIntent: $priceIntent,
            exclusions: $exclusions,
        );
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    public function mergeParsed(array $parsed, string $text): array
    {
        $fallback = $this->extract($text);

        $keywords = array_values(array_filter(array_map('strval', $parsed['keywords'] ?? [])));
        if ($keywords === []) {
            $keywords = $fallback['keywords'];
        } else {
            $keywords = $this->productSearchService->expandTerms($keywords);
            $keywords = $this->filterNoise($keywords);
        }

        $units = $this->productSearchService->normalizeUnits(array_merge(
            array_map('strval', $parsed['units'] ?? []),
            $fallback['units'],
        ));

        $dietary = $this->stringList($parsed['dietary'] ?? null);
        if ($dietary === []) {
            $dietary = $fallback['dietary'];
        }

        $exclusions = $this->stringList($parsed['exclusions'] ?? null);
        if ($exclusions === []) {
            $exclusions = $fallback['exclusions'];
        }

        $product = $this->canonicalizeProduct($this->nullableString($parsed['product'] ?? null) ?? $fallback['product']);
        $brand = $this->nullableString($parsed['brand'] ?? null) ?? $fallback['brand'];
        $priceIntent = $this->nullableString($parsed['price_intent'] ?? null) ?? $fallback['price_intent'];
        $lower = Str::lower($text);

        if ($this->impliesInfantMilkQuery($lower)) {
            $product = $this->canonicalizeProduct($product) ?? 'milk';
            if (! in_array('infant', $dietary, true)) {
                $dietary[] = 'infant';
            }
            $exclusions[] = 'adult_milk';
            $exclusions = array_values(array_filter(
                $exclusions,
                fn (string $exclusion) => $exclusion !== 'infant_formula',
            ));
        } elseif ($this->impliesChildMilkQuery($lower)) {
            $product = $this->canonicalizeProduct($product) ?? 'milk';
            if (! in_array('kids', $dietary, true)) {
                $dietary[] = 'kids';
            }
            $exclusions[] = 'adult_milk';
            $exclusions = array_values(array_filter(
                $exclusions,
                fn (string $exclusion) => ! in_array($exclusion, ['infant_formula', 'kids_milk'], true),
            ));
        } elseif ($this->impliesAdultMilkQuery($lower)) {
            $product = $this->canonicalizeProduct($product) ?? 'milk';
            if (! in_array('adult', $dietary, true)) {
                $dietary[] = 'adult';
            }
            $exclusions[] = 'infant_formula';
            $exclusions[] = 'kids_milk';
        } elseif ($this->impliesDrinkingMilkNotFormula($lower, $product)) {
            $exclusions[] = 'infant_formula';
        }

        if ($this->impliesDrinkingMilkNotCooking($lower, $product)) {
            $exclusions[] = 'cooking_milk';
        }

        if (in_array($priceIntent, ['cheap', 'premium'], true) === false) {
            $priceIntent = $fallback['price_intent'];
        }

        $keywords = $this->alignKeywordsToProduct($keywords, $product, $text);

        return $this->toNlpArray(
            originalText: (string) ($parsed['original_text'] ?? $text),
            language: (string) ($parsed['language'] ?? $fallback['language']),
            keywords: $keywords,
            units: $units,
            product: $product,
            brand: $brand,
            dietary: array_values(array_unique($dietary)),
            priceIntent: $priceIntent,
            exclusions: array_values(array_unique($exclusions)),
            intent: (string) ($parsed['intent'] ?? 'product_search'),
            quantity: max(1, (int) ($parsed['quantity'] ?? 1)),
            checkout: is_array($parsed['checkout'] ?? null) ? $parsed['checkout'] : null,
        );
    }

    /**
     * @param  array<int, string>  $keywords
     * @param  array<int, string>  $units
     * @param  array<int, string>  $dietary
     * @param  array<int, string>  $exclusions
     * @param  array<string, mixed>|null  $checkout
     * @return array<string, mixed>
     */
    public function toNlpArray(
        string $originalText,
        string $language,
        array $keywords,
        array $units,
        ?string $product,
        ?string $brand,
        array $dietary,
        ?string $priceIntent,
        array $exclusions,
        string $intent = 'product_search',
        int $quantity = 1,
        ?array $checkout = null,
    ): array {
        $nlp = [
            'original_text' => $originalText,
            'language' => $language,
            'keywords' => array_values(array_unique($keywords)),
            'units' => $units,
            'intent' => $intent,
            'quantity' => $quantity,
            'checkout' => $checkout,
            'product' => $product,
            'brand' => $brand,
            'dietary' => $dietary,
            'price_intent' => $priceIntent,
            'exclusions' => $exclusions,
        ];

        $nlp['attributes'] = $this->attributesFrom($nlp);

        return $nlp;
    }

    /**
     * @param  array<string, mixed>  $nlp
     * @return array<int, array{type: string, value: string, polarity: string}>
     */
    public function attributesFrom(array $nlp): array
    {
        $attributes = [];

        if (filled($nlp['product'] ?? null)) {
            $attributes[] = $this->attribute(VocAttributeType::Product, (string) $nlp['product'], VocPolarity::Include);
        }

        if (filled($nlp['brand'] ?? null)) {
            $attributes[] = $this->attribute(VocAttributeType::Brand, Str::lower((string) $nlp['brand']), VocPolarity::Include);
        }

        foreach ((array) ($nlp['units'] ?? []) as $unit) {
            $unit = trim((string) $unit);
            if ($unit !== '') {
                $attributes[] = $this->attribute(VocAttributeType::Unit, $unit, VocPolarity::Include);
            }
        }

        foreach ((array) ($nlp['dietary'] ?? []) as $diet) {
            $diet = Str::lower(trim((string) $diet));
            if ($diet !== '') {
                $attributes[] = $this->attribute(VocAttributeType::Dietary, $diet, VocPolarity::Include);
            }
        }

        if (filled($nlp['price_intent'] ?? null)) {
            $attributes[] = $this->attribute(VocAttributeType::PriceIntent, (string) $nlp['price_intent'], VocPolarity::Include);
        }

        foreach ((array) ($nlp['exclusions'] ?? []) as $exclusion) {
            $exclusion = Str::lower(trim((string) $exclusion));
            if ($exclusion !== '') {
                $attributes[] = $this->attribute(VocAttributeType::Exclusion, $exclusion, VocPolarity::Exclude);
            }
        }

        return $attributes;
    }

    /**
     * @return array{type: string, value: string, polarity: string}
     */
    private function attribute(VocAttributeType $type, string $value, VocPolarity $polarity): array
    {
        return [
            'type' => $type->value,
            'value' => $value,
            'polarity' => $polarity->value,
        ];
    }

    private function detectPriceIntent(string $lower): ?string
    {
        foreach (config('voc.price_cheap', []) as $term) {
            if ($this->containsPhrase($lower, (string) $term)) {
                return 'cheap';
            }
        }

        foreach (config('voc.price_premium', []) as $term) {
            if ($this->containsPhrase($lower, (string) $term)) {
                return 'premium';
            }
        }

        return null;
    }

    private function detectBrand(string $lower): ?string
    {
        $brands = config('voc.brands', []);
        usort($brands, fn (string $a, string $b) => strlen($b) <=> strlen($a));
        $folded = Str::ascii($lower);

        foreach ($brands as $brand) {
            if ($this->containsPhrase($lower, $brand) || $this->containsPhrase($folded, Str::ascii($brand))) {
                return Str::ascii($brand);
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function detectDietary(string $lower): array
    {
        $dietary = [];

        foreach (config('voc.dietary', []) as $canonical => $phrases) {
            foreach ($phrases as $phrase) {
                if ($this->containsPhrase($lower, (string) $phrase)) {
                    $dietary[] = (string) $canonical;
                    break;
                }
            }
        }

        return $dietary;
    }

    /**
     * @return array<int, string>
     */
    private function detectExclusions(string $lower): array
    {
        $exclusions = [];
        $aliases = config('voc.exclusion_aliases', []);
        $skip = array_map('strval', config('voc.exclusion_skip_tokens', []));
        $phrases = config('voc.negation_phrases', []);
        usort($phrases, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        $negation = '(?:dili|hindi|not|walay|wala|way|pero dili)';
        $hedge = '(?:kaayoy|kaayo|ra|lang\s+)?';

        foreach ($phrases as $phrase) {
            $phrase = Str::lower((string) $phrase);
            if ($phrase === '') {
                continue;
            }
            if (preg_match('/\b'.$negation.'\s+'.$hedge.'(?:sa|from|nga|ng|pang)?\s*(?:a|the)?\s*'.preg_quote($phrase, '/').'\b/u', $lower)) {
                if ($phrase !== 'lactose') {
                    $exclusions[] = $aliases[$phrase] ?? $phrase;
                }
            }
        }

        if (preg_match_all('/\b'.$negation.'\s+'.$hedge.'(?:sa|from|nga|ng)?\s*(?:a|the)?\s*(\p{L}+)/u', $lower, $matches)) {
            foreach ($matches[1] as $token) {
                $token = Str::lower((string) $token);
                if ($this->isNoiseToken($token) || in_array($token, $skip, true) || $token === 'lactose') {
                    continue;
                }

                $exclusions[] = $aliases[$token] ?? $token;
            }
        }

        return array_values(array_unique($exclusions));
    }

    private function detectProduct(string $lower): ?string
    {
        if ($this->containsPhrase($lower, 'gatas') && $this->containsPhrase($lower, 'sabon')) {
            return 'soap';
        }

        $canonicals = config('voc.canonical_products', []);
        $phrases = array_keys($canonicals);
        usort($phrases, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        $hit = null;
        foreach ($phrases as $phrase) {
            if ($this->containsPhrase($lower, $phrase)) {
                $hit = $this->canonicalizeProduct((string) $canonicals[$phrase]);
                break;
            }
        }

        $milkLike = ['milk', 'low fat milk', 'low fat', 'condensed milk', 'evaporated milk', 'creamer', 'soap', 'coconut milk', 'chocolate'];
        if ($this->containsPhrase($lower, 'gatas') && $hit !== null && ! in_array($hit, $milkLike, true)) {
            return 'milk';
        }

        return $hit;
    }

    private function canonicalizeProduct(?string $product): ?string
    {
        if ($product === null) {
            return null;
        }

        $product = Str::lower(trim($product));

        if ($product === '') {
            return null;
        }

        $mapped = config('voc.canonical_products.'.$product);

        return is_string($mapped) && $mapped !== '' ? Str::lower($mapped) : $product;
    }

    /**
     * @param  array<int, string>  $keywords
     * @return array<int, string>
     */
    private function alignKeywordsToProduct(array $keywords, ?string $product, string $original): array
    {
        if ($product === null || $product === '') {
            return $keywords;
        }

        $keywords[] = $product;
        $original = Str::lower($original);
        $blocked = [];

        foreach (config('voc.canonical_products', []) as $phrase => $english) {
            $english = Str::lower((string) $english);
            $phrase = Str::lower((string) $phrase);

            if ($english === $product) {
                $aliases = array_map('strval', config('voc.catalog_aliases.'.$product, []));
                if ($phrase !== $english && ! in_array($phrase, $aliases, true)) {
                    $blocked[] = $phrase;
                }

                continue;
            }

            if (! $this->containsPhrase($original, $phrase) && ! $this->containsPhrase($original, $english)) {
                $blocked[] = $phrase;
                $blocked[] = $english;
            }
        }

        $blocked = array_map(fn (string $term) => Str::lower($term), $blocked);

        return array_values(array_unique(array_filter(
            $keywords,
            fn (string $term) => ! in_array(Str::lower($term), $blocked, true),
        )));
    }

    /**
     * @param  array<int, string>  $dietary
     * @param  array<int, string>  $exclusions
     */
    private function impliesNonCowMilk(array $dietary, array $exclusions, ?string $product): bool
    {
        $nonCowDiet = array_intersect($dietary, ['vegan', 'plant-based', 'non-cow', 'non-dairy']);

        return $product === 'milk' && ($nonCowDiet !== [] || in_array('cow', $exclusions, true));
    }

    private function impliesInfantMilkQuery(string $lower): bool
    {
        $matched = false;
        foreach (config('voc.infant_milk_query_phrases', []) as $phrase) {
            if ($this->containsPhrase($lower, (string) $phrase)) {
                $matched = true;
                break;
            }
        }

        if (! $matched) {
            return false;
        }

        foreach (['gatas', 'gaas', 'milk', 'infant', 'baby', 'newborn', 'pangbaby'] as $anchor) {
            if ($this->containsPhrase($lower, $anchor)) {
                return true;
            }
        }

        return false;
    }

    private function impliesChildMilkQuery(string $lower): bool
    {
        if ($this->impliesInfantMilkQuery($lower)) {
            return false;
        }

        $matched = false;
        foreach (config('voc.child_milk_query_phrases', []) as $phrase) {
            if ($this->containsPhrase($lower, (string) $phrase)) {
                $matched = true;
                break;
            }
        }

        if (! $matched) {
            return false;
        }

        foreach (['gatas', 'gaas', 'milk', 'bata', 'pangbata', 'kids'] as $anchor) {
            if ($this->containsPhrase($lower, $anchor)) {
                return true;
            }
        }

        return false;
    }

    private function impliesAdultMilkQuery(string $lower): bool
    {
        $matched = false;
        foreach (config('voc.adult_milk_query_phrases', []) as $phrase) {
            if ($this->containsPhrase($lower, (string) $phrase)) {
                $matched = true;
                break;
            }
        }

        if (! $matched) {
            return false;
        }

        foreach (['gatas', 'gaas', 'milk', 'anlene', 'ensure', 'tigulang', 'edaran', 'adulto', 'elderly', 'senior'] as $anchor) {
            if ($this->containsPhrase($lower, $anchor)) {
                return true;
            }
        }

        return false;
    }

    private function impliesDrinkingMilkNotFormula(string $lower, ?string $product): bool
    {
        if ($this->impliesInfantMilkQuery($lower) || $this->impliesChildMilkQuery($lower) || $this->impliesAdultMilkQuery($lower)) {
            return false;
        }

        foreach (config('voc.drinking_milk_query_phrases', []) as $phrase) {
            if ($this->containsPhrase($lower, (string) $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function impliesDrinkingMilkNotCooking(string $lower, ?string $product): bool
    {
        if (! in_array($product, ['milk', 'low fat milk', 'low fat'], true)) {
            return false;
        }

        foreach (config('voc.cooking_milk_query_phrases', []) as $phrase) {
            if ($this->containsPhrase($lower, (string) $phrase)) {
                return false;
            }
        }

        foreach (config('voc.carton_milk_query_phrases', []) as $phrase) {
            if ($this->containsPhrase($lower, (string) $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $dietary
     * @param  array<int, string>  $exclusions
     * @return array<int, string>
     */
    private function buildKeywords(string $text, ?string $product, ?string $brand, array $dietary, array $exclusions): array
    {
        $seeds = [];

        if ($product) {
            $seeds[] = $product;
        }

        if ($brand) {
            $seeds[] = $brand;
        }

        foreach ($dietary as $diet) {
            $seeds[] = $diet;
        }

        $expanded = $this->productSearchService->expandTerms(array_merge($seeds, [$text]));
        $expanded = $this->filterNoise($expanded);

        foreach ($exclusions as $exclusion) {
            $expanded = array_values(array_filter(
                $expanded,
                fn (string $term) => Str::lower($term) !== Str::lower($exclusion)
                    && Str::lower($term) !== 'baka'
                    && Str::lower($term) !== 'cow',
            ));
        }

        return $expanded;
    }

    /**
     * @param  array<int, string>  $terms
     * @return array<int, string>
     */
    public function filterNoise(array $terms): array
    {
        $filtered = [];

        foreach ($terms as $term) {
            $term = trim((string) $term);
            if ($term === '' || $this->isNoiseToken($term)) {
                continue;
            }

            $filtered[] = $term;
        }

        return array_values(array_unique($filtered));
    }

    public function isNoiseToken(string $token): bool
    {
        $token = Str::lower(trim($token));

        if ($token === '' || is_numeric($token) || strlen($token) < 2) {
            return true;
        }

        $stopwords = config('voc.stopwords', []);
        if (in_array($token, $stopwords, true)) {
            return true;
        }

        $price = array_merge(config('voc.price_cheap', []), config('voc.price_premium', []));
        foreach ($price as $phrase) {
            if ($token === Str::lower((string) $phrase)) {
                return true;
            }
        }

        return in_array($token, ['ml', 'kg', 'pcs', 'liter', 'litro', 'cheap', 'barato', 'mura'], true);
    }

    private function detectLanguage(string $lower): string
    {
        $bisaya = (bool) preg_match('/\b(dili|unya|uny|kog|kani|kana)\b/u', $lower);
        $tagalog = (bool) preg_match('/\b(hindi|mga|yung|po|opo)\b/u', $lower);

        if ($bisaya && $tagalog) {
            return 'Mixed';
        }

        if ($bisaya) {
            return 'Bisaya';
        }

        if ($tagalog) {
            return 'Tagalog';
        }

        if (preg_match('/\b(barato|tuyo|gatas|baka|mura|bugas)\b/u', $lower)) {
            return 'Mixed';
        }

        return 'English';
    }

    private function containsPhrase(string $haystack, string $phrase): bool
    {
        $phrase = Str::lower(trim($phrase));

        if ($phrase === '') {
            return false;
        }

        return (bool) preg_match('/(?:^|[^\p{L}\p{N}])'.preg_quote($phrase, '/').'(?:[^\p{L}\p{N}]|$)/u', $haystack);
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($item) => Str::lower(trim((string) $item)),
            $value,
        )));
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : Str::lower($value);
    }
}
