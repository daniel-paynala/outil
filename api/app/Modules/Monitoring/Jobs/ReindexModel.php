<?php

namespace App\Modules\Monitoring\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Repeuple l'index d'un modèle.
 *
 * En file plutôt qu'en direct : un `scout:import` parcourt toute une table par
 * lots et peut prendre des minutes. Le tenir dans le cycle d'une requête HTTP
 * la ferait expirer — et Cloudflare coupe à cent secondes — en laissant croire
 * à un échec alors que l'import continue.
 */
class ReindexModel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @param  class-string  $model */
    public function __construct(private readonly string $model) {}

    public function handle(): void
    {
        $code = Artisan::call('scout:import', ['model' => $this->model]);

        Log::info('Réindexation', [
            'modele' => $this->model,
            'code' => $code,
            'sortie' => trim(Artisan::output()),
        ]);
    }
}
