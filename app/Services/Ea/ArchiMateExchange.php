<?php

namespace App\Services\Ea;

use App\Models\Ea\Capability;
use App\Models\Ea\EaApplication;
use App\Models\Ea\EaInterface;
use App\Models\Ea\InfoDomain;
use App\Models\Ea\LogicalEntity;
use App\Models\Ea\Plateau;
use App\Models\Ea\Principle;
use App\Models\Ea\Process;
use App\Models\Ea\Relationship;
use App\Models\Ea\TechComponent;
use App\Models\Ea\ValueStream;
use Illuminate\Support\Str;

/**
 * Serialiser + parser for The Open Group ArchiMate 3.2 Open Exchange File
 * Format. Produces XML conforming to `ArchiMate3.0_Exchange_v3.2.xsd` and
 * accepts the same shape on import.
 *
 * The implementation is intentionally library-free so the round-trip is fully
 * inspectable.
 */
class ArchiMateExchange
{
    public const NAMESPACE = 'http://www.opengroup.org/xsd/archimate/3.0/';

    /**
     * The single registry of what gets exported: model class => [id prefix,
     * exchange element type, description column].
     *
     * One place, because WS 4.4 turns interchange into a release gate and the
     * gate's tenth check asserts that every type this exports maps back to a
     * model. Two lists would drift and the gate would start failing for a
     * bookkeeping reason.
     *
     * Type choices worth noting: a security `Zone` is a `Grouping`, not a `Node`
     * — a zone is a boundary, and typing it as a node would have Archi draw
     * infrastructure that does not exist. A `Site` is a `Facility`, which is what
     * ArchiMate's physical layer calls a data centre.
     */
    public const REGISTRY = [
        // Strategy
        Capability::class => ['cap', 'Capability', 'description'],
        ValueStream::class => ['vs', 'ValueStream', 'description'],
        \App\Models\Ea\CourseOfAction::class => ['coa', 'CourseOfAction', 'description'],
        // Business
        Process::class => ['proc', 'BusinessProcess', null],
        \App\Models\Ea\BusinessService::class => ['bsvc', 'BusinessService', 'description'],
        InfoDomain::class => ['domain', 'BusinessObject', 'description'],
        // Application
        EaApplication::class => ['app', 'ApplicationComponent', 'description'],
        EaInterface::class => ['iface', 'ApplicationInterface', null],
        \App\Models\Ea\EaApi::class => ['api', 'ApplicationService', 'description'],
        LogicalEntity::class => ['le', 'DataObject', 'description'],
        // Technology & physical
        TechComponent::class => ['tech', 'Node', 'vendor'],
        \App\Models\Ea\Zone::class => ['zone', 'Grouping', 'description'],
        \App\Models\Ea\Site::class => ['site', 'Facility', null],
        // Motivation
        Principle::class => ['prin', 'Principle', 'statement'],
        \App\Models\Ea\Standard::class => ['std', 'Requirement', 'description'],
        \App\Models\Ea\Goal::class => ['goal', 'Goal', 'description'],
        \App\Models\Ea\Driver::class => ['drv', 'Driver', 'description'],
        \App\Models\Ea\Stakeholder::class => ['stk', 'Stakeholder', 'description'],
        \App\Models\Ea\Outcome::class => ['out', 'Outcome', 'description'],
        // Implementation & migration
        \App\Models\Ea\Initiative::class => ['init', 'WorkPackage', 'description'],
        \App\Models\Ea\Solution::class => ['sol', 'Deliverable', 'description'],
        Plateau::class => ['plat', 'Plateau', 'description'],
    ];

