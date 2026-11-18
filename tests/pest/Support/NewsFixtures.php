<?php

declare(strict_types=1);

use App\Models\HomepageNewsState;
use App\Models\News;

/** @return array<string, mixed> */
function newsInput(array $overrides = []): array
{
    return array_replace(['title' => 'New announcement', 'body_markdown' => '**News** with a [link](/about).', 'kind' => 'regular'], $overrides);
}

/** @return array{revision: int, state_revision: int} */
function newsActionData(News $news): array
{
    return ['revision' => $news->fresh()->revision, 'state_revision' => HomepageNewsState::findOrFail(1)->revision];
}
