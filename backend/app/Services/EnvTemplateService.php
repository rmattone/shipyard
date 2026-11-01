<?php

namespace App\Services;

use App\Models\Application;
use App\Support\EnvFile;
use RuntimeException;

/**
 * Builds a starting .env for an application: the repository's
 * .env.example when the git provider can read it, otherwise a built-in
 * template, with production values forced on top (debug off, production
 * environment, a generated APP_KEY). Nothing is saved; the editor shows the
 * result and the operator decides.
 */
class EnvTemplateService
{
    public const EXAMPLE_FILE = '.env.example';

    private const LARAVEL_BUILTIN = <<<'ENV'
APP_NAME=Laravel
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
ENV;

    public function __construct(private GitProviderService $gitProviderService) {}

    /**
     * @return array{content: string, source: string, applied: array<int, string>, notice: ?string}
     */
    public function build(Application $application): array
    {
        $notice = null;
        $example = null;

        try {
            $example = $this->fetchExample($application);
            if ($example === null) {
                $notice = 'No '.self::EXAMPLE_FILE." on branch {$application->branch}; using the built-in template.";
            }
        } catch (RuntimeException $e) {
            $notice = $e->getMessage().' Using the built-in template.';
        }

        $source = $example !== null ? 'repository' : 'builtin';
        $base = $example ?? $this->builtinFor($application);

        if ($base === null) {
            return ['content' => '', 'source' => 'none', 'applied' => [], 'notice' => 'No template is available for static sites.'];
        }

        $document = EnvFile::parseDocument(str_replace("\r\n", "\n", $base));
        $applied = [];

        foreach ($this->productionValues($application, $document['variables']) as $key => $value) {
            if (($document['variables'][$key] ?? null) === $value) {
                continue;
            }

            $document['variables'][$key] = $value;
            $applied[] = $key;
        }

        $variables = [];
        foreach ($document['variables'] as $key => $value) {
            $variables[] = (object) ['key' => $key, 'value' => $value];
        }

        return [
            'content' => rtrim(EnvFile::render($document['layout'], $variables))."\n",
            'source' => $source,
            'applied' => $applied,
            'notice' => $notice,
        ];
    }

    public static function generateAppKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    /**
     * @return string|null null when the repository has no .env.example
     */
    private function fetchExample(Application $application): ?string
    {
        $provider = $application->gitProvider;

        if (! $provider) {
            throw new RuntimeException('The application has no git provider, so the repository cannot be read.');
        }

        $parsed = $provider->parseRepositoryUrl($application->repository_url);

        if (! $parsed) {
            throw new RuntimeException('The repository URL could not be parsed.');
        }

        return $this->gitProviderService->fetchFile(
            $provider,
            $parsed['path'],
            $application->branch ?: 'main',
            self::EXAMPLE_FILE
        );
    }

    private function builtinFor(Application $application): ?string
    {
        return match (true) {
            $application->isLaravel() => self::LARAVEL_BUILTIN,
            $application->isNodejs() => "NODE_ENV=production\nPORT={$application->getPort()}",
            default => null,
        };
    }

    /**
     * @param  array<string, string>  $current
     * @return array<string, string>
     */
    private function productionValues(Application $application, array $current): array
    {
        if ($application->isNodejs()) {
            return ['NODE_ENV' => 'production'];
        }

        if (! $application->isLaravel()) {
            return [];
        }

        $values = [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
        ];

        if (empty($current['APP_KEY'])) {
            $values['APP_KEY'] = self::generateAppKey();
        }

        if (($current['LOG_LEVEL'] ?? 'debug') === 'debug') {
            $values['LOG_LEVEL'] = 'warning';
        }

        if ($domain = $application->primaryDomain()) {
            $values['APP_URL'] = ($domain->ssl_enabled ? 'https://' : 'http://').$domain->domain;
        }

        return $values;
    }
}
