<?php

use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BackupRunStreamController;
use App\Http\Controllers\Api\DaemonController;
use App\Http\Controllers\Api\DatabaseController;
use App\Http\Controllers\Api\DatabaseInstallationStreamController;
use App\Http\Controllers\Api\DatabaseRestoreController;
use App\Http\Controllers\Api\DatabaseUserController;
use App\Http\Controllers\Api\DeploymentController;
use App\Http\Controllers\Api\DeploymentStreamController;
use App\Http\Controllers\Api\DomainController;
use App\Http\Controllers\Api\EnvironmentVariableController;
use App\Http\Controllers\Api\FirewallController;
use App\Http\Controllers\Api\GitProviderController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\LogController;
use App\Http\Controllers\Api\NginxController;
use App\Http\Controllers\Api\NotificationChannelController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\OrganizationInvitationController;
use App\Http\Controllers\Api\OrganizationMemberController;
use App\Http\Controllers\Api\RollbackController;
use App\Http\Controllers\Api\ScheduledTaskController;
use App\Http\Controllers\Api\ServerController;
use App\Http\Controllers\Api\ServerSshKeyController;
use App\Http\Controllers\Api\ServerUserController;
use App\Http\Controllers\Api\SshdSettingsController;
use App\Http\Controllers\Api\SSHKeyController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\TerminalController;
use App\Http\Controllers\Api\TerminalStreamController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

// Public routes (login is strictly throttled per IP on top of the api limiter)
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Webhook route (validated by secret)
Route::post('/webhook/{application}', [WebhookController::class, 'handle']);

// SSE streaming routes (auth handled via query param token)
Route::get('/deployments/{deployment}/stream', [DeploymentStreamController::class, 'stream']);
Route::get('/database-installations/{installation}/stream', [DatabaseInstallationStreamController::class, 'stream']);
Route::get('/terminal-sessions/{terminalSession}/stream', [TerminalStreamController::class, 'stream']);
Route::get('/backup-runs/{backupRun}/stream', [BackupRunStreamController::class, 'stream']);

// Invitation accept flow (public: the token is the shared secret)
Route::middleware('throttle:login')->group(function () {
    Route::get('/invitations/{token}', [InvitationController::class, 'show']);
    Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept']);
});

// Protected routes
// Auth + org bootstrap routes that must keep working for a user with
// zero organizations (org.context would 403 them)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/user', [AuthController::class, 'user']);
    Route::post('/organizations', [OrganizationController::class, 'store'])
        ->name('organizations.store');
});

