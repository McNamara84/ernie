<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Requests\Datacenter\StoreDatacenterRequest;
use App\Http\Requests\LandingPage\StoreLandingPagePreviewRequest;
use App\Http\Requests\LandingPage\StoreLandingPageRequest;
use App\Http\Requests\LandingPage\UpdateLandingPageRequest;
use App\Http\Requests\LandingPageDomain\StoreLandingPageDomainRequest;
use App\Http\Requests\LandingPageTemplate\UploadLandingPageTemplateLogoRequest;
use App\Http\Requests\RelatedItem\ReorderRelatedItemsRequest;
use App\Http\Requests\Settings\DeleteProfileRequest;
use App\Http\Requests\Settings\UpdateThesaurusVersionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

/**
 * Run a FormRequest's rules() against the given payload.
 *
 * @param  array<string, mixed>  $payload
 */
function validatePhase8dRequest(string $class, array $payload, ?User $user = null, ?Request $base = null): Illuminate\Validation\Validator
{
    /** @var StoreDatacenterRequest|StoreLandingPageDomainRequest|StoreLandingPageRequest|UpdateLandingPageRequest|StoreLandingPagePreviewRequest|UploadLandingPageTemplateLogoRequest|ReorderRelatedItemsRequest|DeleteProfileRequest|UpdateThesaurusVersionRequest $request */
    $request = $class::create('/test', 'POST', $payload);

    if ($base !== null) {
        $request->setRouteResolver(fn () => $base->route());
    }

    if ($user !== null) {
        $request->setUserResolver(fn () => $user);
    }

    return Validator::make($payload, $request->rules());
}

it('rejects unauthenticated users on StoreDatacenterRequest', function () {
    $req = StoreDatacenterRequest::create('/x', 'POST', []);
    expect($req->authorize())->toBeFalse();
});

it('validates datacenter name', function () {
    $user = User::factory()->create();
    $v = validatePhase8dRequest(StoreDatacenterRequest::class, ['name' => ''], $user);
    expect($v->fails())->toBeTrue();
});

it('rejects unauthenticated users on StoreLandingPageDomainRequest', function () {
    $req = StoreLandingPageDomainRequest::create('/x', 'POST', []);
    expect($req->authorize())->toBeFalse();
});

it('requires a domain on StoreLandingPageDomainRequest', function () {
    $user = User::factory()->create();
    $v = validatePhase8dRequest(StoreLandingPageDomainRequest::class, ['domain' => ''], $user);
    expect($v->fails())->toBeTrue();
});

it('requires a valid template on StoreLandingPageRequest', function () {
    $user = User::factory()->create();
    $v = validatePhase8dRequest(StoreLandingPageRequest::class, ['template' => 'unknown_template'], $user);
    expect($v->fails())->toBeTrue();
});

it('accepts a valid template on StoreLandingPageRequest', function () {
    $user = User::factory()->create();
    $v = validatePhase8dRequest(StoreLandingPageRequest::class, ['template' => 'default_gfz'], $user);
    expect($v->fails())->toBeFalse();
});

it('makes template optional on UpdateLandingPageRequest', function () {
    $user = User::factory()->create();
    $v = validatePhase8dRequest(UpdateLandingPageRequest::class, [], $user);
    expect($v->fails())->toBeFalse();
});

it('accepts external template at the rules layer (controller rejects previews of external pages downstream)', function () {
    $user = User::factory()->create();
    $v = validatePhase8dRequest(StoreLandingPagePreviewRequest::class, ['template' => 'external'], $user);
    // External is allowed at the rules layer — the controller rejects previews of external pages.
    expect($v->fails())->toBeFalse();
});

it('requires a logo file on UploadLandingPageTemplateLogoRequest', function () {
    $user = User::factory()->create();
    $v = validatePhase8dRequest(UploadLandingPageTemplateLogoRequest::class, [], $user);
    expect($v->fails())->toBeTrue();
});

it('rejects ReorderRelatedItemsRequest without resource access', function () {
    $req = ReorderRelatedItemsRequest::create('/x', 'POST', []);
    expect($req->authorize())->toBeFalse();
});

it('validates reorder payload structure', function () {
    $user = User::factory()->create();
    $v = validatePhase8dRequest(ReorderRelatedItemsRequest::class, ['order' => 'not-an-array'], $user);
    expect($v->fails())->toBeTrue();
});

it('requires a password on DeleteProfileRequest', function () {
    $user = User::factory()->create();
    $v = validatePhase8dRequest(DeleteProfileRequest::class, [], $user);
    expect($v->fails())->toBeTrue();
});

it('denies non-thesaurus-managers on UpdateThesaurusVersionRequest', function () {
    $beginner = User::factory()->create(['role' => UserRole::BEGINNER]);
    $req = UpdateThesaurusVersionRequest::create('/x', 'PATCH', ['version' => '1-0']);
    $req->setUserResolver(fn () => $beginner);
    expect($req->authorize())->toBeFalse();
});

it('allows admins on UpdateThesaurusVersionRequest', function () {
    $admin = User::factory()->admin()->create();
    $req = UpdateThesaurusVersionRequest::create('/x', 'PATCH', ['version' => '1-0']);
    $req->setUserResolver(fn () => $admin);
    expect($req->authorize())->toBeTrue();
});

it('rejects malformed version strings', function () {
    $admin = User::factory()->admin()->create();
    $v = validatePhase8dRequest(UpdateThesaurusVersionRequest::class, ['version' => 'not!valid'], $admin);
    expect($v->fails())->toBeTrue();
});

it('accepts dash-separated version strings', function () {
    $admin = User::factory()->admin()->create();
    $v = validatePhase8dRequest(UpdateThesaurusVersionRequest::class, ['version' => '2-0'], $admin);
    expect($v->fails())->toBeFalse();
});
