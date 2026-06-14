<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    private string $token;
    private string $phoneNumberId;
    private string $apiVersion;
    private string $baseUrl;

    public function __construct()
    {
        $this->token         = config('services.whatsapp.token');
        $this->phoneNumberId = config('services.whatsapp.phone_number_id');
        $this->apiVersion    = config('services.whatsapp.api_version', 'v21.0');
        $this->baseUrl       = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";
    }

    public function sendTemplate(string $to, string $templateName, array $components = []): bool
    {
        $to = $this->formatPhone($to);

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'template',
            'template'          => [
                'name'       => $templateName,
                'language'   => ['code' => 'en'],
                'components' => $components,
            ],
        ];

        return $this->send($payload, $templateName);
    }

    public function sendText(string $to, string $body): bool
    {
        $to = $this->formatPhone($to);

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'text',
            'text'              => ['body' => $body],
        ];

        return $this->send($payload, 'text');
    }

    private function send(array $payload, string $templateName): bool
    {
        try {
            $response = Http::withToken($this->token)
                ->post($this->baseUrl, $payload);

            Log::channel('stack')->info('WhatsApp send', [
                'template' => $templateName,
                'to'       => $payload['to'],
                'status'   => $response->status(),
                'response' => $response->json(),
            ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('WhatsApp send failed', [
                'template' => $templateName,
                'to'       => $payload['to'],
                'error'    => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function formatPhone(string $phone): string
    {
        // Strip any non-digit characters
        $phone = preg_replace('/\D/', '', $phone);

        // Add India country code if 10-digit number
        if (strlen($phone) === 10) {
            $phone = '91' . $phone;
        }

        return $phone;
    }
}
