<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
    protected $fillable = ['disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'uploaded_by'];
    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }
}
