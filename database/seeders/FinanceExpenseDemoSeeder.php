<?php

namespace Database\Seeders;

use App\Events\Finance\ExpenseFlagged;
use App\Events\Finance\FinanceApprovalDecided;
use App\Events\Finance\FinanceApprovalRequested;
use App\Events\Finance\ImportBatchCompleted;
use App\Models\Approvals\ApprovalRequest;
use App\Models\Finance\Actual;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\Category;
use App\Models\Finance\Contract;
use App\Models\Finance\CostCentre;
use App\Models\Finance\ExpenseRequest;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\Invoice;
use App\Models\Finance\Period;
use App\Models\Finance\PurchaseOrder;
use App\Models\Finance\RecurringExpense;
use App\Models\Finance\Renewal;
use App\Models\Organization;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\AccrualService;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\FinancePolicySeeder;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\FinanceTransactionImporter;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\ProcurementService;
use App\Services\Finance\RecurringExpenseService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * IT Financial Management P3 demo data (ATH-FIN-001 §13, plan P3 §5.1): per bank, expense requests in every status (one unbudgeted
 * emergency pending at the CFO, one over-budget request), purchase orders and goods receipts (one with a rejected line), invoices in
 * every status (an overdue one, a variance, an override, a high-value one with excluded PO approvers, a lease, a prepaid subscription,
 * one expected from a recurring expense), recurring expenses, accruals, nine months of GL actuals with a September batch that has
 * unmatched rows, duplicates and an unbudgeted row, an invoices import with rejected rows, and invoice due-date notification history.
 *
 * Everything that has an approval trail is driven through the services as the right users, so pending requests are real engine
 * requests that somebody seeded can decide. Guarded per bank on the expense register. Deliberate gaps are kept.
 */
class FinanceExpenseDemoSeeder extends Seeder
{
    private const BANKS = [
        'kano-heritage-bank' => ['prefix' => 'KHB', 'admin' => 'admin@kanoheritage.ng', 'domain' => 'kanoheritage.ng', 'first' => false,
            'cc' => ['INF' => 'KHB-IT-INF', 'NET' => 'KHB-IT-NET', 'APP' => 'KHB-IT-APP', 'DIG' => 'KHB-IT-DIG', 'SEC' => 'KHB-SEC', 'DC' => 'KHB-IT-DC'],
            'small' => [2400000, 3100000, 3800000, 4400000, 1900000, 2200000], 'medium' => [9600000, 12400000, 14800000, 18500000, 16200000]],
        'first-bank-nigeria' => ['prefix' => 'FBN', 'admin' => 'admin@firstbanknigeria.ng', 'domain' => 'firstbanknigeria.ng', 'first' => true,
            'cc' => ['INF' => 'FBN-GT-INF', 'NET' => 'FBN-GT-NET', 'APP' => 'FBN-GT-CBS', 'DIG' => 'FBN-GT-DIG', 'SEC' => 'FBN-GIS', 'DC' => 'FBN-GT-DC'],
            'small' => [4200000, 5600000, 7300000, 8100000, 3500000, 6400000], 'medium' => [24000000, 31000000, 38000000, 27500000, 33000000]],
    ];

    private const QUIET = [FinanceApprovalRequested::class, FinanceApprovalDecided::class, ExpenseFlagged::class, ImportBatchCompleted::class];

    private int $orgId;

    private array $bank;

    private string $prefix;

    private CarbonImmutable $today;

    private int $periodId;

    private int $fyId;

    /** @var array<string,User> */
    private array $who = [];

    /** @var array<int,User> */
    private array $owners = [];

    private ExpenseService $expenses;

    private ProcurementService $procurement;

    private InvoiceService $invoices;

    /** @var array<string,PurchaseOrder> */
    private array $pos = [];

    /** @var array<string,ExpenseRequest> */
    private array $made = [];

    private int $seq = 0;

    public function run(): void
    {
        $this->expenses = app(ExpenseService::class);
        $this->procurement = app(ProcurementService::class);
        $this->invoices = app(InvoiceService::class);

        foreach (self::BANKS as $slug => $bank) {
            $org = Organization::where('slug', $slug)->first();
            $admin = User::where('email', $bank['admin'])->first();
            if (! $org || ! $admin || ! CostCentre::withoutGlobalScopes()->where('organization_id', $org->id)->exists()) {
                continue;
            }
            $this->orgId = $org->id;
            $this->bank = $bank;
            $this->prefix = $bank['prefix'];
            if (ExpenseRequest::withoutGlobalScopes()->where('organization_id', $this->orgId)->exists()) {
                continue;
            }
            $this->today = FinanceClock::today();
            $fy = FiscalYear::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('is_current', true)->first();
            $period = $fy ? Period::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('fiscal_year_id', $fy->id)->where('is_adjustment', false)->where('status', 'open')->orderBy('starts_on')->first() : null;
            if (! $fy || ! $period) {
                continue;
            }
            $this->fyId = $fy->id;
            $this->periodId = $period->id;
            $this->pos = $this->made = [];
            $this->seq = 0;
            mt_srand(3000 + $this->orgId);
            app(FinanceSettings::class)->ensureDefaults($this->orgId);
            FinancePolicySeeder::ensureDefaults($this->orgId);
            $this->people();
            Auth::setUser($this->who['finance']);
            $this->seedBank();
        }
        mt_srand();
        Auth::forgetUser();
    }

    /* ------------------------------------------------------------------ people and helpers */

    private function people(): void
    {
        foreach (['finance', 'procurement', 'cfo', 'cio', 'md', 'headofit'] as $key) {
            $this->who[$key] = User::where('email', "{$key}@{$this->bank['domain']}")->firstOrFail();
        }
        $this->owners = [];
        foreach (CostCentre::withoutGlobalScopes()->where('organization_id', $this->orgId)->whereNotNull('owner_id')->get() as $cc) {
            $this->owners[(int) $cc->id] = User::withoutGlobalScopes()->find($cc->owner_id);
        }
    }

    private function quiet(callable $fn): mixed
    {
        $out = null;
        Event::fakeFor(function () use ($fn, &$out) {
            $out = $fn();
        }, self::QUIET);

        return $out;
    }

    private function attempt(string $label, callable $fn): mixed
    {
        try {
            return $this->quiet($fn);
        } catch (Throwable $e) {
            $msg = $e instanceof ValidationException ? json_encode($e->errors()) : $e->getMessage();
            $this->command?->warn("  [{$this->prefix}] finance expense seed skipped {$label}: ".mb_substr((string) $msg, 0, 220));

            return null;
        }
    }

    private function cc(string $alias): CostCentre
    {
        return CostCentre::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('code', $this->bank['cc'][$alias])->firstOrFail();
    }

