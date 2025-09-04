<?php

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('registration route is disabled', function () {
    $this->get('/register')->assertStatus(404);
    $this->post('/register')->assertStatus(404);
});
