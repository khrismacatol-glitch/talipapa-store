<?php

namespace Tests\Unit;

use Database\Seeders\MilkCatalogSeeder;
use PHPUnit\Framework\TestCase;

class MilkCatalogSeederTest extends TestCase
{
    /**
     * @return array<int, array<string, mixed>>
     */
    private function catalog(): array
    {
        return require __DIR__.'/../../database/data/milk_catalog.php';
    }

    /**
     * @param  callable(array<string, mixed>): bool  $matches
     */
    private function countMilk(callable $matches): int
    {
        return count(array_filter($this->catalog(), fn (array $row) => $row['specs']['is_milk'] && $matches($row['specs'])));
    }

    public function test_each_milk_category_has_five_or_more_products(): void
    {
        $this->assertGreaterThanOrEqual(5, $this->countMilk(fn ($s) => $s['source'] === 'cow' && $s['sugar'] !== 'sweetened'));
        $this->assertGreaterThanOrEqual(5, $this->countMilk(fn ($s) => in_array($s['fat'], ['low', 'non'], true)));
        $this->assertGreaterThanOrEqual(5, $this->countMilk(fn ($s) => in_array($s['sugar'], ['no_added', 'unsweetened'], true)));
        $this->assertGreaterThanOrEqual(5, $this->countMilk(fn ($s) => $s['organic']));
    }

    public function test_catalog_includes_non_milk_negative_samples(): void
    {
        $negatives = array_column(array_filter($this->catalog(), fn (array $row) => ! $row['specs']['is_milk']), 'name');

        $this->assertGreaterThanOrEqual(10, count($negatives));
        $this->assertContains('Alaska Sweetened Condensed Milk 300ml', $negatives);
        $this->assertContains('UFC Coconut Milk (Gata) 400ml', $negatives);
    }

    public function test_rows_have_required_fields(): void
    {
        foreach ($this->catalog() as $row) {
            foreach (['store', 'category_slug', 'price', 'name', 'description', 'specs'] as $field) {
                $this->assertArrayHasKey($field, $row, $row['name'] ?? 'row');
            }
            $this->assertArrayHasKey('organic', $row['specs']);
            $this->assertArrayHasKey('form', $row['specs']);
        }

        $this->assertTrue(class_exists(MilkCatalogSeeder::class));
    }
}
