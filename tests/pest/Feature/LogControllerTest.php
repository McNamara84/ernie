<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\withoutVite;

describe('Log Routes Access Control', function () {
    it('requires authentication to access logs', function () {
        $response = $this->get(route('logs.index'));

        $response->assertRedirect(route('login'));
    });

    it('requires administration access to view logs', function () {
        $beginner = User::factory()->beginner()->create();

        $response = $this->actingAs($beginner)->get(route('logs.index'));

        $response->assertForbidden();
    });

    it('allows admin to access logs', function () {
        withoutVite();
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('logs.index'));

        $response->assertStatus(200);
    });

    it('denies group leader access to logs', function () {
        // Issue #379: Only Admin can access Logs (Group Leader no longer has access)
        $groupLeader = User::factory()->groupLeader()->create();

        $response = $this->actingAs($groupLeader)->get(route('logs.index'));

        $response->assertForbidden();
    });

    it('denies curator access to logs', function () {
        $curator = User::factory()->curator()->create();

        $response = $this->actingAs($curator)->get(route('logs.index'));

        $response->assertForbidden();
    });
});

describe('Log Data API Access Control', function () {
    it('requires authentication to access logs.data', function () {
        $response = $this->get(route('logs.data'));

        $response->assertRedirect(route('login'));
    });

    it('requires administration access to view logs.data', function () {
        $beginner = User::factory()->beginner()->create();

        $response = $this->actingAs($beginner)->get(route('logs.data'));

        $response->assertForbidden();
    });

    it('denies curator access to logs.data', function () {
        $curator = User::factory()->curator()->create();

        $response = $this->actingAs($curator)->get(route('logs.data'));

        $response->assertForbidden();
    });

    it('allows admin to access logs.data', function () {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('logs.data'));

        $response->assertOk();
    });

    it('denies group leader access to logs.data', function () {
        // Issue #379: Only Admin can access Logs (Group Leader no longer has access)
        $groupLeader = User::factory()->groupLeader()->create();

        $response = $this->actingAs($groupLeader)->get(route('logs.data'));

        $response->assertForbidden();
    });
});

describe('Log Deletion Access Control', function () {
    it('only admin can delete log entries', function () {
        $admin = User::factory()->admin()->create();
        $groupLeader = User::factory()->groupLeader()->create();

        // Group Leader should not be able to delete
        $this->actingAs($groupLeader)
            ->delete(route('logs.destroy'), [
                'line_number' => 1,
                'timestamp' => '2024-01-01 12:00:00',
            ])
            ->assertForbidden();

        // Admin should be able to delete (returns redirect now, not JSON)
        // Even if entry doesn't exist, it should redirect with error flash
        $this->actingAs($admin)
            ->delete(route('logs.destroy'), [
                'line_number' => 1,
                'timestamp' => '2024-01-01 12:00:00',
            ])
            ->assertRedirect(route('logs.index'));
    });

    it('only admin can clear all logs', function () {
        $admin = User::factory()->admin()->create();
        $groupLeader = User::factory()->groupLeader()->create();

        // Group Leader should not be able to clear
        $this->actingAs($groupLeader)
            ->delete(route('logs.clear'))
            ->assertForbidden();

        // Admin should be able to clear (returns redirect now, not JSON)
        $this->actingAs($admin)
            ->delete(route('logs.clear'))
            ->assertRedirect(route('logs.index'));
    });
});

describe('Log Viewing Functionality', function () {
    beforeEach(function () {
        // Create a test log file
        $logPath = storage_path('logs/laravel.log');
        $logContent = <<<'LOG'
[2024-01-01 10:00:00] local.INFO: Test info message
[2024-01-01 10:01:00] local.WARNING: Test warning message
[2024-01-01 10:02:00] local.ERROR: Test error message
Stack trace here
More stack trace
[2024-01-01 10:03:00] local.DEBUG: Test debug message
LOG;
        File::put($logPath, $logContent);
    });

    afterEach(function () {
        // Clean up test log file
        $logPath = storage_path('logs/laravel.log');
        if (File::exists($logPath)) {
            File::put($logPath, '');
        }
    });

    it('returns logs with correct structure', function () {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('logs.data'));

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['timestamp', 'level', 'message', 'context', 'line_number'],
                ],
                'current_page',
                'last_page',
                'per_page',
                'total',
            ]);
    });

    it('filters logs by level', function () {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('logs.data', ['level' => 'error']));

        $response->assertOk();
        $data = $response->json('data');

        foreach ($data as $log) {
            expect($log['level'])->toBe('error');
        }
    });

    it('filters logs by search term', function () {
        // Generate a unique search term to avoid conflicts with parallel tests
        $uniqueId = uniqid('warning_test_', true);

        // Write a log entry with the unique identifier using Laravel's Log facade
        // This ensures the log is written to the correct location
        \Illuminate\Support\Facades\Log::warning("Test warning message {$uniqueId}");

        $admin = User::factory()->admin()->create();

        // Search for our unique warning message
        $response = $this->actingAs($admin)->get(route('logs.data', ['search' => $uniqueId]));

        $response->assertOk();
        $data = $response->json('data');

        expect(count($data))->toBeGreaterThan(0);
        foreach ($data as $log) {
            expect(strtolower($log['message'].$log['context']))->toContain(strtolower($uniqueId));
        }
    });

    it('paginates logs correctly', function () {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('logs.data', ['per_page' => 2, 'page' => 1]));

        $response->assertOk();
        $data = $response->json();

        expect($data['per_page'])->toBe(2);
        expect(count($data['data']))->toBeLessThanOrEqual(2);
    });

    it('returns newest logs first', function () {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('logs.data'));

        $response->assertOk();
        $data = $response->json('data');

        if (count($data) >= 2) {
            $firstTimestamp = strtotime($data[0]['timestamp']);
            $secondTimestamp = strtotime($data[1]['timestamp']);
            expect($firstTimestamp)->toBeGreaterThanOrEqual($secondTimestamp);
        }
    });
});

describe('Log Index Page', function () {
    it('renders the logs page with Inertia', function () {
        withoutVite();
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('logs.index'));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Logs/Index')
                ->has('logs')
                ->has('pagination')
                ->has('filters')
                ->has('available_levels')
                ->has('can_delete')
            );
    });

    it('shows can_delete as true for admin', function () {
        withoutVite();
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('logs.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('can_delete', true)
        );
    });

    it('denies group leader access to logs page entirely', function () {
        // Issue #379: Group Leader no longer has access to Logs at all
        $groupLeader = User::factory()->groupLeader()->create();

        $response = $this->actingAs($groupLeader)->get(route('logs.index'));

        $response->assertForbidden();
    });
});
