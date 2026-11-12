<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationFileUpload;
use App\Models\Organization;
use App\Models\Server;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ApplicationFilesTest extends TestCase
{
    use RefreshDatabase;

    private string $sandbox;

    private Application $target;

    private LocalApplicationFileSSH $ssh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->createOrgUser());
        $this->sandbox = sys_get_temp_dir().'/shipyard-files-test-'.Str::uuid();
        File::makeDirectory($this->sandbox.'/project/releases/one', 0755, true);
        File::makeDirectory($this->sandbox.'/outside', 0755, true);
        symlink($this->sandbox.'/project/releases/one', $this->sandbox.'/project/current');
        $this->target = Application::factory()->laravel()->create([
            'server_id' => Server::factory()->create(['username' => 'root', 'deploy_user' => null])->id,
            'deploy_path' => $this->sandbox.'/project',
            'deployment_strategy' => 'atomic',
        ]);
        $this->ssh = new LocalApplicationFileSSH;
        $this->app->instance(SSHService::class, $this->ssh);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sandbox);
        parent::tearDown();
    }

    private function url(string $suffix = ''): string
    {
        return '/api/applications/'.$this->target->id.'/files'.$suffix;
    }

    private function upload(string $path = 'shared/storage/app/private', string $contents = 'customer data', array $extra = [])
    {
        return $this->postJson($this->url(), array_merge([
            'path' => $path,
            'file' => UploadedFile::fake()->createWithContent('customers.csv', $contents),
        ], $extra));
    }

    public function test_default_is_private_and_is_created_only_when_uploading(): void
    {
        $this->getJson($this->url('/directories'))->assertOk()
            ->assertJsonPath('path', 'shared/storage/app/private')
            ->assertJsonPath('exists', false)->assertJsonPath('public', false)->assertJsonPath('ephemeral', false);
        $this->assertDirectoryDoesNotExist($this->sandbox.'/project/shared');

        $response = $this->upload()->assertCreated();
        $path = $this->sandbox.'/project/shared/storage/app/private/customers.csv';
        $response->assertJsonPath('data.path', $path);
        $this->assertSame('customer data', file_get_contents($path));
        $this->assertSame(0640, fileperms($path) & 0777);
        $this->assertSame('www-data', posix_getpwuid(fileowner($path))['name']);
        $this->assertCount(1, ApplicationFileUpload::all());
        $this->assertFileDoesNotExist($this->ssh->uploadedPaths[0]);
        $this->assertEmpty(glob(dirname($path).'/.shipyard-upload-*'));
        $this->getJson($this->url())->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_root_browsing_and_custom_folder_creation_and_upload(): void
    {
        $this->getJson($this->url('/directories?path='))->assertOk()->assertJsonPath('path', '');
        $name = 'Imports $(touch HACKED)';
        $this->postJson($this->url('/directories'), ['path' => '', 'name' => $name])->assertCreated();
        $this->upload($name)->assertCreated();
        $this->assertFileExists($this->sandbox.'/project/'.$name.'/customers.csv');
        $this->assertFileDoesNotExist($this->sandbox.'/project/HACKED');
        $this->postJson($this->url('/directories'), ['path' => '', 'name' => $name])->assertConflict();
    }

    public function test_in_place_and_other_application_types_have_private_defaults(): void
    {
        foreach ([['laravel', 'in_place', 'storage/app/private'], ['nodejs', 'atomic', 'shared/uploads'], ['static', 'in_place', '.shipyard/uploads']] as [$type, $strategy, $default]) {
            $this->target->update(['type' => $type, 'deployment_strategy' => $strategy]);
            $this->getJson($this->url('/directories'))->assertOk()->assertJsonPath('path', $default)->assertJsonPath('public', false);
            $this->upload($default)->assertCreated();
        }
    }

    public function test_existing_files_require_explicit_replacement_and_remain_intact_on_conflict(): void
    {
        $this->upload(contents: 'original')->assertCreated();
        $path = $this->sandbox.'/project/shared/storage/app/private/customers.csv';
        $this->upload(contents: 'replacement')->assertConflict();
        $this->assertSame('original', file_get_contents($path));
        $this->upload(contents: 'replacement', extra: ['overwrite' => true])->assertCreated();
        $this->assertSame('replacement', file_get_contents($path));
        $this->assertSame(2, ApplicationFileUpload::count());
    }

    public function test_public_and_release_folders_are_labelled_even_through_aliases(): void
    {
        File::makeDirectory($this->sandbox.'/project/releases/one/public', 0755, true);
        symlink($this->sandbox.'/project/current/public', $this->sandbox.'/project/assets-alias');
        $this->getJson($this->url('/directories?path=assets-alias'))->assertOk()
            ->assertJsonPath('public', true)->assertJsonPath('ephemeral', true);
        $this->upload('assets-alias')->assertUnprocessable();
        $this->assertEmpty($this->ssh->uploadedPaths);
        $this->upload('assets-alias', extra: ['allow_public' => true])->assertCreated();
        $path = $this->sandbox.'/project/releases/one/public/customers.csv';
        $this->assertSame(0644, fileperms($path) & 0777);
    }

    public function test_shared_public_storage_is_labelled_without_an_active_storage_symlink(): void
    {
        File::makeDirectory($this->sandbox.'/project/shared/storage/app/public', 0755, true);
        $this->getJson($this->url('/directories?path=shared/storage/app/public'))->assertOk()
            ->assertJsonPath('public', true)->assertJsonPath('ephemeral', false);
        $this->upload('shared/storage/app/public')->assertUnprocessable();
    }

    public function test_traversal_and_external_symlinks_cannot_leave_the_application(): void
    {
        symlink($this->sandbox.'/outside', $this->sandbox.'/project/escape');
        foreach (['../outside', '/etc', 'current/../../outside', 'escape', '.git', 'current\\..\\outside'] as $path) {
            $this->getJson($this->url('/directories?'.http_build_query(['path' => $path])))->assertUnprocessable();
            $this->upload($path)->assertUnprocessable();
            $this->postJson($this->url('/directories'), ['path' => $path, 'name' => 'test'])->assertUnprocessable();
        }
        $folders = $this->getJson($this->url('/directories?path='))->assertOk()->json('folders');
        $this->assertNotContains('escape', array_column($folders, 'name'));
        $this->assertEmpty(File::files($this->sandbox.'/outside'));
        $this->assertEmpty($this->ssh->uploadedPaths);
    }

    public function test_symlinks_to_shared_storage_work_but_file_symlinks_are_not_replaced(): void
    {
        $this->upload()->assertCreated();
        symlink($this->sandbox.'/project/shared/storage', $this->sandbox.'/project/releases/one/storage');
        $this->getJson($this->url('/directories?path=current/storage/app/private'))->assertOk()
            ->assertJsonPath('public', false)->assertJsonPath('ephemeral', false);
        file_put_contents($this->sandbox.'/outside/secret', 'keep');
        symlink($this->sandbox.'/outside/secret', $this->sandbox.'/project/current/link.csv');
        $this->upload('current', extra: ['file' => UploadedFile::fake()->createWithContent('link.csv', 'replace'), 'overwrite' => true])->assertUnprocessable();
        $this->assertSame('keep', file_get_contents($this->sandbox.'/outside/secret'));
    }

    public function test_member_can_browse_but_cannot_upload_or_create_folders(): void
    {
        $user = auth()->user();
        $user->organizations()->updateExistingPivot($user->current_organization_id, ['role' => Organization::ROLE_MEMBER]);
        $this->getJson($this->url('/directories'))->assertOk();
        $this->upload()->assertForbidden();
        $this->postJson($this->url('/directories'), ['path' => '', 'name' => 'imports'])->assertForbidden();
        $this->assertEmpty($this->ssh->uploadedPaths);
    }

    public function test_another_organization_cannot_access_folders_history_or_uploads(): void
    {
        $this->actingAs($this->createOrgUser());
        $this->getJson($this->url('/directories'))->assertNotFound();
        $this->getJson($this->url())->assertNotFound();
        $this->upload()->assertNotFound();
        $this->postJson($this->url('/directories'), ['path' => '', 'name' => 'imports'])->assertNotFound();
        $this->assertEmpty($this->ssh->uploadedPaths);
    }

    public function test_history_is_limited_to_this_application(): void
    {
        $other = Application::factory()->create(['server_id' => $this->target->server_id]);
        foreach (range(1, 22) as $i) {
            ApplicationFileUpload::create(['application_id' => $this->target->id, 'filename' => "file-$i", 'path' => "/private/file-$i", 'size_bytes' => 10]);
        }
        ApplicationFileUpload::create(['application_id' => $other->id, 'filename' => 'other', 'path' => '/other', 'size_bytes' => 10]);
        $this->getJson($this->url())->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('data.0.filename', 'file-22');
    }

    public function test_failed_transfer_cleans_private_staging_and_does_not_record_success(): void
    {
        $this->ssh->failUpload = true;
        $this->upload()->assertStatus(502);
        $this->assertFileDoesNotExist($this->ssh->uploadedPaths[0]);
        $this->assertSame(0, ApplicationFileUpload::count());
        $this->assertFileDoesNotExist($this->sandbox.'/project/shared/storage/app/private/customers.csv');
    }

    public function test_missing_deployment_and_missing_selected_folder_return_actionable_errors(): void
    {
        $this->upload('does-not-exist')->assertUnprocessable();
        $this->target->update(['deploy_path' => $this->sandbox.'/not-deployed']);
        $this->getJson($this->url('/directories'))->assertUnprocessable();
    }

    public function test_invalid_names_and_oversized_uploads_are_rejected(): void
    {
        foreach (['..', '../escape', 'nested/path', '.git'] as $name) {
            $this->postJson($this->url('/directories'), ['path' => '', 'name' => $name])->assertUnprocessable();
        }
        $this->upload(extra: ['file' => UploadedFile::fake()->create('large.bin', 102401)])->assertUnprocessable();
        $this->assertEmpty($this->ssh->uploadedPaths);
    }
}

/** Executes the actual remote scripts in isolated container temp directories. */
class LocalApplicationFileSSH extends SSHService
{
    public array $uploadedPaths = [];

    public bool $failUpload = false;

    public function connect(Server $server): self
    {
        return $this;
    }

    public function execute(string $command, int $timeout = 300): array
    {
        $process = Process::fromShellCommandline($command);
        $process->setTimeout($timeout);
        $process->run();

        return ['success' => $process->isSuccessful(), 'exit_code' => $process->getExitCode(), 'output' => $process->getOutput().$process->getErrorOutput()];
    }

    public function upload(string $localPath, string $remotePath, int $timeout = 30): bool
    {
        $this->uploadedPaths[] = $remotePath;
        copy($localPath, $remotePath);

        return ! $this->failUpload;
    }

    public function disconnect(): void {}
}
