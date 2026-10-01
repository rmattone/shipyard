<?php

namespace Tests\Feature;

use App\Jobs\ProcessDeployment;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\DeploymentService;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class LocalServerSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_organization_does_not_grant_local_execution(): void
    {
        Queue::fake();
        $user = $this->createOrgUser('member');
        $this->actingAs($user)->postJson('/api/organizations', ['name' => 'Tenant organization'])->assertCreated();
        $this->postJson('/api/servers', [
            'name' => 'Local exploit', 'is_local' => true,
            'host' => 'localhost', 'username' => 'root', 'private_key' => 'unused',
        ])->assertUnprocessable()->assertJsonValidationErrors('is_local');
        $this->assertDatabaseCount('servers', 0);
        Queue::assertNothingPushed();
    }

    public function test_remote_server_cannot_be_changed_to_local(): void
    {
        $user = $this->createOrgUser();
        $server = Server::factory()->create();
        $this->actingAs($user)->putJson("/api/servers/{$server->id}", ['is_local' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('is_local');
        $this->assertFalse($server->fresh()->isLocal());
    }

    public function test_existing_local_server_cannot_queue_deployments(): void
    {
        Queue::fake();
        $user = $this->createOrgUser();
        $server = Server::factory()->create(['is_local' => true]);
        $app = Application::factory()->create(['server_id' => $server->id]);
        $this->actingAs($user)->postJson("/api/applications/{$app->id}/deploy")
            ->assertUnprocessable()->assertJsonPath('message', 'Local server execution is disabled. Configure an SSH server instead.');
        $this->assertDatabaseCount('deployments', 0);
        Queue::assertNothingPushed();
    }

    public static function strategies(): array
    {
        return [['atomic'], ['in_place']];
    }

    #[DataProvider('strategies')]
    public function test_already_queued_local_deployment_fails_without_executing(string $strategy): void
    {
        $this->createOrgUser();
        $server = Server::factory()->create(['is_local' => true]);
        $marker = sys_get_temp_dir().'/shipyard-local-blocked-'.bin2hex(random_bytes(8));
        $app = Application::factory()->create([
            'server_id' => $server->id, 'type' => 'static',
            'deployment_strategy' => $strategy,
            'deploy_script' => 'touch '.escapeshellarg($marker),
        ]);
        $deployment = Deployment::factory()->create(['application_id' => $app->id]);
        CurrentOrganization::forget();
        try {
            (new ProcessDeployment($deployment))->handle(app(DeploymentService::class));
            $this->fail('A local deployment must fail at the execution boundary.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Local server execution is disabled', $e->getMessage());
        }
        $this->assertSame('failed', $deployment->fresh()->status);
        $this->assertFileDoesNotExist($marker);
    }
}
