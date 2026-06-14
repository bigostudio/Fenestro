<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendPartnerRejectedJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly User $user) {}

    public function handle(WhatsAppService $whatsApp): void
    {
        $whatsApp->sendTemplate(
            to: '91' . $this->user->phone,
            templateName: 'fenestro_partner_rejected',
        );
    }
}
