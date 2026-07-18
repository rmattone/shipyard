<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use App\Services\NodeVersionService;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The available-versions list used to run `nvm ls-remote` on the target
 * server, which is always empty on a fresh server because nvm is only
 * installed during the Node install itself. The list must come from the
 * nodejs.org release index instead, without touching the server.
 */
class NodeVersionRemoteListTest extends TestCase
{
    use RefreshDatabase;

    private function fakeIndex(): void
    {
        Http::fake([
            'nodejs.org/dist/index.json' => Http::response([
                ['version' => 'v23.1.0', 'lts' => false],
                ['version' => 'v22.14.0', 'lts' => 'Jod'],
                ['version' => 'v22.13.1', 'lts' => 'Jod'],
                ['version' => 'v21.7.3', 'lts' => false],
                ['version' => 'v20.18.0', 'lts' => 'Iron'],
            ]),
        ]);
    }

    public function test_remote_versions_come_from_nodejs_index_without_ssh(): void
    {
        $this->fakeIndex();
        $ssh = $this->mock(SSHService::class);
        $ssh->shouldNotReceive('connect');

        $versions = app(NodeVersionService::class)->getRemoteLtsVersions(Server::factory()->create());

        $this->assertSame(['22.14.0', '22.13.1', '20.18.0'], $versions);
    }

    public function test_index_fetch_failure_returns_empty_list(): void
    {
        Http::fake(['nodejs.org/*' => Http::response(null, 500)]);
        $this->mock(SSHService::class)->shouldNotReceive('connect');

        $versions = app(NodeVersionService::class)->getRemoteLtsVersions(Server::factory()->create());

        $this->assertSame([], $versions);
    }

    public function test_endpoint_returns_versions_for_a_fresh_server(): void
    {
        $this->fakeIndex();
        $this->mock(SSHService::class)->shouldNotReceive('connect');
        $server = Server::factory()->create();

        $response = $this->actingAs(User::factory()->create())
            ->getJson("/api/servers/{$server->id}/node-versions/remote");

        $response->assertOk()
            ->assertJson(['versions' => ['22.14.0', '22.13.1', '20.18.0']]);
    }
}
