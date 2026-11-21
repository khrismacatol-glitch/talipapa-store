<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Product;
use App\Models\StoreProfile;
use App\Models\User;
use App\Services\Search\RankingLabService;
use Database\Seeders\PsgcSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RankingLabTest extends TestCase
{
    use RefreshDatabase;

    private string $evalDir;

    private string $evalFallbackDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PsgcSeeder::class);
        $this->evalDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ranking-lab-eval-'.uniqid('', true);
        $this->evalFallbackDir = $this->evalDir.'-fallback';
        mkdir($this->evalDir, 0777, true);
        mkdir($this->evalFallbackDir, 0777, true);
        config([
            'ranking-lab.eval_directories' => [$this->evalDir, $this->evalFallbackDir],
        ]);
        $runs = storage_path('app/eval/runs');
        if (is_dir($runs)) {
            foreach (glob($runs.'/*/*.csv') ?: [] as $file) {
                @unlink($file);
            }
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->evalDir);
        $this->removeDirectory($this->evalFallbackDir);
        parent::tearDown();
    }

    public function test_admin_can_run_queries_and_see_technology_scores(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $store = StoreProfile::factory()->approved()->create(['store_name' => 'QuickStop Pantry']);
        $category = Category::factory()->create(['name' => 'Dairy']);
        $product = Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Alaska Evaporada Evaporated Creamer 370g',
            'description' => 'Evaporated filled milk creamer for cooking.',
            'price' => 30.32,
            'is_available' => true,
            'stock' => 10,
        ]);
        $this->writeGoldCsv([
            ['q1', 'gatas nga pangluto', 'Cooking', 'Cooking', 'graded'],
            ['q2', 'condensed nga gatas', 'Condensed', '', 'graded'],
        ]);
        $this->writeFamilyCsv([
            [$product->id, $product->name, 'Evaporated', 'Cooking', ''],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.ranking-lab.index'), ['q' => "gatas nga pangluto\ncondensed nga gatas"])
            ->assertOk()
            ->assertSee('Ranking lab')
            ->assertSee('Confusion matrices')
            ->assertSee('LambdaMART')
            ->assertSee('VOC keyword')
            ->assertSee('gatas nga pangluto')
            ->assertSee('Alaska Evaporada Evaporated Creamer 370g')
            ->assertSee('Download CSV')
            ->assertSee('Download matrices CSV')
            ->assertSee('Macro F1')
            ->assertSee('Macro recall')
            ->assertSee('product_family.csv')
            ->assertSee('Eval CSV load')
            ->assertSee('Queries matched to a gold row');
    }

    public function test_eval_csv_load_panel_renders_on_get(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->get(route('admin.ranking-lab.index'))
            ->assertOk()
            ->assertSee('Eval CSV load', false)
            ->assertSee('gold_queries.csv', false)
            ->assertSee('product_family.csv', false)
            ->assertSee('Unlabeled queries', false)
            ->assertSee('Unmapped products', false);
    }

    public function test_admin_can_export_ranking_csv(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $product = Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Carnation Condensada 388g',
            'is_available' => true,
            'stock' => 10,
        ]);
        $this->writeGoldCsv([
            ['q1', 'condensed nga gatas', 'Condensed', '', 'graded'],
        ]);
        $this->writeFamilyCsv([
            [$product->id, $product->name, 'Condensed', '', ''],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.ranking-lab.index'), ['q' => 'condensed nga gatas'])
            ->assertOk();

        $response = $this->actingAs($admin)
            ->get(route('admin.ranking-lab.export'));

        $response->assertOk();
        $response->assertDownload('ranking-lab-scores.csv');
        $csv = file_get_contents($response->getFile()->getPathname());
        $this->assertStringContainsString('Carnation Condensada 388g', $csv);
        $this->assertStringContainsString('score_php_keyword', $csv);
        $this->assertStringContainsString('rank_total', $csv);
        $this->assertStringContainsString('gold_family', $csv);
        $this->assertStringNotContainsString('macro_f1', $csv);
    }

    public function test_admin_can_export_matrices_csv(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $product = Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Carnation Condensada 388g',
            'is_available' => true,
            'stock' => 10,
        ]);
        $this->writeGoldCsv([
            ['q1', 'condensed nga gatas', 'Condensed', '', 'graded'],
        ]);
        $this->writeFamilyCsv([
            [$product->id, $product->name, 'Condensed', '', ''],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.ranking-lab.index'), ['q' => 'condensed nga gatas'])
            ->assertOk();

        $response = $this->actingAs($admin)
            ->get(route('admin.ranking-lab.export-matrices'));

        $response->assertOk();
        $response->assertDownload('ranking-lab-matrices.csv');
        $csv = file_get_contents($response->getFile()->getPathname());
        $this->assertStringContainsString('scorer', $csv);
        $this->assertStringContainsString('Condensed', $csv);
        $this->assertStringContainsString('Live total', $csv);
        $this->assertStringNotContainsString('macro_f1', $csv);
        $this->assertStringNotContainsString('score_php_keyword', $csv);
        $this->assertStringNotContainsString('Carnation Condensada 388g', $csv);
    }

    public function test_export_without_run_returns_not_found(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->get(route('admin.ranking-lab.export'))
            ->assertNotFound();
    }

    public function test_customer_cannot_open_ranking_lab(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);

        $this->actingAs($customer)
            ->get(route('admin.ranking-lab.index'))
            ->assertForbidden();
    }

    public function test_leading_numbering_is_stripped_before_ranking(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $product = Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Fresh Cow Milk 1L',
            'is_available' => true,
            'stock' => 10,
        ]);
        $this->writeGoldCsv([
            ['q1', 'Gatas gikan sa baka', 'Drinking / cow', '', 'graded'],
        ]);
        $this->writeFamilyCsv([
            [$product->id, $product->name, 'Drinking / cow', '', ''],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.ranking-lab.index'), ['q' => '1. Gatas gikan sa baka'])
            ->assertOk()
            ->assertSee('“Gatas gikan sa baka”')
            ->assertDontSee('“1. Gatas gikan sa baka”')
            ->assertSee('Drinking / cow');
    }

    public function test_no_relevant_item_is_excluded_from_metrics(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $product = Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Carnation Condensada 388g',
            'is_available' => true,
            'stock' => 10,
        ]);
        $this->writeGoldCsv([
            ['q1', 'gatas nga wala', 'Other', '', 'no_relevant_item'],
            ['q2', 'condensed nga gatas', 'Condensed', '', 'graded'],
        ]);
        $this->writeFamilyCsv([
            [$product->id, $product->name, 'Condensed', '', ''],
        ]);

        $html = $this->actingAs($admin)
            ->post(route('admin.ranking-lab.index'), ['q' => "gatas nga wala\ncondensed nga gatas"])
            ->assertOk()
            ->assertSee('Excluded')
            ->assertSee('no_relevant_item')
            ->getContent();

        $this->assertMatchesRegularExpression('/Accuracy 100\.0%/', $html);
    }

    public function test_missing_gold_never_falls_back_to_voc_and_is_listed(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Carnation Condensada 388g',
            'is_available' => true,
            'stock' => 10,
        ]);
        $this->writeGoldCsv([]);
        $this->writeFamilyCsv([]);

        $this->actingAs($admin)
            ->post(route('admin.ranking-lab.index'), ['q' => 'condensed nga gatas'])
            ->assertOk()
            ->assertSee('No gold label')
            ->assertSee('condensed nga gatas')
            ->assertSee('unlabeled');
    }

    public function test_suggested_family_column_is_ignored(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $product = Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Fresh Cow Milk 1L',
            'is_available' => true,
            'stock' => 10,
        ]);
        $gold = fopen($this->evalCsvPath('gold_queries.csv'), 'w');
        fputcsv($gold, ['query_id', 'query', 'suggested_family', 'gold_family', 'reason', 'second_grader_family', 'status']);
        fputcsv($gold, ['q1', 'gatas gikan sa baka', 'Condensed', 'Drinking / cow', 'voc said condensed', '', 'graded']);
        fclose($gold);
        $this->writeFamilyCsv([
            [$product->id, $product->name, 'Drinking / cow', '', ''],
        ]);

        $lab = app(RankingLabService::class)->evaluate(['gatas gikan sa baka'], 8);
        $this->assertSame('Drinking / cow', $lab['runs'][0]['gold']);
        $this->assertNotSame('Condensed', $lab['runs'][0]['gold']);

        $this->actingAs($admin)
            ->post(route('admin.ranking-lab.index'), ['q' => 'gatas gikan sa baka'])
            ->assertOk()
            ->assertSee('Drinking / cow');
    }

    public function test_secondary_family_match_lands_on_diagonal(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $match = Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Alaska Evaporada Evaporated Creamer 370g',
            'is_available' => true,
            'stock' => 10,
        ]);
        $this->writeGoldCsv([
            ['q1', 'gatas nga pangluto', 'Cooking', '', 'graded'],
        ]);
        $this->writeFamilyCsv([
            [$match->id, $match->name, 'Evaporated', 'Cooking', ''],
        ]);

        $lab = app(RankingLabService::class)->evaluate(['gatas nga pangluto'], 8);
        $this->assertSame('Cooking', $lab['runs'][0]['top1']['total']);
        $this->assertTrue($lab['runs'][0]['top1_correct']['total']);
        $this->assertSame(1, $lab['matrices']['total']['grid']['Cooking']['Cooking']);
        $this->assertEqualsWithDelta(1.0, $lab['matrices']['total']['accuracy'], 1e-12);
    }

    public function test_primary_mismatch_uses_primary_column(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $product = Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Carnation Condensada 388g',
            'is_available' => true,
            'stock' => 10,
        ]);
        $this->writeGoldCsv([
            ['q1', 'gatas gikan sa baka', 'Drinking / cow', '', 'graded'],
        ]);
        $this->writeFamilyCsv([
            [$product->id, $product->name, 'Condensed', '', ''],
        ]);

        $lab = app(RankingLabService::class)->evaluate(['gatas gikan sa baka'], 8);
        $this->assertSame('Condensed', $lab['runs'][0]['top1']['total']);
        $this->assertFalse($lab['runs'][0]['top1_correct']['total']);
        $this->assertSame(1, $lab['matrices']['total']['grid']['Drinking / cow']['Condensed']);
        $this->assertSame(0, $lab['matrices']['total']['grid']['Drinking / cow']['Drinking / cow']);
    }

    public function test_eval_csv_accepts_bom_semicolon_and_private_storage(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $product = Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Fresh Cow Milk 1L',
            'is_available' => true,
            'stock' => 10,
        ]);

        file_put_contents(
            $this->evalFallbackDir.DIRECTORY_SEPARATOR.'gold_queries.csv',
            "\xEF\xBB\xBFquery_id;query;gold_family;status\n".
            "q1;  GATAS   gikan sa baka ;Drinking / cow;graded\n"
        );
        file_put_contents(
            $this->evalFallbackDir.DIRECTORY_SEPARATOR.'product_family.csv',
            "\xEF\xBB\xBFproduct_id;name;family;secondary_families;note\n".
            ' '.$product->id.' ;Fresh Cow Milk 1L;Drinking / cow;;'."\n"
        );

        $lab = app(RankingLabService::class)->evaluate(['Gatas gikan sa baka'], 8);

        $this->assertSame(
            $this->evalFallbackDir.DIRECTORY_SEPARATOR.'gold_queries.csv',
            $lab['eval']['gold']['path']
        );
        $this->assertTrue($lab['eval']['gold']['bom']);
        $this->assertSame(';', $lab['eval']['gold']['delimiter']);
        $this->assertSame(1, $lab['eval']['gold']['loaded_rows']);
        $this->assertSame('Drinking / cow', $lab['runs'][0]['gold']);
        $this->assertSame('Drinking / cow', $lab['runs'][0]['rows'][0]['family']);
        $this->assertSame(1, $lab['eval']['gold_matched']);
        $this->assertSame([], $lab['eval']['unlabeled']);
        $this->assertSame([], $lab['eval']['unmapped_products']);

        $this->actingAs($admin)
            ->get(route('admin.ranking-lab.index'))
            ->assertOk()
            ->assertSee('Eval CSV load')
            ->assertSee('gold_queries.csv');
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    private function writeGoldCsv(array $rows): void
    {
        $handle = fopen($this->evalCsvPath('gold_queries.csv'), 'w');
        fputcsv($handle, ['query_id', 'query', 'gold_family', 'second_grader_family', 'status']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function writeFamilyCsv(array $rows): void
    {
        $handle = fopen($this->evalCsvPath('product_family.csv'), 'w');
        fputcsv($handle, ['product_id', 'name', 'family', 'secondary_families', 'note']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
    }

    private function evalCsvPath(string $filename): string
    {
        $path = $this->evalDir.DIRECTORY_SEPARATOR.$filename;
        foreach ([storage_path('app/eval'), storage_path('app/private/eval')] as $forbidden) {
            if (str_starts_with($path, $forbidden)) {
                throw new \RuntimeException('Tests must not write eval CSVs under '.$forbidden);
            }
        }

        return $path;
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
