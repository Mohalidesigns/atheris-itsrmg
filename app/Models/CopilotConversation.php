<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class CopilotConversation extends Model
{
    use HasTenantIdAlias;

    protected $guarded = [];

    public function messages()
    {
        return $this->hasMany(CopilotMessage::class, 'conversation_id');
    }
}
