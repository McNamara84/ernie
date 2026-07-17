<?php

declare(strict_types=1);

use App\Http\Controllers\AssessmentController;
use App\Jobs\RunResourceAssessmentsJob;
use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceAssessment;
use App\Models\ResourceType;
use App\Models\Title;
use App\Models\User;
use App\Services\Assessment\FujiAssessmentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery\MockInterface;

covers(AssessmentController::class);

/**
 * @param  array<string, int|float|string>  $earned
 * @param  list<array<string, mixed>>  $results
 * @return array<string, mixed>
 */
function assessmentControllerFujiPayload(array $earned = [], array $results = []): array
{
    $dimensionEarned = array_merge([
        'F' => 7,
        'A' => 7,
        'I' => 6,
        'R' => 6,
    ], $earned);

    $dimensionEarned['FAIR'] = (float) $dimensionEarned['F']
        + (float) $dimensionEarned['A']
        + (float) $dimensionEarned['I']
        + (float) $dimensionEarned['R'];

    return [
        'metric_version' => '0.8',
        'summary' => [
            'score_earned' => $dimensionEarned,
            'score_total' => [
                'F' => 7,
                'A' => 7,
                'I' => 6,
                'R' => 6,
                'FAIR' => 26,
            ],
            'score_percent' => [
                'FAIR' => round(((float) $dimensionEarned['FAIR'] / 26) * 100, 2),
            ],
        ],
        'results' => $results,
    ];
}

/**
 * @param  array<string, array{earned: int|float, total: int|float}>  $tests
 * @return array<string, mixed>
 */
function assessmentControllerFujiMetric(
    string $identifier,
    int|float $earned,
    int|float $total,
    array $tests,
): array {
    return [
        'metric_identifier' => $identifier,
        'score' => [
            'earned' => $earned,
            'total' => $total,
        ],
        'metric_tests' => array_map(
            static fn (array $score): array => [
                'metric_test_score' => $score,
                'metric_test_status' => $score['earned'] >= $score['total'] ? 'pass' : 'fail',
            ],
            $tests,
        ),
    ];
}

beforeEach(function (): void {
    Config::set('fuji.enabled', true);
    Config::set('fuji.base_url', 'https://fuji.test');
    Config::set('fuji.username', 'admin');
    Config::set('fuji.password', 'secret');
    Config::set('cache.default', 'array');
    Cache::flush();
    Http::fake([
        'https://fuji.test/fuji/api/v1/ui*' => Http::response('OK', 200),
    ]);
});

