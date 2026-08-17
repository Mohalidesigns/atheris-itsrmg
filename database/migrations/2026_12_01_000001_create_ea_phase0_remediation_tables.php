<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ATH-EAR-002 Phase 0 remediation.
 *
 * Two changes, both called out as Phase 0 work in the rebuild plan:
 *
 * 1. `ea_interfaces.objective` / `review_cadence` / `last_reviewed_at`.
 *    §8.2 and Appendix B flag CBN Risk-Based Cybersecurity Framework
 *    App. II §1.1(i)–(k) as "the single most EA-tool-shaped clause in
 *    Nigerian regulation": a catalogue of all network connections to
 *    regulatory authorities, switches and third parties, *with the objective
 *    of each connection documented and regularly reviewed*. The interface
 *    register could not record an objective at all, so the clause was
 *    unanswerable. WS 0.1 requires the field on the create form.
 *
 * 2. `ea_feed_runs`. §2.4 (RC-4) and §10 make feed provenance a Phase 0
 *    release gate: the EOL and CVE clients fall back to bundled fixtures
 *    *silently*, so an evaluator on an air-gapped UAT box receives seven
 *    fixture CVEs presented as live data. Every sync now records whether it
 *    reached the live feed or served a fixture, and when it last succeeded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ea_interfaces', function (Blueprint $table) {
            if (! Schema::hasColumn('ea_interfaces', 'objective')) {
                $table->text('objective')->nullable()->after('name');
            }
            if (! Schema::hasColumn('ea_interfaces', 'counterparty_type')) {
                // regulator | switch | third_party | internal — the CBN clause
                // enumerates connections "to regulatory authorities, switches
                // and third parties" specifically.
                $table->string('counterparty_type', 32)->nullable()->after('objective');
            }
            if (! Schema::hasColumn('ea_interfaces', 'review_cadence')) {
                $table->string('review_cadence', 16)->nullable()->after('counterparty_type');
            }
            if (! Schema::hasColumn('ea_interfaces', 'last_reviewed_at')) {
                $table->date('last_reviewed_at')->nullable()->after('review_cadence');
            }
        });

        Schema::create('ea_feed_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            // assets | eol | cve
            $table->string('feed', 32)->index();
            // live | fixture | mixed | failed
            $table->string('provenance', 16)->default('failed');
            $table->string('endpoint')->nullable();
            $table->unsignedInteger('records_touched')->default(0);
            $table->unsignedInteger('records_written')->default(0);
            $table->text('message')->nullable();
            $table->string('triggered_by')->nullable();
            $table->timestamp('ran_at')->nullable();
            $table->timestamps();

            $table->index(['feed', 'ran_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ea_feed_runs');

        Schema::table('ea_interfaces', function (Blueprint $table) {
            foreach (['objective', 'counterparty_type', 'review_cadence', 'last_reviewed_at'] as $col) {
                if (Schema::hasColumn('ea_interfaces', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
