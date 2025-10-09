<?php

// These tests require a connection to the metaworks database
// They are skipped in CI environments where the database is not available

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Skip all tests in this file if metaworks database is not available
    try {
        DB::connection('metaworks')->getPdo();
    } catch (\Exception $e) {
        $this->markTestSkipped('Metaworks database connection not available');
    }
});

it('skips controlled keywords tests when metaworks db unavailable')->skip('Requires metaworks database');