    /** The current (baseline or revised, not superseded) line of a cost centre with the most room. */
    private function line(CostCentre $cc, int $skip = 0): ?BudgetLine
    {
        $budgets = Budget::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('fiscal_year_id', $this->fyId)->where('cost_centre_id', $cc->id)
            ->whereIn('status', ['baseline', 'revised'])->whereNull('superseded_by_id')->pluck('id');

        return BudgetLine::withoutGlobalScopes()->whereIn('budget_id', $budgets)->orderByDesc('base_annual_amount')->orderBy('id')->skip($skip)->first();
    }

    /** The parent cost centre's owner raises the request when the cost centre has its own, different owner, so the owner can endorse the request and confirm its invoices. */
    private function requesterFor(CostCentre $cc): User
    {
        $own = $this->owners[$cc->id] ?? null;
        $parent = $cc->parent_id ? ($this->owners[(int) $cc->parent_id] ?? null) : null;
        if ($own && $parent && $own->id !== $parent->id) {
            return $parent;
        }

        return $own ?? $parent ?? $this->who['procurement'];
    }

    private function vendor(string $like): Vendor
    {
        return Vendor::withoutGlobalScopes()->where('organization_id', $this->orgId)->whereRaw('lower(name) like ?', ['%'.mb_strtolower($like).'%'])->orderBy('id')->first()
            ?? Vendor::withoutGlobalScopes()->where('organization_id', $this->orgId)->orderBy('id')->firstOrFail();
    }

    private function category(): ?int
    {
        return Category::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('code', 'CP-HW')->value('id')
            ?? Category::withoutGlobalScopes()->where('organization_id', $this->orgId)->orderBy('id')->value('id');
    }

    /** @return list<User> */
    private function deciders(CostCentre $cc): array
    {
        $list = [];
        for ($c = $cc, $h = 0; $c && $h < 4; $h++, $c = $c->parent_id ? CostCentre::withoutGlobalScopes()->find($c->parent_id) : null) {
            isset($this->owners[$c->id]) && ($list[] = $this->owners[$c->id]);
        }

        return array_merge($list, [$this->who['headofit'], $this->who['finance'], $this->who['cfo'], $this->who['cio'], $this->who['md']]);
    }

    private function pendingStep(string $process, object $subject): ?string
    {
        $r = ApprovalRequest::withoutGlobalScopes()->where('process', $process)->where('subject_type', $subject::class)->where('subject_id', $subject->id)->latest('id')->first();

        return $r?->steps()->where('status', 'pending')->orderBy('sequence')->value('name');
    }

    /** Approves stages with the first decider who is allowed, until the request waits at $stopAt (null: through to the end). */
    private function advance(string $process, object $subject, callable $approve, array $users, ?string $stopAt = null): void
    {
        for ($n = 0; $n < 8; $n++) {
            $pending = $this->pendingStep($process, $subject->refresh());
            // a request with no pending step still needs its final decision (the zero-step default path)
            $zeroStep = $pending === null && $stopAt === null && $subject->status === 'submitted';
            if (($pending === null && ! $zeroStep) || ($stopAt !== null && $pending === $stopAt)) {
                return;
            }
            $done = false;
            foreach ($users as $u) {
                try {
                    $approve($u);
                    $done = true;
                    break;
                } catch (Throwable) {
                    continue;
                }
            }
            if (! $done) {
                return;
            }
        }
    }

