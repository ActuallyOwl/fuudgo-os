<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModifierOption extends Model
{
    protected $fillable = ['modifier_group_id', 'name', 'price_adjustment_cents', 'available', 'sort_order'];
    protected function casts(): array { return ['available' => 'boolean']; }
    public function group() { return $this->belongsTo(ModifierGroup::class, 'modifier_group_id'); }
}
