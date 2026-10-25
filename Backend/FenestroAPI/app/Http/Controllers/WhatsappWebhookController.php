<?php

namespace App\Http\Controllers;

use App\Models\OrderManagement\StatementSend;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp Cloud API webhook. Records the delivery status WhatsApp reports
 * for payment statements (sent → delivered → read, or failed) against the
 * message id saved when the statement was accepted (om_statement_sends).
 *
 * Security: the GET handshake needs WHATSAPP_VERIFY_TOKEN; every POST must
 * carry Meta's X-Hub-Signature-256 (HMAC-SHA256 of the raw body with
 * WHATSAPP_APP_SECRET). Without these settings the webhook refuses everything.
 */
class WhatsappWebhookController extends Controller
{
    private const RANK = ['sent' => 1, 'delivered' => 2, 'read' => 3];

    public function verify(Request $request): Response
    {
        $token = (string) config('services.whatsapp.verify_token');
        if ($token !== '' && $request->query('hub_mode') === 'subscribe' && hash_equals($token, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    public function receive(Request $request): JsonResponse
    {
        $secret = (string) config('services.whatsapp.app_secret');
        $signature = (string) $request->header('X-Hub-Signature-256');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        if ($secret === '' || ! hash_equals($expected, $signature)) {
            Log::warning('WhatsApp webhook rejected: missing or invalid signature');

            return response()->json(['ok' => false], 403);
        }

        $updated = 0;
        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                foreach ((array) ($change['value']['statuses'] ?? []) as $st) {
                    $updated += $this->record((string) ($st['id'] ?? ''), (string) ($st['status'] ?? ''), $st);
                }
            }
        }

        return response()->json(['ok' => true, 'updated' => $updated]);
    }

    private function record(string $messageId, string $status, array $st): int
    {
        if ($messageId === '' || ! in_array($status, ['sent', 'delivered', 'read', 'failed'], true)) {
            return 0;
        }
        $send = StatementSend::where('message_id', $messageId)->first();
        if (! $send) {
            return 0; // not a payment statement (other WhatsApp messages)
        }
        // Statuses can arrive out of order: never move back (read stays read); failed is final.
        $current = $send->delivery_status;
        if ($current === 'failed' || ($status !== 'failed' && (self::RANK[$current] ?? 0) >= self::RANK[$status])) {
            return 0;
        }
        $error = $status === 'failed'
            ? trim(($st['errors'][0]['title'] ?? $st['errors'][0]['message'] ?? 'Delivery failed').(isset($st['errors'][0]['code']) ? ' (code '.$st['errors'][0]['code'].')' : ''))
            : null;
        $send->update([
            'delivery_status'     => $status,
            'delivery_error'      => $error,
            'delivery_updated_at' => isset($st['timestamp']) && is_numeric($st['timestamp']) ? Carbon::createFromTimestamp((int) $st['timestamp']) : now(),
        ]);

        return 1;
    }
}
