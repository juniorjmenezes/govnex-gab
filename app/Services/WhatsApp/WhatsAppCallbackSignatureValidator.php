<?php

namespace App\Services\WhatsApp;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

final class WhatsAppCallbackSignatureValidator
{
    public function validate(Request $request): void
    {
        $client = strtoupper(trim((string) $request->header('X-WhatsApp-Client')));
        $expectedClient = strtoupper(trim((string) config('whatsapp.client_code')));
        $timestamp = (int) $request->header('X-WhatsApp-Timestamp');
        $nonce = trim((string) $request->header('X-WhatsApp-Nonce'));
        $signature = trim((string) $request->header('X-WhatsApp-Signature'));
        $secret = trim((string) config('whatsapp.callback_secret'));
        if ($client === '' || ! hash_equals($expectedClient, $client) || strlen($secret) < 32) {
            throw new RuntimeException('Cliente ou segredo de callback inválido.');
        }
        if ($timestamp <= 0 || abs(time() - $timestamp) > (int) config('whatsapp.callback_window_seconds', 300)) {
            throw new RuntimeException('Callback expirado.');
        }
        if (! preg_match('/^[A-Za-z0-9_-]{16,160}$/', $nonce)) {
            throw new RuntimeException('Nonce inválido.');
        }
        $body = $request->getContent();
        $path = '/'.ltrim($request->getPathInfo(), '/');
        if ($request->getQueryString()) {
            $path .= '?'.$request->getQueryString();
        }
        $canonical = strtoupper($request->method())."\n".$path."\n".$timestamp."\n".$nonce."\n".hash('sha256', $body);
        $secrets = [$secret];
        $previous = trim((string) config('whatsapp.callback_secret_previous'));
        $previousValidUntil = trim((string) config('whatsapp.callback_previous_valid_until'));
        try {
            if (strlen($previous) >= 32
                && $previousValidUntil !== ''
                && CarbonImmutable::parse($previousValidUntil)->isFuture()) {
                $secrets[] = $previous;
            }
        } catch (Throwable) {
            // An invalid compatibility window never broadens callback access.
        }
        $matches = false;
        foreach ($secrets as $candidate) {
            $expected = 'sha256='.hash_hmac('sha256', $canonical, $candidate);
            $matches = hash_equals($expected, $signature) || $matches;
        }
        if ($signature === '' || ! $matches) {
            throw new RuntimeException('Assinatura inválida.');
        }
        $nonceKey = 'whatsapp:callback:nonce:'.hash('sha256', $client.'|'.$nonce);
        if (! Cache::add($nonceKey, hash('sha256', $body), now()->addMinutes(10))) {
            throw new RuntimeException('Nonce já utilizado.');
        }
    }
}
