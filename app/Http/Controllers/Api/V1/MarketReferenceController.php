<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Markets\BuildMarketReferenceCatalog;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MarketReferenceController extends Controller
{
    public function __invoke(
        Request $request,
        BuildMarketReferenceCatalog $catalog,
    ): JsonResponse|Response {
        $data = $catalog->build();
        $etag = '"'.hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)).'"';
        $headers = [
            'ETag' => $etag,
            'Cache-Control' => 'public, max-age=86400, stale-while-revalidate=604800',
        ];

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304, $headers);
        }

        return response()->json(['data' => $data])->withHeaders($headers);
    }
}
