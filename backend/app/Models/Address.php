<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    protected $fillable = ['label', 'recipient_name', 'phone', 'line_1', 'line_2', 'area', 'postcode', 'city', 'state', 'delivery_instructions', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function user() { return $this->belongsTo(User::class); }
}
