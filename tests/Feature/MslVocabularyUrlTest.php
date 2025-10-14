<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for MSL Vocabulary URL endpoint
 */
class MslVocabularyUrlTest extends TestCase
{
    use RefreshDatabase;
    
    public function test_returns_vocabulary_url_from_config(): void
    {
        $user = \App\Models\User::factory()->create();
        
        $response = $this->actingAs($user)->getJson('/vocabularies/msl-vocabulary-url');

        $response->assertStatus(200);
        $response->assertJsonStructure(['url']);
        
        $url = $response->json('url');
        $this->assertIsString($url);
        $this->assertStringStartsWith('https://', $url);
        $this->assertStringContainsString('laboratories.json', $url);
    }
}
