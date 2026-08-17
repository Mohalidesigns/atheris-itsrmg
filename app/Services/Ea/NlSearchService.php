<?php

namespace App\Services\Ea;

use App\Models\Ea\Capability;
use App\Models\Ea\DataFlow;
use App\Models\Ea\EaApi;
use App\Models\Ea\EaApplication;
use App\Models\Ea\EaInterface;
use App\Models\Ea\InfoDomain;
use App\Models\Ea\Initiative;
use App\Models\Ea\LogicalEntity;
use App\Models\Ea\Plateau;
use App\Models\Ea\Principle;
use App\Models\Ea\Process;
use App\Models\Ea\Standard;
use App\Models\Ea\TechComponent;
use App\Models\Ea\ValueStream;
use App\Models\Ea\Zone;
use Illuminate\Support\Str;

/**
 * NlSearchService — keyword + intent-based search across the EA repository.
 *
 * The service interprets a natural-language phrase by:
 *   1. Tokenising the query
 *   2. Looking for known intents (e.g. "critical", "pii", "eol", "cross-border")
 *   3. Falling back to a substring scan across the searchable columns of every
 *      relevant entity table
 *
 * It is a deliberate stand-in for the platform LLM gateway: when a Copilot
 * service becomes available the same interface can be back-ended by it
 * without changing the controller layer.
 */
class NlSearchService
{
    public function search(string $query, int $limit = 25): array
    {
        $q = trim($query);
        if ($q === '') {
            return ['hits' => [], 'intents' => [], 'count' => 0];
        }
        $lower = strtolower($q);
        $tokens = array_filter(preg_split('/\s+/', $lower));
        $intents = $this->detectIntents($tokens);

        // Build the searchable set per entity, then filter against intents.
        $hits = [];

        $hits = array_merge($hits, $this->scan(EaApplication::query(), ['code', 'name', 'description', 'owner_role'], $q, 'application', function ($a) use ($intents) {
            if (in_array('critical', $intents, true) && $a->criticality !== 'critical') return false;
            if (in_array('retired', $intents, true) && $a->lifecycle !== 'retired') return false;
            return true;
        }));
        $hits = array_merge($hits, $this->scan(Capability::query(), ['code', 'name', 'description'], $q, 'capability'));
        $hits = array_merge($hits, $this->scan(TechComponent::query(), ['code', 'name', 'vendor', 'category'], $q, 'tech', function ($t) use ($intents) {
            if (in_array('eol', $intents, true)) {
                return $t->eol_date && $t->eol_date->between(now()->subYear(), now()->addYear());
            }
            return true;
        }));
        $hits = array_merge($hits, $this->scan(EaInterface::query(), ['code', 'name', 'protocol'], $q, 'interface'));
        $hits = array_merge($hits, $this->scan(EaApi::query(), ['code', 'name', 'base_url'], $q, 'api'));
        $hits = array_merge($hits, $this->scan(LogicalEntity::query(), ['code', 'name', 'description'], $q, 'logical-entity', function ($e) use ($intents) {
            if (in_array('pii', $intents, true) && !$e->pii_flag) return false;
            return true;
        }));
        $hits = array_merge($hits, $this->scan(DataFlow::query(), ['name', 'protocol', 'classification'], $q, 'data-flow', function ($f) use ($intents) {
            if (in_array('cross-border', $intents, true) && !$f->cross_border) return false;
            return true;
        }));
        $hits = array_merge($hits, $this->scan(Principle::query(), ['code', 'name', 'statement', 'rationale'], $q, 'principle'));
        $hits = array_merge($hits, $this->scan(Standard::query(), ['code', 'name', 'description'], $q, 'standard'));
        $hits = array_merge($hits, $this->scan(Initiative::query(), ['code', 'name', 'description'], $q, 'initiative'));
        $hits = array_merge($hits, $this->scan(Process::query(), ['code', 'name'], $q, 'process'));
        $hits = array_merge($hits, $this->scan(Plateau::query(), ['code', 'name', 'description'], $q, 'plateau'));
        $hits = array_merge($hits, $this->scan(ValueStream::query(), ['code', 'name', 'description'], $q, 'value-stream'));
        $hits = array_merge($hits, $this->scan(InfoDomain::query(), ['code', 'name', 'description'], $q, 'info-domain'));
        $hits = array_merge($hits, $this->scan(Zone::query(), ['code', 'name', 'description'], $q, 'zone'));

        usort($hits, fn ($a, $b) => $b['score'] <=> $a['score']);
        $hits = array_slice($hits, 0, $limit);

        return [
            'query' => $q,
            'intents' => array_values($intents),
            'count' => count($hits),
            'hits' => $hits,
        ];
    }

    private function detectIntents(array $tokens): array
    {
        $intents = [];
        $mapping = [
            'critical' => ['critical', 'criticality'],
            'eol' => ['eol', 'end-of-life', 'sunset', 'obsolete', 'obsolescent'],
            'pii' => ['pii', 'personal', 'sensitive', 'ndpa'],
            'cross-border' => ['cross-border', 'crossborder', 'overseas', 'export'],
            'retired' => ['retired', 'decommissioned', 'dead'],
            'no-owner' => ['ownerless', 'unowned', 'no-owner'],
            'risk' => ['risk', 'exposure'],
        ];
        foreach ($mapping as $intent => $words) {
            if (array_intersect($tokens, $words)) {
                $intents[] = $intent;
            }
        }
        return $intents;
    }

    private function scan($queryBuilder, array $cols, string $q, string $type, ?callable $filter = null): array
    {
        $results = $queryBuilder->where(function ($w) use ($cols, $q) {
            foreach ($cols as $c) {
                $w->orWhere($c, 'like', '%'.$q.'%');
            }
        })->limit(15)->get();

        $hits = [];
        foreach ($results as $row) {
            if ($filter && !$filter($row)) continue;
            $title = $row->name ?? $row->code ?? "#{$row->id}";
            $score = 0;
            foreach ($cols as $c) {
                if (!empty($row->{$c}) && Str::contains(strtolower((string) $row->{$c}), strtolower($q))) {
                    $score += $c === 'name' ? 4 : ($c === 'code' ? 3 : 1);
                }
            }
            $hits[] = [
                'type' => $type,
                'id' => $row->id,
                'code' => $row->code ?? null,
                'title' => $title,
                'preview' => $this->preview($row, $cols),
                'score' => $score,
            ];
        }
        return $hits;
    }

    private function preview($row, array $cols): string
    {
        foreach (['description', 'statement', 'definition'] as $c) {
            if (!empty($row->{$c})) {
                return Str::limit((string) $row->{$c}, 100);
            }
        }
        return '';
    }
}
