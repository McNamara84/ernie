<?php

declare(strict_types=1);

namespace App\Services\Citations;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

final readonly class RelatedWorkCitation
{
    /** @param list<array{text: string, italic: bool}>|null $parts */
    public function __construct(
        public string $text,
        public ?array $parts = null,
        public ?string $workType = null,
        public bool $hasSource = false,
        public ?string $doi = null,
    ) {}

    /** Carry generated typography through the editable form, independently of cache expiry. */
    public function proof(): ?string
    {
        if ($this->parts === null) {
            return null;
        }

        return Crypt::encryptString(json_encode(['citation_doi' => $this->doi, 'parts' => $this->parts], JSON_THROW_ON_ERROR));
    }

    /** @return list<array{text: string, italic: bool}>|null */
    public static function verifiedParts(?string $proof, ?string $doi, ?string $text): ?array
    {
        if ($proof === null || $doi === null || $text === null) {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($proof), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        if (($data['citation_doi'] ?? null) !== $doi || ! is_array($data['parts'] ?? null) || ! array_is_list($data['parts'])) {
            return null;
        }
        $parts = [];
        foreach ($data['parts'] as $part) {
            if (! is_string($part['text'] ?? null) || ! is_bool($part['italic'] ?? null)) {
                return null;
            }
            $parts[] = ['text' => $part['text'], 'italic' => $part['italic']];
        }

        return implode('', array_column($parts, 'text')) === $text ? $parts : null;
    }
}
