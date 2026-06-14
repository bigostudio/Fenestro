<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendRegistrationAlertJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly User $user) {}

    public function handle(WhatsAppService $whatsApp): void
    {
        $approvalLink = config('app.url') . '/admin/registrations/' . $this->user->id;

        $whatsApp->sendTemplate(
            to: config('services.whatsapp.ops_manager_phone'),
            templateName: 'fenestro_new_registration',
            components: [
                [
                    'type'       => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => $approvalLink],
                    ],
                ],
            ]
        );
    }
}
