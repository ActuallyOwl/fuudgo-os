<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromotionUsage extends Model
{
    public $timestamps = false;
    protected $fillable = ['promotion_id', 'user_id', 'order_id', 'discount_cents', 'created_at'];
    protected function casts(): array { return ['created_at' => 'datetime']; }
    public function promotion() { return $this->belongsTo(Promotion::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function order() { return $this->belongsTo(Order::class); }
}
