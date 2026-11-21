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
                'sku_scope' => 'contains',
                'contains' => 'milk',
            ])
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Fresh Cow Milk 1L', $csv);
        $this->assertStringContainsString('Alaska Evaporada', $csv);
        $this->assertStringNotContainsString('Jasmine Rice 5kg', $csv);
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
        $this->assertStringNotContainsString(storage_path('app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'eval'), $human->humanQrelsPath());
        $this->assertStringNotContainsString(storage_path('app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'eval'), $human->qrelsPath());
    }

    public function test_page_explains_grade_intent_and_excel_editing(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->get(route('admin.train-labels.index'))
            ->assertOk()
            ->assertSee('How to fill the CSV')
            ->assertSee('Excel')
            ->assertSee('Notepad++')
            ->assertSee('this SKU matches the query')
            ->assertSee('cow');
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
