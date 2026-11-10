<?php

declare(strict_types=1);

namespace App\Services\DcatAp;

use App\Enums\AccessLevel;
use App\Support\LanguageTag;

/** Only vocabulary entries in the pinned local snapshots are exported. */
final class DcatApVocabularyMapper
{
    /** @var array{mediaTypes: list<string>, fileTypes: array<string, list<string>>, languages: array<string, string>, concepts: array<string, list<string>>}|null */
    private ?array $index = null;

    public function mediaType(string $mime): ?string
    {
        $index = $this->index();
        $mime = strtolower(trim($mime));

        return in_array($mime, $index['mediaTypes'], true) ? 'https://www.iana.org/assignments/media-types/'.$mime : null;
    }

    public function fileType(string $mime): ?string
    {
        $index = $this->index();
        $matches = $index['fileTypes'][strtolower(trim($mime))] ?? [];

        return count($matches) === 1 ? $matches[0] : null;
    }

    public function language(?string $code): ?string
    {
        $index = $this->index();
        $code = LanguageTag::primarySubtag(LanguageTag::validOrNull($code));

        return $index['languages'][$code ?? ''] ?? null;
    }

    public function access(?AccessLevel $access): ?string
    {
        $code = match ($access) {
            AccessLevel::OPEN => 'PUBLIC',
            AccessLevel::RESTRICTED, AccessLevel::EMBARGOED => 'RESTRICTED',
            AccessLevel::METADATA_ONLY => 'NON_PUBLIC',
            null => null,
        };

        return $code === null ? null : 'http://publications.europa.eu/resource/authority/access-right/'.$code;
    }

    /** @return array{mediaTypes: list<string>, fileTypes: array<string, list<string>>, languages: array<string, string>, concepts: array<string, list<string>>} */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }
        $contents = file_get_contents(resource_path('data/scheme/dcat-ap/3.0.1/vocabularies/index.json'));
        if ($contents === false) {
            throw new \RuntimeException('Cannot load pinned DCAT-AP vocabulary index');
        }
        /** @var array{mediaTypes: list<string>, fileTypes: array<string, list<string>>, languages: array<string, string>, concepts: array<string, list<string>>}|null $index */
        $index = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if ($index === null) {
            throw new \RuntimeException('The pinned DCAT-AP vocabulary index is empty');
        }

        return $this->index = $index;
    }
}
