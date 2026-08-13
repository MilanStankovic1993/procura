<?php

namespace App\Enums\Catalog;

enum CatalogImportRowStatus: string
{
    case Imported = 'imported';
    case Unchanged = 'unchanged';
    case Rejected = 'rejected';
}
