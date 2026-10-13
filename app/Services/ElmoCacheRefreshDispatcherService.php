<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EditorContext;
use App\Jobs\RefreshElmoCacheJob;
use App\Models\PidSetting;
use App\Models\ThesaurusSetting;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class ElmoCacheRefreshDispatcherService
{
    public function __construct(private readonly ElmoCacheRefreshService $service) {}

    /** @param list<EditorContext> $editors */
    public function dispatch(array $editors, string $trigger): void
    {
        if (! $this->service->isEnabled() || $editors === []) {
            return;
        }

        $correlationId = (string) Str::uuid();
        DB::afterCommit(function () use ($editors, $trigger, $correlationId): void {
            foreach (array_unique($editors, SORT_REGULAR) as $editor) {
                if ($editor === EditorContext::ERNIE) {
                    continue;
                }

                $context = ['editor' => $editor->value, 'trigger' => $trigger, 'correlation_id' => $correlationId];
                if (! $this->service->isConfigured($editor)) {
                    Log::warning('ELMO cache refresh skipped: configuration is missing or invalid.', $context);

                    continue;
                }

                try {
                    // Explicit dispatch keeps queue exceptions inside this catch, rather than a PendingDispatch destructor.
                    Bus::dispatch(new RefreshElmoCacheJob($editor, $trigger, $correlationId));
                } catch (Throwable) {
                    Log::error('ELMO cache refresh could not be queued; a manual refresh is required.', $context);
                }
            }
        });
    }

    public function dispatchForDataUpdate(ThesaurusSetting|PidSetting $setting): void
    {
        $editors = array_values(array_filter(
            [EditorContext::ELMO, EditorContext::ELMO_MSL],
            static fn (EditorContext $editor): bool => (bool) $setting->getAttribute($editor->activationColumn()),
        ));
        $this->dispatch($editors, ($setting instanceof ThesaurusSetting ? 'thesaurus:' : 'pid:').$setting->type);
    }
}
