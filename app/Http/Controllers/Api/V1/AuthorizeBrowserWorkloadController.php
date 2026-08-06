<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

final class AuthorizeBrowserWorkloadController extends Controller
{
    public function __invoke(): Response
    {
        return response()->noContent();
    }
}
