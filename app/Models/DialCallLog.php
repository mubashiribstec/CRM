<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DialCallLog extends Model
{
    protected $table = 'dial_call_logs';

    protected $fillable = [
        'phone_key',
        'user_id',
        'call_date',
        'calls',
    ];

    protected $casts = [
        'call_date' => 'date',
        'user_id'   => 'integer',
        'calls'     => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\Horsefly\User::class);
    }
}
