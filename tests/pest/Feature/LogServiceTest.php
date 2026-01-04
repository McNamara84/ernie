<?php

declare(strict_types=1);

use App\Services\LogService;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->logService = new LogService;
    $this->logPath = storage_path('logs/laravel.log');

    // Backup existing log file
    if (File::exists($this->logPath)) {
        File::move($this->logPath, $this->logPath.'.backup');
    }
});

afterEach(function () {
    // Clean up test log file
    if (File::exists($this->logPath)) {
        File::delete($this->logPath);
    }

    // Restore backup
    if (File::exists($this->logPath.'.backup')) {
        File::move($this->logPath.'.backup', $this->logPath);
    }
});

describe('getLogs', function () {
    it('returns empty array when log file does not exist', function () {
        $result = $this->logService->getLogs();

        expect($result['data'])->toBeArray()->toBeEmpty();
        expect($result['total'])->toBe(0);
    });

    it('parses single log entry correctly', function () {
        $logContent = "[2026-01-04 10:30:00] local.INFO: Test message\n";
        File::put($this->logPath, $logContent);

        $result = $this->logService->getLogs();

        expect($result['data'])->toHaveCount(1);
        expect($result['data'][0]['timestamp'])->toBe('2026-01-04 10:30:00');
        expect($result['data'][0]['level'])->toBe('info');
        expect($result['data'][0]['message'])->toBe('Test message');
        expect($result['data'][0]['context'])->toBe('');
        expect($result['data'][0]['line_number'])->toBe(1);
    });

    it('parses multiple log entries correctly', function () {
        $logContent = "[2026-01-04 10:30:00] local.INFO: First message\n";
        $logContent .= "[2026-01-04 10:31:00] local.ERROR: Second message\n";
        $logContent .= "[2026-01-04 10:32:00] local.WARNING: Third message\n";
        File::put($this->logPath, $logContent);

        $result = $this->logService->getLogs();

        expect($result['data'])->toHaveCount(3);
        // Results are reversed (newest first)
        expect($result['data'][0]['message'])->toBe('Third message');
        expect($result['data'][1]['message'])->toBe('Second message');
        expect($result['data'][2]['message'])->toBe('First message');
    });

    it('parses log entries with multi-line context (stack traces)', function () {
        $logContent = "[2026-01-04 10:30:00] local.ERROR: Test error\n";
        $logContent .= "#0 /var/www/app/Test.php(10): testFunction()\n";
        $logContent .= "#1 /var/www/app/Controller.php(20): callTest()\n";
        $logContent .= "[2026-01-04 10:31:00] local.INFO: Next message\n";
        File::put($this->logPath, $logContent);

        $result = $this->logService->getLogs();

        expect($result['data'])->toHaveCount(2);
        // First entry (newest) is the INFO
        expect($result['data'][0]['message'])->toBe('Next message');
        expect($result['data'][0]['context'])->toBe('');
        // Second entry is the ERROR with stack trace
        expect($result['data'][1]['message'])->toBe('Test error');
        expect($result['data'][1]['context'])->toContain('#0 /var/www/app/Test.php(10)');
        expect($result['data'][1]['context'])->toContain('#1 /var/www/app/Controller.php(20)');
    });

    it('assigns correct entry numbers (not file line numbers)', function () {
        $logContent = "[2026-01-04 10:30:00] local.ERROR: Error with trace\n";
        $logContent .= "#0 stack line 1\n";
        $logContent .= "#1 stack line 2\n";
        $logContent .= "#2 stack line 3\n";
        $logContent .= "[2026-01-04 10:31:00] local.INFO: Second entry\n";
        File::put($this->logPath, $logContent);

        $result = $this->logService->getLogs();

        // Entry numbers should be 1 and 2, not file line numbers
        expect($result['data'][0]['line_number'])->toBe(2); // Second entry
        expect($result['data'][1]['line_number'])->toBe(1); // First entry
    });

    it('returns newest entries first', function () {
        $logContent = "[2026-01-04 08:00:00] local.INFO: Old message\n";
        $logContent .= "[2026-01-04 12:00:00] local.INFO: New message\n";
        File::put($this->logPath, $logContent);

        $result = $this->logService->getLogs();

        expect($result['data'][0]['timestamp'])->toBe('2026-01-04 12:00:00');
        expect($result['data'][1]['timestamp'])->toBe('2026-01-04 08:00:00');
    });
});

