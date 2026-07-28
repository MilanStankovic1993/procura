<?php

namespace App\Analysis\Queries;

use App\Models\Analysis;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;

final class AnalysisIndexQuery
{
    /**
     * @param  array{listing_id?: string, status?: mixed}  $filters
     * @return Builder<Analysis>
     */
    public function build(
        Organization $organization,
        array $filters = [],
    ): Builder {
        $query = Analysis::query()
            ->forOrganization($organization)
            ->with(
                'listing:id,title,marketplace_name,asking_price_minor,currency_code',
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        foreach (['listing_id', 'status'] as $filter) {
            if (isset($filters[$filter])) {
                $query->where($filter, $filters[$filter]);
            }
        }

        return $query;
    }
}
