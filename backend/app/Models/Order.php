<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = ['reference', 'user_id', 'restaurant_id', 'promotion_id', 'fulfilment', 'status', 'payment_method', 'payment_status', 'customer_name', 'customer_email', 'customer_phone', 'delivery_address', 'customer_note', 'subtotal_cents', 'delivery_fee_cents', 'discount_cents', 'total_cents', 'placed_at', 'completed_at'];
    protected function casts(): array { return ['delivery_address' => 'array', 'placed_at' => 'datetime', 'completed_at' => 'datetime']; }
    public function user() { return $this->belongsTo(User::class); }
    public function restaurant() { return $this->belongsTo(Restaurant::class); }
    public function items() { return $this->hasMany(OrderItem::class); }
    public function histories() { return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at'); }
    public function payment() { return $this->hasOne(Payment::class); }
}
