<?php

declare(strict_types=1);

namespace Tests\Support;

use GuzzleHttp\Psr7\Uri;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class GemetRuntimeServer
{
    private Process $process;

    private string $directory;

    public string $url = '';

    public function __construct(string $mode, int $groups = 9)
    {
        $this->directory = sys_get_temp_dir().'/ernie-gemet-runtime-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->configure($mode, $groups);
        $this->process = new Process(['node', base_path('tests/fixtures/gemet-runtime-server.mjs'), $this->directory.'/configuration.json']);
        $this->process->setTimeout(10);
        try {
            $this->process->start();
            $ready = $this->process->waitUntil(function (string $type, string $output): bool {
                if ($type !== Process::OUT || ! str_starts_with($output, 'http://127.0.0.1:')) {
                    return false;
                }
                $this->url = trim($output);

                return true;
            });
            if (! $ready) {
                throw new RuntimeException('GEMET runtime server failed: '.$this->process->getErrorOutput());
            }
        } catch (Throwable $exception) {
            $this->stop();

            throw $exception;
        }
        $this->process->setTimeout(null);

        // Rewrite only the destination. Laravel, Guzzle, CurlMultiHandler,
        // sockets, production timeouts and the monotonic clock remain real.
        Http::globalRequestMiddleware(function (RequestInterface $request): RequestInterface {
            if ($request->getUri()->getHost() !== 'www.eionet.europa.eu') {
                throw new RuntimeException('Unexpected outbound request in GEMET runtime test.');
            }
            $local = new Uri($this->url);

            return $request->withUri($request->getUri()->withScheme('http')->withHost($local->getHost())->withPort($local->getPort()));
        });
    }

    public function configure(string $mode, int $groups = 9): void
    {
        file_put_contents($this->directory.'/configuration.json', json_encode(compact('mode', 'groups'), JSON_THROW_ON_ERROR));
    }

    public function metrics(): array
    {
        return json_decode((string) file_get_contents($this->url.'/metrics'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function stop(): void
    {
        $this->process->stop(1);
        unlink($this->directory.'/configuration.json');
        rmdir($this->directory);
    }
}
