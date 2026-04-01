<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Online Window (Minutes)
    |--------------------------------------------------------------------------
    |
    | The number of minutes of inactivity before a user is considered offline.
    | This value is used by both the TrackLastSeenMiddleware (throttle) and
    | the User::isOnline() method to ensure consistent behavior.
    |
    */

    'online_window_minutes' => (int) env('USER_ONLINE_WINDOW_MINUTES', 5),

];
