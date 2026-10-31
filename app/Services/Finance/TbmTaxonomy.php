<?php

namespace App\Services\Finance;

use App\Models\Finance\Category;
use App\Models\Finance\GlAccount;

/**
 * The Technology Business Management cost-pool and IT-tower taxonomy (ATH-FIN-001 FIN-01.03),
 * seeded per bank as system rows so a bank can deactivate or extend them but never lose the spine.
 */
final class TbmTaxonomy
{
    /** code => [name, kind, parent_code, default_capex, gl_code] */
    public const ROWS = [
        'CP-HW' => ['Hardware', 'cost_pool', null, true, '1810'],
        'CP-SW' => ['Software', 'cost_pool', null, false, '6201'],
        'CP-LAB' => ['Labour', 'cost_pool', null, false, '6101'],
        'CP-LAB-INT' => ['Internal labour', 'sub_category', 'CP-LAB', false, '6101'],
        'CP-LAB-EXT' => ['External labour', 'sub_category', 'CP-LAB', false, '6102'],
        'CP-OS' => ['Outside Services', 'cost_pool', null, false, '6401'],
        'CP-FAC' => ['Facilities & Power', 'cost_pool', null, false, '6501'],
        'CP-TEL' => ['Telecom', 'cost_pool', null, false, '6301'],
        'CP-CLD' => ['Cloud', 'cost_pool', null, false, '6302'],
        'CP-OTH' => ['Other', 'cost_pool', null, false, '6901'],

        'TW-DC' => ['Data Centre', 'tower', null, false, null],
        'TW-DC-ENT' => ['Enterprise data centre', 'sub_category', 'TW-DC', false, null],
        'TW-DC-OTH' => ['Other facilities', 'sub_category', 'TW-DC', false, null],
        'TW-CMP' => ['Compute', 'tower', null, false, null],
        'TW-CMP-SRV' => ['Servers', 'sub_category', 'TW-CMP', false, null],
        'TW-CMP-MF' => ['Mainframe', 'sub_category', 'TW-CMP', false, null],
        'TW-STO' => ['Storage', 'tower', null, false, null],
        'TW-STO-ONL' => ['Online storage', 'sub_category', 'TW-STO', false, null],
        'TW-STO-BKP' => ['Backup & archive', 'sub_category', 'TW-STO', false, null],
        'TW-NET' => ['Network', 'tower', null, false, null],
        'TW-NET-LAN' => ['LAN/WAN', 'sub_category', 'TW-NET', false, null],
        'TW-NET-VOI' => ['Voice', 'sub_category', 'TW-NET', false, null],
        'TW-NET-INT' => ['Internet & connectivity', 'sub_category', 'TW-NET', false, null],
        'TW-PLT' => ['Platform', 'tower', null, false, null],
        'TW-PLT-DB' => ['Database', 'sub_category', 'TW-PLT', false, null],
        'TW-PLT-MW' => ['Middleware', 'sub_category', 'TW-PLT', false, null],
        'TW-OUT' => ['Output', 'tower', null, false, null],
        'TW-OUT-PRT' => ['Print', 'sub_category', 'TW-OUT', false, null],
        'TW-EU' => ['End User', 'tower', null, false, null],
        'TW-EU-WKS' => ['Workspace devices', 'sub_category', 'TW-EU', false, null],
        'TW-EU-COL' => ['Collaboration & productivity', 'sub_category', 'TW-EU', false, null],
        'TW-EU-SD' => ['Service desk', 'sub_category', 'TW-EU', false, null],
        'TW-APP' => ['Application', 'tower', null, false, null],
        'TW-APP-DEV' => ['Development', 'sub_category', 'TW-APP', false, null],
        'TW-APP-SUP' => ['Support & operations', 'sub_category', 'TW-APP', false, null],
        'TW-APP-SAAS' => ['Business software / SaaS', 'sub_category', 'TW-APP', false, null],
        'TW-DEL' => ['Delivery', 'tower', null, false, null],
        'TW-DEL-PM' => ['Project & programme management', 'sub_category', 'TW-DEL', false, null],
        'TW-DEL-ITSM' => ['IT service management', 'sub_category', 'TW-DEL', false, null],
        'TW-DEL-OPS' => ['Operations centre', 'sub_category', 'TW-DEL', false, null],
        'TW-SEC' => ['Security & Compliance', 'tower', null, false, null],
        'TW-SEC-SEC' => ['Security', 'sub_category', 'TW-SEC', false, null],
        'TW-SEC-CMP' => ['Compliance', 'sub_category', 'TW-SEC', false, null],
        'TW-SEC-DR' => ['Disaster recovery', 'sub_category', 'TW-SEC', false, null],
        'TW-ITM' => ['IT Management', 'tower', null, false, null],
        'TW-ITM-STR' => ['IT management & strategy', 'sub_category', 'TW-ITM', false, null],
        'TW-ITM-EA' => ['Enterprise architecture', 'sub_category', 'TW-ITM', false, null],
        'TW-ITM-FIN' => ['IT finance', 'sub_category', 'TW-ITM', false, null],
        'TW-ITM-VM' => ['Vendor management', 'sub_category', 'TW-ITM', false, null],
    ];

    public static function count(): int
    {
        return count(self::ROWS);
    }

    /** Idempotent: creates the missing system rows for one organisation, never touching edited ones. */
    public static function seed(int $orgId): void
    {
        $gl = GlAccount::withoutGlobalScopes()->where('organization_id', $orgId)->pluck('id', 'code');
        $ids = Category::withoutGlobalScopes()->withTrashed()->where('organization_id', $orgId)->pluck('id', 'code')->all();
        $order = 0;

        foreach (self::ROWS as $code => [$name, $kind, $parent, $capex, $glCode]) {
            $order++;
            if (isset($ids[$code])) {
                continue; // already provisioned (possibly edited or archived): never touched
            }
            $row = Category::withoutGlobalScopes()->withTrashed()->firstOrCreate(
                ['organization_id' => $orgId, 'code' => $code],
                [
                    'name' => $name,
                    'kind' => $kind,
                    'parent_id' => $parent ? ($ids[$parent] ?? null) : null,
                    'tbm_reference' => $kind === 'cost_pool' ? 'TBM v4.0 cost pool' : ($kind === 'tower' ? 'TBM v4.0 IT tower' : null),
                    'default_capex' => $capex,
                    'default_gl_account_id' => $glCode ? ($gl[$glCode] ?? null) : null,
                    'is_system' => true,
                    'is_active' => true,
                    'sort_order' => $order,
                ],
            );
            $ids[$code] = $row->id;
        }
    }
}
