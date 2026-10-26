<?php

declare(strict_types=1);

use App\Models\AssistantSuggestion;
use App\Models\FundingAwardUriReview;
use App\Models\FundingReference;
use App\Models\Resource;
use App\Models\User;
use App\Services\FundingAwardUri\AwardMetadata;
use App\Services\FundingAwardUri\FundingAwardUriDiscoveryService;
use App\Services\FundingAwardUri\FundingAwardUriReviewService;
use App\Services\ResourceStorageService;
use Illuminate\Support\Facades\DB;
use Tests\Support\FundingAwardUriFixtures as F;

uses()->group('mysql-sensitive');

it('serializes reviews against other reviews, editor saves and discovery', function (string $scenario): void {
    F::fake();
    $target = F::target();
    $suggestion = F::discover();
    $actor = User::factory()->admin()->create();
    DB::commit();
    DB::disconnect();
    $children = [];
    $editorWins = str_starts_with($scenario, 'editor_');
    try {
        for ($i = 0; $i < 2; $i++) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            if ($sockets === false) {
                throw new RuntimeException('Unable to create sockets.');
            }
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Unable to fork.');
            }
            if ($pid === 0) {
                fclose($sockets[0]);
                fgets($sockets[1]);
                try {
                    DB::purge();
                    if ($scenario === 'discovery' && $i === 1) {
                        app(FundingAwardUriDiscoveryService::class)->discover(static function (string $message): void {});
                        $result = ['kind' => 'discovery', 'success' => true];
                    } else {
                        $result = app(FundingAwardUriReviewService::class)->review($suggestion, $actor,
                            [...F::input($suggestion), 'defer_datacite_sync' => true], $scenario === 'accept_decline' && $i === 1 ? 'declined' : 'accepted');
                        $result['kind'] = 'review';
                    }
                    fwrite($sockets[1], json_encode($result, JSON_THROW_ON_ERROR)."\n");
                    exit(0);
                } catch (Throwable $exception) {
                    fwrite($sockets[1], json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n");
                    exit(1);
                }
            }
            fclose($sockets[1]);
            stream_set_timeout($sockets[0], 30);
            $children[] = ['pid' => $pid, 'socket' => $sockets[0]];
        }
        DB::beginTransaction();
        Resource::whereKey($target->resource_id)->lockForUpdate()->firstOrFail();
        foreach ($children as $child) {
            fwrite($child['socket'], "go\n");
        }
        if ($scenario === 'editor_value') {
            $target->update(['award_uri' => 'https://example.org/editor-award']);
        } elseif ($scenario === 'editor_replace') {
            app(ResourceStorageService::class)->storePrepared(['resourceId' => $target->resource_id, 'titles' => [], 'fundingReferences' => [[
                'funderName' => $target->funder_name, 'funderIdentifier' => $target->funder_identifier, 'funderIdentifierType' => 'Crossref Funder ID',
                'awardNumber' => $target->award_number, 'awardTitle' => $target->award_title,
            ]]]);
        }
        usleep(250000);
        DB::commit();
        $results = [];
        foreach ($children as $child) {
            $line = fgets($child['socket']);
            expect($line)->not->toBeFalse();
            $result = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            expect($result)->not->toHaveKey('error');
            if ($result['kind'] === 'review') {
                $results[] = $result['success'];
            }
            pcntl_waitpid($child['pid'], $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
            fclose($child['socket']);
        }
        expect(count(array_filter($results)))->toBe($editorWins ? 0 : 1)
            ->and(FundingAwardUriReview::where('resource_id', $target->resource_id)->count())->toBe($editorWins ? 0 : 1);
        if ($scenario === 'editor_replace') {
            expect($target->fresh())->toBeNull()
                ->and(FundingReference::where('resource_id', $target->resource_id)->sole()->award_uri)->toBeNull();
        } else {
            $accepted = FundingAwardUriReview::where('resource_id', $target->resource_id)->where('decision', 'accepted')->exists();
            expect($target->fresh()->award_uri)->toBe($scenario === 'editor_value' ? 'https://example.org/editor-award' : ($accepted ? F::URI : null));
        }
        if (! $editorWins) {
            expect(AssistantSuggestion::where('resource_id', $target->resource_id)->where('assistant_id', AwardMetadata::ASSISTANT_ID)->count())->toBe(0);
        }
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::table('funding_award_uri_reviews')->where('resource_id', $target->resource_id)->delete();
        Resource::whereKey($target->resource_id)->delete();
        User::whereKey($actor->id)->delete();
        DB::beginTransaction();
    }
})->with(['accept_accept', 'accept_decline', 'editor_value', 'editor_replace', 'discovery'])
    ->skip(fn () => DB::connection()->getDriverName() !== 'mysql', 'Requires the pinned MySQL 9.7 server.');
