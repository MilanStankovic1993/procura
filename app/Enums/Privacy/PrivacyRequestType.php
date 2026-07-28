<?php

namespace App\Enums\Privacy;

enum PrivacyRequestType: string
{
    case DataExport = 'data_export';
    case AccountDeletion = 'account_deletion';
}
