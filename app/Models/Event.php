<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $fillable = [
        'user_id','title','description','all_day','start','end','color','location','meta'
    ];

    protected $casts = [
        'all_day' => 'boolean',
        'start'   => 'datetime',
        'end'     => 'datetime',
        'meta'    => 'array',
    ];

    public function user() { return $this->belongsTo(User::class); }
}
