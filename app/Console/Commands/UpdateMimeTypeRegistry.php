<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SizeFormat\MimeTypeRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

final class UpdateMimeTypeRegistry extends Command
{
    protected $signature = 'mime-types:update {--source= : Read an offline IANA XML snapshot}';

    protected $description = 'Refresh the versioned IANA MIME type catalog used by the Data Editor';

    public function handle(MimeTypeRegistry $registry): int
    {
        $destination = $registry->path();
        $temporary = null;
        try {
            $source = $this->option('source');
            $xml = is_string($source) && $source !== ''
                ? file_get_contents($source)
                : Http::timeout(60)->get('https://www.iana.org/assignments/media-types/media-types.xml')->throw()->body();
            if (! is_string($xml)) {
                throw new \RuntimeException('Could not read the source XML.');
            }
            $catalog = MimeTypeRegistry::fromXml($xml);
            // An incomplete download must never replace the complete pinned registry.
            if (count($catalog['types']) < 2000) {
                throw new \RuntimeException('The registry contains fewer than 2000 MIME types.');
            }
            foreach (['application', 'audio', 'font', 'haptics', 'image', 'message', 'model', 'multipart', 'text', 'video'] as $category) {
                if (! array_any($catalog['types'], static fn (string $type): bool => str_starts_with($type, $category.'/'))) {
                    throw new \RuntimeException('The registry is missing the '.$category.' category.');
                }
            }
            $temporary = tempnam(dirname($destination), '.mime-types-');
            if ($temporary === false || file_put_contents($temporary, json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n") === false
                || ! rename($temporary, $destination)) {
                throw new \RuntimeException('Could not atomically publish the MIME catalog.');
            }
            $this->info('Published '.count($catalog['types']).' IANA MIME types ('.$catalog['registry_updated'].').');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
