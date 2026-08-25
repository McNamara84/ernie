<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CgiSimpleLithologyVocabularyService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Description('Fetch CGI Simple Lithology from the official CGI vocabulary API')]
#[Signature('get-cgi-simple-lithology')]
final class GetCgiSimpleLithology extends Command
{
    public function handle(CgiSimpleLithologyVocabularyService $service): int
    {
        $this->info('Fetching CGI Simple Lithology from the official CGI vocabulary API...');

        try {
            $payload = $service->updateLocalVocabulary();
            $this->call('cache:clear-app', ['category' => 'vocabularies']);

            $file = (string) config('simple_lithology.output_file');
            $this->components->twoColumnDetail('File', Storage::disk('local')->path($file));
            $this->components->twoColumnDetail('Unique concepts', number_format((int) $payload['total']));
            $this->components->twoColumnDetail('Hierarchy paths', number_format((int) $payload['pathCount']));
            $this->components->twoColumnDetail('Source SHA-256', (string) $payload['source']['sha256']);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('CGI Simple Lithology update failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
