<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModifierGroup extends Model
{
    protected $fillable = ['menu_item_id', 'name', 'required', 'minimum_selections', 'maximum_selections', 'sort_order', 'active'];
    protected function casts(): array { return ['required' => 'boolean', 'active' => 'boolean']; }
    public function item() { return $this->belongsTo(MenuItem::class, 'menu_item_id'); }
    public function options() { return $this->hasMany(ModifierOption::class)->orderBy('sort_order'); }
}
