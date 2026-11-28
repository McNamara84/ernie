<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ContactPersons\ResourceContactProjectorService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

#[Description('Rebuild the contact person search index from current resource metadata')]
#[Signature('contact-persons:rebuild')]
final class RebuildContactPersons extends Command
{
    public function handle(ResourceContactProjectorService $projector): int
    {
        if (! Schema::hasTable('resource_contact_points')) {
            $this->error('Run database migrations before rebuilding contact persons.');

            return self::FAILURE;
        }

        $projector->rebuildAll();
        $this->info('Contact person index rebuilt.');

        return self::SUCCESS;
    }
}
