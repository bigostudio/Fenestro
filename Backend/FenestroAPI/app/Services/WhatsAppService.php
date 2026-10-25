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
    private string $apiBase;

    public function __construct()
    {
        // (string): the service can be created without credentials (isConfigured() = false).
        $this->token         = (string) config('services.whatsapp.token');
        $this->phoneNumberId = (string) config('services.whatsapp.phone_number_id');
        $this->apiVersion    = (string) config('services.whatsapp.api_version', 'v21.0');
        $this->apiBase       = rtrim((string) config('services.whatsapp.api_base', 'https://graph.facebook.com'), '/');
        $this->baseUrl       = "{$this->apiBase}/{$this->apiVersion}/{$this->phoneNumberId}/messages";
    }

    /** True when the Cloud API credentials are set (WHATSAPP_TOKEN + WHATSAPP_PHONE_NUMBER_ID). */
    public function isConfigured(): bool
    {
        return $this->token !== '' && $this->phoneNumberId !== '';
    }

    /** Sending is allowed only when switched on (WHATSAPP_SENDING_ENABLED) and configured. */
    public function isEnabled(): bool
    {
        return (bool) config('services.whatsapp.enabled') && $this->isConfigured();
    }

    /**
     * Send a PDF document to one recipient: upload it as media, then send it
     * either as a document message with a caption (only allowed inside the
     * 24-hour customer-service window) or, when $templateName is given, as an
     * approved template whose DOCUMENT header carries the PDF.
     *
     * Returns ['accepted' => bool, 'message_id' => ?string, 'error' => ?string].
     * "Accepted" means the Cloud API accepted the message for delivery; it is
     * not a delivery or read receipt.
     */
    public function sendDocument(string $to, string $pdf, string $filename, string $caption, ?string $templateName = null, array $bodyParams = [], string $language = 'en'): array
    {
        if (! $this->isEnabled()) {
            return ['accepted' => false, 'message_id' => null, 'error' => 'WhatsApp sending is switched off on this server (WHATSAPP_SENDING_ENABLED).'];
        }
        $to = $this->formatPhone($to);
        $base = "{$this->apiBase}/{$this->apiVersion}/{$this->phoneNumberId}";

        try {
            $upload = Http::withToken($this->token)
                ->attach('file', $pdf, $filename, ['Content-Type' => 'application/pdf'])
                ->post("$base/media", ['messaging_product' => 'whatsapp', 'type' => 'application/pdf']);
            $mediaId = $upload->json('id');
            if (! $upload->successful() || ! $mediaId) {
                return $this->failure('PDF upload', $to, $upload);
            }

            $payload = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to];
            if ($templateName) {
                $payload += ['type' => 'template', 'template' => [
                    'name'       => $templateName,
                    'language'   => ['code' => $language],
                    'components' => [
                        ['type' => 'header', 'parameters' => [['type' => 'document', 'document' => ['id' => $mediaId, 'filename' => $filename]]]],
                        ['type' => 'body', 'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], $bodyParams)],
                    ],
                ]];
            } else {
                $payload += ['type' => 'document', 'document' => ['id' => $mediaId, 'filename' => $filename, 'caption' => $caption]];
            }

            $sent = Http::withToken($this->token)->post("$base/messages", $payload);
            $messageId = $sent->json('messages.0.id');
            if (! $sent->successful() || ! $messageId) {
                return $this->failure('document message', $to, $sent);
            }

            Log::channel('stack')->info('WhatsApp document accepted', ['to' => $to, 'filename' => $filename, 'message_id' => $messageId, 'template' => $templateName]);

            return ['accepted' => true, 'message_id' => $messageId, 'error' => null];
        } catch (\Throwable $e) {
            Log::error('WhatsApp document send failed', ['to' => $to, 'filename' => $filename, 'error' => $e->getMessage()]);

            return ['accepted' => false, 'message_id' => null, 'error' => 'Could not reach the WhatsApp Cloud API: '.$e->getMessage()];
        }
    }

    private function failure(string $step, string $to, \Illuminate\Http\Client\Response $response): array
    {
        $error = $response->json('error.message') ?? ('HTTP '.$response->status());
        $code = $response->json('error.code');
        Log::warning('WhatsApp '.$step.' rejected', ['to' => $to, 'status' => $response->status(), 'error' => $error, 'code' => $code]);

        return ['accepted' => false, 'message_id' => null, 'error' => "WhatsApp rejected the $step: $error".($code ? " (code $code)" : '')];
    }

    /** Which required settings are missing (names of the server environment variables). */
    public function missingSettings(): array
    {
        return array_values(array_filter([
            $this->token === '' ? 'WHATSAPP_TOKEN' : null,
            $this->phoneNumberId === '' ? 'WHATSAPP_PHONE_NUMBER_ID' : null,
        ]));
    }

    /**
     * Ask the Cloud API whether the token and phone number ID work (read-only,
     * sends nothing). Optionally confirms the statement template is approved.
     * Returns ['ok' => bool, 'phone' => [...]|null, 'template' => [...]|null, 'error' => ?string].
     */
    public function checkConnection(?string $templateName = null, ?string $language = null): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'phone' => null, 'template' => null, 'error' => 'Missing settings: '.implode(', ', $this->missingSettings()).'.'];
        }
        try {
            $r = Http::withToken($this->token)->timeout(15)->get("{$this->apiBase}/{$this->apiVersion}/{$this->phoneNumberId}", [
                'fields' => 'display_phone_number,verified_name,quality_rating,code_verification_status,name_status',
            ]);
            if (! $r->successful()) {
                return ['ok' => false, 'phone' => null, 'template' => null, 'error' => 'WhatsApp rejected the credentials: '.($r->json('error.message') ?? 'HTTP '.$r->status()).($r->json('error.code') ? ' (code '.$r->json('error.code').')' : '')];
            }
            $phone = $r->json();
            $template = null;
            $waba = (string) config('services.whatsapp.business_account_id');
            if ($templateName && $waba !== '') {
                $t = Http::withToken($this->token)->timeout(15)->get("{$this->apiBase}/{$this->apiVersion}/{$waba}/message_templates", ['name' => $templateName, 'fields' => 'name,status,language,category']);
                $match = collect($t->json('data') ?? [])->first(fn ($x) => ($x['name'] ?? null) === $templateName && (! $language || ($x['language'] ?? null) === $language));
                $template = $t->successful()
                    ? ($match ? ['name' => $match['name'], 'status' => $match['status'] ?? null, 'language' => $match['language'] ?? null, 'category' => $match['category'] ?? null] : ['name' => $templateName, 'status' => 'NOT FOUND', 'language' => $language])
                    : ['name' => $templateName, 'status' => 'UNKNOWN', 'error' => $t->json('error.message')];
            }

            return ['ok' => true, 'phone' => $phone, 'template' => $template, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'phone' => null, 'template' => null, 'error' => 'Could not reach the WhatsApp Cloud API: '.$e->getMessage()];
        }
    }

    /**
     * @param bool $test true only for the "whatsapp:check --to=" test message to
     *                   your own number, which works before sending is switched on.
     */
    public function sendTemplate(string $to, string $templateName, array $components = [], string $language = 'en', bool $test = false): bool
    {
        if (! $test && ! $this->isEnabled()) {
            Log::info('WhatsApp message not sent: sending is switched off or not configured', ['template' => $templateName]);

            return false;
        }
        if ($test && ! $this->isConfigured()) {
            return false;
        }
        $to = $this->formatPhone($to);

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'template',
            'template'          => [
                'name'       => $templateName,
                'language'   => ['code' => $language],
                'components' => $components,
            ],
        ];

        return $this->send($payload, $templateName);
    }

    public function sendText(string $to, string $body): bool
    {
        if (! $this->isEnabled()) {
            Log::info('WhatsApp message not sent: sending is switched off or not configured', ['template' => 'text']);

            return false;
        }
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