describe('getLogs filtering', function () {
    beforeEach(function () {
        $logContent = "[2026-01-04 10:30:00] local.INFO: Info message one\n";
        $logContent .= "[2026-01-04 10:31:00] local.ERROR: Error message\n";
        $logContent .= "[2026-01-04 10:32:00] local.INFO: Info message two\n";
        $logContent .= "[2026-01-04 10:33:00] local.WARNING: Warning message\n";
        $logContent .= "[2026-01-04 10:34:00] local.DEBUG: Debug message\n";
        File::put($this->logPath, $logContent);
    });

    it('filters by log level', function () {
        $result = $this->logService->getLogs(level: 'error');

        expect($result['data'])->toHaveCount(1);
        expect($result['data'][0]['level'])->toBe('error');
        expect($result['total'])->toBe(1);
    });

    it('filters by search term in message', function () {
        $result = $this->logService->getLogs(search: 'Info message');

        expect($result['data'])->toHaveCount(2);
        expect($result['total'])->toBe(2);
    });

    it('filters by search term case-insensitively', function () {
        $result = $this->logService->getLogs(search: 'WARNING');

        expect($result['data'])->toHaveCount(1);
        expect($result['data'][0]['level'])->toBe('warning');
    });

    it('combines level and search filters', function () {
        $result = $this->logService->getLogs(level: 'info', search: 'one');

        expect($result['data'])->toHaveCount(1);
        expect($result['data'][0]['message'])->toContain('Info message one');
    });
});

describe('getLogs pagination', function () {
    beforeEach(function () {
        $entries = [];
        for ($i = 1; $i <= 15; $i++) {
            $time = sprintf('10:%02d:00', $i);
            $entries[] = "[2026-01-04 {$time}] local.INFO: Message {$i}";
        }
        File::put($this->logPath, implode("\n", $entries));
    });

    it('paginates results correctly', function () {
        $result = $this->logService->getLogs(perPage: 5, page: 1);

        expect($result['data'])->toHaveCount(5);
        expect($result['current_page'])->toBe(1);
        expect($result['last_page'])->toBe(3);
        expect($result['per_page'])->toBe(5);
        expect($result['total'])->toBe(15);
    });

    it('returns correct page of results', function () {
        $page1 = $this->logService->getLogs(perPage: 5, page: 1);
        $page2 = $this->logService->getLogs(perPage: 5, page: 2);

        // First page should have newest entries (15, 14, 13, 12, 11)
        expect($page1['data'][0]['message'])->toBe('Message 15');

        // Second page should have next entries (10, 9, 8, 7, 6)
        expect($page2['data'][0]['message'])->toBe('Message 10');
    });

    it('limits page number to last page when out of range', function () {
        $result = $this->logService->getLogs(perPage: 5, page: 100);

        // Service caps the page number to last_page, so current_page will be last_page
        expect($result['current_page'])->toBe($result['last_page']);
        expect($result['total'])->toBe(15);
    });
});

describe('getAvailableLevels', function () {
    it('returns all standard log levels', function () {
        $levels = $this->logService->getAvailableLevels();

        expect($levels)->toContain('debug');
        expect($levels)->toContain('info');
        expect($levels)->toContain('notice');
        expect($levels)->toContain('warning');
        expect($levels)->toContain('error');
        expect($levels)->toContain('critical');
        expect($levels)->toContain('alert');
        expect($levels)->toContain('emergency');
    });
});

