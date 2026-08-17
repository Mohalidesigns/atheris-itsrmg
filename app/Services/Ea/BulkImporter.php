<?php

namespace App\Services\Ea;

use App\Models\Ea\Capability;
use App\Models\Ea\DataFlow;
use App\Models\Ea\EaApplication;
use App\Models\Ea\EaInterface;
use App\Models\Ea\InfoDomain;
use App\Models\Ea\LogicalEntity;
use App\Models\Ea\Principle;
use App\Models\Ea\Standard;
use App\Models\Ea\TechComponent;

/**
 * BulkImporter — accepts CSV payloads for the canonical Phase 1/2 entity set
 * and upserts rows into the matching tables. Provides matching CSV templates
 * for download.
 *
 * Importable types: capability, application, tech-component, info-domain,
 * logical-entity, data-flow, interface, principle, standard.
 */
class BulkImporter
{
    public const TEMPLATES = [
        'capability' => ['code', 'name', 'parent_code', 'level', 'criticality', 'maturity', 'owner_role', 'source'],
        'application' => ['code', 'name', 'lifecycle', 'time_score', 'business_fit', 'technical_fit', 'criticality', 'annual_cost_ngn', 'user_count', 'owner_role'],
        'tech-component' => ['code', 'name', 'category', 'vendor', 'version', 'radar_status', 'eol_date', 'eos_date', 'cpe'],
        'info-domain' => ['code', 'name', 'owner_role', 'classification', 'description'],
        'logical-entity' => ['code', 'name', 'domain_code', 'classification', 'pii_flag', 'description'],
        'data-flow' => ['code', 'name', 'source_entity_code', 'target_entity_code', 'protocol', 'cross_border', 'classification'],
        'interface' => ['code', 'name', 'source_app_code', 'target_app_code', 'protocol', 'pattern', 'classification', 'status'],
        'principle' => ['code', 'name', 'statement', 'rationale', 'implications', 'status'],
        'standard' => ['code', 'name', 'category', 'description', 'radar_status', 'status'],
    ];

    /**
     * @return array{created:int, updated:int, errors:array<int,string>}
     */
    public function import(string $type, string $csvText): array
    {
        $type = strtolower($type);
        if (! isset(self::TEMPLATES[$type])) {
            return ['created' => 0, 'updated' => 0, 'errors' => ["Unknown import type '$type'."]];
        }

        $rows = $this->parseCsv($csvText);
        if (empty($rows)) {
            return ['created' => 0, 'updated' => 0, 'errors' => ['CSV body is empty.']];
        }

        $header = array_shift($rows);
        $expected = self::TEMPLATES[$type];
        $missing = array_diff($expected, $header);
        if (!empty($missing)) {
            return ['created' => 0, 'updated' => 0, 'errors' => ['Missing columns: '.implode(', ', $missing)]];
        }

        $created = 0; $updated = 0; $errors = [];
        foreach ($rows as $i => $row) {
            try {
                $assoc = $this->zipRow($header, $row);
                $touch = $this->upsertRow($type, $assoc);
                if ($touch === 'created') $created++;
                elseif ($touch === 'updated') $updated++;
            } catch (\Throwable $e) {
                $errors[] = 'Row '.($i + 2).': '.$e->getMessage();
            }
        }
        return compact('created', 'updated', 'errors');
    }

    public function template(string $type): string
    {
        $type = strtolower($type);
        $cols = self::TEMPLATES[$type] ?? [];
        return implode(',', $cols)."\n";
    }

    protected function upsertRow(string $type, array $r): string
    {
        return match ($type) {
            'capability' => $this->upsertCapability($r),
            'application' => $this->upsertApp($r),
            'tech-component' => $this->upsertTech($r),
            'info-domain' => $this->upsertDomain($r),
            'logical-entity' => $this->upsertLogical($r),
            'data-flow' => $this->upsertFlow($r),
            'interface' => $this->upsertInterface($r),
            'principle' => $this->upsertPrinciple($r),
            'standard' => $this->upsertStandard($r),
            default => 'skipped',
        };
    }

    protected function upsertCapability(array $r): string
    {
        $parentId = null;
        if (!empty($r['parent_code'])) {
            $parentId = Capability::where('code', $r['parent_code'])->value('id');
        }
        $existing = Capability::where('code', $r['code'])->first();
        $attrs = [
            'name' => $r['name'],
            'parent_id' => $parentId,
            'level' => (int) ($r['level'] ?: 1),
            'criticality' => $r['criticality'] ?: 'medium',
            'maturity' => (int) ($r['maturity'] ?: 1),
            'owner_role' => $r['owner_role'] ?: null,
            'source' => $r['source'] ?: 'custom',
        ];
        if ($existing) { $existing->update($attrs); return 'updated'; }
        Capability::create(['code' => $r['code']] + $attrs);
        return 'created';
    }

    protected function upsertApp(array $r): string
    {
        $existing = EaApplication::where('code', $r['code'])->first();
        $attrs = [
            'name' => $r['name'],
            'lifecycle' => $r['lifecycle'] ?: 'live',
            'time_score' => $r['time_score'] ?: null,
            'business_fit' => $r['business_fit'] ? (int) $r['business_fit'] : null,
            'technical_fit' => $r['technical_fit'] ? (int) $r['technical_fit'] : null,
            'criticality' => $r['criticality'] ?: 'medium',
            'annual_cost_ngn' => $r['annual_cost_ngn'] ? (float) $r['annual_cost_ngn'] : null,
            'user_count' => $r['user_count'] ? (int) $r['user_count'] : null,
            'owner_role' => $r['owner_role'] ?: null,
        ];
        if ($existing) { $existing->update($attrs); return 'updated'; }
        EaApplication::create(['code' => $r['code']] + $attrs);
        return 'created';
    }

