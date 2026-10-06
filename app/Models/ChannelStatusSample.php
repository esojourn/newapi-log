<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChannelStatusSample extends Model
{
    protected $connection = 'alerts';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'channel_id' => 'integer',
        'status' => 'integer',
        'observed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
