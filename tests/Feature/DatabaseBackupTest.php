<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Services\DatabaseBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PharData;
use Tests\TestCase;

class DatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/uddog-backup-test-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        mkdir($this->directory.'/remote', 0700);
        file_put_contents($this->directory.'/key', 'test key');
        file_put_contents($this->directory.'/known_hosts', 'test known hosts');
        file_put_contents($this->directory.'/fake-dump', <<<'SCRIPT'
#!/usr/bin/env php
<?php
$database = $argv[count($argv) - 1];
if (file_exists(__DIR__.'/fail-dump') && $database === 'company_2') {
    fwrite(STDERR, 'simulated export failure');
    exit(1);
}
echo "-- MySQL dump for {$database}\nCREATE TABLE sample (id int);\n";
SCRIPT);
        file_put_contents($this->directory.'/fake-sftp', <<<'SCRIPT'
#!/usr/bin/env php
<?php
if (file_exists(__DIR__.'/fail-sftp')) {
    fwrite(STDERR, 'simulated remote outage');
    exit(1);
}
foreach (explode("\n", trim(stream_get_contents(STDIN))) as $line) {
    if ($line === '') continue;
    preg_match_all('/"([^"]+)"/', $line, $matches);
    $paths = $matches[1];
    $remote = fn (string $path): string => __DIR__.'/remote/'.basename($path);
    if (str_starts_with($line, 'put ')) copy($paths[0], $remote($paths[1]));
    elseif (str_starts_with($line, 'chmod ')) chmod($remote($paths[0]), 0600);
    elseif (str_starts_with($line, 'get ')) {
        copy($remote($paths[0]), $paths[1]);
        if (file_exists(__DIR__.'/corrupt-download')) file_put_contents($paths[1], 'corrupt', FILE_APPEND);
    }
    elseif (str_starts_with($line, 'rename ')) rename($remote($paths[0]), $remote($paths[1]));
    elseif (str_starts_with($line, 'rm ')) unlink($remote($paths[0]));
}
SCRIPT);
        chmod($this->directory.'/fake-dump', 0700);
        chmod($this->directory.'/fake-sftp', 0700);

        config()->set('database_backups', [
            'directory' => $this->directory.'/backups',
            'dump_binary' => $this->directory.'/fake-dump',
            'sftp_binary' => $this->directory.'/fake-sftp',
            'sftp_host' => 'backup.example',
            'sftp_port' => 22,
            'sftp_user' => 'backup',
            'sftp_directory' => '/backups',
            'sftp_private_key' => $this->directory.'/key',
            'sftp_known_hosts' => $this->directory.'/known_hosts',
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
        parent::tearDown();
    }

    public function test_backups_include_every_database_and_delete_older_copies_only_after_verification(): void
    {
        $service = app(DatabaseBackupService::class);
        $connection = ['driver' => 'mysql', 'username' => 'backup', 'password' => 'secret', 'host' => 'localhost', 'port' => 3306];
        $first = $service->backup(['central', 'company_1', 'company_2'], $connection, true);
        $firstName = basename($first['archive']);
        $this->assertFileExists($first['archive']);
        $this->assertFileExists($this->directory.'/remote/'.$firstName);
        $this->assertSame(0600, fileperms($this->directory.'/remote/'.$firstName) & 0777);
        $archive = new PharData($first['archive']);
        $manifest = json_decode($archive['manifest.json']->getContent(), true);
        $this->assertCount(3, $manifest['databases']);
        $this->assertTrue(isset($archive['central.sql.gz'], $archive['company_1.sql.gz'], $archive['company_2.sql.gz']));
        unset($archive);

        file_put_contents($this->directory.'/fail-sftp', '1');
        try {
            $service->backup(['central', 'company_1', 'company_2'], $connection, true);
            $this->fail('A failed SFTP transfer must stop the backup.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('SFTP', $exception->getMessage());
        }
        $this->assertFileExists($first['archive']);
        $this->assertFileExists($this->directory.'/remote/'.$firstName);
        unlink($this->directory.'/fail-sftp');

        $second = $service->backup(['central', 'company_1', 'company_2'], $connection, true);
        $this->assertFileExists($second['archive']);
        $this->assertFileDoesNotExist($first['archive']);
        $this->assertFileDoesNotExist($this->directory.'/remote/'.$firstName);
        $this->assertFileExists($this->directory.'/remote/'.basename($second['archive']));
    }

    public function test_failed_company_export_keeps_previous_backup(): void
    {
        $service = app(DatabaseBackupService::class);
        $connection = ['driver' => 'mysql', 'username' => 'backup', 'password' => 'secret', 'host' => 'localhost'];
        $first = $service->backup(['central', 'company_1', 'company_2'], $connection, true);
        file_put_contents($this->directory.'/fail-dump', '1');

        try {
            $service->backup(['central', 'company_1', 'company_2'], $connection, true);
            $this->fail('A failed company export must stop the backup.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('company_2', $exception->getMessage());
        }
        $this->assertFileExists($first['archive']);
        $this->assertFileExists($this->directory.'/remote/'.basename($first['archive']));
    }

    public function test_corrupted_remote_download_keeps_previous_backup(): void
    {
        $service = app(DatabaseBackupService::class);
        $connection = ['driver' => 'mysql', 'username' => 'backup', 'password' => 'secret', 'host' => 'localhost'];
        $first = $service->backup(['central', 'company_1'], $connection, true);
        file_put_contents($this->directory.'/corrupt-download', '1');

        try {
            $service->backup(['central', 'company_1'], $connection, true);
            $this->fail('A checksum mismatch must stop the backup.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('verification', $exception->getMessage());
        }
        $this->assertFileExists($first['archive']);
        $this->assertFileExists($this->directory.'/remote/'.basename($first['archive']));
    }

    public function test_command_discovers_central_and_every_provisioned_company_database(): void
    {
        $first = Organization::factory()->create(['tenant_database' => 'company_1']);
        $second = Organization::factory()->create(['tenant_database' => 'company_2']);
        Organization::factory()->create();
        config()->set('tenancy.central_connection', 'sqlite');
        config()->set('tenancy.enabled', true);
        $connection = DB::connection('sqlite')->getConfig();

        $this->mock(DatabaseBackupService::class)
            ->shouldReceive('backup')
            ->once()
            ->with([$connection['database'], $first->tenant_database, $second->tenant_database], $connection, false)
            ->andReturn(['archive' => '/tmp/verified.tar', 'databases' => 3, 'remote' => false, 'cleanup_warning' => null]);

        $this->artisan('backups:databases')->assertSuccessful();
    }

    public function test_local_backup_keeps_the_previous_archive_until_the_new_one_is_verified(): void
    {
        $service = app(DatabaseBackupService::class);
        $connection = ['driver' => 'mysql', 'username' => 'backup', 'password' => 'secret', 'host' => 'localhost'];
        $first = $service->backup(['central', 'company_1', 'company_2'], $connection);
        $this->assertFileExists($first['archive']);
        $this->assertFileDoesNotExist($this->directory.'/remote/'.basename($first['archive']));

        file_put_contents($this->directory.'/fail-dump', '1');
        try {
            $service->backup(['central', 'company_1', 'company_2'], $connection);
            $this->fail('A failed local export must preserve the last good backup.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('company_2', $exception->getMessage());
        }
        $this->assertFileExists($first['archive']);
        unlink($this->directory.'/fail-dump');

        $second = $service->backup(['central', 'company_1', 'company_2'], $connection);
        $this->assertFileExists($second['archive']);
        $this->assertFileDoesNotExist($first['archive']);
        $this->assertCount(1, glob($this->directory.'/backups/uddog-db-*.tar'));
    }

    public function test_public_html_cannot_be_used_for_database_backups(): void
    {
        config()->set('database_backups.directory', $this->directory.'/public_html/backups');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('outside the public web directory');
        app(DatabaseBackupService::class)->backup(['central'], ['driver' => 'mysql']);
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory.'/'.$name;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
