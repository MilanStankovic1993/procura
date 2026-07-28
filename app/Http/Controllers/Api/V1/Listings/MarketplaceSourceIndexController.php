<?php

namespace App\Http\Controllers\Api\V1\Listings;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\MarketplaceSourceResource;
use App\Models\MarketplaceSource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MarketplaceSourceIndexController extends Controller
{
    public function __invoke(): AnonymousResourceCollection
    {
        return MarketplaceSourceResource::collection(
            MarketplaceSource::query()
                ->where('active', true)
                ->orderBy('name')
                ->get(),
        );
    }
}
