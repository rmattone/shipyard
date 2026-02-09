<?php

use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DatabaseController;
use App\Http\Controllers\Api\DatabaseUserController;
use App\Http\Controllers\Api\DeploymentController;
use App\Http\Controllers\Api\DatabaseInstallationStreamController;
use App\Http\Controllers\Api\DeploymentStreamController;
use App\Http\Controllers\Api\DomainController;
use App\Http\Controllers\Api\EnvironmentVariableController;
use App\Http\Controllers\Api\GitProviderController;
use App\Http\Controllers\Api\LogController;
use App\Http\Controllers\Api\NginxController;
use App\Http\Controllers\Api\RollbackController;
use App\Http\Controllers\Api\ServerController;
use App\Http\Controllers\Api\SSHKeyController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/auth/login', [AuthController::class, 'login']);

// Webhook route (validated by secret)
Route::post('/webhook/{application}', [WebhookController::class, 'handle']);

// SSE streaming routes (auth handled via query param token)
Route::get('/deployments/{deployment}/stream', [DeploymentStreamController::class, 'stream']);
Route::get('/database-installations/{installation}/stream', [DatabaseInstallationStreamController::class, 'stream']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/user', [AuthController::class, 'user']);

    // SSH Keys
    Route::post('/ssh-keys/generate', [SSHKeyController::class, 'generate']);

    // Servers
    Route::apiResource('servers', ServerController::class);
    Route::post('/servers/{server}/test-connection', [ServerController::class, 'testConnection']);
    Route::get('/servers/{server}/node-versions', [ServerController::class, 'getNodeVersions']);
    Route::get('/servers/{server}/metrics', [ServerController::class, 'getMetrics']);
    Route::get('/servers/{server}/software', [ServerController::class, 'checkSoftware']);

    // Tags (server-scoped)
    Route::get('/servers/{server}/tags', [TagController::class, 'index']);
    Route::post('/servers/{server}/tags', [TagController::class, 'store']);
    Route::put('/servers/{server}/tags/{tag}', [TagController::class, 'update']);
    Route::delete('/servers/{server}/tags/{tag}', [TagController::class, 'destroy']);

    // Database connections
    Route::get('/servers/{server}/databases/detect', [DatabaseController::class, 'detect']);
    Route::post('/servers/{server}/databases/install', [DatabaseController::class, 'install']);
    Route::get('/servers/{server}/database-installations', [DatabaseController::class, 'installations']);
    Route::get('/database-installations/{installation}', [DatabaseController::class, 'installationStatus']);
    Route::apiResource('servers.databases', DatabaseController::class);
    Route::post('/servers/{server}/databases/{database}/test', [DatabaseController::class, 'testConnection']);

    // Remote database operations
    Route::get('/servers/{server}/databases/{database}/remote-databases', [DatabaseController::class, 'listRemoteDatabases']);
    Route::post('/servers/{server}/databases/{database}/remote-databases', [DatabaseController::class, 'createRemoteDatabase']);
    Route::delete('/servers/{server}/databases/{database}/remote-databases', [DatabaseController::class, 'dropRemoteDatabase']);

    // Database users
    Route::get('/servers/{server}/databases/{database}/users/remote', [DatabaseUserController::class, 'listRemoteUsers']);
    Route::apiResource('servers.databases.users', DatabaseUserController::class)->except(['update']);
    Route::post('/servers/{server}/databases/{database}/users/{user}/grant', [DatabaseUserController::class, 'grantPrivileges']);
    Route::post('/servers/{server}/databases/{database}/users/{user}/revoke', [DatabaseUserController::class, 'revokePrivileges']);

    // Git Providers
    Route::apiResource('git-providers', GitProviderController::class);
    Route::post('/git-providers/{git_provider}/test', [GitProviderController::class, 'testConnection']);
    Route::get('/git-providers/{git_provider}/repositories', [GitProviderController::class, 'repositories']);
    Route::get('/git-providers/{git_provider}/branches', [GitProviderController::class, 'branches']);

    // Applications
    Route::apiResource('applications', ApplicationController::class);
    Route::post('/applications/{application}/deploy', [ApplicationController::class, 'deploy']);
    Route::post('/applications/{application}/setup-ssl', [ApplicationController::class, 'setupSsl']);
    Route::get('/applications/{application}/deployments', [DeploymentController::class, 'index']);
    Route::get('/applications/{application}/deploy-script', [ApplicationController::class, 'getDeployScript']);
    Route::put('/applications/{application}/deploy-script', [ApplicationController::class, 'updateDeployScript']);
    Route::post('/applications/default-script', [ApplicationController::class, 'getDefaultScript']);
    Route::post('/applications/generate-path', [ApplicationController::class, 'generateDeployPath']);
    Route::put('/applications/{application}/tags', [ApplicationController::class, 'syncTags']);

    // Rollback (atomic deployments)
    Route::get('/applications/{application}/releases', [RollbackController::class, 'releases']);
    Route::post('/applications/{application}/rollback', [RollbackController::class, 'rollback']);
    Route::post('/applications/{application}/rollback/previous', [RollbackController::class, 'rollbackToPrevious']);

    // Environment variables
    Route::get('/applications/{application}/env', [EnvironmentVariableController::class, 'index']);
    Route::post('/applications/{application}/env', [EnvironmentVariableController::class, 'store']);
    Route::put('/applications/{application}/env/{environmentVariable}', [EnvironmentVariableController::class, 'update']);
    Route::delete('/applications/{application}/env/{environmentVariable}', [EnvironmentVariableController::class, 'destroy']);

    // Environment file (full .env content)
    Route::get('/applications/{application}/env-file', [EnvironmentVariableController::class, 'getEnvFile']);
    Route::put('/applications/{application}/env-file', [EnvironmentVariableController::class, 'updateEnvFile']);

    // Domains
    Route::get('/applications/{application}/domains', [DomainController::class, 'index']);
    Route::post('/applications/{application}/domains', [DomainController::class, 'store']);
    Route::delete('/applications/{application}/domains/{domain}', [DomainController::class, 'destroy']);
    Route::put('/applications/{application}/domains/{domain}/primary', [DomainController::class, 'setPrimary']);
    Route::post('/applications/{application}/domains/{domain}/ssl', [DomainController::class, 'requestSsl']);
    Route::get('/applications/{application}/domains/{domain}/ssl', [DomainController::class, 'getSslStatus']);

    // Nginx configuration
    Route::get('/applications/{application}/nginx', [NginxController::class, 'show']);
    Route::put('/applications/{application}/nginx', [NginxController::class, 'update']);

    // Logs
    Route::get('/applications/{application}/logs', [LogController::class, 'index']);
    Route::get('/applications/{application}/logs/{filename}', [LogController::class, 'show']);

    // Deployments
    Route::get('/deployments/{deployment}', [DeploymentController::class, 'show']);

    // System
    Route::get('/system/version', [SystemController::class, 'version']);
    Route::get('/system/environment', [SystemController::class, 'environment']);
    Route::post('/system/update', [SystemController::class, 'update']);
    Route::get('/system/update-status', [SystemController::class, 'updateStatus']);
});
