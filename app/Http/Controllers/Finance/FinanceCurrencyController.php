<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Finance\Currency;
use App\Models\Finance\FxRate;
use App\Models\Finance\TaxType;
use App\Services\Finance\CurrencyService;
use App\Services\Finance\FinanceCsvImporter;
use App\Services\Finance\FiscalCalendarService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Currencies, exchange rates and tax types (ATH-FIN-001 FIN-01.05, FIN-01.06). */
class FinanceCurrencyController extends Controller
{
    private function done(string $tab, string $message)
    {
        return redirect()->route('finance.settings', ['tab' => $tab])->with('success', $message);
    }

    public function currencyStore(Request $request)
    {
        Gate::authorize('create', Currency::class);
        $data = $request->validate([
            'code' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/', Rule::unique('fin_currencies', 'code')->where('organization_id', $request->user()->organization_id)],
            'name' => ['required', 'string', 'max:64'],
            'symbol' => ['nullable', 'string', 'max:8'],
            'decimals' => ['required', 'integer', 'between:0,4'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        Currency::create($data + ['is_base' => false]);

        return $this->done('currencies', 'Currency added.');
    }

    public function currencyUpdate(Request $request, Currency $currency, CurrencyService $service)
    {
        Gate::authorize('update', $currency);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'symbol' => ['nullable', 'string', 'max:8'],
            'decimals' => ['required', 'integer', 'between:0,4'],
            'is_active' => ['sometimes', 'boolean'],
            'is_base' => ['sometimes', 'boolean'],
        ]);
        $makeBase = (bool) ($data['is_base'] ?? false);
        unset($data['is_base']);

        if (array_key_exists('is_active', $data) && ! $data['is_active'] && ($currency->is_base || $makeBase)) {
            throw ValidationException::withMessages(['workflow' => 'The base currency cannot be deactivated.']);
        }
        $currency->update($data);
        if ($makeBase && ! $currency->is_base) {
            $service->setBase($currency);
        }

        return $this->done('currencies', 'Currency updated.');
    }

    public function fxRateStore(Request $request)
    {
        Gate::authorize('create', FxRate::class);
        $orgId = $request->user()->organization_id;
        $active = Rule::exists('fin_currencies', 'code')->where('organization_id', $orgId)->where('is_active', true);
        $data = $request->validate([
            'from_currency' => ['required', 'string', $active],
            'to_currency' => ['required', 'string', 'different:from_currency', $active],
            'rate' => ['required', 'regex:'.FinanceCsvImporter::FX_RATE, 'gt:0', 'max:9999999999'],
            'rate_date' => ['required', 'date', 'before_or_equal:today'],
        ]);
        $this->assertDateUnlocked($orgId, $data['rate_date']);
        $row = FxRate::firstOrNew([
            'organization_id' => $orgId, 'from_currency' => $data['from_currency'], 'to_currency' => $data['to_currency'], 'rate_date' => $data['rate_date'],
        ]);
        $row->fill(['rate' => $data['rate'], 'source' => 'manual'])->save();

        return $this->done('currencies', 'Exchange rate saved.');
    }

    public function fxRateDestroy(FxRate $fxRate)
    {
        Gate::authorize('delete', $fxRate);
        $this->assertDateUnlocked($fxRate->organization_id, $fxRate->rate_date->toDateString());
        $fxRate->delete();

        return $this->done('currencies', 'Exchange rate deleted.');
    }

    private function assertDateUnlocked(int $orgId, string $date): void
    {
        $period = app(FiscalCalendarService::class)->periodFor($orgId, CarbonImmutable::parse($date));
        if ($period && $period->status === 'locked') {
            throw ValidationException::withMessages(['workflow' => "Exchange rates dated in the locked period {$period->name} cannot be changed."]);
        }
    }

    public function fxRateImport(Request $request, FinanceCsvImporter $importer)
    {
        Gate::authorize('create', FxRate::class);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);
        $report = $importer->fxRates($request->user()->organization_id, $request->file('file'));

        return $this->done('currencies', "Imported {$report['accepted_count']} of {$report['row_count']} rows.")->with('import_report', $report);
    }

    private function taxData(Request $request, ?TaxType $existing = null): array
    {
        $unique = Rule::unique('fin_tax_types', 'code')->where('organization_id', $request->user()->organization_id);
        if ($existing) {
            $unique = $unique->ignore($existing->id);
        }

        return $request->validate([
            'code' => ['required', 'string', 'max:16', $unique],
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(TaxType::KINDS)],
            'rate' => ['required', 'numeric', 'between:0,100'],
            'is_inclusive' => ['sometimes', 'boolean'],
            'applies_to' => ['required', 'array', 'min:1'],
            'applies_to.*' => [Rule::in(TaxType::APPLIES_TO)],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    public function taxTypeStore(Request $request)
    {
        Gate::authorize('create', TaxType::class);
        TaxType::create($this->taxData($request));

        return $this->done('taxes', 'Tax type created.');
    }

    public function taxTypeUpdate(Request $request, TaxType $taxType)
    {
        Gate::authorize('update', $taxType);
        $taxType->update($this->taxData($request, $taxType));

        return $this->done('taxes', 'Tax type updated.');
    }
}
