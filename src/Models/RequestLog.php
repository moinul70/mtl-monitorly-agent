<?php

namespace Mtl\RequestTracker\Models;

use Illuminate\Database\Eloquent\Model;

class RequestLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['project_name', 'method', 'path', 'status_code', 'response_ms', 'memory_mb', 'peak_memory_mb', 'ip', 'user_agent', 'created_at'];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
