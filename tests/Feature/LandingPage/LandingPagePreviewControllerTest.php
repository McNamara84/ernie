<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\User;
use Illuminate\Support\Facades\Session;

uses()->group('landing-pages', 'preview');

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    
    $this->resource = Resource::factory()->create([
        'created_by_user_id' => $this->user->id,
    ]);
});

describe('Session Preview Creation', function () {
    test('can create temporary preview in session', function () {
        $response = $this->postJson("/resources/{$this->resource->id}/landing-page/preview", [
            'template' => 'default_gfz',
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/test.zip',
        ]);

        $response->assertStatus(200);

        $sessionKey = "landing_page_preview.{$this->resource->id}";
        expect(Session::has($sessionKey))->toBeTrue();
        
        $sessionData = Session::get($sessionKey);
        expect($sessionData)
            ->toHaveKey('template', 'default_gfz')
            ->toHaveKey('ftp_url', 'https://datapub.gfz-potsdam.de/download/test.zip')
            ->toHaveKey('resource_id', $this->resource->id);
    });

    test('validates required template field', function () {
        $response = $this->postJson("/resources/{$this->resource->id}/landing-page/preview", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['template']);
    });

    test('validates ftp_url format', function () {
        $response = $this->postJson("/resources/{$this->resource->id}/landing-page/preview", [
            'template' => 'default_gfz',
            'ftp_url' => 'not-a-url',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['ftp_url']);
    });

    test('does not create database record', function () {
        $this->postJson("/resources/{$this->resource->id}/landing-page/preview", [
            'template' => 'default_gfz',
        ]);

        expect($this->resource->fresh()->landingPage)->toBeNull();
    });
});

describe('Session Preview Display', function () {
    test('can view temporary preview from session', function () {
        Session::put("landing_page_preview.{$this->resource->id}", [
            'template' => 'default_gfz',
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/test.zip',
            'resource_id' => $this->resource->id,
        ]);

        $response = $this->get("/resources/{$this->resource->id}/landing-page/preview");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('LandingPages/default_gfz')
                ->has('resource')
                ->has('landingPage')
                ->where('isPreview', true)
            );
    });

    test('returns 404 when session preview does not exist', function () {
        $response = $this->get("/resources/{$this->resource->id}/landing-page/preview");

        $response->assertStatus(404);
    });

    test('session preview has correct structure', function () {
        Session::put("landing_page_preview.{$this->resource->id}", [
            'template' => 'default_gfz',
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/test.zip',
            'resource_id' => $this->resource->id,
        ]);

        $response = $this->get("/resources/{$this->resource->id}/landing-page/preview");

        $response->assertInertia(fn ($page) => $page
            ->where('landingPage.status', 'preview')
            ->where('landingPage.template', 'default_gfz')
            ->where('landingPage.ftp_url', 'https://datapub.gfz-potsdam.de/download/test.zip')
        );
    });
});

describe('Session Preview Deletion', function () {
    test('can clear preview session', function () {
        $sessionKey = "landing_page_preview.{$this->resource->id}";
        Session::put($sessionKey, [
            'template' => 'default_gfz',
            'resource_id' => $this->resource->id,
        ]);

        $response = $this->deleteJson("/resources/{$this->resource->id}/landing-page/preview");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Preview session cleared',
            ]);

        expect(Session::has($sessionKey))->toBeFalse();
    });

    test('clearing non-existent session returns success', function () {
        $response = $this->deleteJson("/resources/{$this->resource->id}/landing-page/preview");

        $response->assertStatus(200);
    });
});
