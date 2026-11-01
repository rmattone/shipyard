<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\DnsProvider;
use App\Models\Domain;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\CertbotService;
use App\Services\DomainDnsService;
use App\Services\NginxService;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\FakeCloudflare;
use Tests\TestCase;

class DomainDnsTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER_IP = '93.184.216.34';

    private User $user;

    private Application $application;

    private DnsProvider $provider;

    private FakeCloudflare $cloudflare;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(SSHService::class, function ($mock) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('connectSftp')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('uploadContent')->andReturn(true);
            $mock->shouldReceive('execute')->andReturn(['output' => '', 'exit_code' => 0, 'success' => true]);
        });

        $this->user = $this->createOrgUser();
        $server = Server::factory()->create(['host' => self::SERVER_IP]);
        $this->application = Application::factory()->laravel()->create(['server_id' => $server->id]);
        $this->provider = DnsProvider::factory()->create();
        $this->cloudflare = new FakeCloudflare(['example.com' => 'zone-1']);
    }

    private function addDomain(array $payload)
    {
        return $this->actingAs($this->user)->postJson("/api/applications/{$this->application->id}/domains", $payload);
    }

    public function test_adding_a_domain_creates_a_dns_only_record_until_ssl(): void
    {
        $this->addDomain([
            'domain' => 'shop.example.com',
            'dns_provider_id' => $this->provider->id,
            'dns_proxied' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('dns_status', DomainDnsService::STATUS_PENDING_PROXY)
            ->assertJsonPath('dns_record_managed', true)
            ->assertJsonPath('dns_proxied', true);

        $records = $this->cloudflare->recordsFor('shop.example.com');
        $this->assertCount(1, $records);
        $this->assertSame('A', $records[0]['type']);
        $this->assertSame(self::SERVER_IP, $records[0]['content']);
        $this->assertFalse($records[0]['proxied'], 'The record must stay DNS only until the certificate exists.');
    }

    public function test_issuing_the_certificate_switches_the_proxy_on(): void
    {
        $domain = $this->addDomain([
            'domain' => 'shop.example.com',
            'dns_provider_id' => $this->provider->id,
            'dns_proxied' => true,
        ])->json();

        $this->mock(CertbotService::class, function ($mock) {
            $mock->shouldReceive('obtainCertificateForDomain')->andReturnUsing(function (Domain $d) {
                $d->update(['ssl_enabled' => true]);

                return ['message' => 'ok', 'domain' => $d->fresh()];
            });
        });

        $this->actingAs($this->user)
            ->postJson("/api/applications/{$this->application->id}/domains/{$domain['id']}/ssl", ['email' => 'ops@example.com'])
            ->assertOk()
            ->assertJsonPath('domain.dns_status', DomainDnsService::STATUS_ACTIVE);

        $this->assertTrue($this->cloudflare->recordsFor('shop.example.com')[0]['proxied']);
    }

    public function test_a_record_already_pointing_at_the_server_is_adopted_and_kept_on_removal(): void
    {
        $this->cloudflare->addRecord('A', 'shop.example.com', self::SERVER_IP, proxied: true);
        Domain::factory()->primary()->create(['application_id' => $this->application->id, 'domain' => 'main.example.com']);

        $domain = $this->addDomain([
            'domain' => 'shop.example.com',
            'dns_provider_id' => $this->provider->id,
        ])
            ->assertCreated()
            ->assertJsonPath('dns_record_managed', false)
            ->assertJsonPath('dns_proxied', true)
            ->json();

        $this->actingAs($this->user)
            ->deleteJson("/api/applications/{$this->application->id}/domains/{$domain['id']}")
            ->assertNoContent();

        $this->assertCount(1, $this->cloudflare->recordsFor('shop.example.com'), 'A record ShipYard did not create must never be deleted.');
    }

    public function test_a_conflicting_record_needs_confirmation_before_it_is_replaced(): void
    {
        $this->cloudflare->addRecord('A', 'shop.example.com', '198.51.100.7');

        $this->addDomain([
            'domain' => 'shop.example.com',
            'dns_provider_id' => $this->provider->id,
        ])
            ->assertStatus(409)
            ->assertJsonPath('conflict', true)
            ->assertJsonPath('records.0.content', '198.51.100.7');

        $this->assertDatabaseMissing('domains', ['domain' => 'shop.example.com']);
        $this->assertSame('198.51.100.7', $this->cloudflare->recordsFor('shop.example.com')[0]['content']);

        $this->addDomain([
            'domain' => 'shop.example.com',
            'dns_provider_id' => $this->provider->id,
            'dns_overwrite' => true,
        ])->assertCreated()->assertJsonPath('dns_record_managed', true);

        $records = $this->cloudflare->recordsFor('shop.example.com');
        $this->assertCount(1, $records);
        $this->assertSame(self::SERVER_IP, $records[0]['content']);
    }

    public function test_removing_a_domain_deletes_the_record_shipyard_created(): void
    {
        Domain::factory()->primary()->create(['application_id' => $this->application->id, 'domain' => 'main.example.com']);
        $domain = $this->addDomain([
            'domain' => 'shop.example.com',
            'dns_provider_id' => $this->provider->id,
        ])->json();

        $this->actingAs($this->user)
            ->deleteJson("/api/applications/{$this->application->id}/domains/{$domain['id']}")
            ->assertNoContent();

        $this->assertSame([], $this->cloudflare->recordsFor('shop.example.com'));
    }

    public function test_a_failed_domain_save_removes_the_new_record(): void
    {
        $this->mock(NginxService::class, function ($mock) {
            $mock->shouldReceive('deploy')->andThrow(new RuntimeException('nginx -t failed'));
        });

        $this->addDomain([
            'domain' => 'shop.example.com',
            'dns_provider_id' => $this->provider->id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('domains', ['domain' => 'shop.example.com']);
        $this->assertSame([], $this->cloudflare->recordsFor('shop.example.com'));
    }

    public function test_a_server_with_a_private_address_is_refused(): void
    {
        $this->application->server->update(['host' => '10.0.0.5']);

        $this->addDomain([
            'domain' => 'shop.example.com',
            'dns_provider_id' => $this->provider->id,
        ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => "The server address 10.0.0.5 is private. Cloudflare needs the server's public IP."]);

        $this->assertDatabaseMissing('domains', ['domain' => 'shop.example.com']);
        $this->assertSame([], $this->cloudflare->records);
    }

    public function test_a_hostname_outside_the_token_zones_is_refused(): void
    {
        $this->addDomain([
            'domain' => 'shop.other.org',
            'dns_provider_id' => $this->provider->id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('domains', ['domain' => 'shop.other.org']);
    }

    public function test_a_taken_domain_is_refused_before_dns_is_touched(): void
    {
        $this->cloudflare->addRecord('A', 'shop.example.com', '198.51.100.7');
        Domain::factory()->create(['domain' => 'shop.example.com']);

        $this->addDomain([
            'domain' => 'shop.example.com',
            'dns_provider_id' => $this->provider->id,
            'dns_overwrite' => true,
        ])->assertStatus(422);

        $this->assertSame('198.51.100.7', $this->cloudflare->recordsFor('shop.example.com')[0]['content']);
    }

    public function test_a_provider_from_another_organization_is_rejected(): void
    {
        $foreign = DnsProvider::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $this->addDomain([
            'domain' => 'shop.example.com',
            'dns_provider_id' => $foreign->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('dns_provider_id');

        $this->assertSame([], $this->cloudflare->records);
    }

    public function test_an_existing_domain_can_be_linked_toggled_and_unlinked(): void
    {
        $domain = Domain::factory()->primary()->withSsl()->create([
            'application_id' => $this->application->id,
            'domain' => 'main.example.com',
        ]);
        $base = "/api/applications/{$this->application->id}/domains/{$domain->id}/dns";

        // Already has a certificate, so the proxy goes on immediately
        $this->actingAs($this->user)->postJson($base, [
            'dns_provider_id' => $this->provider->id,
            'dns_proxied' => true,
        ])->assertOk()->assertJsonPath('dns_status', DomainDnsService::STATUS_ACTIVE);
        $this->assertTrue($this->cloudflare->recordsFor('main.example.com')[0]['proxied']);

        $this->actingAs($this->user)->putJson($base, ['dns_proxied' => false])
            ->assertOk()->assertJsonPath('dns_proxied', false);
        $this->assertFalse($this->cloudflare->recordsFor('main.example.com')[0]['proxied']);

        $this->actingAs($this->user)->deleteJson($base.'?delete_record=0')
            ->assertOk()->assertJsonPath('dns_provider_id', null);
        $this->assertCount(1, $this->cloudflare->recordsFor('main.example.com'), 'delete_record=0 keeps the record.');
    }

    public function test_deleting_the_application_removes_managed_records(): void
    {
        $this->addDomain([
            'domain' => 'shop.example.com',
            'dns_provider_id' => $this->provider->id,
        ])->assertCreated();

        $this->actingAs($this->user)
            ->deleteJson("/api/applications/{$this->application->id}")
            ->assertNoContent();

        $this->assertSame([], $this->cloudflare->recordsFor('shop.example.com'));
    }

    public function test_members_cannot_change_dns(): void
    {
        $organization = $this->user->currentOrganization;
        $member = User::factory()->create();
        $member->organizations()->attach($organization, ['role' => Organization::ROLE_MEMBER]);
        $member->forceFill(['current_organization_id' => $organization->id])->save();

        $this->actingAs($member)->postJson("/api/applications/{$this->application->id}/domains", [
            'domain' => 'shop.example.com',
            'dns_provider_id' => $this->provider->id,
        ])->assertForbidden();

        $this->assertSame([], $this->cloudflare->records);
    }
}
