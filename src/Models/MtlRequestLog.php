<?php

namespace Mtl\MonitorlyAgent\Models;

use Illuminate\Database\Eloquent\Model;

class MtlRequestLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['project_name', 'method', 'path', 'status_code', 'response_ms', 'memory_mb', 'peak_memory_mb', 'ip', 'user_agent', 'created_at'];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
