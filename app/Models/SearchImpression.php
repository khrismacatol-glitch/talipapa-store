<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchImpression extends Model
{
    protected $fillable = [
        'search_session_id',
        'product_id',
        'position',
        'page',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'page' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(SearchSession::class, 'search_session_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
