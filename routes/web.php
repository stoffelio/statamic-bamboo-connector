<?php

use Illuminate\Support\Facades\Route;
use Stoffelio\BambooConnector\Http\Controllers\FollowUpController;

Route::prefix(config('bamboo.follow_up_path', 'follow-up'))->group(function () {
    Route::get('{dataRequest}/{token}', [FollowUpController::class, 'show'])->name('bamboo.follow-up');
    Route::post('{dataRequest}/{token}', [FollowUpController::class, 'submit']);
    Route::post('{dataRequest}/{token}/rendered', [FollowUpController::class, 'rendered']);
});
