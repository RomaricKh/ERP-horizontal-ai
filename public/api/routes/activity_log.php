<?php

use App\Controllers\ActivityLogController;

/* ========= ACTIVITY LOG ========= */

// GET /activity-log
$router->get('/activity-log',
    fn() => (new ActivityLogController())->index(),
    ['activityLog.view']
);
