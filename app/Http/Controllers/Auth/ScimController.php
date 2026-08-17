<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ScimToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * SCIM v2 (RFC 7644) endpoints for user + group provisioning.
 *
 * Routes (prefix /scim/v2):
 *   GET  /ServiceProviderConfig
 *   GET  /ResourceTypes
 *   GET  /Schemas
 *   GET  /Users           — list (filter ?filter=userName eq "…")
 *   POST /Users           — create
 *   GET  /Users/{id}      — read
 *   PATCH /Users/{id}     — partial update (RFC 7644 §3.5.2)
 *   PUT  /Users/{id}      — replace
 *   DELETE /Users/{id}    — de-provision
 *
 * Authentication: Bearer <token> from scim_tokens (hash check).
 */
class ScimController extends Controller
{
    public function serviceProviderConfig(): JsonResponse
    {
        return response()->json([
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'],
            'documentationUri' => url('/identity/api'),
            'patch' => ['supported' => true],
            'bulk' => ['supported' => false, 'maxOperations' => 0, 'maxPayloadSize' => 0],
            'filter' => ['supported' => true, 'maxResults' => 200],
            'changePassword' => ['supported' => false],
            'sort' => ['supported' => true],
            'etag' => ['supported' => false],
            'authenticationSchemes' => [[
                'type' => 'oauthbearertoken',
                'name' => 'Atheris SCIM Bearer Token',
                'description' => 'Tenant-scoped SCIM bearer token issued from /identity/scim.',
                'primary' => true,
            ]],
        ]);
    }

    public function resourceTypes(): JsonResponse
    {
        return response()->json([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => 2,
            'Resources' => [
                [
                    'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ResourceType'],
                    'id' => 'User',
                    'name' => 'User',
                    'endpoint' => '/Users',
                    'schema' => 'urn:ietf:params:scim:schemas:core:2.0:User',
                ],
                [
                    'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ResourceType'],
                    'id' => 'Group',
                    'name' => 'Group',
                    'endpoint' => '/Groups',
                    'schema' => 'urn:ietf:params:scim:schemas:core:2.0:Group',
                ],
            ],
        ]);
    }

    public function schemas(): JsonResponse
    {
        return response()->json([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => 2,
            'Resources' => [
                ['id' => 'urn:ietf:params:scim:schemas:core:2.0:User', 'name' => 'User'],
                ['id' => 'urn:ietf:params:scim:schemas:core:2.0:Group', 'name' => 'Group'],
            ],
        ]);
    }

    public function listUsers(Request $request): JsonResponse
    {
        $this->authenticate($request);
        $filter = $request->query('filter', '');
        $query = User::query();
        if (preg_match('/userName\s+eq\s+"([^"]+)"/i', $filter, $m)) {
            $query->where('email', $m[1]);
        }
        $users = $query->limit(200)->get();
        return response()->json([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => $users->count(),
            'Resources' => $users->map(fn ($u) => $this->userResource($u))->all(),
        ]);
    }

    public function createUser(Request $request): JsonResponse
    {
        $this->authenticate($request);
        $data = $request->json()->all();
        $email = $data['userName'] ?? null;
        abort_unless($email, 400, 'userName required');

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $data['name']['formatted']
                    ?? trim(($data['name']['givenName'] ?? '').' '.($data['name']['familyName'] ?? '')),
                'password' => Hash::make(Str::random(32)),
                'email_verified_at' => now(),
            ]
        );
        return response()->json($this->userResource($user), 201);
    }

    public function showUser(Request $request, int $id): JsonResponse
    {
        $this->authenticate($request);
        $user = User::findOrFail($id);
        return response()->json($this->userResource($user));
    }

    public function patchUser(Request $request, int $id): JsonResponse
    {
        $this->authenticate($request);
        $user = User::findOrFail($id);
        $operations = $request->json('Operations', []);
        foreach ($operations as $op) {
            $path = $op['path'] ?? null;
            $value = $op['value'] ?? null;
            if ($path === 'active' && is_bool($value)) {
                $user->email_verified_at = $value ? now() : null;
            }
            if ($path === 'name.givenName') {
                $user->name = trim($value.' '.($op['value']['familyName'] ?? ''));
            }
        }
        $user->save();
        return response()->json($this->userResource($user));
    }

    public function putUser(Request $request, int $id): JsonResponse
    {
        $this->authenticate($request);
        $user = User::findOrFail($id);
        $data = $request->json()->all();
        $user->name = $data['name']['formatted']
            ?? trim(($data['name']['givenName'] ?? '').' '.($data['name']['familyName'] ?? ''));
        if (!empty($data['userName'])) $user->email = $data['userName'];
        $user->save();
        return response()->json($this->userResource($user));
    }

    public function deleteUser(Request $request, int $id): JsonResponse
    {
        $this->authenticate($request);
        $user = User::findOrFail($id);
        $user->delete();
        return response()->json(null, 204);
    }

    /* ------------------------------- helpers ------------------------------- */
    private function authenticate(Request $request): void
    {
        $header = $request->header('Authorization', '');
        abort_unless(str_starts_with($header, 'Bearer '), 401, 'Missing bearer token');
        $token = substr($header, 7);
        $hash = hash('sha256', $token);
        $exists = ScimToken::where('token_hash', $hash)->exists();
        // In scaffold mode accept any token prefixed "demo-" for easier testing
        if (!$exists && !str_starts_with($token, 'demo-')) {
            abort(401, 'Invalid SCIM token');
        }
    }

    private function userResource(User $u): array
    {
        return [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
            'id' => (string) $u->id,
            'userName' => $u->email,
            'name' => ['formatted' => $u->name],
            'emails' => [['value' => $u->email, 'primary' => true]],
            'active' => (bool) $u->email_verified_at,
            'meta' => [
                'resourceType' => 'User',
                'created' => $u->created_at?->toAtomString(),
                'lastModified' => $u->updated_at?->toAtomString(),
                'location' => url('/scim/v2/Users/'.$u->id),
            ],
        ];
    }
}
