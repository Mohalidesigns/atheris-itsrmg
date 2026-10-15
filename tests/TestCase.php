<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * Restart the id sequence of every empty table, so a test that addresses records by literal id
     * (id 1, or the 1..n of a bulk insert) behaves the same on PostgreSQL as on in-memory SQLite.
     * RefreshDatabase rolls rows back but never sequences, which are not transactional.
     */
    protected function restartEmptySequences(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $serials = DB::select("select table_name from information_schema.columns
            where table_schema = current_schema() and column_name = 'id' and column_default like 'nextval%'");

        foreach ($serials as $row) {
            if (! DB::table($row->table_name)->exists()) {
                DB::statement("select setval(pg_get_serial_sequence(?, 'id'), 1, false)", [$row->table_name]);
            }
        }
    }
}
