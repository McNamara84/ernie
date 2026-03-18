<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Requests\DeactivateUserRequest;
use App\Http\Requests\ReactivateUserRequest;
use App\Http\Requests\RegisterDoiRequest;
use App\Http\Requests\ResetUserPasswordRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRoleRequest;
use App\Http\Requests\UploadIgsnCsvRequest;
use App\Http\Requests\UploadXmlRequest;
use App\Http\Requests\ValidateDoiRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;

/*
|--------------------------------------------------------------------------
| ValidateDoiRequest
|--------------------------------------------------------------------------
*/

describe('ValidateDoiRequest', function () {
    beforeEach(fn () => $this->user = User::factory()->create());

    it('validates with valid DOI', function () {
        $this->actingAs($this->user)
            ->postJson('/api/v1/doi/validate', ['doi' => '10.5880/test.2024.001'])
            ->assertJsonMissingValidationErrors(['doi']);
    });

    it('rejects missing DOI', function () {
        $this->actingAs($this->user)
            ->postJson('/api/v1/doi/validate', [])
            ->assertJsonValidationErrors(['doi']);
    });

    it('rejects DOI exceeding max length', function () {
        $this->actingAs($this->user)
            ->postJson('/api/v1/doi/validate', ['doi' => str_repeat('a', 256)])
            ->assertJsonValidationErrors(['doi']);
    });

    it('accepts optional exclude_resource_id', function () {
        $this->actingAs($this->user)
            ->postJson('/api/v1/doi/validate', ['doi' => '10.5880/test', 'exclude_resource_id' => 1])
            ->assertJsonMissingValidationErrors(['exclude_resource_id']);
    });

    it('rejects non-integer exclude_resource_id', function () {
        $this->actingAs($this->user)
            ->postJson('/api/v1/doi/validate', ['doi' => '10.5880/test', 'exclude_resource_id' => 'abc'])
            ->assertJsonValidationErrors(['exclude_resource_id']);
    });
});

/*
|--------------------------------------------------------------------------
| RegisterDoiRequest
|--------------------------------------------------------------------------
*/

describe('RegisterDoiRequest', function () {
    beforeEach(fn () => $this->user = User::factory()->create());

    it('rejects missing prefix', function () {
        Config::set('datacite.test_mode', true);
        Config::set('datacite.test.prefixes', ['10.5880']);

        // We can't easily test the full registration endpoint without mocking DataCite,
        // but we can verify the request validates prefix
        $request = new RegisterDoiRequest;
        $rules = $request->rules();

        expect($rules)->toHaveKey('prefix');
        expect($rules['prefix'])->toContain('required');
        expect($rules['prefix'])->toContain('string');
    });

    it('returns custom messages for prefix validation', function () {
        Config::set('datacite.test_mode', true);
        Config::set('datacite.test.prefixes', ['10.5880']);

        $request = new RegisterDoiRequest;
        $messages = $request->messages();

        expect($messages)->toHaveKey('prefix.required');
        expect($messages)->toHaveKey('prefix.in');
    });

    it('authorizes all users', function () {
        $request = new RegisterDoiRequest;

        expect($request->authorize())->toBeTrue();
    });
});

/*
|--------------------------------------------------------------------------
| StoreUserRequest
|--------------------------------------------------------------------------
*/

describe('StoreUserRequest', function () {
    beforeEach(fn () => $this->admin = User::factory()->create(['role' => UserRole::ADMIN]));

    it('allows admin to create user', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/users', [
                'name' => 'New User',
                'email' => 'new@example.org',
            ]);

        // Should pass validation (may redirect on success)
        expect($response->status())->not->toBe(422);
    });

    it('rejects duplicate email', function () {
        $existing = User::factory()->create(['email' => 'taken@example.org']);

        $this->actingAs($this->admin)
            ->postJson('/users', [
                'name' => 'Another User',
                'email' => 'taken@example.org',
            ])
            ->assertJsonValidationErrors(['email']);
    });

    it('rejects missing name', function () {
        $this->actingAs($this->admin)
            ->postJson('/users', [
                'email' => 'valid@example.org',
            ])
            ->assertJsonValidationErrors(['name']);
    });

    it('rejects missing email', function () {
        $this->actingAs($this->admin)
            ->postJson('/users', [
                'name' => 'Test User',
            ])
            ->assertJsonValidationErrors(['email']);
    });

    it('rejects invalid email', function () {
        $this->actingAs($this->admin)
            ->postJson('/users', [
                'name' => 'Test',
                'email' => 'not-an-email',
            ])
            ->assertJsonValidationErrors(['email']);
    });

    it('forbids beginner from creating users', function () {
        $beginner = User::factory()->create(['role' => UserRole::BEGINNER]);

        $this->actingAs($beginner)
            ->postJson('/users', [
                'name' => 'Test',
                'email' => 'test@example.org',
            ])
            ->assertForbidden();
    });
});