    protected function upsertTech(array $r): string
    {
        $existing = TechComponent::where('code', $r['code'])->first();
        $attrs = [
            'name' => $r['name'],
            'category' => $r['category'] ?: null,
            'vendor' => $r['vendor'] ?: null,
            'version' => $r['version'] ?: null,
            'radar_status' => $r['radar_status'] ?: 'assess',
            'eol_date' => $r['eol_date'] ?: null,
            'eos_date' => $r['eos_date'] ?: null,
            'cpe' => $r['cpe'] ?? null,
        ];
        if ($existing) { $existing->update($attrs); return 'updated'; }
        TechComponent::create(['code' => $r['code']] + $attrs);
        return 'created';
    }

    protected function upsertDomain(array $r): string
    {
        $existing = InfoDomain::where('code', $r['code'])->first();
        $attrs = [
            'name' => $r['name'],
            'owner_role' => $r['owner_role'] ?: null,
            'classification' => $r['classification'] ?: 'Internal',
            'description' => $r['description'] ?? null,
        ];
        if ($existing) { $existing->update($attrs); return 'updated'; }
        InfoDomain::create(['code' => $r['code']] + $attrs);
        return 'created';
    }

    protected function upsertLogical(array $r): string
    {
        $domainId = $r['domain_code'] ? InfoDomain::where('code', $r['domain_code'])->value('id') : null;
        $existing = LogicalEntity::where('code', $r['code'])->first();
        $attrs = [
            'name' => $r['name'],
            'domain_id' => $domainId,
            'classification' => $r['classification'] ?: 'Internal',
            'pii_flag' => filter_var($r['pii_flag'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'description' => $r['description'] ?? null,
        ];
        if ($existing) { $existing->update($attrs); return 'updated'; }
        LogicalEntity::create(['code' => $r['code']] + $attrs);
        return 'created';
    }

    protected function upsertFlow(array $r): string
    {
        $src = $r['source_entity_code'] ? LogicalEntity::where('code', $r['source_entity_code'])->value('id') : null;
        $tgt = $r['target_entity_code'] ? LogicalEntity::where('code', $r['target_entity_code'])->value('id') : null;
        $existing = DataFlow::where('name', $r['name'])->first();
        $attrs = [
            'source_entity_id' => $src,
            'target_entity_id' => $tgt,
            'protocol' => $r['protocol'] ?: null,
            'cross_border' => filter_var($r['cross_border'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'classification' => $r['classification'] ?: 'Internal',
        ];
        if ($existing) { $existing->update($attrs); return 'updated'; }
        DataFlow::create(['name' => $r['name']] + $attrs);
        return 'created';
    }

    protected function upsertInterface(array $r): string
    {
        $src = $r['source_app_code'] ? EaApplication::where('code', $r['source_app_code'])->value('id') : null;
        $tgt = $r['target_app_code'] ? EaApplication::where('code', $r['target_app_code'])->value('id') : null;
        $existing = EaInterface::where('code', $r['code'])->first();
        $attrs = [
            'name' => $r['name'],
            'source_app_id' => $src,
            'target_app_id' => $tgt,
            'protocol' => $r['protocol'] ?: null,
            'pattern' => $r['pattern'] ?: null,
            'classification' => $r['classification'] ?: 'Internal',
            'status' => $r['status'] ?: 'active',
        ];
        if ($existing) { $existing->update($attrs); return 'updated'; }
        EaInterface::create(['code' => $r['code']] + $attrs);
        return 'created';
    }

    protected function upsertPrinciple(array $r): string
    {
        $existing = Principle::where('code', $r['code'])->first();
        $attrs = [
            'name' => $r['name'],
            'statement' => $r['statement'] ?? null,
            'rationale' => $r['rationale'] ?? null,
            'implications' => $r['implications'] ?? null,
            'status' => $r['status'] ?: 'active',
        ];
        if ($existing) { $existing->update($attrs); return 'updated'; }
        Principle::create(['code' => $r['code']] + $attrs);
        return 'created';
    }

    protected function upsertStandard(array $r): string
    {
        $existing = Standard::where('code', $r['code'])->first();
        $attrs = [
            'name' => $r['name'],
            'category' => $r['category'] ?? null,
            'description' => $r['description'] ?? null,
            'radar_status' => $r['radar_status'] ?: 'adopt',
            'status' => $r['status'] ?: 'active',
        ];
        if ($existing) { $existing->update($attrs); return 'updated'; }
        Standard::create(['code' => $r['code']] + $attrs);
        return 'created';
    }

    protected function parseCsv(string $text): array
    {
        $rows = [];
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $text);
        rewind($fh);
        while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;
            }
            $rows[] = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row);
        }
        fclose($fh);
        return $rows;
    }

    protected function zipRow(array $header, array $row): array
    {
        $assoc = [];
        foreach ($header as $i => $col) {
            $assoc[$col] = $row[$i] ?? null;
        }
        return $assoc;
    }
}
