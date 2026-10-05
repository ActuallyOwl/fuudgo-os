<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MenuItem extends Model
{
    use SoftDeletes;
    protected $fillable = ['restaurant_id', 'menu_category_id', 'name', 'slug', 'description', 'price_cents', 'promotional_price_cents', 'image', 'dietary_tags', 'available', 'popular', 'featured', 'sort_order'];
    protected function casts(): array { return ['dietary_tags' => 'array', 'available' => 'boolean', 'popular' => 'boolean', 'featured' => 'boolean']; }
    public function restaurant() { return $this->belongsTo(Restaurant::class); }
    public function category() { return $this->belongsTo(MenuCategory::class, 'menu_category_id'); }
    public function modifierGroups() { return $this->hasMany(ModifierGroup::class)->orderBy('sort_order'); }
}
