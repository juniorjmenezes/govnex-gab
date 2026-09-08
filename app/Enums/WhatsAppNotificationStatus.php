<?php

namespace App\Enums;

enum WhatsAppNotificationStatus: string
{
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case Reconciling = 'RECONCILING';
    case Submitted = 'SUBMITTED';
    case Sent = 'SENT';
    case Delivered = 'DELIVERED';
    case Read = 'READ';
    case Failed = 'FAILED';
    case Expired = 'EXPIRED';
    case Cancelled = 'CANCELLED';
    case Suppressed = 'SUPPRESSED';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Read,
            self::Failed,
            self::Expired,
            self::Cancelled,
            self::Suppressed,
        ], true);
    }

    public function rank(): int
    {
        return match ($this) {
            self::Pending => 10,
            self::Processing, self::Reconciling => 20,
            self::Submitted => 30,
            self::Sent => 40,
            self::Delivered => 50,
            self::Read => 60,
            self::Failed, self::Expired, self::Cancelled, self::Suppressed => 100,
        };
    }
}
