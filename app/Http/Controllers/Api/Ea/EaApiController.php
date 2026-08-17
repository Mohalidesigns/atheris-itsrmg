<?php

namespace App\Http\Controllers\Api\Ea;

use App\Http\Controllers\Controller;
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
use App\Repositories\Ea\EaRepository;
use App\Services\Ea\AnomalyEngine;
use App\Services\Ea\ArchiMateExchange;
use App\Services\Ea\BlastRadiusService;
use App\Services\Ea\BulkImporter;
use App\Services\Ea\ControlInheritanceService;
use App\Services\Ea\KriCalculator;
use App\Services\Ea\McpServer;
use App\Services\Ea\NlSearchService;
use App\Services\Ea\ScenarioComparer;
use App\Services\Ea\ViewpointGenerator;
use Illuminate\Http\Request;

/**
 * REST API controller for /api/v1/ea/* — exposes a subset of the EA repository
 * to other modules and external integrations. Read endpoints are paginated;
 * write endpoints go through the EaRepository so the audit trail is preserved.
 *
 * The endpoint set is intentionally narrow at v1 — the controller is the
 * gatekeeper for which entities are externally visible.
 */
class EaApiController extends Controller
{
    public function __construct(protected EaRepository $repo)
    {
    }

    public function capabilities(Request $r)
    {
        return Capability::query()
            ->when($r->query('level'), fn ($q, $v) => $q->where('level', $v))
            ->when($r->query('source'), fn ($q, $v) => $q->where('source', $v))
            ->when($r->query('search'), fn ($q, $v) => $q->where('name', 'like', "%$v%"))
            ->paginate(min(100, (int) $r->query('per_page', 50)));
    }

    public function capabilityShow(Capability $capability)
    {
        return $capability->load('parent', 'children', 'plateau');
    }

    public function capabilityStore(Request $r)
    {
        $data = $r->validate(['code' => 'required|string', 'name' => 'required|string']);
        return $this->repo->create(Capability::class, $r->all());
    }

    public function applications(Request $r)
    {
        return EaApplication::query()
            ->when($r->query('criticality'), fn ($q, $v) => $q->where('criticality', $v))
            ->when($r->query('lifecycle'), fn ($q, $v) => $q->where('lifecycle', $v))
            ->when($r->query('search'), fn ($q, $v) => $q->where('name', 'like', "%$v%"))
            ->paginate(min(100, (int) $r->query('per_page', 50)));
    }

    public function techComponents(Request $r)
    {
        return TechComponent::query()
            ->when($r->query('radar_status'), fn ($q, $v) => $q->where('radar_status', $v))
            ->when($r->query('eol_within_months'), fn ($q, $v) => $q->whereBetween('eol_date', [now(), now()->addMonths((int) $v)]))
            ->paginate(min(100, (int) $r->query('per_page', 50)));
    }

    public function interfaces(Request $r)
    {
        return EaInterface::with('sourceApp:id,name', 'targetApp:id,name')->paginate(50);
    }

    public function apis() { return EaApi::with('interface:id,name')->paginate(50); }
    public function valueStreams() { return ValueStream::paginate(50); }
    public function processes() { return Process::paginate(50); }
    public function infoDomains() { return InfoDomain::paginate(50); }
    public function logicalEntities() { return LogicalEntity::paginate(50); }
    public function dataFlows() { return DataFlow::with('source:id,name', 'target:id,name')->paginate(50); }
    public function zones() { return Zone::with('assignments:id,zone_id,application_id')->paginate(50); }
    public function principles() { return Principle::paginate(50); }
    public function standards() { return Standard::paginate(50); }
    public function initiatives() { return Initiative::paginate(50); }
    public function plateaux() { return Plateau::paginate(50); }

    public function blastRadius(Request $r)
    {
        $id = (int) $r->query('app_id');
        $depth = min(4, (int) $r->query('depth', 3));
        return (new BlastRadiusService())->changeImpact($id, $depth);
    }

