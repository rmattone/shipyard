# ShipYard Deploy User and Home Directory Layout

Date: 2026-07-28
Status: Draft for review

## Context

ShipYard currently defaults new applications to `/var/www/shipyard/{app}` and relies on whatever SSH user the server was connected with (often root or ubuntu). Because `/var/www` is owned by root, the platform needs ownership fixups (`ServerUserService::chownApplications`) and sudo for routine filesystem work. PHP runs through the distro default FPM pool as `www-data`, a different user than the one that owns the code, which causes recurring permission friction on `storage/` and similar writable paths.

This design moves ShipYard to the model proven by Forge and Ploi: a canonical unprivileged `shipyard` user owns and runs applications out of its home directory.

## Goals

1. New applications default to `/home/shipyard/{app}` on servers that have a provisioned deploy user.
2. A first class way to provision the canonical `shipyard` user on a server, with correct home directory permissions for web serving.
3. PHP-FPM pools managed by ShipYard run as the deploy user, so the code owner and the code executor are the same user.
4. Full backward compatibility. Existing servers and applications keep working unchanged.

## Non-goals (explicitly out of scope)

1. Scoped sudoers replacing `NOPASSWD:ALL`. That is a separate follow-up project. This design must not preclude it, and does not implement it.
2. Migration tooling for existing servers or applications. Apps keep their stored `deploy_path`.
3. Per-application isolated users (one Unix user per app). The layout chosen here makes that possible later but it is not built now.

## Design

### The deploy user concept

Add a nullable `deploy_user` string column to `servers`.

1. When `deploy_user` is set, the server is considered to use the home directory layout. New app deploy paths, FPM pool creation, and nginx socket selection all key off this column.
2. When `deploy_user` is null (all existing servers), every current behavior is preserved exactly: `/var/www/shipyard/{app}` defaults, distro default FPM socket, no pool management.

This makes the behavior explicit in data rather than inferred from paths or remote state.

### Provisioning the shipyard user

Extend the existing deploy user flow in `ServerUserService`:

1. `buildCreateDeployUserScript` additionally runs `chmod 711` on the created home directory. Mode 711 lets nginx (`www-data`) traverse into webroots it knows the path to while preventing it from listing the directory contents. Webroot directories are `755` and files `644`, which the default umask already produces.
2. The create endpoint (`POST /servers/{server}/users`) accepts a new boolean `use_as_deploy_user`. When true, after the user is created (or already exists), the server's `deploy_user` column is set and the existing `switchConnectionUser` flow is offered in the UI so the stored SSH credential moves to that user.
3. The frontend server users panel gets a "Provision deploy user" action that pre-fills the username `shipyard` and checks `use_as_deploy_user`. The canonical name is a default, not a constraint. Any valid username is accepted.

Sudo remains full (`NOPASSWD:ALL`) in this project, matching current behavior. The sudoers template stays in one place (`buildCreateDeployUserScript`) so the follow-up scoped sudoers project replaces a single site.

### Default deploy path

`Application::generateDeployPath` becomes instance aware. In the `creating` hook, when the application's server has `deploy_user` set, the default is `/home/{deploy_user}/{safeName}`. Otherwise it remains `/var/www/shipyard/{safeName}`.

The existing path validation in `ApplicationController` (absolute, three segments minimum, no traversal) already accepts `/home/shipyard/app` and needs no change.

### PHP-FPM pool management

New service `PhpFpmPoolService` with one idempotent operation:

`ensurePool(Server $server, string $phpVersion): void`

1. Writes `/etc/php/{version}/fpm/pool.d/shipyard.conf` with `user` and `group` set to the server's `deploy_user`, listening on `unix:/run/php/php{version}-fpm-shipyard.sock`, with `listen.owner` and `listen.group` set to `www-data` so nginx can connect. Pool sizing uses conservative ondemand defaults.
2. Validates with `php-fpm{version} -t` before reloading, mirroring how `NginxService` validates before reload. On validation failure the old pool file is restored and the operation fails loudly.
3. Reloads the `php{version}-fpm` service.
4. Skips everything and returns when the pool file already exists with identical content.

Call site: `NginxService::deploy` for PHP applications on servers with `deploy_user` set, immediately before writing the vhost. This guarantees the socket exists before any config references it, and repeated calls are cheap because of the content check.

### Nginx socket selection

In `NginxService` templates, the FPM socket becomes conditional:

1. Server has `deploy_user`: `unix:/run/php/php{version}-fpm-shipyard.sock`.
2. Server has no `deploy_user`: current behavior, `unix:/var/run/php/php{version}-fpm.sock`.

The decision is made per server, not per application, so one server never mixes layouts for new configs. Existing deployed vhost files on disk are untouched until their application is next deployed.

### Application import

`ApplicationImportService::SCAN_BASES` gains `/home/{deploy_user}` (resolved per server) when `deploy_user` is set. `EXCLUDED_PATHS` logic is unchanged.

### Frontend changes

1. `AppNew.tsx` currently hardcodes the `/var/www/shipyard/` prefix. The server API resource gains a computed `default_deploy_base` field (`/home/{deploy_user}` or `/var/www/shipyard`). The form shows the prefix for the selected server and sends the full path, preserving the current request shape.
2. Placeholder strings in `SchedulerPanel.tsx` and `DaemonsPanel.tsx` update to home directory examples.
3. Server users panel gains the "Provision deploy user" action described above.

### Data changes

1. Migration: add nullable string `deploy_user` to `servers`. No backfill. Null means legacy layout.
2. `ApplicationFactory` and `ScheduledTaskFactory`, `DaemonFactory` defaults stay on `/var/www` paths (they exercise the legacy branch). New factory states cover the home layout.

### Error handling

1. Pool validation failure aborts the deploy before the vhost is written and surfaces the `php-fpm -t` output in the deployment log.
2. Provisioning a deploy user on a server where the connection user lacks sudo fails with the existing sudo unavailable error path in `ServerUserService`.
3. `chmod 711` failure aborts provisioning; a home directory that nginx cannot traverse produces confusing 403 errors later, so failing early is preferred.

### Testing

1. Unit: `generateDeployPath` with and without `deploy_user`; pool config rendering; nginx template socket selection for both layouts.
2. Feature: provisioning endpoint sets `deploy_user` and emits `chmod 711` in the generated script; deploying a PHP app on a home layout server ensures the pool before writing the vhost; app creation on a home layout server produces the home default path; import scans the home base.
3. Manual: fresh EC2 run covering provision, create app, deploy, verify site serves and `storage/` is writable, per the existing first time setup testing workflow.

## Rollout and compatibility

1. Existing servers: `deploy_user` is null, nothing changes.
2. New servers: recommended flow becomes connect, provision `shipyard` user, switch connection to it, create apps.
3. Documentation (README and setup docs) updates to describe the recommended flow.
4. The follow-up scoped sudoers project builds on this layout and replaces the sudoers template plus the nginx config write path. Nothing in this design blocks it.
