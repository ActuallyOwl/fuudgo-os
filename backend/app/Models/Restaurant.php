<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Restaurant extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'slug', 'description', 'cuisines', 'tags', 'promotion_badge', 'phone', 'email', 'cover_image', 'logo_image', 'area', 'operating_hours', 'delivery_minutes', 'pickup_minutes', 'delivery_fee_cents', 'minimum_order_cents', 'free_delivery_threshold_cents', 'rating', 'review_count', 'status', 'featured'];

    protected function casts(): array
    {
        return ['cuisines' => 'array', 'tags' => 'array', 'operating_hours' => 'array', 'featured' => 'boolean'];
    }

    public function categories() { return $this->hasMany(MenuCategory::class)->orderBy('sort_order'); }
    public function menuItems() { return $this->hasMany(MenuItem::class)->orderBy('sort_order'); }
    public function favourites() { return $this->belongsToMany(User::class, 'favourites')->withTimestamps(); }
}
