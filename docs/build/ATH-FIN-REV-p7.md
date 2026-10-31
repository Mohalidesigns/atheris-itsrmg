# ATH-FIN-REV-P7 — Final reviews of IT Financial Management (whole module, at c7d0cc1)

Both reviews ran on Opus, read-only, on their own databases (`it_security_risk_secrev_test`, `it_security_risk_domrev_test`). They cover the whole module, not only the P7 diff. Fixes F1–F4 were dispatched on 2026-10-31; the outcome of each is recorded in ATH-FIN-003 §P7.

## Security review: NO-GO at c7d0cc1 → fixes F1, F2

The review confirmed these areas are clean:
- **Cross-bank IDs:** 192 parameterised `finance.*` routes were called with the other bank's ids; every one returned 403 or 404.
- **Cost-centre scoping:** 284 out-of-scope requests got no hits.
- **Masking on finance pages:** a view-only user received no amounts on any page.
- **Route gating:** all 281 routes are gated, and the 11 service-gated routes match the P7 list.
- **CSV formula guards:** present.
- **ICS feed:** signed, throttled and revocable.
- **Sweeps:** each runs per bank inside a try/catch.

| id | sev | finding | fix |
|---|---|---|---|
| C1 | Critical | The platform audit trail (`AuditTrailController`, stock spatie `Activity`) has no organisation filter and shows finance `properties` with amounts. The seeded Viewer role at First Bank can see Kano Heritage's invoice amounts. This existed before finance, in the initial commit. | F1 |
| M1 | Medium | `workflows.trail` checks only `view it-finance`. It skips `FinancePolicy::view` (cost-centre visibility) and the masked `FinanceApprovals::trail`, and shows `requires_cfo` conditions to viewers without the amounts permission. | F2 |
| M2 | Medium | Finance KRI masking is keyed on the editable `code`. The "measure now" flash prints the value, renaming the code unmasks it, and editing thresholds lets a user narrow down the value. | F2 |
| M3 | Medium | Concurrent approvals: four finance services did not lock the subject row, and `approval_decisions` had no unique `(step_id, user_id)` index. One person could satisfy a `min_approvals=2` stage. | F2 |
| L1 | Low | `RequestMemo` stayed active after a GET in the same process, so values went stale in tests and terminating callbacks. No cross-user leak: every key includes the user, organisation or cost-centre ids. | F2 |
| L2 | Low | `FinanceSettings` was bound as a singleton, so it goes stale under Octane or in a long-lived worker. | F2 (`scoped`) |
| L3 | Low | Anyone with `edit users` can change their own `job_title`, which defeats job-title stages. | F2 |
| L4 | Low | `ApprovalEngine::canDecide` used substring job-title matching, so the display flag was wrong. The server already refused the decision. | F2 |
| L5 | Low | The error message in `ProcurementService:87` contains amounts. | F4 |

## Domain review: CONDITIONAL GO → fixes F3, F4

| id | sev | finding | fix |
|---|---|---|---|
| H1 | High | A red variance passes period close when its note was acknowledged earlier, while the variance was only amber. Escalation, and postings after a re-open, never re-arm the note. | F3 |
| H2 | High | Possible GL duplicates are never resolved. The key needs an exact date and base amount (a GL posting date differs from the invoice date), nothing displays the flag, and both ways of clearing a row count it. | F3 |
| H3 | High | The same defect as security C1. | F1 |
| M1 | Medium | A prepayment release's successor line is resolved when the release is scheduled, not when it posts. | backlog |
| M2 | Medium | The direct-invoice cap counts rejected and soft-deleted invoices, and does not lock the request. | F4 |
| M3 | Medium | A re-open can be approved while a chargeback exists (a race at approval), and a chargeback can be exported while a re-open is pending. | F3 |
| M4 | Medium | A benefit's `aggregation` and `type` can still be edited after approval. | F4 |
| M5 | Medium | `finops_excess` is not masked. | F4 |
| M6 | Medium | Accruals do not store the FX rate type and date, and fall back to the PO rate without saying so. | backlog |

