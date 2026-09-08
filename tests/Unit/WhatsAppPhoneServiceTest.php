<?php

namespace Tests\Unit;

use App\Services\WhatsApp\WhatsAppPhoneService;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class WhatsAppPhoneServiceTest extends TestCase
{
    public function test_it_prefixes_local_numbers_with_the_country_code(): void
    {
        $service = new WhatsAppPhoneService;

        $this->assertSame('5585999998888', $service->normalize('(85) 99999-8888'));
        $this->assertSame('5585988887777', $service->normalize('85 98888-7777'));
    }

    public function test_it_keeps_a_number_that_already_carries_a_country_code(): void
    {
        // Só números de 10 ou 11 dígitos são tratados como locais (DDD +
        // número) e recebem o prefixo do Brasil; qualquer outro tamanho
        // válido é mantido como veio (já inclui código de país).
        $service = new WhatsAppPhoneService;

        $this->assertSame('351912345678', $service->normalize('+351 912 345 678'));
    }

    public function test_it_rejects_a_number_that_is_too_short(): void
    {
        $service = new WhatsAppPhoneService;

        $this->expectException(InvalidArgumentException::class);

        $service->normalize('123');
    }

    public function test_it_rejects_null_and_empty_input(): void
    {
        $service = new WhatsAppPhoneService;

        $this->expectException(InvalidArgumentException::class);

        $service->normalize(null);
    }

    public function test_it_hashes_deterministically_with_a_configured_secret(): void
    {
        config(['whatsapp.phone_hash_secret' => str_repeat('s', 32)]);
        $service = new WhatsAppPhoneService;

        $first = $service->hash('5585999998888');
        $second = $service->hash('5585999998888');
        $different = $service->hash('5585988887777');

        $this->assertSame($first, $second);
        $this->assertNotSame($first, $different);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
    }

    public function test_it_refuses_to_hash_without_a_configured_secret(): void
    {
        config(['whatsapp.phone_hash_secret' => null]);
        $service = new WhatsAppPhoneService;

        $this->expectException(RuntimeException::class);

        $service->hash('5585999998888');
    }
}
