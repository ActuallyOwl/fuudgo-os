<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = ['order_id', 'provider', 'provider_reference', 'status', 'amount_cents', 'provider_metadata', 'paid_at'];
    protected function casts(): array { return ['provider_metadata' => 'array', 'paid_at' => 'datetime']; }
    public function order() { return $this->belongsTo(Order::class); }
}
