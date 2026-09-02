<?php

declare(strict_types=1);

namespace App\Services\Igsn;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class IgsnExternalSampleImageProbeService
{
    private const ERROR_PROBE_SIZE_LIMIT = 'probe_size_limit';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly IgsnSampleImageUrlService $urlService,
    ) {}

    /**
     * @return array{status: string, url: string|null, message: string}
     */
    public function probe(string $externalUrl): array
    {
        $classification = $this->urlService->classifySourceUrl($externalUrl);
        if ($classification['status'] !== IgsnSampleImageUrlService::STATUS_EXTERNAL
            || ! is_string($classification['external_url'])) {
            return $this->result(self::STATUS_FAILED, null, 'unsupported_external_url');
        }

        $url = $classification['external_url'];
        $maxBytes = max(1, (int) config('igsn_images.external_probe_max_bytes', 256 * 1024));

        try {
            $response = Http::connectTimeout(max(1, (int) config('igsn_images.connect_timeout_seconds', 5)))
                ->timeout(max(1, (int) config('igsn_images.external_probe_timeout_seconds', 10)))
                ->withHeaders([
                    'Accept' => 'image/*',
                    'Range' => sprintf('bytes=0-%d', $maxBytes - 1),
                ])
                ->withOptions([
                    'allow_redirects' => false,
                    'progress' => static function (int $downloadTotal, int $downloaded) use ($maxBytes): void {
                        if ($downloadTotal > $maxBytes || $downloaded > $maxBytes) {
                            throw new RuntimeException(self::ERROR_PROBE_SIZE_LIMIT);
                        }
                    },
                ])
                ->get($url);
        } catch (ConnectionException) {
            return $this->result(self::STATUS_FAILED, $url, 'transport_error');
        } catch (Throwable $exception) {
            $error = $exception instanceof RuntimeException
                && $exception->getMessage() === self::ERROR_PROBE_SIZE_LIMIT
                    ? self::ERROR_PROBE_SIZE_LIMIT
                    : 'probe_error';

            return $this->result(self::STATUS_FAILED, $url, $error);
        }

        if ($response->redirect()) {
            return $this->result(self::STATUS_UNAVAILABLE, $url, 'redirect_not_allowed');
        }

        $status = $response->status();
        if (in_array($status, [404, 410], true)) {
            return $this->result(self::STATUS_UNAVAILABLE, $url, 'http_'.$status);
        }
        if ($status === 429 || $status >= 500) {
            return $this->result(self::STATUS_FAILED, $url, 'http_'.$status);
        }
        if (! $response->successful()) {
            return $this->result(self::STATUS_FAILED, $url, 'http_'.$status);
        }

        $body = $response->body();
        if ($body === '') {
            return $this->result(self::STATUS_UNAVAILABLE, $url, 'empty_image');
        }
        if (strlen($body) > $maxBytes) {
            return $this->result(self::STATUS_FAILED, $url, self::ERROR_PROBE_SIZE_LIMIT);
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body);
        $allowedMimeTypes = (array) config('igsn_images.allowed_mime_types', ['image/jpeg' => 'jpg']);
        if (! is_string($mimeType) || ! array_key_exists($mimeType, $allowedMimeTypes)) {
            return $this->result(self::STATUS_UNAVAILABLE, $url, 'unsupported_mime_type');
        }

        return $this->result(self::STATUS_AVAILABLE, $url, 'external_image_available');
    }

    /** @return array{status: string, url: string|null, message: string} */
    private function result(string $status, ?string $url, string $message): array
    {
        return [
            'status' => $status,
            'url' => $url,
            'message' => $message,
        ];
    }
}
