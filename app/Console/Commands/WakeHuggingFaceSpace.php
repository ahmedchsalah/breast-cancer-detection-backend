<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WakeHuggingFaceSpace
 *
 * Sends a lightweight GET /health ping to the BReCAI FastAPI space
 * to prevent it from sleeping after 48 hours of inactivity.
 *
 * Scheduled every 12 hours in routes/console.php (or Kernel.php).
 *
 * Usage:
 *   php artisan brecai:wake
 */
class WakeHuggingFaceSpace extends Command
{
    protected $signature   = 'brecai:wake';
    protected $description = 'Ping the BReCAI HuggingFace Space to keep it awake';

    public function handle(): int
    {
        $url      = rtrim(config('services.brecai.url'), '/') . '/health';
        $hfToken  = config('services.brecai.hf_token');

        $this->info("[BReCAI Wake] Pinging {$url} …");

        try {
            $client = Http::timeout(60)->retry(2, 5000);

            if ($hfToken) {
                $client = $client->withToken($hfToken);
            }

            $response = $client->get($url);

            if ($response->successful()) {
                $data         = $response->json();
                $modelsLoaded = $data['models_loaded'] ?? false;
                $status       = $data['status'] ?? 'unknown';

                $this->info("[BReCAI Wake] ✅ Space is awake — status={$status}, models_loaded=" . ($modelsLoaded ? 'true' : 'false'));
                Log::info("[BReCAI Wake] Space ping OK — status={$status}, models_loaded={$modelsLoaded}");

                if (! $modelsLoaded) {
                    $this->warn("[BReCAI Wake] ⚠ Space is up but models are still loading. Will be ready in a few minutes.");
                }

                return self::SUCCESS;
            }

            $this->error("[BReCAI Wake] ❌ Space returned HTTP {$response->status()}: " . $response->body());
            Log::warning("[BReCAI Wake] Space ping failed — HTTP {$response->status()}");

            return self::FAILURE;

        } catch (\Throwable $e) {
            $this->error("[BReCAI Wake] ❌ Exception: {$e->getMessage()}");
            Log::error("[BReCAI Wake] Exception during ping: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
