<?php

declare(strict_types=1);

namespace App\Services\Orcid;

use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Services\OrcidService;
use App\Support\OrcidNormalizer;
use Illuminate\Support\Facades\Date;

/**
 * Validates every ORCID attached to a {@see Resource} against orcid.org
 * immediately before DOI registration (see issue #610).
 *
 * This is intentionally the only server-side ORCID network validation path
 * on the happy flow – loading a resource into the curation editor must NOT
 * call orcid.org. Typing a new ORCID still triggers client-side auto-verify
 * via the `useOrcidAutofill` hook.
 *
 * Failure handling mirrors the client-side reasons emitted by
 * {@see OrcidService}: `not_found` / `checksum` / `format` are hard-blocking
 * (`$result->shouldBlock === true`) while transient network errors
 * (`timeout`, `api_error`, `network`, `unknown`) only require explicit
 * curator confirmation via `force=true`.
 */
final readonly class OrcidPreflightValidator
{
    public function __construct(
        private OrcidService $orcid,
    ) {}

    /**
     * Validate every creator and contributor ORCID attached to $resource.
     *
     * @param  bool  $force  When true, transient warnings are suppressed (the
     *                      curator has explicitly overridden them).
     */
    public function validate(Resource $resource, bool $force = false): OrcidPreflightResult
    {
        $invalid = [];
        $warnings = [];

        $resource->loadMissing(['creators', 'contributors']);

        foreach ($resource->creators as $creator) {
            /** @var ResourceCreator $creator */
            $issue = $this->checkCreatorOrContributor($creator, 'creator');
            if ($issue === null) {
                continue;
            }
            if ($issue->severity === 'blocking') {
                $invalid[] = $issue;
            } else {
                $warnings[] = $issue;
            }
        }

        foreach ($resource->contributors as $contributor) {
            /** @var ResourceContributor $contributor */
            $issue = $this->checkCreatorOrContributor($contributor, 'contributor');
            if ($issue === null) {
                continue;
            }
            if ($issue->severity === 'blocking') {
                $invalid[] = $issue;
            } else {
                $warnings[] = $issue;
            }
        }

        $shouldBlock = $invalid !== [];
        $needsConfirmation = ! $shouldBlock && $warnings !== [] && ! $force;

        return new OrcidPreflightResult(
            invalid: $invalid,
            warnings: $warnings,
            shouldBlock: $shouldBlock,
            needsConfirmation: $needsConfirmation,
        );
    }

    /**
     * Validate a single creator/contributor row.
     *
     * Returns null for rows that don't need a check (no person, no identifier,
     * non-ORCID scheme) and also for successful validations (the row is then
     * marked verified via {@see self::markVerified()}).
     *
     * @param  ResourceCreator|ResourceContributor  $row
     * @param  'creator'|'contributor'  $role
     */
    private function checkCreatorOrContributor(object $row, string $role): ?OrcidPreflightIssue
    {
        $person = $this->resolvePerson($row);
        if ($person === null) {
            return null;
        }

        $identifier = $person->name_identifier;
        if ($identifier === null || trim($identifier) === '') {
            return null;
        }

        // Only validate identifiers explicitly tagged as ORCID (or untagged legacy rows).
        $scheme = $person->name_identifier_scheme;
        if ($scheme !== null && strtoupper($scheme) !== 'ORCID') {
            return null;
        }

        $bareId = OrcidNormalizer::extractBareId($identifier);
        $position = (int) ($row->position ?? 0);
        $displayName = $this->buildDisplayName($person);

        // Offline gate – catch malformed IDs before hitting the network.
        if (! OrcidNormalizer::isValidFormat($bareId)) {
            return new OrcidPreflightIssue(
                severity: 'blocking',
                reason: 'format',
                role: $role,
                position: $position,
                orcid: $bareId,
                displayName: $displayName,
            );
        }

        if (! OrcidNormalizer::isValidChecksum($bareId)) {
            return new OrcidPreflightIssue(
                severity: 'blocking',
                reason: 'checksum',
                role: $role,
                position: $position,
                orcid: $bareId,
                displayName: $displayName,
            );
        }

        $response = $this->orcid->validateOrcid($bareId);

        // Successful confirmation → stamp verification timestamp and move on.
        if (($response['exists'] ?? null) === true) {
            $this->markVerified($person);

            return null;
        }

        $reason = $response['errorType'] ?? 'unknown';

        return match ($reason) {
            'not_found', 'checksum', 'format' => new OrcidPreflightIssue(
                severity: 'blocking',
                reason: $reason,
                role: $role,
                position: $position,
                orcid: $bareId,
                displayName: $displayName,
            ),
            default => new OrcidPreflightIssue(
                severity: 'warning',
                reason: in_array($reason, ['timeout', 'api_error', 'network', 'unknown'], true) ? $reason : 'unknown',
                role: $role,
                position: $position,
                orcid: $bareId,
                displayName: $displayName,
            ),
        };
    }

    /**
     * Resolve the Person model attached to a creator/contributor row.
     *
     * @param  ResourceCreator|ResourceContributor  $row
     */
    private function resolvePerson(object $row): ?Person
    {
        if ($row instanceof ResourceCreator) {
            $related = $row->creatorable;
        } else {
            /** @var ResourceContributor $row */
            $related = $row->contributorable;
        }

        return $related instanceof Person ? $related : null;
    }

    private function buildDisplayName(Person $person): string
    {
        $given = trim((string) $person->given_name);
        $family = trim((string) $person->family_name);
        $full = trim($given === '' ? $family : "{$given} {$family}");

        return $full === '' ? 'Unnamed person' : $full;
    }

    private function markVerified(Person $person): void
    {
        $person->orcid_verified_at = Date::now();
        $person->save();
    }
}
