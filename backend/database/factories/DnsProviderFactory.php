<?php

namespace Database\Factories;

use App\Models\DnsProvider;
use App\Models\Organization;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

class DnsProviderFactory extends Factory
{
    protected $model = DnsProvider::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn () => CurrentOrganization::id() ?? Organization::factory(),
            'name' => 'Cloudflare',
            'type' => DnsProvider::TYPE_CLOUDFLARE,
            'api_token' => 'cf-test-token-'.fake()->lexify('??????????'),
        ];
    }
}
