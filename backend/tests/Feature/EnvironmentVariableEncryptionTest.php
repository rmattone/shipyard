<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\EnvironmentVariable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EnvironmentVariableEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_values_are_encrypted_at_rest_and_round_trip(): void
    {
        $app = Application::factory()->create();

        $variable = EnvironmentVariable::create([
            'application_id' => $app->id,
            'key' => 'DB_PASSWORD',
            'value' => 'super-secret-value',
        ]);

        $raw = DB::table('environment_variables')->where('id', $variable->id)->value('value');

        $this->assertNotSame('super-secret-value', $raw, 'Value must not be stored as plaintext.');
        $this->assertStringNotContainsString('super-secret-value', $raw);

        $this->assertSame('super-secret-value', $variable->fresh()->value, 'Value must decrypt back to plaintext.');
    }

    public function test_value_is_hidden_from_serialization(): void
    {
        $app = Application::factory()->create();

        $variable = EnvironmentVariable::create([
            'application_id' => $app->id,
            'key' => 'API_KEY',
            'value' => 'another-secret',
        ]);

        $this->assertArrayNotHasKey('value', $variable->toArray());
    }
}
