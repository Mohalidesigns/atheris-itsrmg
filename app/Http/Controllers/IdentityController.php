<?php

namespace App\Http\Controllers;

use App\Models\ScimToken;
use App\Models\SsoConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;

/** Identity & Access: SSO connections, SCIM tokens and the API portal (ATH-VAL-019). */
class IdentityController extends Controller
{
    public function sso()
    {
        return Inertia::render('Identity/Sso', [
            'connections' => SsoConnection::orderBy('idp_name')->get(),
        ]);
    }

    public function ssoStore(Request $request)
    {
        SsoConnection::create($this->ssoData($request) + ['organization_id' => $request->user()->organization_id]);

        return back()->with('success', 'SSO connection added.');
    }

    public function ssoUpdate(Request $request, int $id)
    {
        SsoConnection::findOrFail($id)->update($this->ssoData($request));

        return back()->with('success', 'SSO connection updated.');
    }

    public function ssoMetadata(int $id)
    {
        $c = SsoConnection::findOrFail($id);
        $entityId = e(url('/'));
        $acs = e(route('saml.acs', ['tenant' => $c->organization_id]));
        $xml = <<<XML
<?xml version="1.0"?>
<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="{$entityId}">
  <md:SPSSODescriptor AuthnRequestsSigned="false" WantAssertionsSigned="true" protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
    <md:NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress</md:NameIDFormat>
    <md:AssertionConsumerService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" Location="{$acs}" index="1" isDefault="true"/>
  </md:SPSSODescriptor>
</md:EntityDescriptor>
XML;

        return response($xml, 200, [
            'Content-Type' => 'application/samlmetadata+xml',
            'Content-Disposition' => 'attachment; filename="sp-metadata-'.Str::slug($c->idp_name).'.xml"',
        ]);
    }

    public function scim()
    {
        return Inertia::render('Identity/Scim', [
            'tokens' => ScimToken::orderByDesc('id')->get(),
        ]);
    }

    public function scimStore(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120']);
        $plain = 'scim_'.Str::random(40);
        ScimToken::create([
            'organization_id' => $request->user()->organization_id,
            'name' => $data['name'],
            'token_hash' => hash('sha256', $plain),
            'token_prefix' => substr($plain, 0, 9),
            'scopes' => ['users:r', 'users:w'],
            'expires_at' => now()->addYear(),
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', "Token created. Copy it now, it is shown once: {$plain}");
    }

    public function scimRevoke(int $id)
    {
        ScimToken::findOrFail($id)->update(['revoked_at' => now()]);

        return back()->with('success', 'SCIM token revoked.');
    }

    public function api()
    {
        $endpoints = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/'))
            ->flatMap(fn ($r) => collect($r->methods())->reject(fn ($m) => in_array($m, ['HEAD', 'OPTIONS']))
                ->map(fn ($m) => [
                    'method' => $m,
                    'path' => '/'.$r->uri(),
                    'description' => $r->getName() ?: 'Requires a Sanctum bearer token',
                ]))
            ->sortBy(['path', 'method'])->values();

        return Inertia::render('Identity/Api', compact('endpoints'));
    }

    private function ssoData(Request $request): array
    {
        return $request->validate([
            'type' => 'required|in:saml,oidc',
            'idp_name' => 'required|string|max:120',
            'idp_entity_id' => 'nullable|string|max:255',
            'sso_url' => 'nullable|url|max:255',
            'certificate' => 'nullable|string|max:20000',
            'enabled' => 'boolean',
        ]) + ['sp_entity_id' => url('/')];
    }
}
