<?php

namespace App\Monitoring\Data;

use App\Models\SavedSearch;
use App\Models\SavedSearchVersion;

final readonly class SavedSearchWriteResult
{
    public function __construct(
        public SavedSearch $savedSearch,
        public SavedSearchVersion $version,
        public bool $created,
    ) {}
}
