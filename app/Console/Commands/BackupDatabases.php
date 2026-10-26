<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\DatabaseBackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('backups:databases {--sftp : Also copy the verified archive to an SFTP server}')]
#[Description('Back up the central and every provisioned company database to private storage')]
class BackupDatabases extends Command
{
    public function handle(DatabaseBackupService $backups): int
    {
        try {
            $centralConnection = (string) config('tenancy.central_connection');
            $connection = DB::connection($centralConnection)->getConfig();
            $names = [(string) ($connection['database'] ?? '')];
            if (config('tenancy.enabled')) {
                $names = array_merge($names, Organization::on($centralConnection)
                    ->whereNotNull('tenant_database')->orderBy('id')->pluck('tenant_database')->all());
            }
            $result = $backups->backup($names, $connection, (bool) $this->option('sftp'));
            $this->components->info("Backed up {$result['databases']} database(s): {$result['archive']}");
            if ($result['remote']) {
                $this->components->info('The SFTP upload was downloaded and checksum-verified before older backups were removed.');
            }
            if ($result['cleanup_warning']) {
                $this->components->warn($result['cleanup_warning']);
                Log::warning('Database backup cleanup needs attention', ['message' => $result['cleanup_warning']]);
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('Database backup failed', ['exception' => $exception]);
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
