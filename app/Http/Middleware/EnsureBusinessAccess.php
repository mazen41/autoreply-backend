<?php

namespace App\Http\Middleware;

use App\Services\ActiveBusiness;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBusinessAccess
{
    public function __construct(private readonly ActiveBusiness $activeBusiness)
    {
    }

    public function handle(Request $request, Closure $next, string $permission = 'read'): Response
    {
        $business = $this->activeBusiness->resolve(
            $request,
            permission: $permission,
            routeBusinessId: $request->route('businessId') ?? $request->route('business_id'),
        );
        $request->attributes->set('authorized_business', $business);

        return $next($request);
    }
}
