<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\DnsProvider;
use App\Models\Domain;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeCloudflare;
use Tests\TestCase;

class DnsProviderApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createOrgUser();
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson('/api/dns-providers')->assertUnauthorized();
    }

    public function test_store_verifies_the_token_and_never_returns_it(): void
    {
        new FakeCloudflare(['example.com' => 'zone-1', 'shop.dev' => 'zone-2']);

        $this->actingAs($this->user)
            ->postJson('/api/dns-providers', [
                'name' => 'Main account',
                'type' => 'cloudflare',
                'api_token' => 'cf-secret-token',
            ])
            ->assertCreated()
            ->assertJsonPath('zone_count', 2)
            ->assertJsonMissingPath('api_token');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer cf-secret-token'));

        $raw = DB::table('dns_providers')->value('api_token');
        $this->assertNotSame('cf-secret-token', $raw, 'The token must be encrypted at rest.');
        $this->assertSame('cf-secret-token', DnsProvider::first()->api_token);
    }

    public function test_store_rejects_a_token_cloudflare_refuses(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => false,
                'errors' => [['code' => 9109, 'message' => 'Invalid access token']],
            ], 403),
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/dns-providers', [
                'name' => 'Bad',
                'type' => 'cloudflare',
                'api_token' => 'nope',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('api_token')
            ->assertJsonFragment(['message' => 'Cloudflare could not list zones: Invalid access token']);

        $this->assertDatabaseCount('dns_providers', 0);
    }

    public function test_update_keeps_the_stored_token_when_none_is_sent(): void
    {
        Http::fake();
        $provider = DnsProvider::factory()->create(['api_token' => 'original']);

        $this->actingAs($this->user)
            ->putJson("/api/dns-providers/{$provider->id}", ['name' => 'Renamed', 'api_token' => ''])
            ->assertOk()
            ->assertJsonPath('name', 'Renamed');

        Http::assertNothingSent();
        $this->assertSame('original', $provider->fresh()->api_token);
    }

    public function test_test_endpoint_reports_accessible_zones(): void
    {
        new FakeCloudflare(['example.com' => 'zone-1']);
        $provider = DnsProvider::factory()->create();

        $this->actingAs($this->user)
            ->postJson("/api/dns-providers/{$provider->id}/test")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('zones', ['example.com']);
    }

    public function test_a_provider_that_manages_domains_cannot_be_deleted(): void
    {
        $provider = DnsProvider::factory()->create();
        Domain::factory()->create([
            'application_id' => Application::factory()->create()->id,
            'dns_provider_id' => $provider->id,
            'dns_zone_id' => 'zone-1',
            'dns_record_id' => 'rec-1',
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/dns-providers/{$provider->id}")
            ->assertUnprocessable();

        $this->assertDatabaseHas('dns_providers', ['id' => $provider->id]);
    }

    public function test_providers_are_isolated_per_organization(): void
    {
        Http::fake();
        $foreign = DnsProvider::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $this->actingAs($this->user)->getJson('/api/dns-providers')->assertOk()->assertJsonCount(0);
        $this->actingAs($this->user)->putJson("/api/dns-providers/{$foreign->id}", ['name' => 'x'])->assertNotFound();
        $this->actingAs($this->user)->postJson("/api/dns-providers/{$foreign->id}/test")->assertNotFound();
        $this->actingAs($this->user)->deleteJson("/api/dns-providers/{$foreign->id}")->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_members_can_list_but_not_create(): void
    {
        $organization = $this->user->currentOrganization;
        $member = User::factory()->create();
        $member->organizations()->attach($organization, ['role' => Organization::ROLE_MEMBER]);
        $member->forceFill(['current_organization_id' => $organization->id])->save();

        $this->actingAs($member)->getJson('/api/dns-providers')->assertOk();
        $this->actingAs($member)->postJson('/api/dns-providers', [
            'name' => 'x',
            'type' => 'cloudflare',
            'api_token' => 'y',
        ])->assertForbidden();
    }
}
