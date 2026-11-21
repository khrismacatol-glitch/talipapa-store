<?php

return [

    'enabled' => env('SEARCH_FEEDBACK_ENABLED', true),

    'cookie' => 'search_fb',

    'cookie_minutes' => 30,

    'min_sessions' => (int) env('SEARCH_FEEDBACK_MIN_SESSIONS', 5),

    'min_strong_actions' => (int) env('SEARCH_FEEDBACK_MIN_STRONG', 3),

    'negative_impressions' => (int) env('SEARCH_FEEDBACK_NEGATIVE_IMPRESSIONS', 20),

];
