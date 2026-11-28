<?php

declare(strict_types=1);

return [
    'waiting_overdue_days' => max(1, (int) env('CURATION_WAITING_OVERDUE_DAYS', 14)),
];
