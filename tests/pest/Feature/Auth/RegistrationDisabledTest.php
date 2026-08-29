<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('registration route is disabled', function () {
    $this->get('/register')->assertStatus(404);
    $this->post('/register')->assertStatus(404);
});
