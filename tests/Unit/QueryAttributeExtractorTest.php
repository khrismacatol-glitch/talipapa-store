<?php

namespace Tests\Unit;

use App\Services\Voc\QueryAttributeExtractor;
use Tests\TestCase;

class QueryAttributeExtractorTest extends TestCase
{
    private QueryAttributeExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = app(QueryAttributeExtractor::class);
    }

    public function test_extracts_cheap_tuyo(): void
    {
        $nlp = $this->extractor->extract('barato na tuyo');

        $this->assertSame('soy sauce', $nlp['product']);
        $this->assertSame('cheap', $nlp['price_intent']);
        $this->assertContains('soy sauce', $nlp['keywords']);
        $this->assertNotContains('tuyo', $nlp['keywords']);
        $this->assertNotContains('dried fish', $nlp['keywords']);
        $this->assertNotContains('barato', $nlp['keywords']);
        $this->assertTrue($this->hasAttribute($nlp, 'product', 'soy sauce', 'include'));
        $this->assertTrue($this->hasAttribute($nlp, 'price_intent', 'cheap', 'include'));
    }

    public function test_extracts_non_cow_milk_exclusion(): void
    {
        $nlp = $this->extractor->extract('gatas na dili sa baka');

        $this->assertSame('milk', $nlp['product']);
        $this->assertContains('cow', $nlp['exclusions']);
        $this->assertContains('non-cow', $nlp['dietary']);
        $this->assertSame('Bisaya', $nlp['language']);
        $this->assertTrue($this->hasAttribute($nlp, 'exclusion', 'cow', 'exclude'));
        $this->assertNotContains('cow', $nlp['keywords']);
        $this->assertNotContains('baka', $nlp['keywords']);
    }

    public function test_infant_queries_ask_for_starter_formula_not_adult_milk(): void
    {
        foreach (['gatas para sa infant', 'gatas para sa bay', 'gaas para sa infant'] as $query) {
            $nlp = $this->extractor->extract($query);

            $this->assertSame('milk', $nlp['product'], $query);
            $this->assertContains('infant', $nlp['dietary'], $query);
            $this->assertContains('adult_milk', $nlp['exclusions'], $query);
            $this->assertNotContains('infant_formula', $nlp['exclusions'], $query);
            $this->assertNotContains('kids', $nlp['dietary'], $query);
        }

        foreach (['gatas', 'tanan gatas', 'tanan nga gatas', 'all milk'] as $allMilk) {
            $ordinary = $this->extractor->extract($allMilk);
            $this->assertNotContains('infant_formula', $ordinary['exclusions'], $allMilk);
            $this->assertNotContains('adult_milk', $ordinary['exclusions'], $allMilk);
            $this->assertNotContains('cooking_milk', $ordinary['exclusions'], $allMilk);
            $this->assertNotContains('kids_milk', $ordinary['exclusions'], $allMilk);
        }
    }

    public function test_bata_queries_mean_infant_through_kids_not_adult_milk(): void
    {
        foreach (['gatas para sa bata', 'gatas nga pangbata'] as $query) {
            $nlp = $this->extractor->extract($query);

            $this->assertSame('milk', $nlp['product'], $query);
            $this->assertContains('kids', $nlp['dietary'], $query);
            $this->assertNotContains('infant', $nlp['dietary'], $query);
            $this->assertContains('adult_milk', $nlp['exclusions'], $query);
            $this->assertNotContains('infant_formula', $nlp['exclusions'], $query);
            $this->assertNotContains('kids_milk', $nlp['exclusions'], $query);
        }
    }

    public function test_elderly_queries_exclude_kids_and_infant_formula(): void
    {
        foreach (['gatas para sa tigulang', 'gatas para senior', 'gatas pangsenior'] as $query) {
            $nlp = $this->extractor->extract($query);

            $this->assertSame('milk', $nlp['product'], $query);
            $this->assertContains('adult', $nlp['dietary'], $query);
            $this->assertContains('infant_formula', $nlp['exclusions'], $query);
            $this->assertContains('kids_milk', $nlp['exclusions'], $query);
            $this->assertNotContains('adult_milk', $nlp['exclusions'], $query);
        }
    }

    public function test_wala_kaayoy_asukal_excludes_sugar(): void
    {
        foreach (['gatas nga wala kaayoy asukal', 'gatas nga walay asukal', 'gatas nga way asukal'] as $query) {
            $nlp = $this->extractor->extract($query);
            $this->assertSame('milk', $nlp['product'], $query);
            $this->assertContains('sugar', $nlp['exclusions'], $query);
            $this->assertContains('cooking_milk', $nlp['exclusions'], $query);
        }
    }

    public function test_extracts_vegan_nestle_300ml_milk(): void
    {
        $nlp = $this->extractor->extract('gatas na vegan 300 ml nestle');

        $this->assertSame('milk', $nlp['product']);
        $this->assertSame('nestle', $nlp['brand']);
        $this->assertContains('vegan', $nlp['dietary']);
        $this->assertContains('300ml', $nlp['units']);
        $this->assertContains('cow', $nlp['exclusions']);
        $this->assertTrue($this->hasAttribute($nlp, 'brand', 'nestle', 'include'));
        $this->assertTrue($this->hasAttribute($nlp, 'unit', '300ml', 'include'));
    }

    /**
     * @param  array<string, mixed>  $nlp
     */
    private function hasAttribute(array $nlp, string $type, string $value, string $polarity): bool
    {
        foreach ($nlp['attributes'] as $attribute) {
            if ($attribute['type'] === $type && $attribute['value'] === $value && $attribute['polarity'] === $polarity) {
                return true;
            }
        }

        return false;
    }
}