    private function first(array $users, callable $fn): mixed
    {
        foreach ($users as $u) {
            try {
                return $fn($u);
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------ the bank */

    private function seedBank(): void
    {
        $this->configuration();
        $this->expenseRegister();
        $this->invoiceRegister();
        $this->recurring();
        $this->accruals();
        $this->actualsHistory();
        $this->invoicesImport();
        $this->notificationHistory();
    }

    private function configuration(): void
    {
        $now = now();
        $mapping = $this->bank['first']
            ? ['name' => 'Oracle EBS GL extract', 'delimiter' => ';', 'date_format' => 'Y-m-d']
            : ['name' => 'Finacle GL extract', 'delimiter' => ',', 'date_format' => 'd/m/Y'];
        DB::table('fin_import_mappings')->insertOrIgnore([
            'organization_id' => $this->orgId, 'created_by_id' => $this->who['finance']->id, 'name' => $mapping['name'], 'type' => 'actuals', 'delimiter' => $mapping['delimiter'], 'date_format' => $mapping['date_format'],
            'columns' => json_encode(['posted_on' => 'Posting Date', 'gl_code' => 'GL Account', 'cost_centre_code' => 'Cost Centre', 'amount' => 'Amount', 'description' => 'Narration', 'reference' => 'Document No', 'vendor' => 'Vendor']),
            'decimal_comma' => false, 'is_default' => true, 'has_header' => true, 'default_currency' => 'NGN', 'created_at' => $now, 'updated_at' => $now,
        ]);

        // IFRS 16 indicator: one lease, one short-term exempt; the third lease-type contract (First Bank only) is left unassessed.
        $leases = Contract::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('type', 'lease')->orderBy('id')->get();
        $assess = $this->bank['first'] ? ['ifrs16_lease', 'short_term_exempt'] : ['ifrs16_lease'];
        foreach ($leases->take(count($assess)) as $i => $c) {
            DB::table('fin_contracts')->where('id', $c->id)->update(['lease_assessment' => $assess[$i], 'lease_assessment_note' => $assess[$i] === 'ifrs16_lease' ? 'Fixed term, identified space and power, right to direct use: recognised under IFRS 16.' : 'Term of twelve months or less at commencement.']);
        }
        if (! $this->bank['first']) {
            $link = Contract::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('type', 'telecom')->orderBy('id')->first();
            $link && DB::table('fin_contracts')->where('id', $link->id)->update(['lease_assessment' => 'short_term_exempt', 'lease_assessment_note' => 'Month-to-month dedicated link with a 30-day exit.']);
        }
    }

    /* ------------------------------------------------------------------ expense requests */

    private function expenseRegister(): void
    {
        $s = $this->bank['small'];
        $m = $this->bank['medium'];
        $items = [
            ['k' => 'draft1', 'cc' => 'INF', 'title' => 'Replacement rack PDUs, primary data hall', 'amt' => $s[4], 'v' => 'dell', 'to' => 'draft'],
            ['k' => 'draft2', 'cc' => 'NET', 'title' => 'Branch router refresh, ten sites', 'amt' => $s[5], 'v' => 'cisco', 'to' => 'draft', 'capex' => true],
            ['k' => 'owner', 'cc' => 'DIG', 'title' => 'Mobile app load-testing licences', 'amt' => $s[0], 'v' => 'microsoft', 'to' => 'owner'],
            ['k' => 'head', 'cc' => 'APP', 'title' => 'Finacle UAT environment extension', 'amt' => $s[1], 'v' => 'infosys', 'to' => 'head'],
            ['k' => 'approved', 'cc' => 'NET', 'title' => 'Core switch spares and support uplift', 'amt' => $s[2], 'v' => 'cisco', 'to' => 'approved'],
            ['k' => 'committed1', 'cc' => 'INF', 'title' => 'Storage array expansion shelf', 'amt' => $m[0], 'v' => 'dell', 'to' => 'committed', 'capex' => true],
            ['k' => 'committed2', 'cc' => 'SEC', 'title' => 'Privileged access vault licences', 'amt' => $s[3], 'v' => 'crowdstrike', 'to' => 'committed'],
            ['k' => 'partial', 'cc' => 'DIG', 'title' => 'Mobile banking pen-test tooling', 'amt' => $s[2], 'v' => 'microsoft', 'to' => 'partial'],
            ['k' => 'received', 'cc' => 'DC', 'title' => 'Precision cooling unit for the DR site', 'amt' => $m[1], 'v' => 'rack', 'to' => 'received', 'capex' => true],
            ['k' => 'invoiced1', 'cc' => 'APP', 'title' => 'Card switch HSM firmware and certification', 'amt' => $s[0], 'v' => 'thales', 'to' => 'invoiced'],
            ['k' => 'invoiced2', 'cc' => 'NET', 'title' => 'Firewall policy audit and tuning', 'amt' => $s[1], 'v' => 'fortinet', 'to' => 'invoiced'],
            ['k' => 'closed', 'cc' => 'INF', 'title' => 'Server memory upgrade, virtualisation cluster', 'amt' => $s[4], 'v' => 'dell', 'to' => 'closed'],
            ['k' => 'rejected', 'cc' => 'DIG', 'title' => 'Customer-experience analytics subscription', 'amt' => $s[5], 'v' => 'microsoft', 'to' => 'rejected'],
            ['k' => 'cancelled', 'cc' => 'SEC', 'title' => 'Deception honeypot appliance', 'amt' => $s[3], 'v' => 'crowdstrike', 'to' => 'cancelled'],
            ['k' => 'withdrawn', 'cc' => 'APP', 'title' => 'Reconciliation tooling pilot', 'amt' => $s[2], 'v' => 'infosys', 'to' => 'withdrawn'],
            ['k' => 'overbudget', 'cc' => 'NET', 'title' => 'Metro-ethernet capacity upgrade', 'amt' => $m[2] * 7, 'v' => 'mtn', 'to' => 'head', 'over' => true],
            ['k' => 'highvalue', 'cc' => 'DC', 'title' => 'Generator overhaul and fuel system, data centre', 'amt' => $m[1] + 600000, 'v' => 'rack', 'to' => 'highvalue', 'capex' => true],
            ['k' => 'sept', 'cc' => 'APP', 'title' => 'Switch module for card processing (received, not yet billed)', 'amt' => $s[1], 'v' => 'infosys', 'to' => 'received'],
        ];
        foreach ($items as $spec) {
            $this->attempt("expense {$spec['k']}", fn () => $this->makeExpense($spec));
        }
        $this->emergency();
        $this->attempt('renewal draft', fn () => $this->renewalDraft());
    }

    private function create(array $spec, ?int $lineSkip = 0): ExpenseRequest
    {
        $cc = $this->cc($spec['cc']);
        $line = $this->line($cc, $lineSkip ?? 0);
        $requester = $this->requesterFor($cc);
        $vendor = $this->vendor($spec['v'] ?? 'dell');
        $e = $this->expenses->create([
            'title' => $spec['title'], 'description' => ($spec['description'] ?? $spec['title'].' for '.$this->prefix.' IT.'), 'fiscal_year_id' => $this->fyId, 'budget_line_id' => $spec['unbudgeted'] ?? false ? null : $line?->id,
            'cost_centre_id' => $cc->id, 'category_id' => $spec['unbudgeted'] ?? false ? $this->category() : ($line?->category_id), 'vendor_id' => $vendor->id, 'currency' => $spec['ccy'] ?? 'NGN',
            'amount' => number_format((float) $spec['amt'], 2, '.', ''), 'needed_by' => $this->today->addDays(21)->toDateString(), 'is_capex' => (bool) ($spec['capex'] ?? false),
            'is_emergency' => (bool) ($spec['emergency'] ?? false), 'justification' => $spec['justification'] ?? 'Required to keep the service within its agreed availability and support terms.',
            'overbudget_justification' => 'Demand grew faster than planned; the saving on the legacy circuits offsets it in the second half.',
        ], $requester);
        $this->made[$spec['k']] = $e;

        return $e;
    }

    private function makeExpense(array $spec): void
    {
        $e = $this->create($spec);
        $requester = User::withoutGlobalScopes()->find($e->requested_by_id);
        $cc = $this->cc($spec['cc']);
        $approve = fn ($u) => $this->expenses->approve($e->refresh(), $u);
        $users = $this->deciders($cc);
        $to = $spec['to'];
        if ($to === 'draft') {
            return;
        }
        $e = $this->expenses->submit($e, $requester);
        if ($to === 'owner') {
            return;
        }
        if ($to === 'head') {
            $this->advance(ExpenseService::PROCESS, $e, $approve, $users, 'Head of IT approval');

            return;
        }
        if ($to === 'withdrawn') {
            $this->expenses->withdraw($e->refresh(), $requester);

            return;
        }
        if ($to === 'rejected') {
            $this->advance(ExpenseService::PROCESS, $e, $approve, $users, 'Head of IT approval');
            $this->first($users, fn ($u) => $this->expenses->reject($e->refresh(), $u, 'Duplicates an existing analytics licence; consolidate with the digital team first.'));

            return;
        }
        $this->advance(ExpenseService::PROCESS, $e, $approve, $users);
        $e->refresh();
        if ($to === 'approved') {
            return;
        }
        if ($to === 'cancelled') {
            $this->expenses->cancel($e, $requester, 'The appliance was descoped from the security roadmap.');

            return;
        }
        if ($to === 'highvalue') {
            $this->highValueInvoice($e);

            return;
        }
        $this->commitAndReceive($spec, $e);
    }

    private function commitAndReceive(array $spec, ExpenseRequest $e): void
    {
        $to = $spec['to'];
        $qty = in_array($to, ['partial', 'received', 'invoiced', 'closed'], true) ? 4 : 1;
        $po = $this->procurement->commit($e, [
            'po_date' => $this->today->subDays(18)->toDateString(), 'expected_delivery_on' => $this->today->addDays(10)->toDateString(),
            'lines' => [['description' => $spec['title'].' (units)', 'quantity' => (string) $qty, 'unit_cost' => bcdiv((string) $e->amount, (string) $qty, 2)]],
        ], $this->who['procurement']);
        $this->pos[$spec['k']] = $po;
        if ($to === 'committed') {
            return;
        }
        $line = $po->lines()->first();
        if ($to === 'partial') {
            $this->procurement->receive($po, ['received_on' => $this->today->subDays(9)->toDateString(), 'lines' => [['po_line_id' => $line->id, 'quantity' => '2', 'accepted' => true]]], $this->who['procurement']);

            return;
        }
        if ($spec['k'] === 'received') {
            // one rejected delivery line: 1 damaged unit goes back, the other 3 are accepted
            $this->procurement->receive($po, ['received_on' => $this->today->subDays(9)->toDateString(), 'lines' => [['po_line_id' => $line->id, 'quantity' => '1', 'accepted' => false, 'rejection_reason' => 'Unit arrived with a cracked compressor housing; returned to the vendor.']]], $this->who['procurement']);
            $this->procurement->receive($po->refresh(), ['received_on' => $this->today->subDays(8)->toDateString(), 'lines' => [['po_line_id' => $line->id, 'quantity' => '4', 'accepted' => true]]], $this->who['procurement']);

            return;
        }
        $this->procurement->receive($po, ['received_on' => $this->today->subDays(9)->toDateString(), 'lines' => [['po_line_id' => $line->id, 'quantity' => (string) $qty, 'accepted' => true]]], $this->who['procurement']);
        if ($to === 'received') {
            return;
        }
        $inv = $this->invoiceFor($po, ['key' => $spec['k']]);
        $this->invoices->submit($inv, $this->who['procurement']);
        $this->advance(InvoiceService::PROCESS, $inv, fn ($u) => $this->invoices->approve($inv->refresh(), $u), $this->deciders($this->cc($spec['cc'])));
        if ($to === 'closed') {
            $this->expenses->close($e->refresh(), $this->who['finance'], 'Delivered and fully billed.');
        }
    }

    private function emergency(): void
    {
        $first = $this->bank['first'];
        $spec = $first
            ? ['k' => 'emergency', 'cc' => 'DIG', 'title' => 'Emergency DDoS scrubbing service', 'amt' => 118000, 'ccy' => 'USD', 'v' => 'cloudflare', 'unbudgeted' => true, 'emergency' => true,
                'justification' => 'A sustained volumetric attack on internet banking on the weekend exceeded the contracted mitigation capacity; a scrubbing service must be on-boarded now, outside the approved budget.']
            : ['k' => 'emergency', 'cc' => 'INF', 'title' => 'Emergency replacement of failed core switch, Kano HQ', 'amt' => 18500000, 'v' => 'cisco', 'unbudgeted' => true, 'emergency' => true, 'capex' => true,
                'justification' => 'The HQ core switch failed on Friday and the standby is carrying all branch traffic with no redundancy; a replacement must be procured urgently, outside the approved budget.'];
        $this->attempt('emergency', function () use ($spec) {
            $e = $this->create($spec);
            $requester = User::withoutGlobalScopes()->find($e->requested_by_id);
            $e = $this->expenses->submit($e, $requester);
            $this->advance(ExpenseService::PROCESS, $e, fn ($u) => $this->expenses->approve($e->refresh(), $u), $this->deciders($this->cc($spec['cc'])), 'CFO approval');
        });
    }

    private function renewalDraft(): void
    {
        $r = DB::table('fin_contract_renewals')->where('organization_id', $this->orgId)->where('status', 'executed')->whereNotNull('new_contract_id')->whereNull('expense_request_id')->orderBy('id')->first();
        if (! $r) {
            return;
        }
        $renewal = Renewal::withoutGlobalScopes()->find($r->id);
        $successor = Contract::withoutGlobalScopes()->find($r->new_contract_id);
        $renewal && $successor && $this->expenses->createFromRenewal($renewal, $successor);
    }

    /* ------------------------------------------------------------------ invoices */

    private function nextNo(): string
    {
        return sprintf('%s/%s/%d/%04d', strtoupper(substr(md5((string) $this->orgId), 0, 3)), 'INV', $this->today->year, 4100 + ++$this->seq * 7);
    }

    /** A draft invoice for a PO, lines prefilled from what was received. */
    private function invoiceFor(PurchaseOrder $po, array $over = []): Invoice
    {
        unset($over['key']);

        return $this->invoices->capture($over + [
            'vendor_invoice_no' => $this->nextNo(), 'vendor_id' => $po->vendor_id, 'po_id' => $po->id, 'invoice_date' => $this->today->subDays(6)->toDateString(),
            'period_id' => $this->periodId, 'currency' => $po->currency, 'cost_centre_id' => $po->cost_centre_id, 'budget_line_id' => $po->budget_line_id,
            'description' => 'Invoice against '.$po->po_code,
        ], $this->who['procurement']);
    }

    /** A received PO ready to be billed, off a fresh approved request (own request so every invoice status has its own subject). */
    private function receivedPo(string $key, string $ccAlias, string $title, float $amount, string $vendor = 'dell'): PurchaseOrder
    {
        $spec = ['k' => $key, 'cc' => $ccAlias, 'title' => $title, 'amt' => $amount, 'v' => $vendor, 'to' => 'received'];
        $e = $this->create($spec);
        $requester = User::withoutGlobalScopes()->find($e->requested_by_id);
        $e = $this->expenses->submit($e, $requester);
        $this->advance(ExpenseService::PROCESS, $e, fn ($u) => $this->expenses->approve($e->refresh(), $u), $this->deciders($this->cc($ccAlias)));
        $po = $this->procurement->commit($e->refresh(), [
            'po_date' => $this->today->subDays(30)->toDateString(),
            'lines' => [['description' => $title, 'quantity' => '2', 'unit_cost' => bcdiv(number_format($amount, 2, '.', ''), '2', 2)]],
        ], $this->who['procurement']);
        $this->procurement->receive($po, ['received_on' => $this->today->subDays(12)->toDateString(), 'lines' => [['po_line_id' => $po->lines()->first()->id, 'quantity' => '2', 'accepted' => true]]], $this->who['procurement']);

        return $po->refresh();
    }

    private function approveInvoice(Invoice $inv, string $ccAlias): void
    {
        $this->advance(InvoiceService::PROCESS, $inv, fn ($u) => $this->invoices->approve($inv->refresh(), $u), $this->deciders($this->cc($ccAlias)));
    }

    private function highValueInvoice(ExpenseRequest $e): void
    {
        $po = $this->procurement->commit($e, [
            'po_date' => $this->today->subDays(30)->toDateString(), 'lines' => [['description' => $e->title, 'quantity' => '1', 'unit_cost' => (string) $e->amount]],
        ], $this->who['procurement']);
        $this->procurement->receive($po, ['received_on' => $this->today->subDays(12)->toDateString(), 'lines' => [['po_line_id' => $po->lines()->first()->id, 'quantity' => '1', 'accepted' => true]]], $this->who['procurement']);
        $inv = $this->invoiceFor($po);
        $this->invoices->submit($inv, $this->who['procurement']);
        // Leave it waiting at the high-value Finance Approver stage (the CFO and Head of IT approved the PO, so they are excluded).
        $this->advance(InvoiceService::PROCESS, $inv, fn ($u) => $this->invoices->approve($inv->refresh(), $u), $this->deciders($this->cc('DC')), 'Finance Approver (high value)');
    }

    private function invoiceRegister(): void
    {
        $s = $this->bank['small'];
        $this->attempt('invoice draft', function () use ($s) {
            $po = $this->receivedPo('inv-draft', 'INF', 'Tape library maintenance spares', $s[0]);
            $this->invoiceFor($po);
        });
        $this->attempt('invoice variance', function () use ($s) {
            $po = $this->receivedPo('inv-var', 'NET', 'Wireless controller licences', $s[1], 'cisco');
            $line = $po->lines()->first();
            $this->invoiceFor($po, ['lines' => [['po_line_id' => $line->id, 'description' => $line->description, 'quantity' => '2', 'unit_cost' => bcmul((string) $line->unit_cost, '1.18', 2)]]]);
        });
        $this->attempt('invoice submitted at owner', function () use ($s) {
            $po = $this->receivedPo('inv-own', 'DIG', 'API gateway support hours', $s[2], 'microsoft');
            $this->invoices->submit($this->invoiceFor($po), $this->who['procurement']);
        });
        $this->attempt('invoice override', function () use ($s) {
            $po = $this->receivedPo('inv-ovr', 'APP', 'Switch module re-certification', $s[3], 'infosys');
            $line = $po->lines()->first();
            $inv = $this->invoiceFor($po, ['lines' => [['po_line_id' => $line->id, 'description' => $line->description, 'quantity' => '2', 'unit_cost' => bcmul((string) $line->unit_cost, '1.15', 2)]]]);
            $this->invoices->override($inv->refresh(), 'The vendor re-priced after the CBN switching-fee circular; the account manager confirmed the new rate in writing.', $this->who['procurement']);
            $this->invoices->submit($inv->refresh(), $this->who['procurement']);
            $this->advance(InvoiceService::PROCESS, $inv, fn ($u) => $this->invoices->approve($inv->refresh(), $u), $this->deciders($this->cc('APP')), 'Finance Controller review');
        });
        $this->attempt('invoice approved', function () use ($s) {
            $po = $this->receivedPo('inv-app', 'SEC', 'SIEM connector professional services', $s[4], 'crowdstrike');
            $inv = $this->invoiceFor($po);
            $this->invoices->submit($inv, $this->who['procurement']);
            $this->approveInvoice($inv, 'SEC');
        });
        $this->attempt('invoice scheduled', function () use ($s) {
            $po = $this->receivedPo('inv-sch', 'INF', 'Backup appliance support renewal', $s[5]);
            $inv = $this->invoiceFor($po);
            $this->invoices->submit($inv, $this->who['procurement']);
            $this->approveInvoice($inv, 'INF');
            $this->first([$this->who['finance'], $this->who['cfo'], $this->who['md'], $this->who['cio']], fn ($u) => $this->invoices->schedule($inv->refresh(), $this->today->addDays(5)->toDateString(), $u));
        });
        $this->attempt('invoice paid', function () use ($s) {
            $po = $this->receivedPo('inv-paid', 'DC', 'UPS battery string replacement', $s[0], 'rack');
            $inv = $this->invoiceFor($po, ['invoice_date' => $this->today->subDays(40)->toDateString()]);
            $this->invoices->submit($inv, $this->who['procurement']);
            $this->approveInvoice($inv, 'DC');
            $this->first([$this->who['finance'], $this->who['cfo'], $this->who['md'], $this->who['cio']], function ($u) use ($inv) {
                $i = $this->invoices->schedule($inv->refresh(), $this->today->subDays(8)->toDateString(), $u);

                return $this->invoices->markPaid($i, $this->today->subDays(3)->toDateString(), 'NIP-'.random_int(100000, 999999), $u);
            });
        });
        $this->attempt('invoice disputed', function () use ($s) {
            $po = $this->receivedPo('inv-dis', 'NET', 'Spare optics and patch leads', $s[1], 'cisco');
            $this->invoices->dispute($this->invoiceFor($po), 'Half the optics were delivered to Ikeja instead of the data centre; the vendor must reissue the delivery note.', $this->who['procurement']);
        });
        $this->attempt('invoice rejected', function () use ($s) {
            $po = $this->receivedPo('inv-rej', 'DIG', 'Chatbot integration workshop', $s[2], 'microsoft');
            $inv = $this->invoiceFor($po);
            $this->invoices->submit($inv, $this->who['procurement']);
            $this->advance(InvoiceService::PROCESS, $inv, fn ($u) => $this->invoices->approve($inv->refresh(), $u), $this->deciders($this->cc('DIG')), 'Cost-centre owner confirmation');
            $this->first($this->deciders($this->cc('DIG')), fn ($u) => $this->invoices->reject($inv->refresh(), $u, 'The workshop was not attended by the bank; billed days are disputed.'));
        });
        $this->attempt('invoice cancelled', function () use ($s) {
            $po = $this->receivedPo('inv-can', 'APP', 'Duplicate capture of switch certification', $s[0], 'infosys');
            $this->invoices->cancel($this->invoiceFor($po), 'Captured twice by mistake; the first capture stands.', $this->who['procurement']);
        });
        $this->attempt('invoice overdue', function () use ($s) {
            $po = $this->receivedPo('inv-over', 'NET', 'Branch WAN optimiser support', $s[3], 'mtn');
            $inv = $this->invoiceFor($po, ['invoice_date' => $this->today->subDays(52)->toDateString(), 'due_on' => $this->today->subDays(12)->toDateString()]);
            $this->invoices->submit($inv, $this->who['procurement']);
            $this->approveInvoice($inv, 'NET');
        });
        $this->attempt('invoice contract (non-PO)', fn () => $this->contractInvoices());
        $this->attempt('invoice prepaid', function () use ($s) {
            $cc = $this->cc('SEC');
            $line = $this->line($cc, 1) ?? $this->line($cc);
            $inv = $this->invoices->capture([
                'vendor_invoice_no' => $this->nextNo(), 'vendor_id' => $this->vendor('crowdstrike')->id, 'invoice_date' => $this->today->subDays(4)->toDateString(), 'period_id' => $this->periodId, 'currency' => 'NGN',
                'cost_centre_id' => $cc->id, 'budget_line_id' => $line?->id, 'category_id' => $line?->category_id, 'subtotal' => (string) ($s[3] * 3), 'prepayment_months' => 12,
                'description' => 'Annual endpoint protection subscription, paid in advance',
            ], $this->who['procurement']);
            $this->invoices->submit($inv, $this->who['procurement']);
            $this->approveInvoice($inv, 'SEC');
        });
    }

    /** Microsoft quarterly (pending at the owner) and an IFRS 16 lease invoice (approved). */
    private function contractInvoices(): void
    {
        $ms = Contract::withoutGlobalScopes()->where('organization_id', $this->orgId)->whereRaw("lower(title) like '%microsoft enterprise%'")->first();
        if ($ms) {
            $cc = CostCentre::withoutGlobalScopes()->find($ms->cost_centre_id);
            $inv = $this->invoices->capture([
                'vendor_invoice_no' => $this->nextNo(), 'vendor_id' => $ms->vendor_id, 'contract_id' => $ms->id, 'invoice_date' => $this->today->subDays(5)->toDateString(), 'period_id' => $this->periodId,
                'currency' => $ms->currency, 'cost_centre_id' => $ms->cost_centre_id, 'budget_line_id' => $ms->budget_line_id, 'subtotal' => bcdiv((string) $ms->contract_value, '4', 2),
                'description' => 'Quarterly instalment, enterprise agreement',
            ], $this->who['procurement']);
            $this->invoices->submit($inv, $this->who['procurement']);
        }
        $lease = Contract::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('lease_assessment', 'ifrs16_lease')->first();
        if ($lease) {
            $cc = CostCentre::withoutGlobalScopes()->find($lease->cost_centre_id);
            $inv = $this->invoices->capture([
                'vendor_invoice_no' => $this->nextNo(), 'vendor_id' => $lease->vendor_id, 'contract_id' => $lease->id, 'invoice_date' => $this->today->subDays(8)->toDateString(), 'period_id' => $this->periodId,
                'currency' => $lease->currency, 'cost_centre_id' => $lease->cost_centre_id, 'budget_line_id' => $lease->budget_line_id, 'subtotal' => bcdiv((string) $lease->contract_value, '12', 2),
                'description' => 'Monthly colocation charge (IFRS 16 lease)',
            ], $this->who['procurement']);
            $this->invoices->submit($inv, $this->who['procurement']);
            $this->advance(InvoiceService::PROCESS, $inv, fn ($u) => $this->invoices->approve($inv->refresh(), $u), $this->deciders($cc));
        }
    }

    /* ------------------------------------------------------------------ recurring, accruals */

    private function recurring(): void
    {
        $svc = app(RecurringExpenseService::class);
        $specs = [
            ['t' => 'MTN MPLS branch links', 'like' => '%mtn%', 'cc' => 'NET', 'f' => 'monthly', 'a' => 3900000, 'due' => 'today'],
            ['t' => 'Airtel internet and backup links', 'like' => '%airtel%', 'cc' => 'NET', 'f' => 'monthly', 'a' => 600000, 'due' => 'later'],
            ['t' => 'Microsoft Enterprise Agreement instalment', 'like' => '%microsoft enterprise%', 'cc' => 'INF', 'f' => 'quarterly', 'a' => 8000000, 'due' => 'expected'],
            ['t' => 'Finacle AMC annual fee', 'like' => '%finacle%', 'cc' => 'APP', 'f' => 'annual', 'a' => 46000000, 'due' => 'later'],
            ['t' => 'AWS cloud consumption', 'like' => '%amazon%', 'cc' => 'DC', 'f' => 'monthly', 'a' => 7000000, 'due' => 'later', 'auto' => 'accrual'],
            ['t' => 'Legacy SMS gateway fee', 'like' => '%infobip%', 'cc' => 'DIG', 'f' => 'monthly', 'a' => 450000, 'due' => 'later', 'auto' => 'none', 'inactive' => true],
        ];
        $recurringCodes = [];
        foreach ($specs as $sp) {
            $this->attempt("recurring {$sp['t']}", function () use ($svc, $sp) {
                $c = Contract::withoutGlobalScopes()->where('organization_id', $this->orgId)->whereRaw('lower(title) like ?', [$sp['like']])->first();
                $cc = $this->cc($sp['cc']);
                $line = $c?->budget_line_id ? BudgetLine::withoutGlobalScopes()->find($c->budget_line_id) : $this->line($cc);
                $step = RecurringExpense::FREQUENCIES[$sp['f']];
                $r = $svc->save(null, [
                    'title' => $sp['t'], 'contract_id' => $c?->id, 'vendor_id' => $c?->vendor_id ?? $this->vendor('mtn')->id, 'budget_line_id' => $line?->id, 'cost_centre_id' => $cc->id, 'frequency' => $sp['f'],
                    'amount' => number_format($sp['a'] * ($this->bank['first'] ? 3 : 1), 2, '.', ''), 'currency' => 'NGN', 'starts_on' => $this->today->subMonthsNoOverflow($step * 3)->toDateString(),
                    'auto_generate' => $sp['auto'] ?? 'expected_invoice', 'is_active' => ! ($sp['inactive'] ?? false), 'category_id' => $line?->category_id,
                ], $this->who['finance']);
                $due = match ($sp['due']) {
                    'today' => $this->today,
                    'expected' => $this->today->subDays(3),
                    default => $svc->nextDue($r, $this->today),
                };
                if ($sp['due'] === 'expected') {
                    $svc->generate($r, $due); // the expected invoice appears in the register
                    $due = $svc->nextDue($r, $due);
                }
                $r->forceFill(['next_due_on' => $due->toDateString(), 'last_generated_on' => $sp['due'] === 'expected' ? $this->today->subDays(3)->toDateString() : $r->last_generated_on])->save();
            });
        }
    }

    private function accruals(): void
    {
        $this->attempt('accruals', function () {
            // September: a received-not-invoiced accrual, posted then reversed in October (September is closed, so the pair is written directly).
            $po = PurchaseOrder::withoutGlobalScopes()->where('organization_id', $this->orgId)->whereIn('status', ['received', 'partially_received'])->orderBy('id')->first();
            $sept = Period::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('fiscal_year_id', $this->fyId)->where('sequence', 9)->first();
            if ($po && $sept) {
                $now = now();
                $base = ['organization_id' => $this->orgId, 'created_by_id' => $this->who['finance']->id, 'fiscal_year_id' => $this->fyId, 'cost_centre_id' => $po->cost_centre_id, 'budget_line_id' => $po->budget_line_id,
                    'currency' => $po->currency, 'fx_rate' => (string) $po->fx_rate, 'created_at' => $now, 'updated_at' => $now];
                $amount = bcdiv((string) $po->base_total_amount, '2', 2);
                $accrualId = DB::table('fin_accruals')->insertGetId($base + ['period_id' => $sept->id, 'type' => 'accrual', 'source' => 'po', 'dedupe_key' => "po:{$po->id}:{$sept->id}", 'po_id' => $po->id,
                    'description' => "Received not invoiced: {$po->po_code}", 'amount' => $amount, 'base_amount' => $amount, 'status' => 'reversed', 'posted_by_id' => $this->who['finance']->id, 'posted_at' => $sept->ends_on->copy()->setTime(17, 0),
                    'reversed_by_id' => $this->who['finance']->id, 'reversed_at' => $this->today->startOfMonth()->setTime(9, 0), 'reversal_period_id' => $this->periodId]);
                $row = fn (array $o) => $base + ['source_ref' => "ACR-{$accrualId}", 'accrual_id' => $accrualId, 'gl_account_id' => null, 'fx_rate_type' => 'spot', 'match_status' => 'matched', 'match_reason' => 'source', 'matched_at' => $now,
                    'is_capex' => false, 'is_lease' => false, 'description' => "Received not invoiced: {$po->po_code}"] + $o;
                $a1 = DB::table('fin_actuals')->insertGetId($row(['period_id' => $sept->id, 'posted_on' => $sept->ends_on->toDateString(), 'source' => 'accrual', 'posting_key' => "accrual:{$accrualId}", 'amount' => $amount, 'base_amount' => $amount]));
                $a2 = DB::table('fin_actuals')->insertGetId($row(['period_id' => $this->periodId, 'posted_on' => $this->today->startOfMonth()->toDateString(), 'source' => 'accrual_reversal', 'posting_key' => "accrual-reversal:{$accrualId}",
                    'amount' => '-'.$amount, 'base_amount' => '-'.$amount, 'reverses_actual_id' => $a1]));
                DB::table('fin_accruals')->where('id', $accrualId)->update(['posted_actual_id' => $a1, 'reversal_actual_id' => $a2]);
            }
            // October: whatever is still received and unbilled is proposed.
            app(AccrualService::class)->propose(Period::withoutGlobalScopes()->findOrFail($this->periodId), $this->who['finance']);
        });
    }

    /* ------------------------------------------------------------------ actuals and imports */

    /** Jan to Sep of the current fiscal year as GL actuals (September is its own batch with rows to reconcile). */
    private function actualsHistory(): void
    {
        $this->attempt('actuals history', function () {
            $fy = FiscalYear::withoutGlobalScopes()->find($this->fyId);
            $periods = Period::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('fiscal_year_id', $this->fyId)->where('is_adjustment', false)
                ->whereDate('ends_on', '<', $this->periodStart())->orderBy('sequence')->get();
            if ($periods->isEmpty()) {
                return;
            }
            $budgets = Budget::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('fiscal_year_id', $this->fyId)->whereIn('status', ['baseline', 'revised'])->whereNull('superseded_by_id')->pluck('id');
            $lines = BudgetLine::withoutGlobalScopes()->whereIn('budget_id', $budgets)->orderBy('id')->get();
            $now = now();
            $by = $this->who['finance']->id;
            $batch = fn (string $name, string $status, array $report) => DB::table('fin_import_batches')->insertGetId([
                'organization_id' => $this->orgId, 'created_by_id' => $by, 'type' => 'actuals', 'file_name' => $name, 'file_sha256' => hash('sha256', $this->prefix.$name), 'row_count' => $report['rows'],
                'accepted_count' => $report['accepted'], 'rejected_count' => $report['rejected'], 'duplicate_count' => $report['duplicates'], 'matched_count' => $report['matched'], 'unmatched_count' => $report['unmatched'],
                'report' => json_encode($report), 'imported_by_id' => $by, 'status' => $status, 'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $lastPeriod = $periods->last();
            $earlier = $periods->slice(0, -1);
            $rows = ['early' => [], 'last' => []];
            $factors = [];
            foreach ($lines->values() as $n => $line) {
                $factors[$line->id] = match (true) {
                    $n % 11 === 0 || $n % 11 === 5 || $n === 2 => 1.15,
                    $n % 9 === 1 || $n === 7 || $n === 11 => 1.07,
                    $n % 13 === 3 => 0.85,
                    default => 0.92 + (mt_rand(0, 8) / 100),
                };
            }
            $lineRows = function (Period $p, float $mult) use ($lines, $factors, $now, $by) {
                $out = [];
                foreach ($lines as $line) {
                    $amount = number_format(((float) $line->base_annual_amount / 12) * $factors[$line->id] * $mult, 2, '.', '');
                    if ((float) $amount <= 0) {
                        continue;
                    }
                    $out[] = [
                        'organization_id' => $this->orgId, 'created_by_id' => $by, 'fiscal_year_id' => $this->fyId, 'period_id' => $p->id, 'posted_on' => $p->ends_on->toDateString(), 'source' => 'gl_import',
                        'source_ref' => sprintf('GL/%s/%02d/%04d', $this->prefix, $p->sequence, $line->id), 'gl_account_id' => $line->gl_account_id, 'category_id' => $line->category_id, 'vendor_id' => $line->vendor_id,
                        'contract_id' => $line->contract_id, 'cost_centre_id' => $line->cost_centre_id, 'budget_line_id' => $line->id, 'description' => mb_substr('Ledger posting: '.$line->description, 0, 255),
                        'amount' => $amount, 'currency' => 'NGN', 'fx_rate' => 1, 'fx_rate_type' => 'spot', 'fx_rate_date' => $p->ends_on->toDateString(), 'base_amount' => $amount, 'is_capex' => (bool) $line->is_capex,
                        'is_lease' => false, 'match_status' => 'matched', 'match_reason' => 'gl_account', 'matched_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                    ];
                }

                return $out;
            };
            $earlyCount = 0;
            $earlyId = $batch('GL extract Jan-Aug', 'completed', ['rows' => 0, 'accepted' => 0, 'rejected' => 0, 'duplicates' => 0, 'matched' => 0, 'unmatched' => 0, 'errors' => [], 'sample' => []]);
            foreach ($earlier as $p) {
                $chunk = array_map(fn ($r) => $r + ['import_batch_id' => $earlyId], $lineRows($p, 1.0));
                foreach (array_chunk($chunk, 200) as $c) {
                    DB::table('fin_actuals')->insert($c);
                }
                $earlyCount += count($chunk);
            }
            DB::table('fin_import_batches')->where('id', $earlyId)->update(['row_count' => $earlyCount, 'accepted_count' => $earlyCount, 'matched_count' => $earlyCount,
                'report' => json_encode(['mode' => 'apply', 'type' => 'actuals', 'rows' => $earlyCount, 'accepted' => $earlyCount, 'rejected' => 0, 'duplicates' => 0, 'matched' => $earlyCount, 'unmatched' => 0, 'errors' => [], 'sample' => [], 'batch_id' => $earlyId])]);

            // September: the closing month.
            $septRows = $lineRows($lastPeriod, 1.0);
            $unmatched = $this->unmatchedRows($lastPeriod, $now, $by);
            $dupes = $this->duplicateRows($lastPeriod, $now, $by);
            $total = count($septRows) + count($unmatched) + count($dupes);
            $report = ['mode' => 'apply', 'type' => 'actuals', 'rows' => $total, 'accepted' => $total - count($dupes), 'rejected' => 0, 'duplicates' => count($dupes), 'matched' => count($septRows),
                'unmatched' => count($unmatched), 'errors' => [], 'sample' => [], 'file_name' => 'GL extract Sep'];
            $septId = $batch('GL extract Sep', 'completed', $report);
            DB::table('fin_import_batches')->where('id', $septId)->update(['report' => json_encode($report + ['batch_id' => $septId])]);
            foreach (array_chunk(array_map(fn ($r) => $r + ['import_batch_id' => $septId], array_merge($septRows, $unmatched, $dupes)), 200) as $c) {
                DB::table('fin_actuals')->insert(array_map(fn ($r) => $r + array_fill_keys(['asset_id', 'investment_id', 'ea_application_id', 'accrual_id', 'posting_key', 'match_rule_id', 'duplicate_of_id', 'reverses_actual_id', 'matched_by_id', 'matched_at'], null) + ['gl_account_id' => null, 'category_id' => null, 'vendor_id' => null, 'contract_id' => null, 'budget_line_id' => null], $c));
            }
        });
    }

    private function periodStart(): string
    {
        return Period::withoutGlobalScopes()->whereKey($this->periodId)->value('starts_on')->format('Y-m-d');
    }

    /** @return list<array<string,mixed>> */
    private function unmatchedRows(Period $p, $now, int $by): array
    {
        $ccs = CostCentre::withoutGlobalScopes()->where('organization_id', $this->orgId)->whereIn('code', array_values($this->bank['cc']))->pluck('id', 'code');
        $ids = array_values($ccs->all());
        $why = ['ambiguous_gl', 'no_line_for_gl', 'unknown_vendor', 'ambiguous_gl', 'no_line_for_gl', 'unknown_cost_centre', 'unknown_vendor'];
        $narr = ['Cloud storage overage (unallocated)', 'Courier and handling, branch network hardware', 'Consultant daily rate, unnamed vendor', 'Software licence true-up', 'Cabling and civil works, Abuja annex',
            'Mobile data bundles, field engineers', 'Training exam fees, security certifications'];
        $rows = [];
        foreach ($why as $i => $reason) {
            $amount = number_format(180000 + mt_rand(0, 90) * 25000 * ($this->bank['first'] ? 3 : 1), 2, '.', '');
            $rows[] = $this->glRow($p, $now, $by, $ids[$i % count($ids)], $narr[$i], $amount, 'unmatched', $reason, sprintf('GL/%s/09/U%02d', $this->prefix, $i + 1));
        }
        $rows[] = $this->glRow($p, $now, $by, $ids[0], 'Regulatory penalty, late NDPC filing (acknowledged)', number_format(750000 * ($this->bank['first'] ? 3 : 1), 2, '.', ''), 'unbudgeted', 'acknowledged', sprintf('GL/%s/09/B01', $this->prefix));

        return $rows;
    }

    /** @return list<array<string,mixed>> two GL rows that repeat posted invoice actuals */
    private function duplicateRows(Period $p, $now, int $by): array
    {
        $out = [];
        foreach (Actual::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('source', 'invoice')->orderBy('id')->limit(2)->get() as $a) {
            $r = $this->glRow($p, $now, $by, (int) $a->cost_centre_id, 'Duplicate of '.$a->source_ref.' (also posted from the invoice)', (string) $a->base_amount, 'duplicate', 'duplicate_of_invoice', (string) $a->source_ref);
            $r['duplicate_of_id'] = $a->id;
            $out[] = $r;
        }

        return $out;
    }

    private function glRow(Period $p, $now, int $by, int $ccId, string $desc, string $amount, string $status, string $reason, string $ref): array
    {
        return [
            'organization_id' => $this->orgId, 'created_by_id' => $by, 'fiscal_year_id' => $this->fyId, 'period_id' => $p->id, 'posted_on' => $p->ends_on->toDateString(), 'source' => 'gl_import', 'source_ref' => $ref,
            'cost_centre_id' => $ccId, 'description' => mb_substr($desc, 0, 255), 'amount' => $amount, 'currency' => 'NGN', 'fx_rate' => 1, 'fx_rate_type' => 'spot', 'fx_rate_date' => $p->ends_on->toDateString(),
            'base_amount' => $amount, 'is_capex' => false, 'is_lease' => false, 'match_status' => $status, 'match_reason' => $reason, 'created_at' => $now, 'updated_at' => $now,
        ];
    }

    /** An invoices CSV with two valid rows and three rejected ones, applied through the importer so the batch report is real. */
    private function invoicesImport(): void
    {
        $this->attempt('invoices import', function () {
            $cc = $this->cc('NET')->code;
            $v = $this->vendor('mtn')->name;
            $ok = fn ($no, $amt, $desc) => implode(',', [$no, '"'.$v.'"', '', '', $this->today->subDays(9)->toDateString(), $this->today->addDays(21)->toDateString(), 'NGN', $amt, '', '', $cc, '', '"'.$desc.'"']);
            $csv = implode("\n", [
                implode(',', FinanceTransactionImporter::INVOICE_HEADERS),
                $ok('MTN/OCT/'.random_int(1000, 9999), '1450000.00', 'MPLS circuit rental, October'),
                $ok('MTN/OCT/'.random_int(1000, 9999), '620000.00', 'Static IP block rental'),
                implode(',', ['BAD-001', '"Unknown Vendor Ltd"', '', '', $this->today->subDays(9)->toDateString(), '', 'NGN', '90000.00', '', '', $cc, '', '"Vendor not on the register"']),
                implode(',', ['BAD-002', '"'.$v.'"', '', '', 'not-a-date', '', 'NGN', '90000.00', '', '', $cc, '', '"Bad invoice date"']),
                implode(',', ['BAD-003', '"'.$v.'"', '', '', $this->today->subDays(9)->toDateString(), '', 'NGN', 'abc', '', '', 'NO-SUCH-CC', '', '"Bad amount and cost centre"']),
            ])."\n";
            $file = UploadedFile::fake()->createWithContent($this->prefix.'-invoices-oct.csv', $csv);
            app(FinanceTransactionImporter::class)->invoices($file, $this->who['procurement'], true);
        });
    }

    private function notificationHistory(): void
    {
        $inv = Invoice::withoutGlobalScopes()->where('organization_id', $this->orgId)->whereIn('status', ['approved', 'scheduled'])->whereDate('due_on', '<', $this->today->toDateString())->orderBy('due_on')->first();
        if (! $inv) {
            return;
        }
        $owner = $this->owners[(int) $inv->cost_centre_id] ?? null;
        $users = array_filter([$this->who['finance']->id, $owner?->id, $this->who['procurement']->id]);
        $key = 'invoice_due:'.$inv->due_on->format('Y-m-d');
        $now = now();
        foreach ([7 => 7, 0 => 0, -1 => 1, -7 => 7] as $offset => $daysFromDue) {
            $day = $offset >= 0 ? $inv->due_on->copy()->subDays($daysFromDue) : $inv->due_on->copy()->addDays($daysFromDue);
            foreach (array_unique($users) as $uid) {
                DB::table('fin_notification_logs')->insertOrIgnore([
                    'organization_id' => $this->orgId, 'notifiable_type' => Invoice::class, 'notifiable_id' => $inv->id, 'notification_key' => $key, 'offset_days' => $offset, 'sent_to_user_id' => $uid,
                    'sent_at' => $day->setTime(6, 30)->utc(), 'channel' => 'database', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }
}
