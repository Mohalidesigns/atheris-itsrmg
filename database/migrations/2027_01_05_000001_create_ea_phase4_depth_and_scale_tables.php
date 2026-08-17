<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ATH-EAR-002 Phase 4 — "Depth and scale" (§9).
 *
 * Seven work streams need storage this schema does not yet have:
 *
 *  · WS 4.1 (B13) real diagram editor — `ea_diagram_versions` so a saved canvas
 *    is restorable, and `ea_diagram_entities` so the question RBCF App. II
 *    §1.1(i) actually asks ("show me the approved topology diagram that covers
 *    this connection") is answerable by query rather than by eyeball.
 *  · WS 4.3 (B15) plateau diff — `ea_plateau_entities`. `plateau_id` on the
 *    entity itself can only express "this record belongs to one plateau", which
 *    makes a current-vs-target comparison impossible without duplicating every
 *    record. Membership needs its own row, and it needs a *disposition* — the
 *    post-merger question is not "is this app in the target" but "is it
 *    retained, retired, replaced or introduced".
 *  · WS 4.5 draft-and-approve — `ea_draft_changes`. An MCP or GraphQL write
 *    lands as a proposal, not a mutation.
 *  · WS 4.6 / §10 performance — `ea_closure` (materialised hierarchy closure)
 *    and `ea_application_capabilities` (materialised JSON pivot). §10 names
 *    both by hand: "the current `byParent` recursive render and JSON-column
 *    filtering will not hold; add materialised closure tables for hierarchies
 *    and indexed columns for anything filtered."
 *
 * Driver-agnostic throughout: the suite runs on in-memory SQLite, production is
 * MySQL (gotcha 3 from Phase 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->diagramTables();
        $this->plateauMembership();
        $this->draftChanges();
        $this->performanceTables();
        $this->hotColumnIndexes();
        $this->backfill();
    }

    /* ================= WS 4.1 — diagram editor ================= */

    private function diagramTables(): void
    {
        // Every save mints a version. §10 asks for "rollback for accidental bulk
        // changes (Avolution ships this; it is a governance selling point)" —
        // for diagrams that means the snapshot, not a field-level audit row.
        if (! Schema::hasTable('ea_diagram_versions')) {
            Schema::create('ea_diagram_versions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->foreignId('diagram_id')->constrained('ea_diagrams')->cascadeOnDelete();
                $table->unsignedInteger('version');
                $table->json('elements_json')->nullable();
                $table->json('edges_json')->nullable();
                $table->json('layout_json')->nullable();
                $table->string('status', 32)->default('draft');
                $table->string('change_note', 255)->nullable();
                $table->unsignedInteger('element_count')->default(0);
                $table->unsignedInteger('edge_count')->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['diagram_id', 'version']);
            });
        }

        // Slim index of which repository entities a diagram actually depicts.
        // Kept in sync on save; the canvas JSON stays the authoring truth.
        if (! Schema::hasTable('ea_diagram_entities')) {
            Schema::create('ea_diagram_entities', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->foreignId('diagram_id')->constrained('ea_diagrams')->cascadeOnDelete();
                $table->string('element_key', 64);
                $table->string('entity_type', 64);
                $table->unsignedBigInteger('entity_id');
                $table->string('archimate_type', 48);
                $table->timestamps();
                $table->index(['entity_type', 'entity_id']);
                $table->unique(['diagram_id', 'element_key']);
            });
        }

        Schema::table('ea_diagrams', function (Blueprint $table) {
            if (! Schema::hasColumn('ea_diagrams', 'layout_algorithm')) {
                // layered / hierarchical / grid / circular / manual
                $table->string('layout_algorithm', 24)->default('manual')->after('layout_json');
            }
            if (! Schema::hasColumn('ea_diagrams', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('owner_id');
            }
            if (! Schema::hasColumn('ea_diagrams', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
            if (! Schema::hasColumn('ea_diagrams', 'is_topology')) {
                // RBCF App. II §1.1(i) — "approved network topology diagram".
                $table->boolean('is_topology')->default(false)->after('approved_at');
            }
            if (! Schema::hasColumn('ea_diagrams', 'validation_json')) {
                $table->json('validation_json')->nullable()->after('is_topology');
            }
        });
    }

    /* ================= WS 4.3 — plateau membership ================= */

    private function plateauMembership(): void
    {
        if (Schema::hasTable('ea_plateau_entities')) {
            return;
        }

        Schema::create('ea_plateau_entities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->foreignId('plateau_id')->constrained('ea_plateaux')->cascadeOnDelete();
            $table->string('entity_type', 64);
            $table->unsignedBigInteger('entity_id');

            // The whole point of the table. `retain` and `introduce` mean the
            // entity is present in this plateau; `retire` means it is not.
            // `replace` is present-but-doomed and carries the successor.
            $table->string('disposition', 16)->default('retain'); // retain/introduce/modify/replace/retire
            $table->unsignedBigInteger('replaced_by_id')->nullable();

            // Target-state economics. Null means "same as today", which is the
            // honest default — a scenario that invents numbers for every record
            // produces a confident total over guesses.
            $table->decimal('target_annual_cost', 18, 2)->nullable();
            $table->string('target_cost_currency', 3)->nullable();
            $table->decimal('one_off_cost', 18, 2)->nullable();
            $table->unsignedTinyInteger('confidence')->nullable(); // 1-5, how firm the number is
            $table->text('rationale')->nullable();
            $table->timestamps();

            $table->unique(['plateau_id', 'entity_type', 'entity_id'], 'ea_plateau_entities_unique');
            $table->index(['entity_type', 'entity_id']);
            $table->index(['plateau_id', 'disposition']);
        });
    }

    /* ================= WS 4.5 — draft-and-approve ================= */

    private function draftChanges(): void
    {
        if (Schema::hasTable('ea_draft_changes')) {
            return;
        }

        Schema::create('ea_draft_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->string('reference', 32)->index();
            $table->string('origin', 24)->default('mcp'); // mcp / graphql / api / ui
            $table->string('operation', 16);              // create / update / delete
            $table->string('entity_type', 64);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('payload');
            $table->json('before')->nullable();
            $table->json('validation_json')->nullable();
            $table->string('status', 16)->default('pending'); // pending/approved/rejected/applied/failed
            $table->text('reason')->nullable();
            $table->string('proposed_by_label', 96)->nullable(); // agent identity, when not a user
            $table->unsignedBigInteger('proposed_by')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'entity_type']);
        });
    }

    /* ================= §10 — performance ================= */

    private function performanceTables(): void
    {
        // Materialised transitive closure over any parent_id hierarchy. One row
        // per (ancestor, descendant) pair including the self-pair at depth 0, so
        // "the whole subtree of X" is a single indexed read instead of a
        // recursive PHP walk over the full table.
        if (! Schema::hasTable('ea_closure')) {
            Schema::create('ea_closure', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->string('entity_type', 64);
                $table->unsignedBigInteger('ancestor_id');
                $table->unsignedBigInteger('descendant_id');
                $table->unsignedSmallInteger('depth');
                $table->unique(['entity_type', 'ancestor_id', 'descendant_id'], 'ea_closure_pair_unique');
                $table->index(['entity_type', 'ancestor_id', 'depth'], 'ea_closure_ancestor_idx');
                $table->index(['entity_type', 'descendant_id'], 'ea_closure_descendant_idx');
            });
        }

        // §10: "JSON-column filtering will not hold". `EaApplication.capability_ids`
        // is the named offender — ScoreCardService, CostModelService, the
        // capability overlay and CapabilityShow all filter on it.
        if (! Schema::hasTable('ea_application_capabilities')) {
            Schema::create('ea_application_capabilities', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->unsignedBigInteger('application_id');
                $table->unsignedBigInteger('capability_id');
                // When one app realises several capabilities, cost has to be
                // split somehow. Stored rather than assumed so an architect can
                // say "core banking is 70% payments, 30% deposits".
                $table->decimal('allocation_weight', 6, 4)->default(1.0);
                $table->timestamps();
                $table->unique(['application_id', 'capability_id'], 'ea_app_cap_unique');
                $table->index('capability_id');
            });
        }
    }

    /**
     * Indexes on the columns the module actually filters and sorts on. Cheap,
     * and the 5,000-entity benchmark in §10 is unreachable without them.
     */
    private function hotColumnIndexes(): void
    {
        $wanted = [
            'ea_applications_ext' => [
                ['organization_id', 'criticality'],
                ['organization_id', 'time_score'],
                ['business_fit', 'technical_fit'],
                ['plateau_id', 'lifecycle'],
            ],
            'ea_capabilities' => [
                ['organization_id', 'parent_id'],
                ['organization_id', 'level'],
            ],
            'ea_relationships' => [
                ['organization_id', 'relation_type'],
                ['source_type', 'source_id', 'relation_type'],
                ['target_type', 'target_id', 'relation_type'],
            ],
            'ea_interfaces' => [
                ['source_app_id', 'status'],
                ['target_app_id', 'status'],
            ],
            'ea_tech_components_ext' => [
                ['organization_id', 'radar_status'],
                ['organization_id', 'obsolescence_flag'],
            ],
            'ea_processes' => [
                ['organization_id', 'level'],
            ],
        ];

        foreach ($wanted as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($indexes as $columns) {
                $present = array_filter($columns, fn ($c) => Schema::hasColumn($table, $c));
                if (count($present) !== count($columns)) {
                    continue;
                }
                $name = 'idx_'.substr(md5($table.implode('_', $columns)), 0, 20);
                try {
                    Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
                } catch (\Throwable $e) {
                    // Index already present under another name — harmless.
                }
            }
        }
    }

    /**
     * Populate the two materialised tables from what is already in the
     * repository, so the first request after migrating is fast rather than
     * silently reading an empty pivot.
     */
    private function backfill(): void
    {
        // capability_ids JSON → pivot
        if (Schema::hasTable('ea_applications_ext') && Schema::hasTable('ea_application_capabilities')) {
            $capabilityIds = Schema::hasTable('ea_capabilities')
                ? DB::table('ea_capabilities')->pluck('id')->map(fn ($i) => (int) $i)->all()
                : [];
            $valid = array_flip($capabilityIds);

            $rows = [];
            DB::table('ea_applications_ext')
                ->select('id', 'organization_id', 'capability_ids')
                ->orderBy('id')
                ->chunk(500, function ($apps) use (&$rows, $valid) {
                    foreach ($apps as $app) {
                        $ids = json_decode((string) $app->capability_ids, true);
                        if (! is_array($ids)) {
                            continue;
                        }
                        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
                        $ids = array_values(array_filter($ids, fn ($id) => isset($valid[$id])));
                        if (! $ids) {
                            continue;
                        }
                        $weight = round(1 / count($ids), 4);
                        foreach ($ids as $capabilityId) {
                            $rows[] = [
                                'organization_id' => $app->organization_id,
                                'application_id' => $app->id,
                                'capability_id' => $capabilityId,
                                'allocation_weight' => $weight,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                        }
                    }
                });

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('ea_application_capabilities')->insertOrIgnore($chunk);
            }
        }

        // parent_id hierarchies → closure
        if (Schema::hasTable('ea_closure')) {
            foreach (['ea_capabilities' => \App\Models\Ea\Capability::class,
                      'ea_processes' => \App\Models\Ea\Process::class] as $table => $class) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'parent_id')) {
                    continue;
                }
                $this->rebuildClosure($table, $class);
            }
        }

        // plateau_id columns → membership rows, disposition `retain`.
        if (Schema::hasTable('ea_plateau_entities')) {
            $sources = [
                'ea_applications_ext' => \App\Models\Ea\EaApplication::class,
                'ea_capabilities' => \App\Models\Ea\Capability::class,
                'ea_tech_components_ext' => \App\Models\Ea\TechComponent::class,
            ];
            foreach ($sources as $table => $class) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'plateau_id')) {
                    continue;
                }
                $rows = [];
                DB::table($table)->whereNotNull('plateau_id')
                    ->select('id', 'organization_id', 'plateau_id')
                    ->orderBy('id')
                    ->chunk(500, function ($records) use (&$rows, $class) {
                        foreach ($records as $record) {
                            $rows[] = [
                                'organization_id' => $record->organization_id,
                                'plateau_id' => $record->plateau_id,
                                'entity_type' => $class,
                                'entity_id' => $record->id,
                                'disposition' => 'retain',
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                        }
                    });
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('ea_plateau_entities')->insertOrIgnore($chunk);
                }
            }
        }
    }

    /** Iterative closure build — no recursive CTE, so SQLite and MySQL agree. */
    private function rebuildClosure(string $table, string $class): void
    {
        $nodes = DB::table($table)->select('id', 'parent_id', 'organization_id')->get()
            ->keyBy('id');

        $rows = [];
        foreach ($nodes as $node) {
            $depth = 0;
            $rows[] = [
                'organization_id' => $node->organization_id,
                'entity_type' => $class,
                'ancestor_id' => $node->id,
                'descendant_id' => $node->id,
                'depth' => 0,
            ];
            $cursor = $node->parent_id;
            $guard = 0;
            while ($cursor && isset($nodes[$cursor]) && $guard++ < 64) {
                $depth++;
                $rows[] = [
                    'organization_id' => $node->organization_id,
                    'entity_type' => $class,
                    'ancestor_id' => (int) $cursor,
                    'descendant_id' => $node->id,
                    'depth' => $depth,
                ];
                $cursor = $nodes[$cursor]->parent_id;
            }
        }

        DB::table('ea_closure')->where('entity_type', $class)->delete();
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('ea_closure')->insertOrIgnore($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ea_application_capabilities');
        Schema::dropIfExists('ea_closure');
        Schema::dropIfExists('ea_draft_changes');
        Schema::dropIfExists('ea_plateau_entities');
        Schema::dropIfExists('ea_diagram_entities');
        Schema::dropIfExists('ea_diagram_versions');

        if (Schema::hasTable('ea_diagrams')) {
            Schema::table('ea_diagrams', function (Blueprint $table) {
                foreach (['layout_algorithm', 'approved_by', 'approved_at', 'is_topology', 'validation_json'] as $column) {
                    if (Schema::hasColumn('ea_diagrams', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