describe('index', function () {
    it('returns assessment page for admins', function () {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->get('/assessment')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('assessment')
                ->where('auth.user.can_access_assessment', true)
                ->where('fujiConfigured', true)
                ->where('fujiHealthy', true)
                ->where('fujiStatusMessage', null)
                ->has('resourcesNeedingAttention')
                ->has('igsnsNeedingAttention')
            );
    });

    it('renders the page with an unhealthy F-UJI warning when the service is temporarily unavailable', function () {
        $this->mock(FujiAssessmentService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('healthStatus')
                ->once()
                ->andReturn([
                    'healthy' => false,
                    'message' => 'F-UJI is currently unavailable. Please try again shortly.',
                    'statusCode' => 503,
                ]);
            $mock->shouldReceive('isConfigured')
                ->once()
                ->andReturn(true);
        });

        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->get('/assessment')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('assessment')
                ->where('fujiConfigured', true)
                ->where('fujiHealthy', false)
                ->where('fujiStatusMessage', 'F-UJI is currently unavailable. Please try again shortly.')
                ->where('fujiStatusCode', 503)
            );
    });

    it('rejects unauthenticated users', function () {
        $this->get('/assessment')
            ->assertRedirect('/login');
    });

    it('forbids non-admin users', function () {
        $user = User::factory()->create(['role' => 'curator']);

        $this->actingAs($user)
            ->get('/assessment')
            ->assertForbidden();
    });

    it('returns populated summaries and ordered attention lists for both scopes', function () {
        $physicalObjectType = ResourceType::factory()->create([
            'name' => 'Physical Object',
            'slug' => 'physical-object',
        ]);

        $lowestResource = Resource::factory()->withDoi('10.5880/test.resource.001')->create();
        Title::factory()->for($lowestResource)->create(['value' => 'Lowest resource']);
        ResourceAssessment::query()->create([
            'resource_id' => $lowestResource->id,
            'status' => ResourceAssessment::STATUS_COMPLETED,
            'total_score' => 12.5,
            'assessed_identifier' => $lowestResource->doi,
            'assessed_at' => now(),
        ]);

        $higherResource = Resource::factory()->withDoi('10.5880/test.resource.002')->create();
        Title::factory()->for($higherResource)->create(['value' => 'Higher resource']);
        ResourceAssessment::query()->create([
            'resource_id' => $higherResource->id,
            'status' => ResourceAssessment::STATUS_COMPLETED,
            'total_score' => 24.75,
            'assessed_identifier' => $higherResource->doi,
            'assessed_at' => now(),
        ]);

        $failedResource = Resource::factory()->withDoi('10.5880/test.resource.003')->create();
        Title::factory()->for($failedResource)->create(['value' => 'Failed resource']);
        ResourceAssessment::query()->create([
            'resource_id' => $failedResource->id,
            'status' => ResourceAssessment::STATUS_FAILED,
            'error_message' => 'Request failed.',
            'assessed_identifier' => $failedResource->doi,
            'assessed_at' => now(),
        ]);

        Resource::factory()->withDoi('10.5880/test.resource.004')->create();

        $lowestIgsn = Resource::factory()->withDoi('10.5880/test.igsn.001')->create([
            'resource_type_id' => $physicalObjectType->id,
        ]);
        Title::factory()->for($lowestIgsn)->create(['value' => 'Lowest IGSN']);
        IgsnMetadata::create([
            'resource_id' => $lowestIgsn->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);
        ResourceAssessment::query()->create([
            'resource_id' => $lowestIgsn->id,
            'status' => ResourceAssessment::STATUS_COMPLETED,
            'total_score' => 8.25,
            'assessed_identifier' => $lowestIgsn->doi,
            'assessed_at' => now(),
        ]);

        $higherIgsn = Resource::factory()->withDoi('10.5880/test.igsn.002')->create([
            'resource_type_id' => $physicalObjectType->id,
        ]);
        Title::factory()->for($higherIgsn)->create(['value' => 'Higher IGSN']);
        IgsnMetadata::create([
            'resource_id' => $higherIgsn->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);
        ResourceAssessment::query()->create([
            'resource_id' => $higherIgsn->id,
            'status' => ResourceAssessment::STATUS_COMPLETED,
            'total_score' => 19.5,
            'assessed_identifier' => $higherIgsn->doi,
            'assessed_at' => now(),
        ]);

        $skippedIgsn = Resource::factory()->withDoi('10.5880/test.igsn.003')->create([
            'resource_type_id' => $physicalObjectType->id,
        ]);
        Title::factory()->for($skippedIgsn)->create(['value' => 'Skipped IGSN']);
        ResourceAssessment::query()->create([
            'resource_id' => $skippedIgsn->id,
            'status' => ResourceAssessment::STATUS_SKIPPED,
            'error_message' => 'Landing page is not published.',
            'assessed_identifier' => $skippedIgsn->doi,
            'assessed_at' => now(),
        ]);

        Resource::factory()->withDoi('10.5880/test.igsn.004')->create([
            'resource_type_id' => $physicalObjectType->id,
        ]);

        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->get('/assessment')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('assessment')
                ->where('resourceAssessmentSummary.total', 4)
                ->where('resourceAssessmentSummary.assessed', 2)
                ->where('resourceAssessmentSummary.failed', 1)
                ->where('resourceAssessmentSummary.skipped', 0)
                ->where('resourceAssessmentSummary.unassessed', 1)
                ->where('igsnAssessmentSummary.total', 4)
                ->where('igsnAssessmentSummary.assessed', 2)
                ->where('igsnAssessmentSummary.failed', 0)
                ->where('igsnAssessmentSummary.skipped', 1)
                ->where('igsnAssessmentSummary.unassessed', 1)
                ->where('resourcesNeedingAttention.0.mainTitle', 'Lowest resource')
                ->where('resourcesNeedingAttention.0.score', 12.5)
                ->where('resourcesNeedingAttention.1.mainTitle', 'Higher resource')
                ->where('igsnsNeedingAttention.0.mainTitle', 'Lowest IGSN')
                ->where('igsnsNeedingAttention.0.score', 8.25)
                ->where('igsnsNeedingAttention.1.mainTitle', 'Higher IGSN')
            );
    });

    it('returns scope-specific FAIR opportunities without exposing raw F-UJI payloads', function () {
        $physicalObjectType = ResourceType::factory()->create([
            'name' => 'Physical Object',
            'slug' => 'physical-object',
        ]);

        $resource = Resource::factory()->withDoi('10.5880/test.guidance.resource')->create();
        Title::factory()->for($resource)->create(['value' => 'Guided digital resource']);
        LandingPage::factory()->for($resource)->withDoi((string) $resource->doi)->published()->create();
        ResourceAssessment::query()->create([
            'resource_id' => $resource->id,
            'status' => ResourceAssessment::STATUS_COMPLETED,
            'total_score' => 96.15,
            'assessed_identifier' => $resource->doi,
            'payload' => assessmentControllerFujiPayload(
                earned: ['R' => 5],
                results: [
                    assessmentControllerFujiMetric(
                        identifier: 'FsF-R1.1-01M',
                        earned: 0,
                        total: 1,
                        tests: [
                            'FsF-R1.1-01M-1' => ['earned' => 0, 'total' => 1],
                        ],
                    ),
                ],
            ),
            'assessed_at' => now()->addMinute(),
        ]);

        $igsn = Resource::factory()->withDoi('10.60510/GFZ.TEST.GUIDANCE')->create([
            'resource_type_id' => $physicalObjectType->id,
        ]);
        Title::factory()->for($igsn)->create(['value' => 'Guided physical sample']);
        IgsnMetadata::create([
            'resource_id' => $igsn->id,
            'upload_status' => IgsnMetadata::STATUS_PENDING,
        ]);
        LandingPage::factory()->for($igsn)->withDoi((string) $igsn->doi)->published()->create([
            'template' => 'default_gfz_igsn',
        ]);
        ResourceAssessment::query()->create([
            'resource_id' => $igsn->id,
            'status' => ResourceAssessment::STATUS_COMPLETED,
            'total_score' => 92.31,
            'assessed_identifier' => $igsn->doi,
            'payload' => assessmentControllerFujiPayload(
                earned: ['F' => 5],
                results: [
                    assessmentControllerFujiMetric(
                        identifier: 'FsF-F1-01MD',
                        earned: 0,
                        total: 1,
                        tests: [
                            'FsF-F1-01MD-1' => ['earned' => 0, 'total' => 1],
                        ],
                    ),
                    assessmentControllerFujiMetric(
                        identifier: 'FsF-F1-02MD',
                        earned: 0,
                        total: 1,
                        tests: [
                            'FsF-F1-02MD-1' => ['earned' => 0, 'total' => 0.5],
                            'FsF-F1-02MD-2' => ['earned' => 0, 'total' => 0.5],
                        ],
                    ),
                ],
            ),
            'assessed_at' => now()->addMinute(),
        ]);

        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->get('/assessment')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('resourcesNeedingAttention.0.improvementOpportunity.status', 'available')
                ->where('resourcesNeedingAttention.0.improvementOpportunity.dimension', 'R')
                ->where('resourcesNeedingAttention.0.improvementOpportunity.severity', 'low')
                ->where('resourcesNeedingAttention.0.improvementOpportunity.suggestions.0.actor', 'curator')
                ->where(
                    'resourcesNeedingAttention.0.improvementOpportunity.suggestions.0.text',
                    'Select an explicit licence in Licences and Rights and save the digital resource so ERNIE republishes it to DataCite.',
                )
                ->where('igsnsNeedingAttention.0.improvementOpportunity.status', 'available')
                ->where('igsnsNeedingAttention.0.improvementOpportunity.dimension', 'F')
                ->where('igsnsNeedingAttention.0.improvementOpportunity.suggestions.0.actor', 'curator')
                ->where(
                    'igsnsNeedingAttention.0.improvementOpportunity.suggestions.0.text',
                    'Register the IGSN with DataCite and point it to a published ERNIE sample landing page so the identifier remains persistent and resolvable.',
                )
                ->missing('resourcesNeedingAttention.0.payload')
                ->missing('resourcesNeedingAttention.0.results')
                ->missing('igsnsNeedingAttention.0.payload')
            );
    });

    it('distinguishes complete, unusable, and invalid IGSN scope opportunities', function () {
        $physicalObjectType = ResourceType::factory()->create([
            'name' => 'Physical Object',
            'slug' => 'physical-object',
        ]);

        $unusable = Resource::factory()->withDoi('10.5880/test.guidance.unusable')->create();
        Title::factory()->for($unusable)->create(['value' => 'Unusable guidance payload']);
        ResourceAssessment::query()->create([
            'resource_id' => $unusable->id,
            'status' => ResourceAssessment::STATUS_COMPLETED,
            'total_score' => 10,
            'assessed_identifier' => $unusable->doi,
            'payload' => ['summary' => ['score_percent' => ['FAIR' => 10]]],
            'assessed_at' => now()->addMinute(),
        ]);

        $complete = Resource::factory()->withDoi('10.5880/test.guidance.complete')->create();
        Title::factory()->for($complete)->create(['value' => 'Complete guidance payload']);
        ResourceAssessment::query()->create([
            'resource_id' => $complete->id,
            'status' => ResourceAssessment::STATUS_COMPLETED,
            'total_score' => 100,
            'assessed_identifier' => $complete->doi,
            'payload' => assessmentControllerFujiPayload(),
            'assessed_at' => now()->addMinute(),
        ]);

        $physicalObjectWithoutMetadata = Resource::factory()->withDoi('10.60510/GFZ.TEST.INVALID')->create([
            'resource_type_id' => $physicalObjectType->id,
        ]);
        Title::factory()->for($physicalObjectWithoutMetadata)->create(['value' => 'Physical Object without IGSN metadata']);
        ResourceAssessment::query()->create([
            'resource_id' => $physicalObjectWithoutMetadata->id,
            'status' => ResourceAssessment::STATUS_COMPLETED,
            'total_score' => 50,
            'assessed_identifier' => $physicalObjectWithoutMetadata->doi,
            'payload' => assessmentControllerFujiPayload(earned: ['R' => 2]),
            'assessed_at' => now()->addMinute(),
        ]);

        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->get('/assessment')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('resourcesNeedingAttention.0.improvementOpportunity.status', 'unavailable')
                ->where('resourcesNeedingAttention.0.improvementOpportunity.reason', 'invalid-payload')
                ->where('resourcesNeedingAttention.1.improvementOpportunity.status', 'complete')
                ->where('igsnsNeedingAttention.0.improvementOpportunity.status', 'unavailable')
                ->where('igsnsNeedingAttention.0.improvementOpportunity.reason', 'invalid-scope')
                ->where(
                    'igsnsNeedingAttention.0.improvementOpportunity.message',
                    'FAIR improvement guidance is unavailable because this entry has no IGSN sample metadata.',
                )
            );
    });

    it('selects state-aware Resource landing-page and IGSN registration guidance', function () {
        $physicalObjectType = ResourceType::factory()->create([
            'name' => 'Physical Object',
            'slug' => 'physical-object',
        ]);

        $resourcePayload = assessmentControllerFujiPayload(
            earned: ['F' => 5],
            results: [
                assessmentControllerFujiMetric(
                    identifier: 'FsF-F4-01M',
                    earned: 0,
                    total: 2,
                    tests: [
                        'FsF-F4-01M-1' => ['earned' => 0, 'total' => 2],
                    ],
                ),
            ],
        );
        $draftResource = Resource::factory()->withDoi('10.5880/test.guidance.draft')->create();
        Title::factory()->for($draftResource)->create(['value' => 'Draft landing-page resource']);
        LandingPage::factory()->for($draftResource)->withDoi((string) $draftResource->doi)->draft()->create();
        ResourceAssessment::query()->create([
            'resource_id' => $draftResource->id,
            'status' => ResourceAssessment::STATUS_COMPLETED,
            'total_score' => 80,
            'assessed_identifier' => $draftResource->doi,
            'payload' => $resourcePayload,
            'assessed_at' => now()->addMinute(),
        ]);

        $publishedResource = Resource::factory()->withDoi('10.5880/test.guidance.published')->create();
        Title::factory()->for($publishedResource)->create(['value' => 'Published landing-page resource']);
        LandingPage::factory()->for($publishedResource)->withDoi((string) $publishedResource->doi)->published()->create();
        ResourceAssessment::query()->create([
            'resource_id' => $publishedResource->id,
            'status' => ResourceAssessment::STATUS_COMPLETED,
            'total_score' => 81,
            'assessed_identifier' => $publishedResource->doi,
            'payload' => $resourcePayload,
            'assessed_at' => now()->addMinute(),
        ]);

        $igsnPayload = assessmentControllerFujiPayload(
            earned: ['F' => 6],
            results: [
                assessmentControllerFujiMetric(
                    identifier: 'FsF-F1-01MD',
                    earned: 0,
                    total: 1,
                    tests: [
                        'FsF-F1-01MD-1' => ['earned' => 0, 'total' => 1],
                    ],
                ),
            ],
        );
        $pendingIgsn = Resource::factory()->withDoi('10.60510/GFZ.STATE.PENDING')->create([
            'resource_type_id' => $physicalObjectType->id,
        ]);
        Title::factory()->for($pendingIgsn)->create(['value' => 'Pending IGSN']);
        IgsnMetadata::create([
            'resource_id' => $pendingIgsn->id,
            'upload_status' => IgsnMetadata::STATUS_PENDING,
        ]);
        LandingPage::factory()->for($pendingIgsn)->withDoi((string) $pendingIgsn->doi)->published()->create([
            'template' => 'default_gfz_igsn',
        ]);
        ResourceAssessment::query()->create([
            'resource_id' => $pendingIgsn->id,
            'status' => ResourceAssessment::STATUS_COMPLETED,
            'total_score' => 82,
            'assessed_identifier' => $pendingIgsn->doi,
            'payload' => $igsnPayload,
            'assessed_at' => now()->addMinute(),
        ]);

        $registeredIgsn = Resource::factory()->withDoi('10.60510/GFZ.STATE.REGISTERED')->create([
            'resource_type_id' => $physicalObjectType->id,
        ]);
        Title::factory()->for($registeredIgsn)->create(['value' => 'Registered IGSN']);
        IgsnMetadata::create([
            'resource_id' => $registeredIgsn->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);
        LandingPage::factory()->for($registeredIgsn)->withDoi((string) $registeredIgsn->doi)->published()->create([
            'template' => 'default_gfz_igsn',
        ]);
        ResourceAssessment::query()->create([
            'resource_id' => $registeredIgsn->id,
            'status' => ResourceAssessment::STATUS_COMPLETED,
            'total_score' => 83,
            'assessed_identifier' => $registeredIgsn->doi,
            'payload' => $igsnPayload,
            'assessed_at' => now()->addMinute(),
        ]);

        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->get('/assessment')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('resourcesNeedingAttention.0.improvementOpportunity.suggestions.0.actor', 'curator')
                ->where(
                    'resourcesNeedingAttention.0.improvementOpportunity.suggestions.0.text',
                    'Use an ERNIE landing-page template and keep the page published so search-engine-readable Schema.org metadata is embedded.',
                )
                ->where('resourcesNeedingAttention.1.improvementOpportunity.suggestions.0.actor', 'administrator')
                ->where(
                    'resourcesNeedingAttention.1.improvementOpportunity.suggestions.0.text',
                    'Make the published ERNIE landing page\'s Schema.org metadata crawlable in the initial server response.',
                )
                ->where('igsnsNeedingAttention.0.improvementOpportunity.suggestions.0.actor', 'curator')
                ->where(
                    'igsnsNeedingAttention.0.improvementOpportunity.suggestions.0.text',
                    'Register the IGSN with DataCite and point it to a published ERNIE sample landing page so the identifier remains persistent and resolvable.',
                )
                ->where('igsnsNeedingAttention.1.improvementOpportunity.suggestions.0.actor', 'administrator')
                ->where(
                    'igsnsNeedingAttention.1.improvementOpportunity.suggestions.0.text',
                    'Verify and correct the IGSN registration or resolver target so it resolves to the published ERNIE sample landing page.',
                )
            );
    });

    it('keeps FAIR context relation queries bounded as both attention lists grow', function () {
        $physicalObjectType = ResourceType::factory()->create([
            'name' => 'Physical Object',
            'slug' => 'physical-object',
        ]);
        $counter = 0;
        $createAssessedEntry = function (bool $isIgsn) use ($physicalObjectType, &$counter): void {
            $counter++;
            $doi = $isIgsn
                ? sprintf('10.60510/GFZ.QUERY.%02d', $counter)
                : sprintf('10.5880/test.query.%02d', $counter);
            $resource = Resource::factory()->withDoi($doi)->create([
                'resource_type_id' => $isIgsn ? $physicalObjectType->id : null,
            ]);

            Title::factory()->for($resource)->create(['value' => sprintf('Query fixture %02d', $counter)]);
            LandingPage::factory()
                ->for($resource)
                ->withDoi($doi)
                ->published()
                ->external()
                ->create();

            if ($isIgsn) {
                IgsnMetadata::create([
                    'resource_id' => $resource->id,
                    'upload_status' => IgsnMetadata::STATUS_REGISTERED,
                ]);
            }

            ResourceAssessment::query()->create([
                'resource_id' => $resource->id,
                'status' => ResourceAssessment::STATUS_COMPLETED,
                'total_score' => 100,
                'assessed_identifier' => $doi,
                'payload' => assessmentControllerFujiPayload(),
                'assessed_at' => now()->addMinute(),
            ]);
        };

        $createAssessedEntry(false);
        $createAssessedEntry(true);

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get('/assessment')->assertOk();

        $contextTables = [
            'landing_pages',
            'landing_page_domains',
            'landing_page_files',
            'landing_page_links',
            'igsn_metadata',
        ];
        $countContextQueries = static fn (array $sql): int => count(array_filter(
            $sql,
            static fn (string $query): bool => collect($contextTables)
                ->contains(static fn (string $table): bool => str_contains($query, $table)),
        ));
        $singleRowQueryCount = $countContextQueries($queries);

        for ($index = 0; $index < 9; $index++) {
            $createAssessedEntry(false);
            $createAssessedEntry(true);
        }

        $queries = [];
        $this->actingAs($user)->get('/assessment')->assertOk();
        $tenRowQueryCount = $countContextQueries($queries);

        expect($singleRowQueryCount)
            ->toBeGreaterThan(0)
            ->and($tenRowQueryCount)
            ->toBe($singleRowQueryCount);
    });

    it('keeps legacy assessments without an assessed identifier actionable', function () {
        $resource = Resource::factory()->withDoi('10.5880/test.guidance.legacy')->create();
        Title::factory()->for($resource)->create(['value' => 'Legacy guidance resource']);
        ResourceAssessment::query()->create([
            'resource_id' => $resource->id,
            'status' => ResourceAssessment::STATUS_COMPLETED,
            'total_score' => 96.15,
            'assessed_identifier' => null,
            'payload' => assessmentControllerFujiPayload(
                earned: ['R' => 5],
                results: [
                    assessmentControllerFujiMetric(
                        identifier: 'FsF-R1.1-01M',
                        earned: 0,
                        total: 1,
                        tests: [
                            'FsF-R1.1-01M-1' => ['earned' => 0, 'total' => 1],
                        ],
                    ),
                ],
            ),
            'assessed_at' => now(),
        ]);

        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->get('/assessment')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('resourcesNeedingAttention.0.improvementOpportunity.status', 'available')
                ->where('resourcesNeedingAttention.0.improvementOpportunity.dimension', 'R')
                ->where('resourcesNeedingAttention.0.improvementOpportunity.requiresReassessment', false)
                ->has('resourcesNeedingAttention.0.improvementOpportunity.suggestions', 1)
                ->missing('resourcesNeedingAttention.0.improvementOpportunity.guidanceMessage')
            );
    });

    it('asks for reassessment when tracked ERNIE state is newer than the stored score', function () {
        $resource = Resource::factory()->withDoi('10.5880/test.guidance.stale')->create();
        Title::factory()->for($resource)->create(['value' => 'Stale guidance resource']);
        LandingPage::factory()->for($resource)->withDoi((string) $resource->doi)->published()->create();
        ResourceAssessment::query()->create([
            'resource_id' => $resource->id,
            'status' => ResourceAssessment::STATUS_COMPLETED,
            'total_score' => 96.15,
            'assessed_identifier' => $resource->doi,
            'payload' => assessmentControllerFujiPayload(
                earned: ['R' => 5],
                results: [
                    assessmentControllerFujiMetric(
                        identifier: 'FsF-R1.1-01M',
                        earned: 0,
                        total: 1,
                        tests: [
                            'FsF-R1.1-01M-1' => ['earned' => 0, 'total' => 1],
                        ],
                    ),
                ],
            ),
            'assessed_at' => now()->subDay(),
        ]);

        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->get('/assessment')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('resourcesNeedingAttention.0.improvementOpportunity.status', 'available')
                ->where('resourcesNeedingAttention.0.improvementOpportunity.dimension', 'R')
                ->where('resourcesNeedingAttention.0.improvementOpportunity.requiresReassessment', true)
                ->where(
                    'resourcesNeedingAttention.0.improvementOpportunity.guidanceMessage',
                    'Run the assessment again to refresh FAIR improvement guidance after the recent ERNIE changes.',
                )
                ->has('resourcesNeedingAttention.0.improvementOpportunity.suggestions', 0)
            );
    });
});