    public function viewpoint(string $viewpoint)
    {
        return (new ViewpointGenerator())->generate($viewpoint);
    }

    public function search(Request $r)
    {
        return (new NlSearchService())->search((string) $r->query('q', ''), 25);
    }

    public function scenarios(Request $r)
    {
        $a = (int) $r->query('a'); $b = (int) $r->query('b');
        if (!$a || !$b || $a === $b) {
            return response()->json(['error' => 'Provide two distinct plateau ids ?a=&b='], 422);
        }
        return (new ScenarioComparer())->compare($a, $b);
    }

    public function controlInheritance(int $applicationId)
    {
        return (new ControlInheritanceService())->resolveForApplication($applicationId);
    }

    public function runAnomalies()
    {
        return (new AnomalyEngine())->run();
    }

    public function recomputeKris()
    {
        return (new KriCalculator())->compute();
    }

    public function exportArchiMate()
    {
        $payload = (new ArchiMateExchange())->export();
        return response($payload['xml'], 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="ea-export.xml"',
            'X-Element-Count' => $payload['element_count'],
            'X-Relationship-Count' => $payload['relationship_count'],
        ]);
    }

    public function importCsv(Request $r)
    {
        $data = $r->validate(['type' => 'required|string', 'csv' => 'required|string']);
        return (new BulkImporter())->import($data['type'], $data['csv']);
    }

    public function mcp(Request $r)
    {
        return (new McpServer())->handle($r->all());
    }

    public function mcpTools()
    {
        return ['tools' => (new McpServer())->tools()];
    }

    public function openapi()
    {
        return response()->json($this->openApiSpec(), 200, ['Content-Type' => 'application/json']);
    }

    protected function openApiSpec(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'NexusRisk EA-Studio API',
                'version' => '1.0.0',
                'description' => 'Read & write endpoints for the Enterprise Architecture module.',
            ],
            'paths' => [
                '/api/v1/ea/capabilities' => ['get' => ['summary' => 'List capabilities']],
                '/api/v1/ea/applications' => ['get' => ['summary' => 'List applications']],
                '/api/v1/ea/tech-components' => ['get' => ['summary' => 'List tech components']],
                '/api/v1/ea/interfaces' => ['get' => ['summary' => 'List interfaces']],
                '/api/v1/ea/apis' => ['get' => ['summary' => 'List APIs']],
                '/api/v1/ea/value-streams' => ['get' => ['summary' => 'List value streams']],
                '/api/v1/ea/processes' => ['get' => ['summary' => 'List business processes']],
                '/api/v1/ea/info-domains' => ['get' => ['summary' => 'List information domains']],
                '/api/v1/ea/logical-entities' => ['get' => ['summary' => 'List logical entities']],
                '/api/v1/ea/data-flows' => ['get' => ['summary' => 'List data flows']],
                '/api/v1/ea/zones' => ['get' => ['summary' => 'List security zones']],
                '/api/v1/ea/principles' => ['get' => ['summary' => 'List principles']],
                '/api/v1/ea/standards' => ['get' => ['summary' => 'List standards']],
                '/api/v1/ea/initiatives' => ['get' => ['summary' => 'List initiatives']],
                '/api/v1/ea/plateaux' => ['get' => ['summary' => 'List plateaux']],
                '/api/v1/ea/blast-radius' => ['get' => ['summary' => 'N-hop blast radius for an application']],
                '/api/v1/ea/viewpoints/{viewpoint}' => ['get' => ['summary' => 'Render an ArchiMate viewpoint']],
                '/api/v1/ea/search' => ['get' => ['summary' => 'Natural-language search']],
                '/api/v1/ea/exchange/archimate' => ['get' => ['summary' => 'Round-trip ArchiMate Open Exchange XML']],
                '/api/v1/ea/mcp' => ['post' => ['summary' => 'MCP JSON-RPC endpoint for external LLM agents']],
            ],
        ];
    }
}
