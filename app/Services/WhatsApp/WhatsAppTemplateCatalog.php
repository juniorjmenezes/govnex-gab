<?php

namespace App\Services\WhatsApp;

use App\Enums\WhatsAppPurpose;
use InvalidArgumentException;

final class WhatsAppTemplateCatalog
{
    /** @return array<string, mixed> */
    public function definition(WhatsAppPurpose $purpose, ?string $body = null): array
    {
        $definition = $this->definitions()[$purpose->value];
        $body ??= $definition['body'];
        $this->assertBodyContract($purpose, $body);
        $components = [
            [
                'type' => 'BODY',
                'text' => $body,
                'example' => ['body_text' => [$definition['examples']]],
            ],
            [
                'type' => 'FOOTER',
                'text' => 'GOVNEX GAB · F3 Sistemas',
            ],
        ];

        return [
            'purpose' => $purpose->value,
            'meta_name' => $purpose->metaName(),
            'language' => 'pt_BR',
            'components' => $components,
            'contract_hash' => $this->hash($components),
            'parameter_count' => count($definition['examples']),
            'body' => $body,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return array_map(fn (WhatsAppPurpose $purpose): array => $this->definition($purpose), WhatsAppPurpose::cases());
    }

    public function assertBodyContract(WhatsAppPurpose $purpose, string $body): void
    {
        $expected = count($this->definitions()[$purpose->value]['examples']);
        preg_match_all('/\{\{(\d+)\}\}/', $body, $matches);
        $numbers = array_values(array_unique(array_map('intval', $matches[1])));
        sort($numbers);
        if ($numbers !== ($expected === 0 ? [] : range(1, $expected))) {
            throw new InvalidArgumentException('O texto deve preservar exatamente os marcadores {{1}} até {{'.$expected.'}}.');
        }
        if (mb_strlen(trim($body)) < 20 || mb_strlen($body) > 1024) {
            throw new InvalidArgumentException('O texto do template deve possuir entre 20 e 1024 caracteres.');
        }
        if (preg_match('/^\s*\{\{\d+\}\}/u', $body)
            || preg_match('/\{\{\d+\}\}[\s\p{P}]*$/u', $body)) {
            throw new InvalidArgumentException(
                'As variáveis do template não podem aparecer no início ou no final efetivo do texto.',
            );
        }
    }

    /** @param array<int, array<string, mixed>> $components */
    public function hash(array $components): string
    {
        return hash('sha256', json_encode(
            array_values($components),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /** @return array<string, array{body:string,examples:list<string>}> */
    private function definitions(): array
    {
        return [
            WhatsAppPurpose::DemandAssigned->value => [
                'body' => 'Olá, {{1}}. A demanda {{2}} foi atribuída a você. Prazo: {{3}}. Acesse o GOVNEX GAB para consultar os detalhes.',
                'examples' => ['Marina', 'GAB-2026-0012', '05/08/2026'],
            ],
            WhatsAppPurpose::DemandStatusChanged->value => [
                'body' => 'Olá, {{1}}. A demanda {{2}} teve o status atualizado para {{3}}. Consulte o GOVNEX GAB ou o gabinete responsável para mais informações.',
                'examples' => ['Marina', 'GAB-2026-0012', 'Em andamento'],
            ],
            WhatsAppPurpose::DemandObservationAdded->value => [
                'body' => 'Olá, {{1}}. {{3}} registrou uma nova observação na demanda {{2}}. O conteúdo está disponível somente no GOVNEX GAB.',
                'examples' => ['Marina', 'GAB-2026-0012', 'Carlos'],
            ],
            WhatsAppPurpose::DemandDeadlineDigest->value => [
                'body' => 'Olá, {{1}}. Resumo de prazos do GOVNEX GAB: {{2}} demanda(s) atrasada(s), {{3}} próxima(s) do prazo e {{4}} próxima(s) ação(ões) pendente(s).',
                'examples' => ['Marina', '2', '3', '1'],
            ],
            WhatsAppPurpose::AppointmentStaffReminder->value => [
                'body' => 'Olá, {{1}}. Você possui um compromisso em {{2}}, às {{3}}. Local: {{4}}. Consulte a agenda do GOVNEX GAB para os detalhes.',
                'examples' => ['Marina', '05/08/2026', '14:30', 'Gabinete'],
            ],
            WhatsAppPurpose::AppointmentCitizenReminder->value => [
                'body' => 'Olá, {{1}}. Este é um lembrete do seu compromisso com o gabinete em {{2}}, às {{3}}. Local: {{4}}. Em caso de dúvida, entre em contato com o gabinete.',
                'examples' => ['João', '05/08/2026', '14:30', 'Gabinete'],
            ],
            WhatsAppPurpose::AppointmentChanged->value => [
                'body' => 'Olá, {{1}}. Seu compromisso com o gabinete foi atualizado para {{2}}, às {{3}}. Confirme os detalhes pelos canais oficiais do gabinete.',
                'examples' => ['João', '06/08/2026', '15:00'],
            ],
            WhatsAppPurpose::AppointmentCancelled->value => [
                'body' => 'Olá, {{1}}. O compromisso previsto para {{2}}, às {{3}}, foi cancelado. Entre em contato com o gabinete se precisar de atendimento.',
                'examples' => ['João', '06/08/2026', '15:00'],
            ],
            WhatsAppPurpose::PoliticalPollPublished->value => [
                'body' => 'Olá, {{1}}. Uma nova pesquisa eleitoral foi publicada: {{2}}. Abrangência: {{3}}. Consulte o painel político do GOVNEX GAB.',
                'examples' => ['Marina', 'Pesquisa municipal de agosto', 'Fortaleza/CE'],
            ],
        ];
    }
}
