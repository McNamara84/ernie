<?php

declare(strict_types=1);

namespace App\Services\Rights;

use App\Models\Right;
use App\Services\Spdx\SpdxLicenseLookup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CustomRightCatalogService
{
    public const string IDENTIFIER_PREFIX = 'CUSTOM-';

    private const int MAX_SLUG_LENGTH = 180;

    public function findOrCreate(string $name, string $uri): Right
    {
        $name = $this->normalizeRequired($name, 'customLicenses.*.name', 'Custom license name is required.');
        $uri = $this->normalizeRequired($uri, 'customLicenses.*.uri', 'Custom license URL is required.');
        $identifier = $this->identifierFor($name, $uri);

        /** @var Right|null $existingByIdentifier */
        $existingByIdentifier = Right::query()
            ->where('identifier', $identifier)
            ->first();

        if ($existingByIdentifier instanceof Right) {
            return $this->activateCustomRight($existingByIdentifier);
        }

        $existingByNameAndUri = $this->findExistingCustomRight($name, $uri);

        if ($existingByNameAndUri instanceof Right) {
            return $this->activateCustomRight($existingByNameAndUri);
        }

        /** @var Right $right */
        $right = Right::query()->firstOrCreate(
            ['identifier' => $identifier],
            [
                'name' => $name,
                'uri' => $uri,
                'scheme_uri' => null,
                'is_active' => true,
                'is_elmo_active' => false,
                'usage_count' => 0,
            ],
        );

        return $this->activateCustomRight($right);
    }

    public static function isSpdxRight(?Right $right): bool
    {
        return $right instanceof Right
            && $right->scheme_uri === SpdxLicenseLookup::SCHEME_URI;
    }

    public static function isCustomRight(?Right $right): bool
    {
        return $right instanceof Right
            && ! self::isSpdxRight($right);
    }

    public function identifierFor(string $name, string $uri): string
    {
        $slug = Str::slug($name, '-');
        $slug = $slug !== '' ? $slug : 'license';
        $slug = mb_strtoupper(mb_substr($slug, 0, self::MAX_SLUG_LENGTH));
        $hash = substr(hash('sha256', Str::lower(trim($name)).'|'.SpdxLicenseLookup::normalizeUri($uri)), 0, 12);

        return self::IDENTIFIER_PREFIX.$slug.'-'.mb_strtoupper($hash);
    }

    private function activateCustomRight(Right $right): Right
    {
        if (self::isSpdxRight($right)) {
            throw ValidationException::withMessages([
                'customLicenses' => '[Licenses & Rights] The generated custom license identifier conflicts with an SPDX license.',
            ]);
        }

        if (! $right->is_active || $right->is_elmo_active) {
            $right->forceFill([
                'is_active' => true,
                'is_elmo_active' => false,
            ])->save();
        }

        return $right;
    }

    private function findExistingCustomRight(string $name, string $uri): ?Right
    {
        $normalizedName = SpdxLicenseLookup::normalizeText($name);
        $normalizedUri = SpdxLicenseLookup::normalizeUri($uri);
        $uriLikePattern = '%'.$this->escapeSqlLikePattern($normalizedUri).'%';

        /** @var Collection<int, Right> $candidates */
        $candidates = Right::query()
            ->select(['id', 'identifier', 'name', 'uri', 'scheme_uri', 'is_active', 'is_elmo_active'])
            ->where(function ($query): void {
                $query->whereNull('scheme_uri')
                    ->orWhere('scheme_uri', '!=', SpdxLicenseLookup::SCHEME_URI);
            })
            ->whereNotNull('uri')
            ->whereRaw('LOWER(TRIM(name)) = ?', [Str::lower(trim($name))])
            ->whereRaw("LOWER(uri) LIKE ? ESCAPE '!'", [$uriLikePattern])
            ->get();

        return $candidates->first(
            fn (Right $right): bool => SpdxLicenseLookup::normalizeText($right->name) === $normalizedName
                && SpdxLicenseLookup::normalizeUri($right->uri) === $normalizedUri
        );
    }

    private function escapeSqlLikePattern(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    private function normalizeRequired(string $value, string $field, string $message): string
    {
        $value = trim($value);

        if ($value === '') {
            throw ValidationException::withMessages([
                $field => '[Licenses & Rights] '.$message,
            ]);
        }

        return $value;
    }
}
