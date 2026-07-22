<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ThesaurusSetting;
use App\Services\MslLaboratoryVocabularyService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Description('Discover and download the latest stable MSL laboratories vocabulary')]
#[Signature('get-msl-laboratories')]
class GetMslLaboratories extends Command
{
    public function handle(MslLaboratoryVocabularyService $vocabularyService): int
    {
        $this->info('Discovering the latest stable MSL laboratories vocabulary...');

        try {
            $payload = $vocabularyService->updateLocal();

            $setting = ThesaurusSetting::query()->firstOrCreate(
                ['type' => ThesaurusSetting::TYPE_MSL_LABORATORIES],
                [
                    'display_name' => ThesaurusSetting::definitions()[
                        ThesaurusSetting::TYPE_MSL_LABORATORIES
                    ],
                    'is_active' => true,
                    'is_elmo_active' => true,
                ]
            );
            $setting->update(['version' => $payload['version']]);

            $this->components->twoColumnDetail('Version', (string) $payload['version']);
            $this->components->twoColumnDetail('Laboratories', number_format((int) $payload['total']));
            $this->components->twoColumnDetail('Source', (string) $payload['source']['path']);
            $this->components->twoColumnDetail('Git SHA', (string) $payload['source']['sha']);
            $this->components->twoColumnDetail(
                'Stored at',
                Storage::path($setting->getFilePath())
            );

            $this->info('MSL laboratories vocabulary updated successfully.');

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Failed to update MSL laboratories: '.$exception->getMessage());

            return Command::FAILURE;
        }
    }
}
