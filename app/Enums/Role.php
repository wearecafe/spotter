<?php

namespace App\Enums;

enum Role: string
{
    case Coach = 'coach';
    case Client = 'client';

    public function label(): string
    {
        return match ($this) {
            self::Coach => 'Coach',
            self::Client => 'Client',
        };
    }
}
