<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Product;
use App\Models\StoreProfile;
use App\Models\User;
use App\Services\Search\EvalLabelService;
use Database\Seeders\PsgcSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class EvalLabelsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PsgcSeeder::class);
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-labels-'.uniqid('', true);
        mkdir($this->dir, 0777, true);
        config([
            'ranking-lab.eval_write_dir' => $this->dir,
            'ranking-lab.eval_directories' => [$this->dir],
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);
        parent::tearDown();
    }

    public function test_admin_can_download_gold_template_with_blank_family(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this->actingAs($admin)
            ->post(route('admin.ranking-lab.download-gold'), [
                'q' => "1. Gatas gikan sa baka\ngatas nga pangluto\n",
            ]);

        $response->assertOk();
        $response->assertDownload('gold_queries.csv');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('query_id,query,gold_family,second_grader_family,status', $csv);
        $this->assertStringContainsString('Gatas gikan sa baka', $csv);
        $this->assertStringContainsString('graded', $csv);
        $this->assertDoesNotMatchRegularExpression('/Gatas gikan sa baka,[^,]+,/', $csv);
        $this->assertStringNotContainsString(storage_path('app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'eval'), $csv);
    }

    public function test_family_download_is_milk_only_with_blank_family(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Grocery']);
        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Fresh Cow Milk 1L',
            'description' => 'Pasteurized',
            'is_available' => true,
            'stock' => 10,
        ]);
        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Jasmine Rice 5kg',
            'description' => 'White rice',
            'is_available' => true,
            'stock' => 10,
        ]);

        $response = $this->actingAs($admin)
            ->post(route('admin.ranking-lab.download-family'));

        $response->assertOk();
        $response->assertDownload('product_family.csv');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('product_id,name,family,secondary_families,note', $csv);
        $this->assertStringContainsString('Fresh Cow Milk 1L', $csv);
        $this->assertStringNotContainsString('Jasmine Rice', $csv);
        $this->assertMatchesRegularExpression('/Fresh Cow Milk 1L["]*,,/', $csv);
        $this->assertStringNotContainsString('contains', strtolower($csv));
    }

    public function test_gold_upload_drops_ai_rows_and_writes_temp_eval_dir(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $existing = $this->dir.DIRECTORY_SEPARATOR.'gold_queries.csv';
        file_put_contents($existing, "query_id,query,gold_family,second_grader_family,status\nold,old query,Powder,,graded\n");

        $csv = "query_id,query,gold_family,suggested_family,reason,status\n"
            ."q1,gatas gikan sa baka,Drinking / cow,,,graded\n"
            ."q2,gatas nga pangluto,Cooking,Cooking,voc said this,graded\n"
            ."q3,gatas,Powder,Powder,,graded\n"
            ."q4,skip me,,,,graded\n";
        $file = UploadedFile::fake()->createWithContent('gold_queries.csv', $csv);

        $this->actingAs($admin)
            ->post(route('admin.ranking-lab.upload-gold'), ['gold' => $file])
            ->assertRedirect()
            ->assertSessionHas('status');

        $service = app(EvalLabelService::class);
        $this->assertSame($this->dir.DIRECTORY_SEPARATOR.'gold_queries.csv', $service->goldPath());
        $this->assertStringNotContainsString(storage_path('app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'eval'), $service->goldPath());
        $saved = file_get_contents($service->goldPath());
        $this->assertStringContainsString('gatas gikan sa baka', $saved);
        $this->assertStringContainsString('Drinking / cow', $saved);
        $this->assertStringNotContainsString('gatas nga pangluto', $saved);
        $this->assertStringNotContainsString(',gatas,', $saved);
        $this->assertFileExists($existing.'.backup.csv');
        $this->assertStringContainsString('old query', file_get_contents($existing.'.backup.csv'));
    }

    public function test_family_upload_rejects_cooking_primary_and_drops_ai(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $csv = "product_id,name,family,secondary_families,note,suggested_family\n"
            ."11,Fresh Cow Milk,Drinking / cow,,,\n"
            ."12,Coconut oil,Cooking,,,Cooking\n"
            ."13,Bear Brand,Powder,,chatgpt labeled this,\n";
        $file = UploadedFile::fake()->createWithContent('product_family.csv', $csv);

        $this->actingAs($admin)
            ->post(route('admin.ranking-lab.upload-family'), ['family' => $file])
            ->assertRedirect()
            ->assertSessionHas('status');

        $saved = file_get_contents(app(EvalLabelService::class)->familyPath());
        $this->assertStringContainsString('Drinking / cow', $saved);
        $this->assertStringNotContainsString('Coconut oil', $saved);
        $this->assertStringNotContainsString('Bear Brand', $saved);
        $this->assertStringNotContainsString(storage_path('app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'eval'), app(EvalLabelService::class)->familyPath());
    }

    public function test_page_has_relabel_steps_and_downloads_current_files(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $gold = "query_id,query,gold_family,status\nq1,gatas gikan sa baka,Drinking / cow,graded\n";
        $family = "product_id,name,family,secondary_families,note\n11,Fresh Cow Milk,Drinking / cow,,\n";
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.'gold_queries.csv', $gold);
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.'product_family.csv', $family);

        $this->actingAs($admin)
            ->get(route('admin.ranking-lab.index'))
            ->assertOk()
            ->assertSee('How to relabel and run')
            ->assertSee('16 family names are fixed')
            ->assertSee('Milk for kids')
            ->assertSee('Drinking / cow')
            ->assertSee('Download current gold_queries.csv')
            ->assertSee('Download current product_family.csv')
            ->assertSee('gatas gikan sa baka')
            ->assertDontSee('name="contains"', false)
            ->assertDontSee('sku_scope');

        $this->actingAs($admin)
            ->get(route('admin.ranking-lab.current-gold'))
            ->assertOk()
            ->assertDownload('gold_queries.csv');

        $this->actingAs($admin)
            ->get(route('admin.ranking-lab.current-family'))
            ->assertOk()
            ->assertDownload('product_family.csv');

        $this->actingAs($admin)
            ->get(route('admin.eval-labels.index'))
            ->assertRedirect(route('admin.ranking-lab.index').'#eval-labels');
    }

    public function test_customer_cannot_open_eval_labels(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);

        $this->actingAs($customer)
            ->get(route('admin.eval-labels.index'))
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
