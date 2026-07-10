<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Package;
use Illuminate\Http\Request;

class PackageController extends Controller
{
    public function index()
    {
        $packages = Package::active()->ordered()->get();
        return response()->json($packages);
    }

    public function show($id)
    {
        $package = Package::findOrFail($id);
        return response()->json($package);
    }
}
