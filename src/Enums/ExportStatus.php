<?php

declare(strict_types=1);

namespace Alif\Export\Enums;

enum ExportStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    /** True while the row can still move on its own (a worker may be about to claim or finish it). */
    public function isInFlight(): bool
    {
        return match ($this) {
            self::PENDING, self::PROCESSING => true,
            self::COMPLETED, self::FAILED => false,
        };
    }

    /** @return list<string> */
    public static function inFlightValues(): array
    {
        return array_values(array_map(
            fn (self $status): string => $status->value,
            array_filter(self::cases(), fn (self $status): bool => $status->isInFlight()),
        ));
    }
}
