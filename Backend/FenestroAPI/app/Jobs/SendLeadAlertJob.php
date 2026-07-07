<?php

namespace App\Jobs;

use App\Services\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendLeadAlertJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;

    public function handle(WhatsAppService $whatsApp): void
    {
        $whatsApp->sendTemplate(
            to: config('services.whatsapp.ops_manager_phone'),
            templateName: 'fenestro_new_lead'
        );
    }
}
