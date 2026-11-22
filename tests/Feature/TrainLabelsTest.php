<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Product;
use App\Models\StoreProfile;
use App\Models\User;
use App\Services\Search\HumanTrainLabelService;
use Database\Seeders\PsgcSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class TrainLabelsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PsgcSeeder::class);
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'train-labels-'.uniqid('', true);
        mkdir($this->dir, 0777, true);
        config([
            'ranking-lab.human_dir' => $this->dir.DIRECTORY_SEPARATOR.'human',
            'ranking-lab.qrels_path' => $this->dir.DIRECTORY_SEPARATOR.'qrels.csv',
            'ranking-lab.queries_path' => $this->dir.DIRECTORY_SEPARATOR.'queries.csv',
            'ranking-lab.models_dir' => $this->dir.DIRECTORY_SEPARATOR.'models',
        ]);
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.'queries.csv', "query,intent,split,lang\nexisting query,cow,train,ceb\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);
        parent::tearDown();
    }

    public function test_admin_can_download_judgment_csv_from_live_products(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $product = Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Fresh Cow Milk 1L',
            'description' => 'Pasteurized milk',
            'is_available' => true,
            'stock' => 10,
        ]);

        $response = $this->actingAs($admin)
            ->post(route('admin.train-labels.download'), [
                'q' => "1. gatas nga pangluto\n",
                'query_source' => 'pasted',
                'sku_scope' => 'all',
            ]);

        $response->assertOk();
        $response->assertDownload('train-judgment.csv');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('query,product_id,product_key,name,description,category,grade,intent,split', $csv);
        $this->assertStringContainsString('gatas nga pangluto', $csv);
        $this->assertStringContainsString((string) $product->id, $csv);
        $this->assertStringContainsString('Fresh Cow Milk 1L', $csv);
        $this->assertStringNotContainsString(storage_path('app/private/eval'), $csv);
    }

    public function test_download_can_keep_skus_whose_name_or_description_contains_a_word(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Grocery']);
        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Fresh Cow Milk 1L',
            'description' => 'Pasteurized dairy',
            'is_available' => true,
            'stock' => 10,
        ]);
        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Alaska Evaporada',
            'description' => 'Evaporated milk creamer for cooking',
            'is_available' => true,
            'stock' => 10,
        ]);
        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Jasmine Rice 5kg',
            'description' => 'Long grain rice',
            'is_available' => true,
            'stock' => 10,
        ]);

        $csv = $this->actingAs($admin)
            ->post(route('admin.train-labels.download'), [
                'q' => 'gatas nga pangluto',
                'query_source' => 'pasted',
                'sku_scope' => 'contains',
                'contains' => 'milk',
            ])
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Fresh Cow Milk 1L', $csv);
        $this->assertStringContainsString('Alaska Evaporada', $csv);
        $this->assertStringNotContainsString('Jasmine Rice 5kg', $csv);
    }

    public function test_download_uses_pasted_queries_even_if_all_train_is_selected(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Fresh Cow Milk 1L',
            'description' => 'Pasteurized milk',
            'is_available' => true,
            'stock' => 10,
        ]);

        $csv = $this->actingAs($admin)
            ->post(route('admin.train-labels.download'), [
                'q' => "gatas gikan sa baka\ngatas sa baka\n",
                'query_source' => 'all_train',
                'sku_scope' => 'contains',
                'contains' => 'milk',
            ])
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('gatas gikan sa baka', $csv);
        $this->assertStringContainsString('gatas sa baka', $csv);
        $this->assertStringNotContainsString('existing query', $csv);
    }

    public function test_download_contains_comma_list_matches_any_word(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Grocery']);
        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Lucky Me Pancit Canton Noodles 80g',
            'description' => 'Instant pancit',
            'is_available' => true,
            'stock' => 10,
        ]);
        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => '555 Sardines',
            'description' => 'Canned fish in tomato sauce',
            'is_available' => true,
            'stock' => 10,
        ]);
        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Jasmine Rice 5kg',
            'description' => 'Long grain rice',
            'is_available' => true,
            'stock' => 10,
        ]);

        $csv = $this->actingAs($admin)
            ->post(route('admin.train-labels.download'), [
                'q' => 'sud-an',
                'query_source' => 'pasted',
                'sku_scope' => 'contains',
                'contains' => 'canned, noodles',
            ])
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Lucky Me Pancit Canton Noodles 80g', $csv);
        $this->assertStringContainsString('555 Sardines', $csv);
        $this->assertStringNotContainsString('Jasmine Rice 5kg', $csv);
    }

    public function test_download_can_keep_skus_in_one_category(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $store = StoreProfile::factory()->approved()->create();
        $dairy = Category::factory()->create(['name' => 'Dairy']);
        $grains = Category::factory()->create(['name' => 'Grains']);
        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $dairy->id,
            'name' => 'Fresh Cow Milk 1L',
            'is_available' => true,
            'stock' => 10,
        ]);
        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $grains->id,
            'name' => 'Jasmine Rice 5kg',
            'is_available' => true,
            'stock' => 10,
        ]);

        $csv = $this->actingAs($admin)
            ->post(route('admin.train-labels.download'), [
                'q' => 'gatas',
                'query_source' => 'pasted',
                'sku_scope' => 'category',
                'category_id' => $dairy->id,
            ])
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Fresh Cow Milk 1L', $csv);
        $this->assertStringNotContainsString('Jasmine Rice 5kg', $csv);
    }

    public function test_admin_can_upload_grades_and_apply_without_touching_eval(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $csv = "query,product_id,product_key,name,description,category,grade,intent,split\n".
            "gatas nga pangluto,1,alaska-evaporada,Alaska Evaporada,creamer,Dairy,2,cow,train\n".
            "gatas nga pangluto,2,fresh-milk-1l,Fresh Milk,fresh,Dairy,1,cow,train\n".
            "gatas nga pangluto,3,rice,Rice,grain,Grains,,cow,train\n";
        $file = UploadedFile::fake()->createWithContent('grades.csv', $csv);

        $this->actingAs($admin)
            ->post(route('admin.train-labels.upload'), ['grades' => $file])
            ->assertRedirect()
            ->assertSessionHas('status');

        $human = app(HumanTrainLabelService::class);
        $this->assertFileExists($human->humanQrelsPath());
        $qrels = file_get_contents($human->humanQrelsPath());
        $this->assertStringContainsString('alaska-evaporada,2', str_replace('"', '', $qrels));
        $this->assertStringNotContainsString('rice', $qrels);

        $this->actingAs($admin)
            ->post(route('admin.train-labels.apply'))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertFileExists($human->qrelsPath());
        $this->assertStringContainsString('alaska-evaporada', file_get_contents($human->qrelsPath()));
        $this->assertStringContainsString('gatas nga pangluto', file_get_contents($human->queriesPath()));
        $this->assertStringContainsString('existing query', file_get_contents($human->queriesPath()));
        $this->assertStringNotContainsString(storage_path('app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'eval'), $human->humanQrelsPath());
        $this->assertStringNotContainsString(storage_path('app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'eval'), $human->qrelsPath());
    }

    public function test_apply_merges_grades_and_keeps_other_queries(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        file_put_contents(
            $this->dir.DIRECTORY_SEPARATOR.'qrels.csv',
            "query,product_key,grade\nexisting query,old-sku,2\n",
        );
        $csv = "query,product_id,product_key,name,description,category,grade,intent,split\n".
            "gatas sa baka,1,alaminos-goat,Goat milk,goat,Dairy,0,cow,train\n".
            "gatas sa baka,2,fresh-milk-1l,Fresh Milk,fresh,Dairy,2,cow,train\n";
        $file = UploadedFile::fake()->createWithContent('grades.csv', $csv);

        $this->actingAs($admin)
            ->post(route('admin.train-labels.upload'), ['grades' => $file])
            ->assertRedirect();
        $this->actingAs($admin)
            ->post(route('admin.train-labels.apply'))
            ->assertRedirect()
            ->assertSessionHas('status');

        $qrels = str_replace('"', '', (string) file_get_contents(app(HumanTrainLabelService::class)->qrelsPath()));
        $this->assertStringContainsString('existing query,old-sku,2', $qrels);
        $this->assertStringContainsString('alaminos-goat,0', $qrels);
        $this->assertStringContainsString('fresh-milk-1l,2', $qrels);
    }

    public function test_download_existing_qrels_includes_every_graded_query(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $milk = Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Horizon Organic Milk 1L',
            'description' => 'Organic cow milk',
            'is_available' => true,
            'stock' => 10,
        ]);
        file_put_contents(
            $this->dir.DIRECTORY_SEPARATOR.'qrels.csv',
            "query,product_key,grade\ngatas sa baka,fresh-milk-1l,2\ngatas nga pangluto,alaska-evaporada,2\n",
        );

        $csv = $this->actingAs($admin)
            ->get(route('admin.train-labels.download-existing'))
            ->assertOk()
            ->assertDownload('train-qrels-all-queries.csv')
            ->streamedContent();

        $this->assertStringContainsString('gatas sa baka', $csv);
        $this->assertStringContainsString('gatas nga pangluto', $csv);
        $this->assertStringContainsString('fresh-milk-1l', $csv);
        $this->assertStringContainsString(',2,', $csv);
        $this->assertStringContainsString('Horizon Organic Milk 1L', $csv);
        $this->assertStringContainsString((string) $milk->id, $csv);
        $this->assertEquals(2, substr_count($csv, 'Horizon Organic Milk 1L'));
    }

    public function test_page_explains_grade_intent_and_excel_editing(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->get(route('admin.train-labels.index'))
            ->assertOk()
            ->assertSee('How to fill the CSV')
            ->assertSee('correct every query')
            ->assertSee('Merge upload into')
            ->assertSee('Train candidate')
            ->assertSee('Promote candidate to live')
            ->assertSee('5. Promote candidate to live')
            ->assertSee('Excel')
            ->assertSee('Notepad++')
            ->assertSee('this SKU matches the query')
            ->assertSee('cow');
    }

    public function test_admin_can_promote_latest_candidate_without_copying_by_hand(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $models = $this->dir.DIRECTORY_SEPARATOR.'models';
        $candidate = $models.DIRECTORY_SEPARATOR.'candidates'.DIRECTORY_SEPARATOR.'20261121T160000Z';
        mkdir($candidate, 0777, true);
        file_put_contents($models.DIRECTORY_SEPARATOR.'ranker.txt', 'old-ranker');
        file_put_contents($candidate.DIRECTORY_SEPARATOR.'ranker.txt', 'new-ranker');
        file_put_contents($candidate.DIRECTORY_SEPARATOR.'meta.json', '{"version":"20261121T160000Z"}');

        $this->actingAs($admin)
            ->post(route('admin.train-labels.promote'))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame('new-ranker', file_get_contents($models.DIRECTORY_SEPARATOR.'ranker.txt'));
        $this->assertSame('{"version":"20261121T160000Z"}', file_get_contents($models.DIRECTORY_SEPARATOR.'meta.json'));
    }

    public function test_customer_cannot_open_train_labels(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);

        $this->actingAs($customer)
            ->get(route('admin.train-labels.index'))
            ->assertForbidden();
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
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