describe('checkResources', function () {
    it('starts the resource assessment job and returns a job id', function () {
        Queue::fake();
        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)
            ->post('/assessment/check-resources')
            ->assertOk();

        expect($response->json('jobId'))->toBeString()->toMatch('/^[a-f0-9-]{36}$/');
        Queue::assertPushed(RunResourceAssessmentsJob::class, 1);
    });

    it('returns 409 when the resource assessment lock is already held', function () {
        $user = User::factory()->create(['role' => 'admin']);
        Cache::lock('resource_assessment:resource:running', 7200)->get();

        $this->actingAs($user)
            ->post('/assessment/check-resources')
            ->assertStatus(409);
    });

    it('returns 503 when F-UJI is not configured', function () {
        Config::set('fuji.enabled', false);
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->post('/assessment/check-resources')
            ->assertStatus(503)
            ->assertJson(['error' => 'F-UJI is not configured.']);
    });

    it('returns 503 when F-UJI is configured but unhealthy', function () {
        $this->mock(FujiAssessmentService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('healthStatus')
                ->once()
                ->andReturn([
                    'healthy' => false,
                    'message' => 'F-UJI is currently unavailable. Please try again shortly.',
                ]);
        });
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->post('/assessment/check-resources')
            ->assertStatus(503)
            ->assertJson(['error' => 'F-UJI is currently unavailable. Please try again shortly.']);
    });
});

