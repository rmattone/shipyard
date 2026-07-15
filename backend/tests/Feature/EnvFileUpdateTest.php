<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EnvFileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_env_file_replaces_variables_and_survives_round_trip(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->create();
        EnvironmentVariable::create(['application_id' => $app->id, 'key' => 'OLD', 'value' => 'gone']);

        $content = "APP_KEY=base64:abc\nDB_PASSWORD=\"pa\$\$w'ord\"";

        $response = $this->actingAs($user)->putJson("/api/applications/{$app->id}/env-file", [
            'content' => $content,
        ]);

        $response->assertOk();

        $this->assertDatabaseMissing('environment_variables', ['application_id' => $app->id, 'key' => 'OLD']);

        $vars = $app->environmentVariables()->pluck('value', 'key');
        $this->assertSame('base64:abc', $vars['APP_KEY']);
        $this->assertSame("pa\$\$w'ord", $vars['DB_PASSWORD'], 'The password with $ and quote must survive the round trip intact.');
    }

    public function test_env_values_are_encrypted_at_rest(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->create();

        $this->actingAs($user)->putJson("/api/applications/{$app->id}/env-file", [
            'content' => 'SECRET=top-secret-value',
        ])->assertOk();

        $raw = DB::table('environment_variables')
            ->where('application_id', $app->id)
            ->where('key', 'SECRET')
            ->value('value');

        $this->assertStringNotContainsString('top-secret-value', $raw);
    }
}
