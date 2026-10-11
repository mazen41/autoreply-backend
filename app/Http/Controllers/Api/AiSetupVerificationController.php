<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActiveBusiness;
use App\Services\AiSetupVerificationService;
use Illuminate\Http\Request;

class AiSetupVerificationController extends Controller
{
    public function show(Request $request, ActiveBusiness $activeBusiness, AiSetupVerificationService $verification)
    {
        $business = $activeBusiness->resolve($request, permission: 'read');

        return response()->json($verification->checklist($business));
    }
}
