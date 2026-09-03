<?php

namespace App\Enums\Privacy;

enum PrivacyRequestActorType: string
{
    case Subject = 'subject';
    case Operator = 'operator';
    case System = 'system';
}