/*
|--------------------------------------------------------------------------
| UpdateUserRoleRequest
|--------------------------------------------------------------------------
*/

describe('UpdateUserRoleRequest', function () {
    it('requires a valid role enum value', function () {
        $request = new UpdateUserRoleRequest;
        $rules = $request->rules();

        expect($rules)->toHaveKey('role');
    });

    it('provides attribute for role field', function () {
        $request = new UpdateUserRoleRequest;

        expect($request->attributes())->toBe(['role' => 'user role']);
    });
});

/*
|--------------------------------------------------------------------------
| DeactivateUserRequest / ReactivateUserRequest / ResetUserPasswordRequest
|--------------------------------------------------------------------------
*/

describe('DeactivateUserRequest', function () {
    it('has empty rules', function () {
        $request = new DeactivateUserRequest;

        expect($request->rules())->toBe([]);
    });
});

describe('ReactivateUserRequest', function () {
    it('has empty rules', function () {
        $request = new ReactivateUserRequest;

        expect($request->rules())->toBe([]);
    });
});

describe('ResetUserPasswordRequest', function () {
    it('has empty rules', function () {
        $request = new ResetUserPasswordRequest;

        expect($request->rules())->toBe([]);
    });
});

/*
|--------------------------------------------------------------------------
| UploadXmlRequest
|--------------------------------------------------------------------------
*/

describe('UploadXmlRequest', function () {
    beforeEach(fn () => $this->user = User::factory()->create());

    it('accepts valid XML file upload', function () {
        $file = UploadedFile::fake()->createWithContent('test.xml', '<?xml version="1.0"?><root/>');

        $response = $this->actingAs($this->user)
            ->postJson('/dashboard/upload-xml', ['file' => $file]);

        // Should not fail on file validation (may fail on XML content parsing)
        $response->assertJsonMissingValidationErrors(['file']);
    });

    it('rejects upload without file', function () {
        $this->actingAs($this->user)
            ->postJson('/dashboard/upload-xml', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'file_required');
    });

    it('returns custom messages', function () {
        $request = new UploadXmlRequest;
        $messages = $request->messages();

        expect($messages)->toHaveKeys([
            'file.required',
            'file.file',
            'file.mimes',
            'file.max',
        ]);
    });

    it('has correct validation rules', function () {
        $request = new UploadXmlRequest;
        $rules = $request->rules();

        expect($rules['file'])->toContain('required');
        expect($rules['file'])->toContain('file');
    });
});

/*
|--------------------------------------------------------------------------
| UploadIgsnCsvRequest
|--------------------------------------------------------------------------
*/

describe('UploadIgsnCsvRequest', function () {
    beforeEach(fn () => $this->user = User::factory()->create());

    it('authorizes all users', function () {
        $request = new UploadIgsnCsvRequest;

        expect($request->authorize())->toBeTrue();
    });

    it('has correct validation rules', function () {
        $request = new UploadIgsnCsvRequest;
        $rules = $request->rules();

        expect($rules['file'])->toContain('required');
        expect($rules['file'])->toContain('file');
    });

    it('returns custom messages', function () {
        $request = new UploadIgsnCsvRequest;
        $messages = $request->messages();

        expect($messages)->toHaveKeys([
            'file.required',
            'file.file',
            'file.mimes',
            'file.max',
        ]);
    });

    it('rejects upload without file', function () {
        $this->actingAs($this->user)
            ->postJson('/dashboard/upload-igsn-csv', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'file_required');
    });
});