describe('checkIgsns', function () {
    it('starts the igsn assessment job and returns a job id', function () {
        Queue::fake();
        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)
            ->post('/assessment/check-igsns')
            ->assertOk();

        expect($response->json('jobId'))->toBeString()->toMatch('/^[a-f0-9-]{36}$/');
        Queue::assertPushed(RunResourceAssessmentsJob::class, 1);
    });

    it('returns 503 when F-UJI is configured but unhealthy', function () {
        $this->mock(FujiAssessmentService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('healthStatus')
                ->once()
                ->andReturn([
                    'healthy' => false,
                    'message' => 'F-UJI is currently unavailable. Please try again shortly.',
                ]);
        });
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->post('/assessment/check-igsns')
            ->assertStatus(503)
            ->assertJson(['error' => 'F-UJI is currently unavailable. Please try again shortly.']);
    });
});

describe('status', function () {
    it('returns cached job status for a running assessment', function () {
        $user = User::factory()->create(['role' => 'admin']);
        $jobId = Str::uuid()->toString();

        Cache::put(RunResourceAssessmentsJob::getCacheKey('resource', $jobId), [
            'status' => 'completed',
            'progress' => 'Done.',
            'assessedResources' => 4,
        ], now()->addHour());

        $this->actingAs($user)
            ->get("/assessment/check/resource/{$jobId}/status")
            ->assertOk()
            ->assertJson([
                'status' => 'completed',
                'assessedResources' => 4,
            ]);
    });

    it('returns 404 for unknown scopes', function () {
        $response = app(AssessmentController::class)->status('unknown', '11111111-1111-4111-8111-111111111111');

        expect($response->getStatusCode())->toBe(404)
            ->and($response->getData(true))->toBe(['error' => 'Unknown assessment scope.']);
    });

    it('returns 404 when the job status is missing', function () {
        $user = User::factory()->create(['role' => 'admin']);
        $jobId = Str::uuid()->toString();

        $this->actingAs($user)
            ->get("/assessment/check/resource/{$jobId}/status")
            ->assertStatus(404)
            ->assertJson([
                'status' => 'unknown',
                'progress' => 'Job not found.',
            ]);
    });
});

