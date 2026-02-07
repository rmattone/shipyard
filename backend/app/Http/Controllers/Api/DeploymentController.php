<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Deployment;
use Illuminate\Http\JsonResponse;

class DeploymentController extends Controller
{
    public function index(Application $application): JsonResponse
    {
        $deployments = $application->deployments()
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($deployments);
    }

    public function show(Deployment $deployment): JsonResponse
    {
        $deployment->load('application.server');

        return response()->json($deployment);
    }
}
