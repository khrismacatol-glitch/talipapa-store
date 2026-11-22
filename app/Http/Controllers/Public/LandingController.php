<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\StoreProfile;
use Illuminate\View\View;

class LandingController extends Controller
{
    public function index(): View
    {
        $featuredStores = StoreProfile::query()
            ->approved()
            ->with('addresses')
            ->withCount(['products' => fn ($query) => $query->available()])
            ->latest('approved_at')
            ->limit(3)
            ->get();

        $categories = Category::query()
            ->where('is_active', true)
            ->withCount(['products' => fn ($query) => $query->available()])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $popularCategoryHighlights = $categories
            ->filter(fn (Category $category) => $category->products_count > 0)
            ->take(3)
            ->values();

        foreach ($popularCategoryHighlights as $category) {
            $category->setRelation(
                'products',
                Product::query()
                    ->available()
                    ->with('store')
                    ->where('category_id', $category->id)
                    ->latest()
                    ->limit(10)
                    ->get(),
            );
        }

        return view('public.landing', compact('featuredStores', 'categories', 'popularCategoryHighlights'));
    }
}
