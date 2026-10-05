<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderStatusHistory extends Model
{
    public $timestamps = false;
    protected $fillable = ['order_id', 'changed_by', 'from_status', 'to_status', 'note', 'created_at'];
    protected function casts(): array { return ['created_at' => 'datetime']; }
}
