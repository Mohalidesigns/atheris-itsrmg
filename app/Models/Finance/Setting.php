<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use Illuminate\Database\Eloquent\Model;

/** Per-bank finance settings (ATH-FIN-001 §4.1): one row per organisation. */
class Setting extends Model
{
    use FinanceRecord;

    public const OVERBUDGET_MODES = ['warn', 'block'];

    public const EAC_METHODS = ['remaining_budget', 'run_rate'];

    public const SECTIONS = ['general', 'doa', 'variance', 'renewal'];

    protected $table = 'fin_settings';

    protected $guarded = [];

    protected $casts = [
        'renewal_notice_offsets' => 'array',
        'doa_bands' => 'array',
        'investment_score_weights' => 'array',
        'variance_amber_pct' => 'decimal:2',
        'variance_red_pct' => 'decimal:2',
        'variance_abs_floor' => 'decimal:2',
        'renewal_cfo_from' => 'decimal:2',
        'renewal_cfo_uplift_pct' => 'decimal:2',
        'match_tolerance_pct' => 'decimal:2',
        'match_tolerance_abs' => 'decimal:2',
        'showback_enabled' => 'boolean',
        'fiscal_year_start_month' => 'integer',
        'renewal_lead_days' => 'integer',
        'digest_hour' => 'integer',
    ];
}
