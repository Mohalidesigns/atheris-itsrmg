<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Creates one login per role so every permission level can be tested.
 *
 * All accounts use the password: Password@123
 *
 *   superadmin@atheris.ng      → Super Admin
 *   orgadmin@atheris.ng        → Organization Admin
 *   riskmanager@atheris.ng     → Risk Manager
 *   compliance@atheris.ng      → Compliance Officer
 *   security@atheris.ng        → Security Analyst
 *   auditor@atheris.ng         → Auditor
 *   viewer@atheris.ng          → Viewer
 *
 * Users are attached to the Kano Heritage Bank organization (the fully
 * populated demo dataset) so every module shows real data for each role.
 */
class RoleDemoUsersSeeder extends Seeder
{
    public const PASSWORD = 'Password@123';

    public function run(): void
    {
        $orgId = Organization::where('slug', 'kano-heritage-bank')->value('id')
            ?? Organization::query()->value('id');

        if (! $orgId) {
            $this->command?->warn('RoleDemoUsersSeeder skipped: no organization found. Run organization seeders first.');

            return;
        }

        $users = [
            ['Sadiq Umar',      'superadmin@atheris.ng',  'Super Admin',        'Platform Owner',            'Platform'],
            ['Amina Lawal',     'orgadmin@atheris.ng',    'Organization Admin', 'Head of GRC',               'Governance'],
            ['Efe Oghenekaro',  'riskmanager@atheris.ng', 'Risk Manager',       'Senior Risk Manager',       'Enterprise Risk'],
            ['Halima Sule',     'compliance@atheris.ng',  'Compliance Officer', 'Compliance Manager',        'Compliance'],
            ['Dubem Okafor',    'security@atheris.ng',    'Security Analyst',   'SOC Analyst',               'Security Operations'],
            ['Ronke Ajayi',     'auditor@atheris.ng',     'Auditor',            'Senior IT Auditor',         'Internal Audit'],
            ['Bashir Tanko',    'viewer@atheris.ng',      'Viewer',             'Business Stakeholder',      'Operations'],
        ];

        foreach ($users as [$name, $email, $role, $title, $dept]) {
            if (! Role::where('name', $role)->exists()) {
                $this->command?->warn("RoleDemoUsersSeeder: role '{$role}' not found — run RolesAndPermissionsSeeder first. Skipping {$email}.");

                continue;
            }

            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make(self::PASSWORD),
                    'organization_id' => $orgId,
                    'job_title' => $title,
                    'department' => $dept,
                    'email_verified_at' => now(),
                ]
            );

            $user->syncRoles($role);
        }

        $this->command?->info('Role demo users seeded — password for all: '.self::PASSWORD);
    }
}
