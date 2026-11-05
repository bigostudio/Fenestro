<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\PartnerCommonCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Settings → Partner Access → "Common partner access code" (administrators only).
 *
 *  GET    status — never the code, its hash or salt
 *  POST   generate a new random code ({"generate": true}) or set one ({"code": "######"});
 *         replaces the active code (ending its sessions). A GENERATED code is returned in this one
 *         response only, for the administrator to copy and share privately; a code the admin typed
 *         is not echoed back. Responses are marked no-store.
 *  DELETE revoke the active code (ends its sessions; partners fall back to the WhatsApp OTP)
 */
class PartnerCommonCodeController extends Controller
{
    public function show(): JsonResponse
    {
        return $this->noStore(response()->json(PartnerCommonCode::status()));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'generate' => 'sometimes|boolean',
            'code'     => 'required_without:generate|nullable|string',
        ]);
        $generate = (bool) ($data['generate'] ?? false);
        $by = (string) (auth('api')->user()?->name ?? 'admin');

        try {
            $result = PartnerCommonCode::set($generate ? null : (string) ($data['code'] ?? ''), $by);
        } catch (InvalidArgumentException $e) {
            return $this->noStore(response()->json(['message' => $e->getMessage(), 'errors' => ['code' => [$e->getMessage()]]], 422));
        }

        // Audit without the code.
        Log::info('Partner common access code '.($generate ? 'generated' : 'set'), ['by_user' => auth('api')->id(), 'code_id' => $result['id']]);

        return $this->noStore(response()->json([
            'message' => 'Common partner access code '.($generate ? 'generated' : 'set').'. Any previous code no longer works and partners signed in with it must sign in again.',
            // Shown once: copy it now. Not stored anywhere in clear, and never returned again.
            'code'    => $generate ? $result['code'] : null,
            'status'  => PartnerCommonCode::status(),
        ], 201));
    }

    public function destroy(): JsonResponse
    {
        $n = PartnerCommonCode::revokeActive('revoked_by_admin', (string) (auth('api')->user()?->name ?? 'admin'));
        Log::info('Partner common access code revoked', ['by_user' => auth('api')->id(), 'revoked' => $n]);

        return $this->noStore(response()->json([
            'message' => $n ? 'Common partner access code revoked. Partners signed in with it must sign in again; partners now receive the normal WhatsApp login code.' : 'There is no active common partner access code.',
            'revoked' => $n > 0,
            'status'  => PartnerCommonCode::status(),
        ]));
    }

    private function noStore(JsonResponse $r): JsonResponse
    {
        return $r->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }
}
