<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Resource;
use App\Models\User;
use App\Services\DataCiteModeResolverService;
use App\Services\UserActivityService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/** Records completed HTTP business actions; queue outcomes are recorded by jobs. */
final class LogUserActivity
{
    /** @var array<string, string> */
    private const ACTIONS = [
        'resources.destroy' => 'deleted', 'resources.batch-destroy' => 'deleted', 'resources.destroy-all' => 'deleted',
        'igsns.destroy' => 'deleted', 'igsns.batch.destroy' => 'deleted',
        'landing-page.destroy' => 'deleted the landing page of',
        'resources.export-datacite-json' => 'generated a DataCite JSON export for',
        'resources.export-datacite-xml' => 'generated a DataCite XML export for',
        'resources.export-jsonld' => 'generated a JSON-LD export for',
        'resources.export-iso-19115-3' => 'generated an ISO 19115-3 export for',
        'igsns.export.json' => 'generated a DataCite JSON export for',
        'igsns.export.jsonld' => 'generated a JSON-LD export for',
        'resources.batch-export' => 'generated a metadata export for',
        'resources.register-doi' => 'registered the DOI or updated DataCite metadata for',
        'igsns.register' => 'registered or updated DataCite metadata for',
        'resources.batch-register' => 'registered the DOI or updated DataCite metadata for',
        'resources.send-review-links' => 'queued review-link emails for delivery for',
        'resources.send-review-link-migrations' => 'queued review-link migration emails for delivery for',
    ];

    public function __construct(private readonly UserActivityService $activities) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route()?->getName() ?? '';
        $user = $request->user();
        $control = preg_match('/^(datacite\.import|igsns\.import|igsns\.batch-register|datacite\.url-updates)\.(cancel|resume|retry-failed|retry-sync)$/', $route) === 1;
        if (! $user instanceof User || (! isset(self::ACTIONS[$route]) && ! $control)) {
            return $next($request);
        }

        $actor = $this->activities->actor($user);
        $operation = (string) Str::uuid();
        $resource = $request->route('resource');
        $resource = $resource instanceof Resource ? $resource : null;
        $subjects = [];
        if ($resource !== null) {
            $subjects[$resource->id] = $this->activities->subject($resource);
        } elseif (in_array($route, ['resources.batch-destroy', 'resources.destroy-all', 'igsns.batch.destroy', 'resources.batch-export', 'resources.batch-register', 'resources.send-review-links', 'resources.send-review-link-migrations'], true)) {
            $ids = array_values(array_filter((array) $request->input('ids', []), static fn (mixed $id): bool => is_numeric($id)));
            $query = Resource::query()->with(['titles.titleType', 'igsnMetadata', 'resourceType']);
            if ($route !== 'resources.destroy-all') {
                $query->whereIn('id', $ids);
            }
            $query->chunkById(250, function ($resources) use (&$subjects): void {
                foreach ($resources as $item) {
                    $subjects[$item->id] = $this->activities->subject($item);
                }
            });
        }
        $before = $route === 'landing-page.destroy' && $resource !== null ? $this->activities->snapshot($resource, true) : [];
        $requestedCount = count($subjects);
        $testMode = str_contains($route, 'register') && app(DataCiteModeResolverService::class)->shouldUseTestMode($user);
        $response = $next($request);
        if ($response->getStatusCode() >= 400) {
            return $response;
        }
        $payload = $response instanceof JsonResponse ? $response->getData(true) : [];
        if ($control) {
            $run = $request->route('registrationRun') ?? $request->route('run');
            $id = $run instanceof Model ? $run->getKey() : ($request->route('importId') ?? $operation);
            $name = match (true) {
                str_starts_with($route, 'datacite.import.') => 'DataCite resource import',
                str_starts_with($route, 'igsns.import.') => 'DataCite IGSN import',
                str_starts_with($route, 'igsns.batch-register.') => 'IGSN registration run',
                default => 'DataCite landing-page URL update run',
            };
            $controlVerb = match (true) {
                str_ends_with($route, '.cancel') => 'requested cancellation of',
                str_ends_with($route, '.resume') => 'resumed',
                str_ends_with($route, '.retry-failed') => 'requested a retry of failed items in',
                default => 'requested a DataCite synchronization retry for',
            };
            $this->activities->record($actor, $route, "{$controlVerb} the {$name} (operation {$id})", operationId: (string) $id);

            return $response;
        }

