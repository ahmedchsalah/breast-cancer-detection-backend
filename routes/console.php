<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── BReCAI HuggingFace Space keep-alive ──────────────────────────────────────
// Pings the FastAPI /health endpoint every 12 hours so the HF Space
// never goes to sleep (HF pauses free spaces after 48h of inactivity).
Schedule::command('brecai:wake')
    ->everyTwelveHours()
    ->withoutOverlapping()
    ->runInBackground()
    ->onSuccess(function () {
        \Illuminate\Support\Facades\Log::info('[Scheduler] brecai:wake completed successfully.');
    })
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::warning('[Scheduler] brecai:wake failed — space may be sleeping.');
    });