Route::middleware(['auth:sanctum', 'org.context', 'org.writes'])->group(function () {
    // Organizations (owner checks live in the controllers, since the
    // {organization} in the URL is not necessarily the current one)
    Route::get('/organizations', [OrganizationController::class, 'index']);
    Route::get('/organizations/current', [OrganizationController::class, 'current']);
    Route::put('/organizations/{organization}', [OrganizationController::class, 'update']);
    Route::delete('/organizations/{organization}', [OrganizationController::class, 'destroy']);
    Route::post('/organizations/{organization}/switch', [OrganizationController::class, 'switch'])
        ->name('organizations.switch');
    Route::get('/organizations/{organization}/members', [OrganizationMemberController::class, 'index']);
    Route::put('/organizations/{organization}/members/{user}', [OrganizationMemberController::class, 'update']);
    Route::delete('/organizations/{organization}/members/{user}', [OrganizationMemberController::class, 'destroy'])
        ->name('organizations.members.destroy');
    Route::get('/organizations/{organization}/invitations', [OrganizationInvitationController::class, 'index']);
    Route::post('/organizations/{organization}/invitations', [OrganizationInvitationController::class, 'store']);
    Route::delete('/organizations/{organization}/invitations/{invitation}', [OrganizationInvitationController::class, 'destroy']);

    // SSH Keys
    Route::post('/ssh-keys/generate', [SSHKeyController::class, 'generate']);

    // Servers. The trash listing is declared ahead of the resource routes so
    // /servers/trashed is not swallowed by /servers/{server}.
    Route::get('/servers/trashed', [ServerController::class, 'trashed']);
    Route::apiResource('servers', ServerController::class)->except(['destroy']);
    Route::post('/servers/test-connection', [ServerController::class, 'testConnectionAdhoc']);
    Route::post('/servers/{server}/test-connection', [ServerController::class, 'testConnection']);
    Route::get('/servers/{server}/node-versions', [ServerController::class, 'getNodeVersions']);
    Route::get('/servers/{server}/node-versions/remote', [ServerController::class, 'getRemoteNodeVersions']);
    Route::post('/servers/{server}/node-versions/default', [ServerController::class, 'setDefaultNodeVersion']);
    Route::get('/servers/{server}/metrics', [ServerController::class, 'getMetrics']);
    Route::get('/servers/{server}/software', [ServerController::class, 'checkSoftware']);

    // Destructive server lifecycle (admin or owner only). restore and force
    // need withTrashed() bindings or they 404 on the very rows they act on;
    // that bypasses the soft-delete scope but not the organization scope.
    Route::middleware('org.role:admin')->group(function () {
        Route::delete('/servers/{server}', [ServerController::class, 'destroy'])
            ->name('servers.destroy');
        Route::post('/servers/{server}/restore', [ServerController::class, 'restore'])
            ->withTrashed();
        Route::delete('/servers/{server}/force', [ServerController::class, 'forceDestroy'])
            ->withTrashed();
    });

    // Tags (server-scoped)
    Route::get('/servers/{server}/tags', [TagController::class, 'index']);
    Route::post('/servers/{server}/tags', [TagController::class, 'store']);
    Route::put('/servers/{server}/tags/{tag}', [TagController::class, 'update']);
    Route::delete('/servers/{server}/tags/{tag}', [TagController::class, 'destroy']);

    // Scheduled tasks (server-scoped cron entries)
    Route::get('/servers/{server}/scheduled-tasks', [ScheduledTaskController::class, 'index']);
    Route::post('/servers/{server}/scheduled-tasks', [ScheduledTaskController::class, 'store']);
    Route::get('/servers/{server}/scheduled-tasks/{scheduledTask}', [ScheduledTaskController::class, 'show']);
    Route::get('/servers/{server}/scheduled-tasks/{scheduledTask}/output', [ScheduledTaskController::class, 'output']);
    Route::delete('/servers/{server}/scheduled-tasks/{scheduledTask}', [ScheduledTaskController::class, 'destroy']);

    // Daemons (server-scoped systemd-managed processes)
    Route::get('/servers/{server}/daemons', [DaemonController::class, 'index']);
    Route::post('/servers/{server}/daemons', [DaemonController::class, 'store']);
    Route::get('/servers/{server}/daemons/{daemon}', [DaemonController::class, 'show']);
    Route::get('/servers/{server}/daemons/{daemon}/status', [DaemonController::class, 'status']);
    Route::get('/servers/{server}/daemons/{daemon}/output', [DaemonController::class, 'output']);
    Route::post('/servers/{server}/daemons/{daemon}/restart', [DaemonController::class, 'restart']);
    Route::delete('/servers/{server}/daemons/{daemon}', [DaemonController::class, 'destroy']);

    // SSH keys (server-scoped authorized_keys management)
    Route::get('/servers/{server}/ssh-keys/authorized', [ServerSshKeyController::class, 'authorized']);
    Route::get('/servers/{server}/ssh-keys', [ServerSshKeyController::class, 'index']);
    Route::post('/servers/{server}/ssh-keys', [ServerSshKeyController::class, 'store']);
    Route::delete('/servers/{server}/ssh-keys/{sshKey}', [ServerSshKeyController::class, 'destroy']);

    // SSH settings (sshd)
    Route::get('/servers/{server}/sshd-settings', [SshdSettingsController::class, 'show']);
    Route::put('/servers/{server}/sshd-settings', [SshdSettingsController::class, 'update']);

    // Firewall (ufw)
    Route::get('/servers/{server}/firewall', [FirewallController::class, 'show']);
    Route::post('/servers/{server}/firewall/rules', [FirewallController::class, 'storeRule']);
    Route::delete('/servers/{server}/firewall/rules', [FirewallController::class, 'destroyRule']);
    Route::post('/servers/{server}/firewall/enable', [FirewallController::class, 'enable']);
    Route::post('/servers/{server}/firewall/disable', [FirewallController::class, 'disable']);
    Route::post('/servers/{server}/firewall/install', [FirewallController::class, 'install']);

    // Web terminal (shell access: admin or owner only; the SSE stream
    // route lives in the public section with the other streams)
    Route::middleware('org.role:admin')->group(function () {
        Route::post('/servers/{server}/terminal-sessions', [TerminalController::class, 'open']);
        Route::post('/terminal-sessions/{terminalSession}/input', [TerminalController::class, 'input'])
            ->withoutMiddleware('throttle:api') // 120/min would starve keystrokes
            ->middleware('throttle:terminal-input');
        Route::post('/terminal-sessions/{terminalSession}/resize', [TerminalController::class, 'resize']);
        Route::post('/terminal-sessions/{terminalSession}/close', [TerminalController::class, 'close']);
    });

    // Server users
    Route::get('/servers/{server}/users', [ServerUserController::class, 'index']);
    Route::post('/servers/{server}/users', [ServerUserController::class, 'store']);
    Route::post('/servers/{server}/switch-user', [ServerUserController::class, 'switchUser']);
    Route::post('/servers/{server}/deploy-user', [ServerUserController::class, 'setDeployUser']);

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

    // Database restores. The upload drops and recreates a database, so it
    // sits behind the admin gate like the destructive server actions.
    Route::get('/servers/{server}/databases/{database}/restores', [DatabaseRestoreController::class, 'index']);
    Route::get('/backup-runs/{backupRun}', [DatabaseRestoreController::class, 'show']);
    Route::middleware('org.role:admin')->group(function () {
        Route::post('/servers/{server}/databases/{database}/restores', [DatabaseRestoreController::class, 'store']);
    });

    // Database users
    Route::get('/servers/{server}/databases/{database}/users/remote', [DatabaseUserController::class, 'listRemoteUsers']);
    Route::apiResource('servers.databases.users', DatabaseUserController::class)->except(['update']);
    Route::post('/servers/{server}/databases/{database}/users/{user}/grant', [DatabaseUserController::class, 'grantPrivileges']);
    Route::post('/servers/{server}/databases/{database}/users/{user}/revoke', [DatabaseUserController::class, 'revokePrivileges']);

    // Git Providers
    // Notification channels
    Route::apiResource('notification-channels', NotificationChannelController::class);
    Route::post('/notification-channels/{notification_channel}/test', [NotificationChannelController::class, 'test']);

    Route::apiResource('git-providers', GitProviderController::class);
    Route::post('/git-providers/{git_provider}/test', [GitProviderController::class, 'testConnection']);
    Route::get('/git-providers/{git_provider}/repositories', [GitProviderController::class, 'repositories']);
    Route::get('/git-providers/{git_provider}/branches', [GitProviderController::class, 'branches']);

    // Applications
    Route::post('/servers/{server}/applications/import', [ApplicationController::class, 'import']);
    Route::apiResource('applications', ApplicationController::class);
    Route::post('/applications/{application}/deploy', [ApplicationController::class, 'deploy'])
        ->name('applications.deploy');
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

    // System (host-level operations on the ShipYard installation itself:
    // owner-only, this reaches far beyond a single organization)
    Route::middleware('org.role:owner')->group(function () {
        Route::get('/system/version', [SystemController::class, 'version']);
        Route::get('/system/environment', [SystemController::class, 'environment']);
        Route::post('/system/update', [SystemController::class, 'update']);
        Route::get('/system/update-status', [SystemController::class, 'updateStatus']);
    });
});