        $fields = [];
        $verb = self::ACTIONS[$route];
        $testMode = ($payload['mode'] ?? null) === 'test' || $testMode;
        if (in_array($route, ['resources.register-doi', 'igsns.register'], true)) {
            $verb = ($payload['updated'] ?? false) ? 'updated DataCite metadata for' : 'registered at DataCite';
        }
        if ($route === 'landing-page.destroy' && $resource !== null) {
            $after = $this->activities->snapshot($resource, true);
            $fields = $this->activities->changedFields($before, $after);
            if ($fields === []) {
                return $response;
            }
        }
        // A 207 response is a successful partial batch, not success for every input ID.
        if ($route === 'resources.batch-register') {
            $subjects = array_intersect_key($subjects, array_flip(array_column($payload['success'] ?? [], 'id')));
        } elseif (str_starts_with($route, 'resources.send-review-link')) {
            $subjects = array_intersect_key($subjects, array_flip(array_column($payload['successful_resources'] ?? [], 'id')));
        } elseif ($route === 'resources.batch-export' && $response instanceof BinaryFileResponse) {
            $zip = new ZipArchive;
            $included = [];
            if ($zip->open($response->getFile()->getPathname()) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    if (preg_match('/^resource-(\d+)[-.]/', $zip->getNameIndex($i) ?: '', $match) === 1) {
                        $included[] = (int) $match[1];
                    }
                }
                $zip->close();
            }
            $subjects = array_intersect_key($subjects, array_flip($included));
        }
        if ($verb === 'deleted') {
            foreach (array_chunk(array_keys($subjects), 250) as $ids) {
                foreach (Resource::query()->whereIn('id', $ids)->pluck('id') as $id) {
                    unset($subjects[$id]);
                }
            }
        } elseif ($resource !== null) {
            $subjects = [$resource->id => $this->activities->subject($resource->fresh() ?? $resource)];
        }
        $write = function () use ($actor, $route, $verb, $subjects, $fields, $operation, $testMode, $resource, $payload, $requestedCount): void {
            foreach ($subjects as $subject) {
                $itemVerb = $verb;
                if ($route === 'resources.batch-register') {
                    $result = array_find($payload['success'] ?? [], static fn (array $item): bool => $item['id'] === $subject['id']);
                    $itemVerb = ($result['updated'] ?? false) ? 'updated DataCite metadata for' : 'registered at DataCite';
                } elseif (str_starts_with($route, 'resources.send-review-link')) {
                    $result = array_find($payload['successful_resources'] ?? [], static fn (array $item): bool => $item['id'] === $subject['id']);
                    $itemVerb = 'queued '.(str_ends_with($route, 'migrations') ? 'review-link migration' : 'review-link').
                        ' emails for delivery to '.($result['queued_recipients'] ?? 0).' recipients for';
                }
                $this->activities->record($actor, $route, $itemVerb, $subject, $fields, $operation, $testMode);
            }
            if ($resource === null && $subjects !== []) {
                $name = match ($route) {
                    'resources.batch-export' => 'metadata export',
                    'resources.batch-register' => 'DOI registration',
                    'resources.send-review-links' => 'review-link email queueing',
                    'resources.send-review-link-migrations' => 'review-link migration email queueing',
                    'igsns.batch.destroy' => 'IGSN deletion',
                    default => 'resource deletion',
                };
                $this->activities->summary($actor, $name, $operation, [
                    'successful' => count($subjects),
                    'failed' => max($requestedCount - count($subjects), count($payload['failed'] ?? $payload['failed_resources'] ?? [])),
                ], $testMode);
            }
        };
        if ($response instanceof StreamedResponse) {
            $callback = $response->getCallback();
            $response->setCallback(static function () use ($callback, $write): void {
                if ($callback !== null) {
                    $callback();
                    $write();
                }
            });
        } else {
            $write();
        }

        return $response;
    }
}
