<?php

namespace Database\Seeders;

use App\Models\Control;
use App\Models\CopilotConversation;
use App\Models\CopilotMessage;
use App\Models\Risk;
use App\Models\ScimToken;
use App\Models\SsoConnection;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Identity and Copilot demo data per bank (ATH-VAL-019). SCIM tokens are stored hashed (the plain
 * value is never kept: issue a new one from Identity > SCIM). Copilot replies are the offline,
 * records-grounded kind, recorded with model "atheris-offline".
 */
class IdentityAdminCopilotDemoSeeder extends Seeder
{
    /** org => [slug, admin email] */
    private const BANKS = [
        2 => ['kano', 'admin@kanoheritage.ng'],
        3 => ['firstbank', 'admin@firstbanknigeria.ng'],
    ];

    public function run(): void
    {
        foreach (self::BANKS as $orgId => [$slug, $email]) {
            $admin = User::where('email', $email)->where('organization_id', $orgId)->first();
            if (! $admin) {
                continue;
            }
            $this->identity($orgId, $slug, $admin);
            $this->copilot($orgId, $admin);
        }
    }

    private function identity(int $orgId, string $slug, User $admin): void
    {
        $sso = SsoConnection::withoutGlobalScopes()->where('organization_id', $orgId);
        if (! $sso->exists()) {
            foreach ([
                ['saml', 'Entra ID (Primary)', true],
                ['oidc', 'Okta Workforce', false],
            ] as [$type, $name, $enabled]) {
                SsoConnection::withoutGlobalScopes()->create([
                    'organization_id' => $orgId, 'type' => $type, 'idp_name' => $name, 'enabled' => $enabled,
                    'sp_entity_id' => url('/'), 'idp_entity_id' => "https://idp.{$slug}.example/".Str::slug($name),
                    'sso_url' => "https://idp.{$slug}.example/sso",
                ]);
            }
        }
        if (! ScimToken::withoutGlobalScopes()->where('organization_id', $orgId)->exists()) {
            ScimToken::withoutGlobalScopes()->create([
                'organization_id' => $orgId, 'name' => 'Entra ID provisioning',
                'token_hash' => hash('sha256', Str::random(48)), 'token_prefix' => 'scim_demo',
                'scopes' => ['users:r', 'users:w'], 'expires_at' => now()->addYear(),
                'created_by' => $admin->id,
            ]);
        }
    }

    private function copilot(int $orgId, User $admin): void
    {
        if (CopilotConversation::withoutGlobalScopes()->where('organization_id', $orgId)->exists()) {
            return;
        }
        $risks = Risk::withoutGlobalScopes()->where('organization_id', $orgId)->orderByDesc('inherent_score')->limit(3)->get();
        $controls = Control::withoutGlobalScopes()->where('organization_id', $orgId)->count();
        $total = Risk::withoutGlobalScopes()->where('organization_id', $orgId)->count();

        $reply = "Offline assistant (no language model connected). Your register holds {$total} risks and {$controls} controls.\n"
            .$risks->map(fn ($r) => "• Risk {$r->risk_id_code}: {$r->title} (inherent {$r->inherent_rating}, status {$r->status})")->implode("\n");

        $conv = CopilotConversation::withoutGlobalScopes()->create([
            'organization_id' => $orgId, 'user_id' => $admin->id, 'title' => 'Top risks by inherent score', 'mode' => 'risk',
        ]);
        CopilotMessage::create(['conversation_id' => $conv->id, 'role' => 'user', 'content' => 'Show me the highest risks', 'model' => 'atheris-offline', 'created_at' => now()->subMinutes(30)]);
        CopilotMessage::create(['conversation_id' => $conv->id, 'role' => 'assistant', 'content' => $reply, 'model' => 'atheris-offline', 'created_at' => now()->subMinutes(30)->addSecond()]);
    }
}
