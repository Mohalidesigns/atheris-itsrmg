<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsInvestments;
use App\Models\Finance\Investment;
use App\Services\Finance\PortfolioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Portfolio ranking, fund line and splits (FIN-05.03, .09). */
class PortfolioController extends Controller
{
    use PresentsInvestments;

    public function __construct(private readonly PortfolioService $portfolio) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', Investment::class);
        $user = $request->user();
        $orgId = (int) $user->organization_id;
        $f = $request->validate(['fiscal_year_id' => ['nullable', 'integer'], 'envelope' => ['nullable', 'string', 'max:24']]);
        $data = $this->portfolio->portfolio($orgId, isset($f['fiscal_year_id']) ? (int) $f['fiscal_year_id'] : null, $f['envelope'] ?? null, $user);
        $fy = $data['fiscal_year_id'];
        unset($data['fiscal_year_id']);

        return $this->renderInvestments('Finance/Investments/Portfolio', [
            'filters' => ['fiscal_year_id' => $fy, 'envelope' => $f['envelope'] ?? ''], 'fiscalYears' => $this->fiscalYearsOpts($orgId),
        ] + $data);
    }
}
