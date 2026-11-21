<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SearchSession extends Model
{
    protected $fillable = [
        'token',
        'session_key',
        'user_hash',
        'query',
        'query_norm',
        'model_version',
        'mode',
        'is_milk_query',
        'source',
        'page',
    ];

    protected function casts(): array
    {
        return [
            'is_milk_query' => 'boolean',
            'page' => 'integer',
        ];
    }

    public function impressions(): HasMany
    {
        return $this->hasMany(SearchImpression::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SearchEvent::class);
    }
}
