<?php

declare(strict_types=1);

// CLI-only fixture: preserve the local browser stack's shared quarter state.
use App\Models\Setting;
use App\Services\ThesaurusReviewReminderService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! in_array(config('app.env'), ['local', 'testing'], true)) {
    throw new RuntimeException('Review browser fixtures require a local or testing environment.');
}

$action = $argv[1] ?? '';
if ($action === 'snapshot') {
    $period = app(ThesaurusReviewReminderService::class)->status()['period'];
    $key = 'thesauri_review_completed:'.$period;
    echo json_encode(['period' => $period, 'key' => $key, 'row' => Setting::where('key', $key)->first()?->getAttributes()], JSON_THROW_ON_ERROR);
    exit(0);
}

$snapshot = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (! in_array($action, ['open', 'restore'], true)
    || ! is_array($snapshot)
    || ! is_string($snapshot['key'] ?? null)
    || preg_match('/\Athesauri_review_completed:[0-9]{4}-Q[1-4]\z/', $snapshot['key']) !== 1) {
    throw new RuntimeException('Invalid review fixture operation.');
}

DB::transaction(function () use ($action, $snapshot): void {
    Setting::where('key', $snapshot['key'])->delete();
    if ($action === 'restore' && is_array($snapshot['row'] ?? null)) {
        Setting::query()->insert([
            'id' => $snapshot['row']['id'],
            'key' => $snapshot['key'],
            'value' => $snapshot['row']['value'],
        ]);
    }
});
