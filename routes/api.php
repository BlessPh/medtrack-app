<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    require app_path('Modules/Auth/Routes/api.php');
    require app_path('Modules/Institution/Routes/api.php');
    require app_path('Modules/Academic/Routes/api.php');
    require app_path('Modules/Admission/Routes/api.php');
    require app_path('Modules/Internship/Routes/api.php');
    require app_path('Modules/Scheduling/Routes/api.php');
    require app_path('Modules/Assessment/Routes/api.php');
    require app_path('Modules/Finance/Routes/api.php');
    require app_path('Modules/Media/Routes/api.php');
    require app_path('Modules/Notification/Routes/api.php');
    require app_path('Modules/Reporting/Routes/api.php');
});
