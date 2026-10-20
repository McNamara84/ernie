<?php

declare(strict_types=1);

namespace App\Services\Usage;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class CounterListService
{
    /** @var list<string> */
    private const GENERAL_CLIENTS = ['okhttp', 'RestSharp', 'ReactorNetty\/\d', 'Pcore-HTTP', 'Scrapy\/\d', '^java\/\d'];

    public function installBundled(): int
    {
        $sources = [];
        foreach (['counter' => 'counter-robots.json', 'robots' => 'robots.txt', 'machines' => 'machines.txt'] as $name => $file) {
            $content = file_get_contents(resource_path('data/counter/'.$file));
            if ($content === false) {
                throw new RuntimeException('Bundled COUNTER lists are unavailable.');
            }
            $sources[$name] = $content;
        }

        return $this->install($sources, 'bundled');
    }

    public function update(): int
    {
        $sources = [];
        foreach (['counter', 'robots', 'machines'] as $name) {
            $url = (string) config('usage.sources.'.$name);
            if (! str_starts_with($url, 'https://')) {
                throw new RuntimeException('COUNTER sources must use HTTPS.');
            }
            $response = Http::withOptions(['allow_redirects' => false])->connectTimeout(5)->timeout(20)->get($url)->throw();
            $sources[$name] = $response->body();
        }

        return $this->install($sources, 'upstream');
    }

    /**
     * Fetch and validate the entire set before changing the active version.
     *
     * @param  array<string, string>  $sources
     */
    public function install(array $sources, string $origin): int
    {
        foreach (['counter', 'robots', 'machines'] as $name) {
            if (! isset($sources[$name]) || strlen($sources[$name]) > (int) config('usage.max_list_bytes') || trim($sources[$name]) === '') {
                throw new RuntimeException('Invalid COUNTER source: '.$name);
            }
        }
        $counter = json_decode($sources['counter'], true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($counter) || ! array_is_list($counter)) {
            throw new RuntimeException('COUNTER robot source must be a JSON list.');
        }
        $robots = $this->lines($sources['robots']);
        $sourceMachines = $this->lines($sources['machines']);
        if ($sourceMachines === []) {
            throw new RuntimeException('The machine list must contain classification rules.');
        }
        $machines = array_values(array_unique([...$sourceMachines, ...self::GENERAL_CLIENTS]));
        foreach ($counter as $entry) {
            if (! is_array($entry) || ! isset($entry['pattern']) || ! is_string($entry['pattern'])) {
                throw new RuntimeException('Every COUNTER robot entry must contain a pattern.');
            }
            $robots[] = $entry['pattern'];
        }
        // Research Data explicitly permits general-purpose research clients.
        // Only remove matching generic rules; specific crawler rules still win.
        $robots = array_values(array_diff(array_unique($robots), $machines));
        foreach ([...$robots, ...$machines] as $pattern) {
            if (strlen($pattern) > 2000 || @preg_match($this->regex($pattern), '') === false) {
                throw new RuntimeException('Invalid regular expression in COUNTER lists.');
            }
        }
        if ($robots === []) {
            throw new RuntimeException('COUNTER lists must not be empty.');
        }
        $rules = ['robots' => $robots, 'machines' => $machines];
        $classifier = new CounterUserAgentClassifierService;
        foreach (['Googlebot' => 'Robot', 'GPTBot' => 'Robot', 'curl/8.0' => 'Machine', 'python-requests/2.32' => 'Machine', 'Wget/1.21' => 'Machine', 'Java/21' => 'Machine', 'Mozilla/5.0 Firefox/130' => 'Regular'] as $agent => $expected) {
            if ($classifier->classify($agent, $rules) !== $expected) {
                throw new RuntimeException('COUNTER lists failed the research-data classification checks.');
            }
        }
        $encoded = json_encode($rules, JSON_THROW_ON_ERROR);
        $checksum = hash('sha256', $encoded);
        $manifest = ['origin' => $origin, 'adapter' => 1, 'sources' => []];
        foreach ($sources as $name => $content) {
            $manifest['sources'][$name] = ['url' => config('usage.sources.'.$name), 'sha256' => hash('sha256', $content)];
        }

        return DB::transaction(function () use ($checksum, $encoded, $manifest): int {
            DB::table('usage_tracking_state')->where('id', 1)->lockForUpdate()->firstOrFail();
            $id = DB::table('usage_list_versions')->where('checksum', $checksum)->value('id');
            if ($id === null) {
                $id = DB::table('usage_list_versions')->insertGetId([
                    'checksum' => $checksum, 'rules' => $encoded,
                    'sources' => json_encode($manifest, JSON_THROW_ON_ERROR), 'created_at' => CarbonImmutable::now('UTC'),
                ]);
            }
            DB::table('usage_tracking_state')->where('id', 1)->update(['list_version_id' => $id]);

            return (int) $id;
        });
    }

    /** @return list<string> */
    private function lines(string $content): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $content)), static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#')));
    }

    public static function regex(string $pattern): string
    {
        return '~'.str_replace('~', '\~', $pattern).'~i';
    }
}
