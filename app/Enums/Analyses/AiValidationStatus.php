<?php

namespace App\Enums\Analyses;

enum AiValidationStatus: string
{
    case Pending = 'pending';
    case Valid = 'valid';
    case Invalid = 'invalid';
}