Domain Lows outside P4 (backlog):
1. **IAS 21:** the expense should be at the GRN-date rate, with the liability retranslated at the closing rate. The current management-reporting simplification needs documenting.
2. **EVM after delivery:** actual cost keeps growing after `actual_ends_on`.
3. **Benefit readings:** an `updateOrCreate` overwrites a closed period's reading. Keep history.
4. **Benefit freeze:** null to null counts as a change (fixed in F4).
5. **In-file GL duplicates:** legitimate identical lines are excluded rather than flagged.
6. **Global readiness:**
   - one global timezone
   - a Monday–Friday working week with no holiday calendar
   - the EA TCO comparison only when the base currency is NGN
   - the KRI owner found by English job title

### P4 domain Lows (re-derived; these were never itemised in P4)

| id | finding |
|---|---|
| L1 | BvA columns use different bases. Committed, Available and Forecast are for the full year as of today, while Budget, Actual and Variance follow the selected range. |
| L2 | "YTD" means the whole year before the first period has ended. |
| L3 | "As of" is the last ended period, not the last closed one. The sweep evaluates periods that have ended but are still open, with no grace period. |
| L4 | The absolute variance floor is bank-wide only; FIN-01.08 asks for it per line. |
| L5 | A red note can be explained without a corrective action (APO06.05). |
| L6 | The run-rate EAC ignores phasing, so an annual payment made in P1 is multiplied by 12. |
| L7 | The forecast variance is labelled "vs baseline" but is calculated against the current budget. |
| L8 | Burn and exhaustion dates assume 30.4375-day months. |
| L9 | The dashboard's `unexplained_red` counts only `open` notes, so it disagrees with the close checklist (fixed in F3). |
| L10 | The unbudgeted figure double-counts and is not limited to the fiscal year. |
| L11 | Finance KRI definitions: `-UNB` uses inconsistent numerator and denominator, `-VAR` nets overspends against underspends, and `-NOT` also counts contracts past their end date. |
| L12 | Period 1 can't close while the prior year's adjustment period is open. |
| L13 | Lock has no segregation of duties from close and no checklist. |
| L14 | The re-open approver doesn't see the capitalisation warning. |
| L15 | Reports don't tie out:<br>• the BvA report's totals leave out unallocated actuals<br>• the vendor spend report uses its own definition of commitment<br>• invoice ageing uses today's status and the gross amount |
| L16 | The close checklist has no item for missing closing FX rates or unresolved possible duplicates (the duplicates item was added in F3). `actuals_posted` fails a period that genuinely had no IT actuals. |
| L17 | The total EAC can be lower than the YTD actual when there is unbudgeted spend. |
| L18 | Close reminders assume a Monday–Friday week, one timezone and no holidays. |

## H9: requirement coverage

The domain reviewer mapped every §12 FIN-xx "M" clause to the test classes and methods that prove it. The table goes into ATH-FIN-003 §P7, grouped by FIN-01 to FIN-07. M clauses with no proof, or only partial proof:

| clause | coverage |
|---|---|
| FIN-07.06 audit-trail export per record or period | Not built. The audit trail is now scoped (F1); the export goes to the backlog. |
| FIN-03.15 invoice bulk import | Covered only by the seeded-estate assertion in `RouteSmokeTest`; there is no PHPUnit test. |
| FIN-02.13 and FIN-06.06 XLSX | Refused by decision (P1-2, P4-10). Record this as a spec deviation. |
| FIN-01.10 dimensions | Proven only at the props level. |
| FIN-04 "close blocked by unexplained red" | The escalation path was unproven; F3 adds the regression test. |
| FIN-06.04 and FIN-07.03 webhook channel | Optional in the spec, and not built. |

S items:
- **Delivered:** FIN-02.07, 03.06, 03.09, 03.10, 03.12, 03.13, 05.10, 05.11, 06.07, 06.08, 06.09.
- **Partial:**

  | item | what is missing |
  |---|---|
  | FIN-02.06 | driver × rate, not tested |
  | FIN-02.15 | attachments |
  | FIN-05.08 | built as links only |
  | FIN-07.04 | only the digest toggle |
  | FIN-07.10 | built as masking only |
- **Deferred:** FIN-01.11, 02.08, 02.09, 04.09, 07.08, 07.09.

