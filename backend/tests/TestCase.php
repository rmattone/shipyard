<?php

namespace Tests;

use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create a user with an organization and bind that organization as
     * the active context, so tenant-owned factories in the test body
     * (Server, GitProvider, NotificationChannel) automatically land in
     * the same organization the user acts on. The org-context middleware
     * rebinds the same organization on each request.
     */
    protected function createOrgUser(string $role = Organization::ROLE_OWNER): User
    {
        $user = User::factory()->create();
        $organization = $user->currentOrganization;

        if ($role !== Organization::ROLE_OWNER) {
            $user->organizations()->updateExistingPivot($organization->id, ['role' => $role]);
        }

        CurrentOrganization::set($organization, $role);

        return $user;
    }

    protected function tearDown(): void
    {
        CurrentOrganization::forget();

        parent::tearDown();
    }
}
