<?php

namespace App\Services\Hub;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Valida a assinatura HMAC-SHA256 dos webhooks do Govnex Hub.
 *
 * Mesmo esquema que o GAB já valida nos callbacks do WhatsApp
 * (`WhatsAppCallbackSignatureValidator`), e de propósito: o Hub escolheu repetir
 * um esquema já revisado dos dois lados em vez de inventar um melhor.
 *
 * O canônico é `MÉTODO\nCAMINHO?QUERY\nTIMESTAMP\nNONCE\nsha256(corpo)`. O
 * caminho e o corpo entram para a assinatura não poder ser reaproveitada em
 * outra rota nem com outro conteúdo; o timestamp e o nonce, para não poder ser
 * reproduzida depois.
 *
 * Uma diferença em relação ao WhatsApp: o Hub não manda cabeçalho de
 * identificação do sistema (ver `AssinadorDeWebhook` lá), e validar um
 * cabeçalho que o emissor não envia só recusaria tudo. Quem identifica o
 * emissor é o próprio segredo — ele é exclusivo do Sistema GAB no Hub.
 */
class HubCallbackSignatureValidator
{
    public function validate(Request $request): void
    {
        $secret = trim((string) config('services.hub.api_secret'));

        if (strlen($secret) < 32) {
            throw new RuntimeException('Segredo de webhook do Hub ausente ou curto demais.');
        }

        $timestamp = (int) $request->header('X-Hub-Timestamp');
        $nonce = trim((string) $request->header('X-Hub-Nonce'));
        $signature = trim((string) $request->header('X-Hub-Signature'));

        $janela = (int) config('services.hub.webhook_window_seconds', 300);

        if ($timestamp <= 0 || abs(time() - $timestamp) > $janela) {
            throw new RuntimeException('Webhook expirado.');
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
        $expected = 'sha256='.hash_hmac('sha256', $canonical, $secret);

        if ($signature === '' || ! hash_equals($expected, $signature)) {
            throw new RuntimeException('Assinatura inválida.');
        }

        // Nonce queimado: bloqueia reprodução mesmo dentro da janela de
        // tolerância. É defesa contra repetição maliciosa, não idempotência —
        // a reentrega legítima do próprio Hub repete o `id` do evento com
        // nonce novo, e quem a descarta é o controller.
        $nonceKey = 'hub:webhook:nonce:'.hash('sha256', $nonce);

        if (! Cache::add($nonceKey, hash('sha256', $body), now()->addMinutes(10))) {
            throw new RuntimeException('Nonce já utilizado.');
        }
    }
}