    /**
     * Build the round-trip XML for the current tenant's repository.
     *
     * @return array{xml:string, element_count:int, relationship_count:int, skipped_relationships:int}
     */
    public function export(?int $tenantId = null): array
    {
        $elements = [];
        $identifiers = [];   // "Class|id" => exchange identifier

        foreach (self::REGISTRY as $class => [$prefix, $type, $descriptionColumn]) {
            if (! class_exists($class)) {
                continue;
            }

            $rows = $class::query()
                ->when($tenantId, fn ($q) => $q->where('organization_id', $tenantId))
                ->orderBy('id')   // deterministic ordering — gate check 8
                ->get();

            foreach ($rows as $row) {
                $identifier = $this->idFor($prefix, $row->id);
                $identifiers[$class.'|'.$row->id] = $identifier;

                // Every element must carry a name (gate check 9); fall back to
                // the code, then to the identifier, rather than emitting blank.
                $name = $row->name ?: ($row->code ?: $identifier);
                $description = $descriptionColumn ? ($row->{$descriptionColumn} ?? null) : null;

                $elements[] = [$identifier, $type, $name, $description];
            }
        }

        $relationships = [];
        $skipped = 0;

        // Interfaces are also relationships: the connection catalogue CBN's RBCF
        // App. II §1.1(i) asks about is app → interface → app.
        $interfaces = EaInterface::query()
            ->when($tenantId, fn ($q) => $q->where('organization_id', $tenantId))
            ->orderBy('id')->get(['id', 'name', 'source_app_id', 'target_app_id']);

        foreach ($interfaces as $interface) {
            foreach ([['source_app_id', 'in'], ['target_app_id', 'out']] as [$column, $direction]) {
                if (! $interface->{$column}) {
                    continue;
                }
                $appKey = EaApplication::class.'|'.$interface->{$column};
                $interfaceKey = EaInterface::class.'|'.$interface->id;
                if (! isset($identifiers[$appKey], $identifiers[$interfaceKey])) {
                    $skipped++;

                    continue;
                }
                $relationships[] = [
                    'id' => "rel-iface-{$direction}-{$interface->id}",
                    'source' => $direction === 'in' ? $identifiers[$appKey] : $identifiers[$interfaceKey],
                    'target' => $direction === 'in' ? $identifiers[$interfaceKey] : $identifiers[$appKey],
                    // The calling component is assigned its interface; the
                    // interface serves the component that consumes it. Typing
                    // both ends `Flow` imports into Archi as two unrelated
                    // arrows between three boxes.
                    'type' => $direction === 'in' ? 'Assignment' : 'Serving',
                    'name' => $interface->name,
                ];
            }
        }

        $rels = Relationship::query()
            ->when($tenantId, fn ($q) => $q->where('organization_id', $tenantId))
            ->orderBy('id')->get();

        foreach ($rels as $relationship) {
            $source = $identifiers[$relationship->source_type.'|'.$relationship->source_id] ?? null;
            $target = $identifiers[$relationship->target_type.'|'.$relationship->target_id] ?? null;

            // A relationship whose endpoint was not exported must be dropped,
            // not emitted with a dangling reference: Archi answers a dangling
            // reference with an empty view rather than an error, which is the
            // worst possible failure mode in an evaluation.
            if (! $source || ! $target) {
                $skipped++;

                continue;
            }

            $relationships[] = [
                'id' => 'rel-'.$relationship->id,
                'source' => $source,
                'target' => $target,
                'type' => $this->exchangeRelationType($relationship->relation_type),
                'name' => null,
            ];
        }

        $xml = $this->renderXml($elements, $relationships);

        return [
            'xml' => $xml,
            'element_count' => count($elements),
            'relationship_count' => count($relationships),
            'skipped_relationships' => $skipped,
        ];
    }

    /**
     * Internal relation name → exchange type name.
     *
     * The mapping lives in {@see ArchiMateRoundTrip::RELATION_TO_EXCHANGE} and
     * matters more than it looks: the standard spells these `Realization` and
     * `Specialization`, while the module's internal vocabulary is British.
     * `ucfirst('realises')` produces `Realises`, which Archi drops silently.
     */
    private function exchangeRelationType(?string $relation): string
    {
        $key = strtolower(str_replace([' ', '_'], ['', ''], (string) $relation));

        return ArchiMateRoundTrip::RELATION_TO_EXCHANGE[$key]
            ?? ArchiMateRoundTrip::RELATION_TO_EXCHANGE[strtolower((string) $relation)]
            ?? 'Association';
    }

    /**
     * Parse an ArchiMate Open Exchange XML document and return a structured
     * representation suitable for an importer to consume.
     *
     * @return array{elements:array<int,array<string,string|null>>, relationships:array<int,array<string,string|null>>}
     */
    public function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $doc = new \SimpleXMLElement($xml);
        libxml_use_internal_errors($previous);

