<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\VocabularyCorruptedException;
use App\Exceptions\VocabularyNotFoundException;
use App\Exceptions\VocabularyReadException;
use App\Models\PidSetting;
use App\Models\ThesaurusSetting;
use App\Support\Traits\ChecksCacheTagging;
use Closure;
use GuzzleHttp\Psr7\CachingStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PublicVocabularySnapshotService
{
    use ChecksCacheTagging;

    public function __construct(
        private readonly PublicVocabularySchemaService $schemas,
        private readonly MslLaboratoryVocabularyService $laboratories,
        private readonly Pid4instVocabularyService $instruments,
        private readonly CgiSimpleLithologyVocabularyService $lithology,
    ) {}

    public function response(string $slug, PidSetting|ThesaurusSetting $setting): JsonResponse|StreamedResponse
    {
        $lithology = $slug === 'cgi-simple-lithology';
        $disk = Storage::disk($lithology ? 'local' : null);
        $file = $lithology ? (string) config('simple_lithology.output_file') : $setting->getFilePath();
        if (! $disk->exists($file)) {
            throw new VocabularyNotFoundException('php artisan '.$setting->getArtisanCommand());
        }
        if ($slug === 'ror-affiliations') {
            return $this->ror($disk, $file);
        }
        $source = $disk->get($file);
        if ($source === null) {
            throw new VocabularyReadException;
        }

        $projection = $this->remember($slug, hash('xxh128', $source), fn () => $this->validateSnapshot($slug, $source));

        return JsonResponse::fromJsonString($projection ?? $source);
    }

    /** Return a projection only for vocabularies containing private metadata. */
    private function validateSnapshot(string $slug, string $source): ?string
    {
        $data = json_decode($source, flags: JSON_THROW_ON_ERROR);
        if ($slug === 'msl-laboratories') {
            // The legacy normalizer decodes associative arrays, where {} and [] would be indistinguishable.
            if (! is_array($data->data ?? null)) {
                throw new VocabularyCorruptedException('Expected a laboratory data list.');
            }
            $json = json_encode($this->laboratories->getPublicPayload($source), JSON_THROW_ON_ERROR);
            $this->schemas->validate($slug, json_decode($json, flags: JSON_THROW_ON_ERROR));

            return $json;
        }

        $this->schemas->validate($slug, $data);
        if ($slug === 'pid4inst-instruments') {
            return json_encode($this->instruments->getPublicPayload($source), JSON_THROW_ON_ERROR);
        }
        if ($slug === 'cgi-simple-lithology') {
            $this->lithology->decodeLocalPayload($source);
        }

        return null;
    }

    private function ror(Filesystem $disk, string $file): StreamedResponse
    {
        $handle = $disk->readStream($file);
        if (! is_resource($handle)) {
            throw new VocabularyReadException;
        }
        $stream = Utils::streamFor($handle);
        $stat = fstat($handle);
        $localFile = $stat !== false && ($stat['mode'] & 0170000) === 0100000
            && $stream->getMetadata('wrapper_type') === 'plainfile';
        $modified = $localFile ? $stat['mtime'] : $disk->lastModified($file);
        if (! $stream->isSeekable()) {
            $stream = new CachingStream($stream);
        }

        // GetRorIds publishes by rename. An old local inode can be served without scanning the file.
        // Hash recent writes because two same-size edits can share one second of stat timestamps.
        $prepare = fn () => $this->rorPrefix($stream, $modified);
        if ($localFile && PHP_OS_FAMILY !== 'Windows' && $stat['ino'] > 0 && $stat['ctime'] > 0
            && max($modified, $stat['ctime']) < now()->getTimestamp() - 1) {
            $identity = implode(':', [gethostname(), $stat['dev'], $stat['ino'], $stat['size'], $modified, $stat['ctime']]);
            $prefix = $this->remember('ror-affiliations:file', $identity, $prepare);
        } else {
            $prefix = $prepare();
        }
        $stream->rewind();

        return response()->stream(static function () use ($stream, $prefix): void {
            try {
                echo $prefix;
                while (! $stream->eof()) {
                    echo $stream->read(65536);
                }
                if ($prefix !== null) {
                    echo '}';
                }
            } finally {
                $stream->close();
            }
        }, headers: ['Content-Type' => 'application/json']);
    }

    private function rorPrefix(StreamInterface $stream, int $modified): ?string
    {
        $revision = Utils::hash($stream, 'xxh128').':'.$modified;

        return $this->remember('ror-affiliations', $revision, function () use ($stream, $modified): ?string {
            $snapshot = json_decode($stream->getContents(), flags: JSON_THROW_ON_ERROR);
            $prefix = null;
            if (is_array($snapshot)) {
                $updated = gmdate(DATE_ATOM, $modified);
                $total = count($snapshot);
                $snapshot = (object) ['lastUpdated' => $updated, 'total' => $total, 'data' => $snapshot];
                $prefix = '{"lastUpdated":"'.$updated.'","total":'.$total.',"data":';
            }
            $this->schemas->validate('ror-affiliations', $snapshot);
            if ($snapshot->total != count($snapshot->data)) {
                throw new VocabularyCorruptedException('ROR total does not match the number of affiliations.');
            }

            return $prefix;
        });
    }

    /**
     * A raw snapshot only needs a validation marker. A legacy ROR list needs its wrapper prefix.
     * Keep one revision per cache key and store larger public projections
     * on disk so they also work with size-limited database and memcached stores.
     *
     * @param  Closure(): ?string  $prepare
     */
    private function remember(string $slug, string $revision, Closure $prepare): ?string
    {
        $revision .= ':'.$this->schemas->revision();
        $key = 'vocabularies:public:1.2:'.$slug;
        $cache = in_array($slug, ['msl-laboratories', 'pid4inst-instruments'], true)
            ? Cache::store('file')
            : $this->getCacheInstance(str_starts_with($slug, 'ror-affiliations') ? ['vocabularies', 'ror'] : ['vocabularies']);
        $cached = $cache->get($key);
        if (is_array($cached) && $cached['revision'] === $revision) {
            return $cached['projection'];
        }

        $projection = $prepare();
        $cache->forever($key, ['revision' => $revision, 'projection' => $projection]);

        return $projection;
    }
}
