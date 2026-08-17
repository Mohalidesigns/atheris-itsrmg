<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EA Phase 4 — closes the gaps identified in ATH-GAP-EA-001 v1.0.
 *
 * Adds:
 *   - ArchiMate Motivation layer: ea_goals, ea_drivers, ea_stakeholders, ea_outcomes, ea_courses_of_action
 *   - Data privacy: ea_dpia_assessments, ea_consent_purposes, ea_glossary_terms
 *   - Security: ea_threat_models, ea_threat_techniques
 *   - Visualisation: ea_diagrams (persisted ArchiMate viewpoints + free-form diagrams)
 *   - Roadmap: ea_initiative_dependencies, ea_adm_deliverables
 *   - Cross-cut: ea_anomaly_findings (output of anomaly rules), ea_search_index (Meilisearch shadow)
 *   - Column adds: plateau_id + version_no on capabilities/applications/tech-components,
 *                  pdf_hash + zip_hash + sha256 on evidence_packs,
 *                  contract files on apis, dependencies, owner_id columns
 */
return new class extends Migration {
    public function up(): void
    {
        // ====== Motivation layer (ArchiMate 3.2) ======
        Schema::create('ea_goals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('horizon', 16)->default('annual'); // annual / 3y / 5y
            $table->string('priority', 16)->default('medium');
            $table->string('status', 32)->default('active');
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('ea_drivers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('origin', 32)->default('internal'); // internal / external / regulatory / market
            $table->string('classification', 32)->default('strategic');
            $table->timestamps();
        });

        Schema::create('ea_stakeholders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            $table->string('role')->nullable();
            $table->string('influence', 16)->default('medium'); // low/medium/high
            $table->string('interest', 16)->default('medium');
            $table->timestamps();
        });

        Schema::create('ea_outcomes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('goal_id')->nullable()->constrained('ea_goals')->nullOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('measure_unit', 32)->default('ratio');
            $table->decimal('baseline', 12, 2)->nullable();
            $table->decimal('target', 12, 2)->nullable();
            $table->decimal('actual', 12, 2)->nullable();
            $table->date('measured_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ea_courses_of_action', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('initiative_id')->nullable()->constrained('ea_initiatives')->nullOnDelete();
            $table->string('status', 32)->default('proposed');
            $table->timestamps();
        });

        // ====== Privacy & Data ======
        Schema::create('ea_dpia_assessments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('subject');
            $table->foreignId('data_flow_id')->nullable()->constrained('ea_data_flows')->nullOnDelete();
            $table->foreignId('logical_entity_id')->nullable()->constrained('ea_logical_entities')->nullOnDelete();
            $table->string('status', 32)->default('draft'); // draft/in_review/approved/rejected
            $table->json('answers')->nullable();         // 10 NDPA Schedule questions
            $table->decimal('risk_score', 5, 2)->nullable();
            $table->string('risk_band', 16)->default('low');
            $table->text('mitigation_plan')->nullable();
            $table->string('dpo_decision', 32)->nullable();
            $table->date('decided_on')->nullable();
            $table->unsignedBigInteger('assessor_id')->nullable();
            $table->timestamps();
        });

        Schema::create('ea_consent_purposes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('purpose');
            $table->text('description')->nullable();
            $table->string('lawful_basis', 64)->default('consent'); // NDPA Article 25
            $table->string('retention_period', 32)->nullable();
            $table->boolean('cross_border')->default(false);
            $table->json('linked_flow_ids')->nullable();
            $table->timestamps();
        });

        Schema::create('ea_glossary_terms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('term', 128);
            $table->string('category', 32)->default('business');
            $table->text('definition');
            $table->text('synonyms')->nullable();
            $table->string('owner_role', 64)->nullable();
            $table->string('status', 32)->default('approved');
            $table->timestamps();
            $table->index(['tenant_id', 'term']);
        });

        // ====== Security threat models ======
        Schema::create('ea_threat_models', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            $table->string('subject_type', 64); // application / interface / logical_entity
            $table->unsignedBigInteger('subject_id');
            $table->string('methodology', 16)->default('STRIDE'); // STRIDE / LINDDUN / PASTA
            $table->json('summary')->nullable();
            $table->decimal('risk_score', 5, 2)->default(0);
            $table->string('status', 32)->default('draft');
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('ea_threat_techniques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('model_id')->constrained('ea_threat_models')->cascadeOnDelete();
            $table->string('category', 32); // STRIDE letter, LINDDUN letter, ATT&CK tactic
            $table->string('mitre_attack', 16)->nullable(); // T1003 etc.
            $table->string('threat');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('likelihood')->default(3);
            $table->unsignedTinyInteger('impact')->default(3);
            $table->unsignedTinyInteger('residual')->default(0);
            $table->text('mitigation')->nullable();
            $table->timestamps();
        });

        // ====== Visualisation ======
        Schema::create('ea_diagrams', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            $table->string('viewpoint', 32)->default('custom'); // layered / information_structure / application_cooperation / etc.
            $table->text('description')->nullable();
            $table->json('elements_json')->nullable();
            $table->json('edges_json')->nullable();
            $table->json('layout_json')->nullable();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->timestamps();
        });

        // ====== Roadmap supplements ======
        Schema::create('ea_initiative_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('predecessor_id')->constrained('ea_initiatives')->cascadeOnDelete();
            $table->foreignId('successor_id')->constrained('ea_initiatives')->cascadeOnDelete();
            $table->string('type', 16)->default('finish_to_start'); // FS/SS/FF/SF
            $table->unsignedInteger('lag_days')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['predecessor_id', 'successor_id']);
        });

        Schema::create('ea_adm_deliverables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('initiative_id')->constrained('ea_initiatives')->cascadeOnDelete();
            $table->string('phase', 4); // 'A'..'H' / 'Pre' / 'Req'
            $table->string('code', 32);
            $table->string('name');
            $table->string('status', 32)->default('not_started'); // not_started/in_progress/draft/approved
            $table->date('due_date')->nullable();
            $table->string('artifact_path')->nullable();
            $table->timestamps();
        });

        // ====== Anomaly & Discovery ======
        Schema::create('ea_anomaly_findings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('rule_code', 32);
            $table->string('severity', 16)->default('medium');
            $table->string('entity_type', 64);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('title');
            $table->text('detail')->nullable();
            $table->string('status', 16)->default('open'); // open / acknowledged / resolved / suppressed
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->timestamps();
            $table->index(['rule_code']);
            $table->index(['status']);
        });

        // ====== Lightweight search index ======
        Schema::create('ea_search_index', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('entity_type', 64);
            $table->unsignedBigInteger('entity_id');
            $table->string('code', 64)->nullable();
            $table->string('title');
            $table->text('haystack');
            $table->json('attributes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'entity_type']);
            // Full-text indexes on `haystack` would be added at the driver level; for SQLite/MySQL portability we LIKE-scan.
        });

        // ====== CVE / EOL tracking ======
        Schema::create('ea_tech_vulnerabilities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('component_id')->constrained('ea_tech_components_ext')->cascadeOnDelete();
            $table->string('cve_id', 24)->nullable();
            $table->string('cpe', 128)->nullable();
            $table->string('severity', 16)->default('medium'); // low/medium/high/critical
            $table->decimal('cvss', 4, 1)->nullable();
            $table->text('summary')->nullable();
            $table->string('source', 32)->default('nvd');
            $table->date('published_at')->nullable();
            $table->string('status', 16)->default('open'); // open / patched / accepted
            $table->timestamps();
        });

        // ====== Maturity multi-framework ======
        if (!Schema::hasColumn('ea_maturity_domains', 'framework')) {
            Schema::table('ea_maturity_domains', function (Blueprint $table) {
                $table->string('framework', 16)->default('cbn-ea')->after('code')->index();
            });
        }
        if (!Schema::hasColumn('ea_maturity_assessments', 'framework')) {
            Schema::table('ea_maturity_assessments', function (Blueprint $table) {
                $table->string('framework', 16)->default('cbn-ea')->after('title')->index();
            });
        }

        // ====== Plateau / version columns on long-lived entities ======
        Schema::table('ea_capabilities', function (Blueprint $table) {
            if (!Schema::hasColumn('ea_capabilities', 'plateau_id')) {
                $table->unsignedBigInteger('plateau_id')->nullable()->after('source')->index();
            }
            if (!Schema::hasColumn('ea_capabilities', 'version_no')) {
                $table->unsignedInteger('version_no')->default(1)->after('plateau_id');
            }
        });
        Schema::table('ea_applications_ext', function (Blueprint $table) {
            if (!Schema::hasColumn('ea_applications_ext', 'plateau_id')) {
                $table->unsignedBigInteger('plateau_id')->nullable()->after('lifecycle')->index();
            }
            if (!Schema::hasColumn('ea_applications_ext', 'version_no')) {
                $table->unsignedInteger('version_no')->default(1)->after('plateau_id');
            }
            if (!Schema::hasColumn('ea_applications_ext', 'vendor_id')) {
                $table->unsignedBigInteger('vendor_id')->nullable()->after('owner_role')->index();
            }
            if (!Schema::hasColumn('ea_applications_ext', 'six_r_score')) {
                $table->string('six_r_score', 16)->nullable()->after('time_score');
            }
            if (!Schema::hasColumn('ea_applications_ext', 'tco_annual_ngn')) {
                $table->decimal('tco_annual_ngn', 16, 2)->nullable()->after('annual_cost_ngn');
            }
        });
        Schema::table('ea_tech_components_ext', function (Blueprint $table) {
            if (!Schema::hasColumn('ea_tech_components_ext', 'plateau_id')) {
                $table->unsignedBigInteger('plateau_id')->nullable()->after('obsolescence_flag')->index();
            }
            if (!Schema::hasColumn('ea_tech_components_ext', 'tech_debt_score')) {
                $table->decimal('tech_debt_score', 5, 2)->default(0)->after('obsolescence_flag');
            }
            if (!Schema::hasColumn('ea_tech_components_ext', 'cpe')) {
                $table->string('cpe', 128)->nullable()->after('version');
            }
        });

        // ====== Evidence pack hashing & signing ======
        Schema::table('ea_evidence_packs', function (Blueprint $table) {
            if (!Schema::hasColumn('ea_evidence_packs', 'pdf_hash')) {
                $table->string('pdf_hash', 80)->nullable()->after('pdf_path');
            }
            if (!Schema::hasColumn('ea_evidence_packs', 'zip_hash')) {
                $table->string('zip_hash', 80)->nullable()->after('zip_path');
            }
            if (!Schema::hasColumn('ea_evidence_packs', 'signed_by')) {
                $table->string('signed_by', 128)->nullable()->after('zip_hash');
            }
            if (!Schema::hasColumn('ea_evidence_packs', 'signature')) {
                $table->string('signature', 255)->nullable()->after('signed_by');
            }
        });

        // ====== APIs contract ======
        Schema::table('ea_apis', function (Blueprint $table) {
            if (!Schema::hasColumn('ea_apis', 'contract_kind')) {
                $table->string('contract_kind', 16)->default('openapi')->after('auth_method'); // openapi / asyncapi / wsdl
            }
            if (!Schema::hasColumn('ea_apis', 'contract_text')) {
                $table->longText('contract_text')->nullable()->after('contract_kind');
            }
        });

        // ====== Standards exception cross-ref ======
        Schema::table('ea_exceptions', function (Blueprint $table) {
            if (!Schema::hasColumn('ea_exceptions', 'standard_id')) {
                $table->unsignedBigInteger('standard_id')->nullable()->after('subject')->index();
            }
            if (!Schema::hasColumn('ea_exceptions', 'principle_id')) {
                $table->unsignedBigInteger('principle_id')->nullable()->after('standard_id')->index();
            }
            if (!Schema::hasColumn('ea_exceptions', 'risk_band')) {
                $table->string('risk_band', 16)->default('medium')->after('status');
            }
        });

        // ====== ARB workflow signatures ======
        Schema::table('ea_arb_submissions', function (Blueprint $table) {
            if (!Schema::hasColumn('ea_arb_submissions', 'minutes')) {
                $table->longText('minutes')->nullable()->after('digital_signature');
            }
            if (!Schema::hasColumn('ea_arb_submissions', 'voters')) {
                $table->json('voters')->nullable()->after('minutes');
            }
            if (!Schema::hasColumn('ea_arb_submissions', 'votes')) {
                $table->json('votes')->nullable()->after('voters');
            }
            if (!Schema::hasColumn('ea_arb_submissions', 'decision_rationale')) {
                $table->text('decision_rationale')->nullable()->after('decided_by');
            }
            if (!Schema::hasColumn('ea_arb_submissions', 'risk_band')) {
                $table->string('risk_band', 16)->default('medium')->after('status');
            }
            if (!Schema::hasColumn('ea_arb_submissions', 'meeting_date')) {
                $table->date('meeting_date')->nullable()->after('risk_band');
            }
        });

        // ====== Audit log enrichment ======
        Schema::table('ea_audit_log', function (Blueprint $table) {
            if (!Schema::hasColumn('ea_audit_log', 'actor_email')) {
                $table->string('actor_email', 128)->nullable()->after('actor_id');
            }
            if (!Schema::hasColumn('ea_audit_log', 'ip')) {
                $table->string('ip', 64)->nullable()->after('after');
            }
            if (!Schema::hasColumn('ea_audit_log', 'context')) {
                $table->json('context')->nullable()->after('ip');
            }
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ea_tech_vulnerabilities');
        Schema::dropIfExists('ea_search_index');
        Schema::dropIfExists('ea_anomaly_findings');
        Schema::dropIfExists('ea_adm_deliverables');
        Schema::dropIfExists('ea_initiative_dependencies');
        Schema::dropIfExists('ea_diagrams');
        Schema::dropIfExists('ea_threat_techniques');
        Schema::dropIfExists('ea_threat_models');
        Schema::dropIfExists('ea_glossary_terms');
        Schema::dropIfExists('ea_consent_purposes');
        Schema::dropIfExists('ea_dpia_assessments');
        Schema::dropIfExists('ea_courses_of_action');
        Schema::dropIfExists('ea_outcomes');
        Schema::dropIfExists('ea_stakeholders');
        Schema::dropIfExists('ea_drivers');
        Schema::dropIfExists('ea_goals');
    }
};
