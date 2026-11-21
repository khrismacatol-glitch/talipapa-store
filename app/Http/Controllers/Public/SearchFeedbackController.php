<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\Search\SearchFeedbackService;
use Illuminate\Http\Response;
use Illuminate\Http\Request;

class SearchFeedbackController extends Controller
{
    public function __construct(
        private readonly SearchFeedbackService $searchFeedback,
    ) {}

    public function click(Request $request): Response
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'search_session' => ['nullable', 'uuid'],
        ]);

        $this->searchFeedback->recordEvent(
            $request,
            (int) $validated['product_id'],
            SearchFeedbackService::TYPE_CLICK,
            $validated['search_session'] ?? null,
        );

        return response()->noContent();
    }
}
