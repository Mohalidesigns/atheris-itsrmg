<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CopilotMessage extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'tool_args' => 'array',
        'tool_result' => 'array',
        'created_at' => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(CopilotConversation::class, 'conversation_id');
    }
}
