<?php

namespace App\Services\WhatsApp;

use App\Exceptions\WhatsAppGatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class WhatsAppGatewayClient
{
    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sendTemplate(array $payload): array
    {
        return $this->request('POST', '/api/internal/v1/messages/templates', $payload);
    }

    /** @return array<string, mixed>|null */
    public function message(string $clientRequestId): ?array
    {
        try {
            $response = $this->request('GET', '/api/internal/v1/messages/'.rawurlencode($clientRequestId));
        } catch (WhatsAppGatewayException $exception) {
            if ($exception->httpStatus === 404) {
                return null;
            }

            throw $exception;
        }

        return is_array($response['message'] ?? null) ? $response['message'] : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function templates(): array
    {
        $response = $this->request('GET', '/api/internal/v1/templates');

        return array_values(array_filter((array) ($response['templates'] ?? []), 'is_array'));
    }

    /** @return array<int, array<string, mixed>> */
    public function accounts(): array
    {
        $response = $this->request('GET', '/api/internal/v1/accounts');

        return array_values(array_filter((array) ($response['accounts'] ?? []), 'is_array'));
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createTemplate(array $payload): array
    {
        $response = $this->request('POST', '/api/internal/v1/templates/drafts', $payload);

        return (array) ($response['template'] ?? []);
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateTemplate(int $id, array $payload): array
    {
        $response = $this->request('PATCH', '/api/internal/v1/templates/drafts/'.$id, $payload);

        return (array) ($response['template'] ?? []);
    }

    /** @return array<string, mixed> */
    public function submitTemplate(int $id): array
    {
        $response = $this->request('POST', '/api/internal/v1/templates/'.$id.'/submit', timeoutSeconds: 60);

        return (array) ($response['template'] ?? []);
    }

    /** @return array<int, array<string, mixed>> */
    public function syncTemplates(): array
    {
        $response = $this->request('POST', '/api/internal/v1/templates/sync', timeoutSeconds: 60);

        return array_values(array_filter((array) ($response['templates'] ?? []), 'is_array'));
    }

    /** @return array<string, mixed> */
    public function suppress(string $phoneHash, string $lastFour, ?int $adminUserId = null): array
    {
        return $this->request('PUT', '/api/internal/v1/suppressions/'.rawurlencode($phoneHash), [
            'phone_last_four' => $lastFour,
            'source' => 'GOVNEXGAB',
            'reason' => 'Consentimento revogado no GOVNEX GAB.',
            'admin_user_id' => $adminUserId,
        ]);
    }

    /** @return array<string, mixed> */
    public function releaseSuppression(string $phoneHash, ?int $adminUserId = null): array
    {
        return $this->request('DELETE', '/api/internal/v1/suppressions/'.rawurlencode($phoneHash), [
            'admin_user_id' => $adminUserId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        array $payload = [],
        int $timeoutSeconds = 20,
    ): array {
        $baseUrl = trim((string) config('whatsapp.gateway_url'));
        $clientCode = trim((string) config('whatsapp.client_code'));
        $secret = trim((string) config('whatsapp.request_secret'));
        if (! $this->validBaseUrl($baseUrl) || ! preg_match('/^[A-Z0-9_]{2,60}$/', $clientCode) || strlen($secret) < 32) {
            throw new RuntimeException('A integração com o Gateway WhatsApp não está configurada.');
        }

        $method = strtoupper($method);
        $body = $payload === []
            ? ''
            : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = now()->timestamp;
        $nonce = Str::random(40);
        $canonical = $method."\n".$path."\n".$timestamp."\n".$nonce."\n".hash('sha256', $body);
        $signature = 'sha256='.hash_hmac('sha256', $canonical, $secret);
        $request = $this->pending($timeoutSeconds)->withHeaders([
            'X-WhatsApp-Client' => $clientCode,
            'X-WhatsApp-Timestamp' => (string) $timestamp,
            'X-WhatsApp-Nonce' => $nonce,
            'X-WhatsApp-Signature' => $signature,
        ]);
        if ($body !== '') {
            $request = $request->withBody($body, 'application/json');
        }

        try {
            $response = $request->send($method, $baseUrl.$path);
        } catch (ConnectionException $exception) {
            throw new WhatsAppGatewayException(
                'Não foi possível confirmar a resposta do Gateway WhatsApp.',
                retryable: true,
                ambiguous: $method !== 'GET',
            );
        } catch (Throwable $exception) {
            throw new WhatsAppGatewayException('Falha temporária na comunicação com o Gateway WhatsApp.', retryable: true);
        }

        $data = $response->json();
        $data = is_array($data) ? $data : [];
        if ($response->successful()) {
            return $data;
        }

        $status = $response->status();
        $message = trim((string) ($data['message'] ?? ''));
        $safeMessage = $message !== '' && $status < 500
            ? $message
            : 'O Gateway WhatsApp recusou temporariamente a operação.';

        throw new WhatsAppGatewayException(
            $safeMessage,
            $status,
            (bool) ($data['retryable'] ?? ($status === 408 || $status === 429 || $status >= 500)),
            $method !== 'GET' && (bool) ($data['ambiguous'] ?? ($status === 408)),
        );
    }

    private function pending(int $timeoutSeconds): PendingRequest
    {
        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(max(5, min($timeoutSeconds, 90)))
            ->withoutRedirecting();
    }

    private function validBaseUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
            || parse_url($url, PHP_URL_HOST) === null
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null
            || parse_url($url, PHP_URL_QUERY) !== null
            || parse_url($url, PHP_URL_FRAGMENT) !== null) {
            return false;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $port = parse_url($url, PHP_URL_PORT);

        return ($path === '' || $path === '/') && ($port === null || (int) $port === 443);
    }
}
