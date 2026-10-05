<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    protected $fillable = ['code', 'title', 'description', 'type', 'value', 'minimum_spend_cents', 'maximum_discount_cents', 'restaurant_id', 'starts_at', 'ends_at', 'usage_limit', 'uses_per_customer', 'active'];
    protected function casts(): array { return ['active' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime']; }
    public function restaurant() { return $this->belongsTo(Restaurant::class); }
}
