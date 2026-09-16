<?php

namespace App\Enums;

enum DemandStatus: string
{
    case New = 'nova';
    case InProgress = 'em_andamento';
    case Awaiting = 'aguardando';
    case Resolved = 'resolvida';
    case Closed = 'encerrada';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Nova',
            self::InProgress => 'Em andamento',
            self::Awaiting => 'Aguardando',
            self::Resolved => 'Resolvida',
            self::Closed => 'Encerrada',
        };
    }

    /**
     * A máquina de status é deliberadamente permissiva: o status representa
     * só "onde a demanda está", não um fluxo rígido. Praticamente qualquer
     * transição direta é válida, com duas exceções: sair de
     * Resolvida/Encerrada é sempre uma "reabertura" (ver ReopenDemand), não
     * uma transição simples; e Nova é só o estado inicial de quem acabou de
     * chegar — uma demanda já em andamento não volta a ser nova.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::InProgress, self::Awaiting, self::Resolved, self::Closed],
            self::InProgress => [self::Awaiting, self::Resolved, self::Closed],
            self::Awaiting => [self::InProgress, self::Resolved, self::Closed],
            self::Resolved => [self::InProgress, self::Closed],
            self::Closed => [self::InProgress],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return $status === $this || in_array($status, $this->allowedTransitions(), true);
    }

    public function isCompleted(): bool
    {
        return in_array($this, [self::Resolved, self::Closed], true);
    }

    public function isOpen(): bool
    {
        return ! $this->isCompleted();
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::New->value, self::InProgress->value, self::Awaiting->value];
    }

    /** @return list<string> */
    public static function completedValues(): array
    {
        return [self::Resolved->value, self::Closed->value];
    }
}