describe('deleteLogEntry', function () {
    it('returns false when log file does not exist', function () {
        $result = $this->logService->deleteLogEntry(1, '2026-01-04 10:30:00');

        expect($result)->toBeFalse();
    });

    it('deletes a single log entry by entry number and timestamp', function () {
        $logContent = <<<'LOG'
[2026-01-04 10:30:00] local.INFO: First message
[2026-01-04 10:31:00] local.ERROR: Second message
[2026-01-04 10:32:00] local.INFO: Third message
LOG;
        File::put($this->logPath, $logContent);

        $result = $this->logService->deleteLogEntry(2, '2026-01-04 10:31:00');

        expect($result)->toBeTrue();

        $remainingContent = File::get($this->logPath);
        expect($remainingContent)->toContain('First message');
        expect($remainingContent)->not->toContain('Second message');
        expect($remainingContent)->toContain('Third message');
    });

    it('deletes entry including its context (stack trace)', function () {
        $logContent = <<<'LOG'
[2026-01-04 10:30:00] local.INFO: First message
[2026-01-04 10:31:00] local.ERROR: Error with trace
#0 stack line 1
#1 stack line 2
[2026-01-04 10:32:00] local.INFO: Third message
LOG;
        File::put($this->logPath, $logContent);

        $result = $this->logService->deleteLogEntry(2, '2026-01-04 10:31:00');

        expect($result)->toBeTrue();

        $remainingContent = File::get($this->logPath);
        expect($remainingContent)->toContain('First message');
        expect($remainingContent)->not->toContain('Error with trace');
        expect($remainingContent)->not->toContain('stack line');
        expect($remainingContent)->toContain('Third message');
    });

    it('returns false when entry number does not match', function () {
        $logContent = "[2026-01-04 10:30:00] local.INFO: Test message\n";
        File::put($this->logPath, $logContent);

        $result = $this->logService->deleteLogEntry(999, '2026-01-04 10:30:00');

        expect($result)->toBeFalse();
    });

    it('returns false when timestamp does not match', function () {
        $logContent = "[2026-01-04 10:30:00] local.INFO: Test message\n";
        File::put($this->logPath, $logContent);

        $result = $this->logService->deleteLogEntry(1, '2099-01-01 00:00:00');

        expect($result)->toBeFalse();
    });

    it('requires both entry number AND timestamp to match', function () {
        $logContent = <<<'LOG'
[2026-01-04 10:30:00] local.INFO: First message
[2026-01-04 10:30:00] local.INFO: Same timestamp different entry
LOG;
        File::put($this->logPath, $logContent);

        // Try to delete entry 1 with correct timestamp
        $result = $this->logService->deleteLogEntry(1, '2026-01-04 10:30:00');

        expect($result)->toBeTrue();

        $remainingContent = File::get($this->logPath);
        expect($remainingContent)->not->toContain('First message');
        expect($remainingContent)->toContain('Same timestamp different entry');
    });

    it('uses entry numbers not file line numbers', function () {
        $logContent = <<<'LOG'
[2026-01-04 10:30:00] local.ERROR: Entry one
#0 stack trace line (file line 2)
#1 stack trace line (file line 3)
[2026-01-04 10:31:00] local.INFO: Entry two
LOG;
        File::put($this->logPath, $logContent);

        // Entry 2 is on file line 4, but entry number is 2
        $result = $this->logService->deleteLogEntry(2, '2026-01-04 10:31:00');

        expect($result)->toBeTrue();

        $remainingContent = File::get($this->logPath);
        expect($remainingContent)->toContain('Entry one');
        expect($remainingContent)->toContain('stack trace line');
        expect($remainingContent)->not->toContain('Entry two');
    });
});

describe('clearLogs', function () {
    it('returns true when log file does not exist', function () {
        $result = $this->logService->clearLogs();

        expect($result)->toBeTrue();
    });

    it('clears all log entries', function () {
        $logContent = <<<'LOG'
[2026-01-04 10:30:00] local.INFO: First message
[2026-01-04 10:31:00] local.ERROR: Second message
LOG;
        File::put($this->logPath, $logContent);

        $result = $this->logService->clearLogs();

        expect($result)->toBeTrue();
        expect(File::get($this->logPath))->toBe('');
    });
});