describe('checkAll', function () {
    it('starts both assessment scopes', function () {
        Queue::fake();
        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)
            ->post('/assessment/check-all')
            ->assertOk();

        expect($response->json())->toHaveKeys(['resourceJobId', 'igsnJobId']);
        Queue::assertPushed(RunResourceAssessmentsJob::class, 2);
    });

    it('returns 503 when F-UJI is not configured', function () {
        Config::set('fuji.enabled', false);
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->post('/assessment/check-all')
            ->assertStatus(503)
            ->assertJson(['error' => 'F-UJI is not configured.']);
    });

    it('returns 503 when F-UJI is configured but unhealthy', function () {
        $this->mock(FujiAssessmentService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('healthStatus')
                ->once()
                ->andReturn([
                    'healthy' => false,
                    'message' => 'F-UJI is currently unavailable. Please try again shortly.',
                ]);
        });
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->post('/assessment/check-all')
            ->assertStatus(503)
            ->assertJson(['error' => 'F-UJI is currently unavailable. Please try again shortly.']);
    });

    it('returns 409 when all assessment jobs are already running', function () {
        $user = User::factory()->create(['role' => 'admin']);
        Cache::lock('resource_assessment:resource:running', 7200)->get();
        Cache::lock('resource_assessment:igsn:running', 7200)->get();

        $this->actingAs($user)
            ->post('/assessment/check-all')
            ->assertStatus(409)
            ->assertJson([
                'error' => 'All assessment jobs are already running. Please wait for them to finish.',
            ]);
    });

    it('returns a partial result when one scope is locked and the other can start', function () {
        Queue::fake();
        $user = User::factory()->create(['role' => 'admin']);
        Cache::lock('resource_assessment:resource:running', 7200)->get();

        $response = $this->actingAs($user)
            ->post('/assessment/check-all')
            ->assertOk();

        expect($response->json())->toHaveKeys(['resourceError', 'igsnJobId']);
        expect($response->json('resourceError'))->toBe('Resource assessment is already running.');
        Queue::assertPushed(RunResourceAssessmentsJob::class, 1);
    });
});
