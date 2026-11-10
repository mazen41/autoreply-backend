<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActiveBusiness;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductTourController extends Controller
{
    public function show(Request $request, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'read');
        $state = DB::table('product_tour_states')
            ->where('user_id', $request->user()->id)
            ->where('business_id', $business->id)
            ->first();

        return response()->json([
            'status' => $state->status ?? 'not_started',
            'current_step' => (int) ($state->current_step ?? 0),
            'updated_at' => $state?->updated_at,
        ]);
    }

    public function update(Request $request, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'read');
        $data = $request->validate([
            'status' => ['required', 'string', 'in:in_progress,completed,skipped'],
            'current_step' => ['required', 'integer', 'min:0', 'max:30'],
        ]);

        $now = now();
        $existing = DB::table('product_tour_states')
            ->where('user_id', $request->user()->id)
            ->where('business_id', $business->id)
            ->value('created_at');
        DB::table('product_tour_states')->updateOrInsert(
            [
                'user_id' => $request->user()->id,
                'business_id' => $business->id,
            ],
            [
                'status' => $data['status'],
                'current_step' => $data['current_step'],
                'updated_at' => $now,
                'created_at' => $existing ?? $now,
            ],
        );

        return response()->json([
            'status' => $data['status'],
            'current_step' => $data['current_step'],
            'updated_at' => $now->toISOString(),
        ]);
    }
}
