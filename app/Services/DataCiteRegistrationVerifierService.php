<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DataCiteVerificationException;
use App\Support\DataCiteRegistrationEvidence;
use Illuminate\Http\Client\Response;

final class DataCiteRegistrationVerifierService
{
    public function verify(DataCiteMemberApiClient $client, string $doi, bool $deferWhenLimited = false): DataCiteRegistrationEvidence
    {
        // Validate the configured repository even when the remote DOI is missing.
        $repository = $client->repositoryClientId($doi);
        $response = $client->getDoi($doi, $deferWhenLimited);
        if ($response->status() === 404) {
            return new DataCiteRegistrationEvidence(strtolower(trim($doi)), 'missing', $repository, $client->endpoint(), $client->isTestMode(), null);
        }
        $response->throw();

        return $this->fromResponse($client, $doi, $response);
    }

    public function fromResponse(DataCiteMemberApiClient $client, string $doi, Response $response): DataCiteRegistrationEvidence
    {
        $id = $response->json('data.id');
        $attributeDoi = $response->json('data.attributes.doi');
        $state = $response->json('data.attributes.state');
        $owner = $response->json('data.relationships.client.data.id');
        $url = $response->json('data.attributes.url');
        if (! is_string($id) || strcasecmp(trim($id), trim($doi)) !== 0
            || ($attributeDoi !== null && (! is_string($attributeDoi) || strcasecmp(trim($attributeDoi), trim($doi)) !== 0))
            || ! in_array($state, ['draft', 'registered', 'findable'], true) || ! is_string($owner)) {
            throw new DataCiteVerificationException('doi_verification_unavailable', 'DataCite did not confirm the DOI identity, repository and state. No publication can be inferred.', true);
        }
        $repository = $client->repositoryClientId($doi);
        if (strtolower(trim($owner)) !== $repository) {
            throw new DataCiteVerificationException('doi_repository_mismatch', 'This DOI belongs to another DataCite repository.');
        }

        return new DataCiteRegistrationEvidence(strtolower(trim($doi)), $state, $repository, $client->endpoint(), $client->isTestMode(), is_string($url) ? $url : null);
    }

    public function requireRegistered(DataCiteRegistrationEvidence $evidence): void
    {
        if (! $evidence->isRegistered()) {
            throw new DataCiteVerificationException('doi_registration_required', 'This DOI is not registered in the selected DataCite environment. Explicit DOI and landing-page publication is required.');
        }
    }
}
