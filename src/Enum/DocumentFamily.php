<?php

declare(strict_types=1);

namespace App\Enum;

enum DocumentFamily: string
{
    case Accounting = 'accounting';
    case Legal = 'legal';
    case Payroll = 'payroll';
    case Childcare = 'childcare';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Accounting => 'Comptabilité et fiscalité',
            self::Legal => 'Société et pièces juridiques',
            self::Payroll => 'Paie et droit social',
            self::Childcare => 'Micro-crèche',
            self::Other => 'Autres documents',
        };
    }
}
