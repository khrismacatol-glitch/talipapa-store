<?php

return [

    /*
    | ML ranking service in ml/ (LaBSE embeddings + LightGBM LambdaMART).
    | When it is disabled or unreachable, search falls back to keyword scoring.
    */
    'enabled' => env('SEMANTIC_SEARCH_ENABLED', false),

    'url' => env('SEMANTIC_SEARCH_URL', 'http://127.0.0.1:8088'),

    'timeout' => (float) env('SEMANTIC_SEARCH_TIMEOUT', 10),

    // Products sent to the ranker per query.
    'candidate_limit' => (int) env('SEMANTIC_SEARCH_CANDIDATES', 2000),

    // LaBSE / BM25 / LTR totals on search cards. Off for shoppers; on for you or admins.
    'show_scores' => env('SEARCH_SCORE_DEBUG', false),

];
