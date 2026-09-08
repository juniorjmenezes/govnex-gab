<?php

namespace App\Services\WhatsApp;

use InvalidArgumentException;
use RuntimeException;

final class WhatsAppPhoneService
{
    public function normalize(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if (in_array(strlen($digits), [10, 11], true)) {
            $digits = '55'.$digits;
        }
        if (! preg_match('/^[1-9][0-9]{9,14}$/', $digits)) {
            throw new InvalidArgumentException('Informe um número de WhatsApp válido com DDD.');
        }

        return $digits;
    }

    public function hash(string $normalizedPhone): string
    {
        $secret = trim((string) config('whatsapp.phone_hash_secret'));
        if (strlen($secret) < 32) {
            throw new RuntimeException('O segredo de pseudonimização do WhatsApp não está configurado.');
        }

        return hash_hmac('sha256', $normalizedPhone, $secret);
    }
}