### H9 table: each M clause and the tests that prove it
Tests are given as Class::method, with the `test_` prefix left off.

| M clause | proving tests |
|---|---|
| FIN-01.01 fiscal calendars | FinanceConfigurationTest::monthly_fiscal_year_has_twelve_contiguous_periods, ::fiscal_years_cannot_overlap_and_only_one_is_current, ::periods_close_in_order_and_lock_only_after_close, ::period_lookup_skips_the_adjustment_period_and_close_order_spans_years |
| FIN-01.02 cost centres | FinanceConfigurationTest::cost_centre_hierarchy_and_csv_import, ::cost_centre_parent_id_string_cannot_create_a_cycle |
| FIN-01.03 TBM | FinanceConfigurationTest::tbm_taxonomy_is_seeded_for_every_new_org, ::only_cost_pools_carry_capex_and_gl_defaults |
| FIN-01.04 GL | FinanceConfigurationTest::gl_codes_are_unique_per_org_only; ActualsImportAndReconciliationTest::auto_match_by_rules_then_cascade_leaves_ambiguous_in_queue |
| FIN-01.05 FX | FinanceConfigurationTest::fx_conversion_stores_rate_and_base_amount, ::fx_inverse_cross_rates_and_amount_rounding; FinanceDoaFxAndCurrencyGuardTest::convert_returns_rate_type_and_rate_date_with_fallback, ::base_currency_cannot_change_once_money_rows_exist |
| FIN-01.06 taxes | FinanceConfigurationTest::nigeria_tax_defaults_are_seeded_and_editable; ProcurementThreeWayMatchTest::vat_and_wht_computation_and_budget_cost |
| FIN-01.07 DoA | FinanceConfigurationTest::doa_bands_are_editable_and_banding_is_correct; FinanceWorkflowStudioTest::amount_band_condition_values_come_from_the_banks_doa_bands; FinanceDoaFxAndCurrencyGuardTest::band_change_needs_a_second_person_and_is_audited, ::future_effective_version_activates_on_its_date_and_remaps_stage_conditions |
| FIN-01.08 variance thresholds | FinanceConfigurationTest::variance_thresholds_validate; MonitoringAndVarianceTest::classify_thresholds (the absolute floor is not per line; see P4 L4) |
| FIN-01.09 renewal policy | FinanceConfigurationTest::renewal_policy_offsets_normalised; ContractNotificationSweepTest::per_contract_offset_override |
| FIN-01.10 dimensions | FinanceConfigurationTest::settings_props_expose_every_dimension_source (props only) |
| FIN-01.12 settings | FinanceConfigurationTest::settings_changes_are_audited; FinancePermissionsTest::settings_page_is_read_only_without_manage; P7BudgetAndApprovalHardeningTest::settings_thresholds_are_null_without_amount_permission |
| §12 FIN-01 close refused while actuals are unposted | PeriodCloseTest::close_refused_while_actuals_unposted |
| FIN-02.01 cycles | BudgetLifecycleTest::cycles_have_types_windows_and_guarded_transitions |
| FIN-02.02 versions and immutable baseline | BudgetLifecycleTest::baseline_is_immutable_and_an_edit_creates_version_two, ::only_one_baseline_per_fiscal_year_and_cost_centre, ::version_diff_reports_added_removed_changed_lines; BudgetDomainReviewRegressionTest::locked_versions_refuse_illegal_status_moves |
| FIN-02.03 targets and ceilings | BudgetWorkbookTest::ceiling_breach_flags_scope_and_lines, ::ceiling_on_a_parent_cost_centre_covers_its_children |
| FIN-02.04 lines and phasing | BudgetWorkbookTest::line_requires_tbm_pool_and_tower_and_defaults_gl_and_capex, ::every_phasing_method_sums_exactly_to_annual, ::custom_phasing_must_sum_to_annual; P7BudgetAndApprovalHardeningTest::spread_never_overshoots_or_goes_negative |
| FIN-02.05 budgeting modes | BudgetLifecycleTest::incremental_from_prior_baseline_and_prior_year_columns, ::incremental_uses_the_current_amount_including_adjustments; ActualsImportAndReconciliationTest::from_actuals_mode_and_py_actual_populate |
| FIN-02.10 virements | BudgetTransferTest::transfer_is_net_zero_and_applied_only_after_approval, ::transfer_cannot_exceed_availability_or_cross_capex_opex, ::requester_cannot_approve_own_transfer; P7BudgetAndApprovalHardeningTest::transfer_between_legal_entities_is_refused |
| FIN-02.11 supplementary | BudgetTransferTest::supplementary_request_lifecycle_creates_resulting_line, ::supplementary_top_up_of_existing_line |
| FIN-02.12 allocation | ShowbackAndAllocationTest::percentage_driver_and_direct_shares_and_the_most_specific_source_wins, ::largest_remainder_rounding_sums_to_the_cent |
| FIN-02.13 workbook (CSV) | BudgetWorkbookTest::workbook_csv_round_trip, ::workbook_import_is_all_or_nothing_with_report, ::bulk_grid_save_validates_per_cell, ::workbook_xlsx_is_refused_with_422 |
| FIN-02.14 submission and approval | BudgetLifecycleTest::draft_submitted_approved_baseline_happy_path_unconfigured, ::withdraw_return_and_reject_paths; FinanceWorkflowStudioTest::budget_runs_through_configured_stages_in_order; Playwright finance-budget.spec.js |
| FIN-02.16 prior-year columns | BudgetLifecycleTest::incremental_from_prior_baseline_and_prior_year_columns; ExpenseServicesSmokeTest::budget_from_actuals_uses_the_prior_year_and_py_actual_follows |
| FIN-03.01 availability | ExpenseApprovalAndAvailabilityTest::availability_is_current_budget_minus_committed_minus_actual, ::block_mode_refuses_over_budget_submission, ::warn_mode_requires_justification_and_flags_over_budget, ::final_approval_rechecks_availability_in_block_mode |
| FIN-03.02 DoA routing and SoD | ExpenseApprovalAndAvailabilityTest::doa_bands_route_stages_and_snapshot_band, ::requester_and_creator_cannot_approve_and_nobody_decides_two_stages; P7BudgetAndApprovalHardeningTest::non_matching_title_cannot_decide_a_job_title_stage |
| FIN-03.03 PO and GRN | ProcurementThreeWayMatchTest::commit_creates_po_with_fx_at_po_date_and_refuses_requester, ::partial_and_full_receipt_derive_statuses_and_refuse_over_receipt |
| FIN-03.04 three-way match | ProcurementThreeWayMatchTest::within_tolerance_auto_matches, ::outside_tolerance_is_variance_and_cannot_submit, ::override_needs_reason_and_adds_finance_controller_stage, ::schedule_pay_dispute_resolve, ::invoice_final_approver_cannot_be_po_approver_above_band, ::invoice_approval_posts_actual_in_invoice_period_once |
| FIN-03.05 actuals and unmatched queue | ActualsImportAndReconciliationTest::auto_match_by_rules_then_cascade_leaves_ambiguous_in_queue, ::manual_match_unmatch_and_remember_as_rule, ::gl_row_duplicating_invoice_actual_is_excluded_from_totals; P7SpendLedgerAndCloseHardeningTest (duplicate key, plus the F3 possible-duplicate tests) |
| FIN-03.07 recurring | ActualsImportAndReconciliationTest::recurring_expense_generates_expected_invoice_on_due_date; ExpenseWorkflowIntegrationTest::recurring_generate_is_idempotent |
| FIN-03.08 unbudgeted and emergency | ExpenseApprovalAndAvailabilityTest::unbudgeted_requires_justification_is_flagged_and_escalates_one_band, ::emergency_requires_justification_and_is_flagged; ExpenseWorkflowIntegrationTest::unbudgeted_submit_notifies_finance_and_head_of_it_once |
| FIN-03.14 evidence hash | ExpenseBackendSmokeTest::documents_are_hashed_in_the_vault_and_hidden_from_it; ContractRenewalLifecycleTest::documents_store_evidence_with_hash |
| FIN-03.15 bulk import | ExpenseApprovalAndAvailabilityTest::bulk_expense_import_preview_then_apply_creates_drafts; ActualsImportAndReconciliationTest::gl_import_preview_does_not_write_and_apply_creates_batch (invoices: smoke test only) |
| FIN-04.01 BvA to the cent | MonitoringAndVarianceTest::bva_reconciles_to_the_cent_for_every_grouping; MonitoringReviewRegressionTest::dashboard_kri_and_board_tie_to_bva_and_ledger |
| FIN-04.02 EAC | MonitoringAndVarianceTest::eac_remaining_budget_and_run_rate, ::manual_override_and_clear; MonitoringReviewRegressionTest::eac_is_floored_at_actuals_and_override_below_is_refused |
| FIN-04.03 thresholds, each alert sent once | MonitoringAndVarianceTest::variance_sweep_notifies_once_per_line_period_severity, ::variance_recipients; MonitoringWorkflowIntegrationTest::variance_listener_sends_once_and_escalation_sends_red_once |
| §12 close blocked by an unexplained red variance | PeriodCloseTest::close_blocked_by_unexplained_red_variance; P7SpendLedgerAndCloseHardeningTest::close_needs_red_variances_acknowledged_by_a_second_person; F3 escalation regression |
| FIN-04.04 burn | MonitoringAndVarianceTest::burn_and_exhaustion |
| FIN-04.05 close, lock, re-open | PeriodCloseTest (all); MonitoringWorkflowIntegrationTest::reopen_through_workflow_studio; P7SpendLedgerAndCloseHardeningTest::reopen_is_refused_while_a_chargeback_is_exported_and_clears_frozen_results |
| FIN-04.06 drill-down | MonitoringAndVarianceTest::drill_path_and_export_totals_match; P7SpendLedgerAndCloseHardeningTest::commitment_items_match_the_committed_total_and_posted_accruals_relieve_a_po |
| FIN-04.07 dashboards | MonitoringBackendSmokeTest::dashboard_props; MonitoringAndVarianceTest::owner_sees_own_cost_centres; FinOpsWorkflowIntegrationTest::dashboard_cloud_metrics_and_masking |
| FIN-04.08 KRIs | MonitoringAndVarianceTest::kri_measure_produces_finance_readings; MonitoringWorkflowIntegrationTest::kri_measure_records_the_four_finance_kris; FinOpsWorkflowIntegrationTest::kri_fin_cld_is_measured; P7FinOpsHardeningTest::finance_kri_definitions_are_provisioned_for_every_bank |
| FIN-04.10 commentary and issues | MonitoringAndVarianceTest::explain_acknowledge_comment_and_raise_issue; MonitoringBackendSmokeTest::explain_acknowledge_comment_and_raise_issue_over_http |
| FIN-04.11 board pack | MonitoringWorkflowIntegrationTest::board_pack_section_and_masking; FinanceReportsTest::report_figures_finance |
| FIN-05.01 portfolio | InvestmentAndBusinessCaseTest::lifecycle_idea_to_closed; InvestmentBackendSmokeTest::pages_render |
| FIN-05.02 NPV, IRR and payback | Unit BusinessCaseCalculatorTest::reference_case, ::irr_null_without_sign_change, ::rate_zero_npv_is_the_sum_of_net_flows, ::payback_beyond_horizon_is_null, ::multiple_sign_changes_flagged |
| FIN-05.03 scoring | InvestmentAndBusinessCaseTest::rank_recomputed_on_score_change, ::weights_sum_and_rescore, ::delivery_risk_is_inverted; P7InvestmentHardeningTest::sponsor_and_manager_cannot_score_their_own_investment |
| FIN-05.04 gates | InvestmentAndBusinessCaseTest::gate_advance_requires_approval, ::gate_criteria_enforced; P7InvestmentHardeningTest::funded_needs_tagged_lines_to_cover_bac, ::decision_rechecks_auto_criteria_and_payload_reads_actual_end |
| FIN-05.05 EVM | InvestmentAndBusinessCaseTest::evm_from_actuals, ::bac_set_on_approval_is_the_one_off_cost; P7InvestmentHardeningTest::evm_edge_cases, ::actual_with_an_investment_is_not_counted_via_another_investments_line |
| FIN-05.06 benefits | BenefitsAndValueReviewTest::realised_pct_updates_on_readings, ::status_derivation_at_due_date; P7InvestmentHardeningTest::benefit_control_fields_freeze_after_approval_and_closed_takes_no_readings |
| FIN-05.07 value reviews | BenefitsAndValueReviewTest::reviews_scheduled_on_delivery, ::sweep_notifies_once; InvestmentWorkflowIntegrationTest::value_review_sweep_sends_once_on_due_and_once_per_overdue_offset_and_rearms_on_reschedule |
| FIN-05.09 portfolio dashboard | InvestmentWorkflowIntegrationTest::dashboard_metrics_and_board_section_are_filled_and_masked |
| FIN-06.01 contract register | ContractRenewalLifecycleTest::register_fields_codes_and_fx; ContractBackendSmokeTest::create_computes_code_term_fx_and_dates |
| FIN-06.02 contract lifecycle | ContractRenewalLifecycleTest::status_derivation_every_path, ::manual_no_decision_notice_passed_then_expired, ::auto_renew_rolls_one_new_term |
| FIN-06.03 renewal windows | ContractRenewalLifecycleTest::dates_computed_on_create_and_recomputed_on_edit; ContractReviewRegressionTest::renewal_window_opens_lead_days_before_the_notice_deadline |
| FIN-06.04 T-minus alerts | ContractNotificationSweepTest::end_series_once_per_offset_to_whole_team, ::notice_series_once_per_offset, ::escalation_at_notice_minus_seven_once, ::recipients_are_exact_and_never_cross_banks |
| FIN-06.05 renewal decision | ContractRenewalLifecycleTest::approved_renewal_executes_successor_linked_both_ways, ::renewal_sod_owner_initiator_and_nobody_decides_two_stages; ExpenseWorkflowIntegrationTest::an_auto_rolled_renewal_raises_one_draft_expense_request; Playwright finance-contract-renewal.spec.js |
| FIN-06.06 calendar | CalendarTest::events_for_every_contract_milestone, ::passed_list_sorted_by_days_overdue_and_cleared_by_decision, ::ics_body_is_valid_and_has_no_amounts_or_other_bank_events; Playwright finance-calendar.spec.js |
| FIN-06.10 vendor spend | ContractBackendSmokeTest::index_vendor_spend_and_vendor_summary_sync; ExpenseBackendSmokeTest::vendor_spend_includes_expense_actuals |
| FIN-07.01 approval processes | FinanceWorkflowStudioTest::defaults_seed_per_process_idempotently_with_band_conditions, ::default_when_unconfigured; FinOpsWorkflowIntegrationTest::capitalisation_defaults_seed_two_stages_and_cfo_stage_is_conditional |
| FIN-07.02 SoD | FinanceWorkflowStudioTest::sod_owner_initiator_and_two_stage_rules, ::zero_step_request_still_enforces_segregation_of_duties; InvestmentWorkflowIntegrationTest::segregation_of_duties_and_one_stage_per_person |
| FIN-07.03 notifications | FinanceNotificationsTest::every_notification_renders_mail_and_database_payloads_without_amounts, ::catalogue_lists_every_notification_class |
| FIN-07.05 unified calendar | CalendarTest::cycle_and_period_milestones; ExpenseWorkflowIntegrationTest::calendar_and_digest_carry_invoices; InvestmentWorkflowIntegrationTest::calendar_returns_investment_events_passed_investments_and_ics |
| FIN-07.06 audit trail | FinanceAuditTrailTest (all); BudgetLifecycleTest::lifecycle_is_activity_logged; AuditTrailTenancyTest (F1). The export is not built. |
| FIN-07.07 reports | FinanceReportsTest::every_report_downloads_in_csv_xlsx_and_pdf, ::xlsx_is_a_valid_workbook_and_pdf_starts_with_the_marker |
| §12 FIN-07 masking | FinanceMaskingSweepTest (all); P7MaskingHardeningTest (all); RouteSmokeTest::finance_amounts_masked_and_query_budget_for_view_only_user |
| Tenancy | FinanceTenancyAndRoutesTest::every_finance_page_renders_for_an_organization_admin, ::records_404_for_another_banks_records, ::a_new_bank_with_no_finance_data_renders_empty_not_errors; FinanceRoutePermissionSweepTest::foreign_tenant_records_are_not_found |
