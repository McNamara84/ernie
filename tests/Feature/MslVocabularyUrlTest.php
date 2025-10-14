<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Feature tests for MSL Vocabulary URL API endpoint
 */
class MslVocabularyUrlTest extends TestCase
{
    public function test_returns_vocabulary_url_from_config(): void
    {
        $response = $this->getJson('/api/v1/msl-vocabulary-url');

        $response->assertStatus(200);
        $response->assertJsonStructure(['url']);
        
        $url = $response->json('url');
        $this->assertIsString($url);
        $this->assertStringStartsWith('https://', $url);
        $this->assertStringContainsString('laboratories.json', $url);
    }
}
