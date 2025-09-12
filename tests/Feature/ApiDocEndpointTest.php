<?php

use function Pest\Laravel\getJson;

it('returns the OpenAPI documentation', function () {
    getJson('/api/v1/doc')
        ->assertOk()
        ->assertJsonPath('openapi', '3.1.0')
        ->assertJsonPath('paths./api/v1/resource-types/elmo.get.summary', 'List resource types enabled for ELMO');
});
