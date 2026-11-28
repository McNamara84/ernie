<?php

declare(strict_types=1);

namespace App\Services\FundingAwardUri;

interface AwardSource
{
    public function lookup(string $awardNumber, FunderIdentity $funder): AwardSourceResult;
}
