<?php

namespace App\Modules\HospifyLink\Http;

use App\Support\HospifyToken;
use Closure;
use Illuminate\Http\Request;

/** Server-to-server calls from Hospify's API, signed with Hospify's key for one scope. */
class HospifyService
{
    public function handle(Request $request, Closure $next, string $scope)
    {
        HospifyToken::verify($request->bearerToken(), $scope);

        return $next($request);
    }
}