        // Many ArchiMate XML producers (Archi, Sparx) emit different default namespace prefixes.
        $namespaces = $doc->getDocNamespaces(true);
        $alias = '';
        if (!empty($namespaces[''])) {
            $doc->registerXPathNamespace('a', $namespaces['']);
            $alias = 'a:';
        }

        $elements = [];
        foreach ($doc->xpath('//'.$alias.'elements/'.$alias.'element') ?: [] as $el) {
            $attrs = $el->attributes('xsi', true);
            $name = (string) ($el->{'name'} ?? '');
            $description = (string) ($el->{'documentation'} ?? '');
            $elements[] = [
                'identifier' => (string) ($el['identifier'] ?? Str::random(8)),
                'type' => (string) ($attrs['type'] ?? $el['type'] ?? 'ApplicationComponent'),
                'name' => $name,
                'description' => $description,
            ];
        }

        $relationships = [];
        foreach ($doc->xpath('//'.$alias.'relationships/'.$alias.'relationship') ?: [] as $rel) {
            $attrs = $rel->attributes('xsi', true);
            $relationships[] = [
                'identifier' => (string) ($rel['identifier'] ?? Str::random(8)),
                'source' => (string) ($rel['source'] ?? ''),
                'target' => (string) ($rel['target'] ?? ''),
                'type' => (string) ($attrs['type'] ?? $rel['type'] ?? 'Association'),
                'name' => (string) ($rel->{'name'} ?? ''),
            ];
        }

        return ['elements' => $elements, 'relationships' => $relationships];
    }

    public function exportToDisk(string $relativePath, ?int $tenantId = null): array
    {
        $payload = $this->export($tenantId);
        \Illuminate\Support\Facades\Storage::disk('local')->put(ltrim($relativePath, '/'), $payload['xml']);
        return $payload + ['file_path' => $relativePath, 'sha256' => hash('sha256', $payload['xml']), 'bytes' => strlen($payload['xml'])];
    }

    private function renderXml(array $elements, array $relationships): string
    {
        $ns = self::NAMESPACE;
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= "<model xmlns=\"{$ns}\" xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" identifier=\"".$this->idFor('model', time())."\">\n";
        $xml .= "  <name xml:lang=\"en\">NexusRisk EA-Studio export</name>\n";
        $xml .= "  <documentation xml:lang=\"en\">Generated by Atheris NexusRisk on ".date('c').".</documentation>\n";

        $xml .= "  <elements>\n";
        foreach ($elements as [$id, $type, $name, $description]) {
            $xml .= '    <element identifier="'.htmlspecialchars($id).'" xsi:type="'.htmlspecialchars($type).'">'."\n";
            $xml .= '      <name xml:lang="en">'.htmlspecialchars((string) $name).'</name>'."\n";
            if (!empty($description)) {
                $xml .= '      <documentation xml:lang="en">'.htmlspecialchars((string) $description).'</documentation>'."\n";
            }
            $xml .= "    </element>\n";
        }
        $xml .= "  </elements>\n";

        $xml .= "  <relationships>\n";
        foreach ($relationships as $r) {
            $xml .= '    <relationship identifier="'.htmlspecialchars($r['id']).'"'.
                ' source="'.htmlspecialchars($r['source']).'"'.
                ' target="'.htmlspecialchars($r['target']).'"'.
                ' xsi:type="'.htmlspecialchars($r['type']).'">'."\n";
            if (!empty($r['name'])) {
                $xml .= '      <name xml:lang="en">'.htmlspecialchars($r['name']).'</name>'."\n";
            }
            $xml .= "    </relationship>\n";
        }
        $xml .= "  </relationships>\n";

        $xml .= "</model>\n";
        return $xml;
    }

    private function idFor(string $kind, int $id): string
    {
        return "id-{$kind}-{$id}";
    }

    /** Exchange identifier for a model instance, or null when its type is not exported. */
    public function identifierFor(string $class, int $id): ?string
    {
        $prefix = self::REGISTRY[$class][0] ?? null;

        return $prefix ? $this->idFor($prefix, $id) : null;
    }
}
