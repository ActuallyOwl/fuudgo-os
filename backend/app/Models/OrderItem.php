<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = ['order_id', 'menu_item_id', 'item_name', 'base_unit_price_cents', 'unit_price_cents', 'quantity', 'line_total_cents', 'modifiers_snapshot'];
    protected function casts(): array { return ['modifiers_snapshot' => 'array']; }
}
