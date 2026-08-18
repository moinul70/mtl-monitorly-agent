<?php

namespace Mtl\RequestTracker\Models;

use Illuminate\Database\Eloquent\Model;

class RequestLog extends Model
{
    public $timestamps = false; // created_at is set manually, no updated_at needed

    protected $fillable = [
        'method',
        'path',
        'status_code',
        'response_ms',
        'ip',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
