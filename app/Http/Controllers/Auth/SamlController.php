<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SsoConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

/**
 * SAML 2.0 Service Provider endpoints.
 *
 * Routes:
 *   GET  /auth/saml/{tenant}/metadata  — SP metadata XML
 *   POST /auth/saml/{tenant}/acs       — ACS (Assertion Consumer Service)
 *   POST /auth/saml/{tenant}/sls       — SLO (Single Logout Service)
 *
 * Library dependency: `php-saml/php-saml` or `aacotroneo/laravel-saml2` for prod.
 * This scaffold emits correct metadata and accepts ACS POST so that IdPs
 * (Entra ID, Okta, Ping) can complete the round-trip during setup.
 */
class SamlController extends Controller
{
    public function metadata(string $tenant)
    {
        $entityId = url("/auth/saml/{$tenant}/metadata");
        $acsUrl = url("/auth/saml/{$tenant}/acs");
        $sloUrl = url("/auth/saml/{$tenant}/sls");

        $xml = <<<XML
<?xml version="1.0"?>
<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata"
                     entityID="{$entityId}">
  <md:SPSSODescriptor AuthnRequestsSigned="false"
                      WantAssertionsSigned="true"
                      protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
    <md:NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress</md:NameIDFormat>
    <md:AssertionConsumerService
      Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST"
      Location="{$acsUrl}"
      index="1" isDefault="true"/>
    <md:SingleLogoutService
      Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect"
      Location="{$sloUrl}"/>
  </md:SPSSODescriptor>
  <md:Organization>
    <md:OrganizationName xml:lang="en">Atheris ITSRM&amp;G</md:OrganizationName>
    <md:OrganizationDisplayName xml:lang="en">Atheris ITSRM&amp;G ({$tenant})</md:OrganizationDisplayName>
    <md:OrganizationURL xml:lang="en">https://atheris.ng</md:OrganizationURL>
  </md:Organization>
  <md:ContactPerson contactType="technical">
    <md:EmailAddress>security@atheris.ng</md:EmailAddress>
  </md:ContactPerson>
</md:EntityDescriptor>
XML;

        return response($xml, 200, ['Content-Type' => 'application/samlmetadata+xml']);
    }

    public function acs(Request $request, string $tenant)
    {
        // Production: validate SAMLResponse signature, decrypt assertion,
        // map NameID to App\Models\User, then Auth::login($user).
        $samlResponse = (string) $request->input('SAMLResponse', '');
        if ($samlResponse === '') {
            return response('Missing SAMLResponse', 400);
        }
        // Log inbound assertion for audit (stub).
        \Log::info('[SAML ACS] received assertion', [
            'tenant' => $tenant,
            'length' => strlen($samlResponse),
            'relay_state' => $request->input('RelayState'),
        ]);

        // In scaffold mode, redirect to dashboard (assumes user already logged in via Laravel Breeze).
        return redirect('/dashboard');
    }

    public function sls(Request $request, string $tenant)
    {
        \Auth::logout();
        return redirect('/');
    }

    public function index()
    {
        $connections = SsoConnection::all();
        return response()->json([
            'connections' => $connections,
            'metadata_url_template' => url('/auth/saml/{tenant}/metadata'),
        ]);
    }
}
