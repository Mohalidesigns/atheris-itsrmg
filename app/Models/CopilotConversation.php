<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class CopilotConversation extends Model
{
    use BelongsToOrganization;
    use HasTenantIdAlias;

    protected $guarded = [];

    public function messages()
    {
        return $this->hasMany(CopilotMessage::class, 'conversation_id');
    }
}
