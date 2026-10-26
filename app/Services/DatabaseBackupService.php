<?php

namespace App\Services;

use PharData;
use RuntimeException;
use Symfony\Component\Process\Process;

class DatabaseBackupService
{
    /**
     * @param  list<string>  $databaseNames
     * @param  array<string, mixed>  $connection
     * @return array{archive: string, databases: int, remote: bool, cleanup_warning: ?string}
     */
    public function backup(array $databaseNames, array $connection, bool $uploadToSftp = false): array
    {
        if (! in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Database backups require a MySQL or MariaDB central connection.');
        }

        $databaseNames = array_values(array_unique($databaseNames));
        if ($databaseNames === []) {
            throw new RuntimeException('No databases were selected for backup.');
        }
        foreach ($databaseNames as $databaseName) {
            if (! preg_match('/\A[a-zA-Z0-9_]+\z/D', $databaseName)) {
                throw new RuntimeException('A database name contains unsupported characters.');
            }
        }

        $directory = $this->privateDirectory();
        $lock = fopen($directory.'/.backup.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('A database backup is already running.');
        }

        $id = gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(3));
        $staging = $directory.'/.pending-'.$id;
        $archive = $directory.'/uddog-db-'.$id.'.tar';
        $credentials = $directory.'/.mysql-'.$id.'.cnf';
        $published = false;

        try {
            $previous = [];
            if ($uploadToSftp) {
                $this->validateSftpSettings();
                $previous = $this->readState($directory);
            }
            if (! mkdir($staging, 0700)) {
                throw new RuntimeException('Could not create the backup staging directory.');
            }
            $this->writeCredentials($credentials, $connection);

            $files = [];
            foreach ($databaseNames as $databaseName) {
                $filename = $databaseName.'.sql.gz';
                $path = $staging.'/'.$filename;
                $this->dumpDatabase($databaseName, $credentials, $path);
                $size = filesize($path);
                $checksum = hash_file('sha256', $path);
                if ($size === false || $checksum === false) {
                    throw new RuntimeException("Could not inspect the {$databaseName} export.");
                }
                $files[$filename] = ['size' => $size, 'sha256' => $checksum];
            }
            $manifest = json_encode(['created_at' => gmdate(DATE_ATOM), 'databases' => $files], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            $tar = new PharData($archive);
            foreach (array_keys($files) as $filename) {
                $tar->addFile($staging.'/'.$filename, $filename);
            }
            $tar->addFromString('manifest.json', $manifest);
            unset($tar);
            if (! is_file($archive) || filesize($archive) === 0) {
                throw new RuntimeException('The backup archive was not created.');
            }
            chmod($archive, 0600);
            $this->verifyArchive($archive, $manifest, $files);

            $cleanupWarning = null;
            if ($uploadToSftp) {
                $remoteName = basename($archive);
                $this->uploadAndVerify($archive, $remoteName, $directory);
                $retired = array_values(array_unique(array_filter([
                    ...($previous['retired'] ?? []),
                    $previous['remote_archive'] ?? null,
                ])));
                $state = ['remote_archive' => $remoteName, 'retired' => $retired];
                $this->writeState($directory, $state);
                $published = true;

                $stillRetired = [];
                foreach ($retired as $oldName) {
                    try {
                        $this->runSftp('rm '.$this->sftpQuote($this->remotePath($oldName))."\n");
                    } catch (RuntimeException) {
                        $stillRetired[] = $oldName;
                        $cleanupWarning = 'The new backup is safe, but an older remote backup could not be deleted.';
                    }
                }
                $state['retired'] = $stillRetired;
                $this->writeState($directory, $state);
            } else {
                $published = true;
            }

            foreach (glob($directory.'/uddog-db-*.tar') ?: [] as $oldArchive) {
                if ($oldArchive !== $archive && ! unlink($oldArchive)) {
                    $cleanupWarning = 'The new backup is safe, but an older local backup could not be deleted.';
                }
            }

            return ['archive' => $archive, 'databases' => count($databaseNames), 'remote' => $uploadToSftp, 'cleanup_warning' => $cleanupWarning];
        } catch (\Throwable $exception) {
            if (! $published && is_file($archive)) {
                unlink($archive);
            }
            throw $exception;
        } finally {
            if (is_file($credentials)) {
                unlink($credentials);
            }
            $this->removeDirectory($staging);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, array{size: int, sha256: string}> $files */
    private function verifyArchive(string $archive, string $manifest, array $files): void
    {
        $tar = new PharData($archive);
        if (! isset($tar['manifest.json']) || ! hash_equals($manifest, $tar['manifest.json']->getContent())) {
            throw new RuntimeException('The backup archive manifest failed verification.');
        }
        foreach ($files as $filename => $details) {
            if (! isset($tar[$filename]) || $tar[$filename]->getSize() !== $details['size']) {
                throw new RuntimeException("The {$filename} archive entry failed verification.");
            }
            $checksum = hash_file('sha256', 'phar://'.$archive.'/'.$filename);
            if ($checksum === false || ! hash_equals($details['sha256'], $checksum)) {
                throw new RuntimeException("The {$filename} archive entry failed verification.");
            }
        }
    }

    private function privateDirectory(): string
    {
        $directory = (string) config('database_backups.directory');
        if ($directory === '' || (! is_dir($directory) && ! mkdir($directory, 0700, true))) {
            throw new RuntimeException('The private backup directory is unavailable.');
        }
        $directory = realpath($directory);
        $public = realpath(public_path());
        if ($directory === false || preg_match('~/(?:public_html|www)(?:/|$)~', $directory)
            || ($public && ($directory === $public || str_starts_with($directory, $public.DIRECTORY_SEPARATOR)))) {
            throw new RuntimeException('Backups must be stored outside the public web directory.');
        }
        chmod($directory, 0700);

        return $directory;
    }

    /** @param array<string, mixed> $connection */
    private function writeCredentials(string $path, array $connection): void
    {
        $options = ['user' => $connection['username'] ?? '', 'password' => $connection['password'] ?? ''];
        foreach (['host', 'port', 'unix_socket', 'ssl_ca'] as $key) {
            if (! empty($connection[$key])) {
                $options[$key === 'unix_socket' ? 'socket' : str_replace('_', '-', $key)] = $connection[$key];
            }
        }
        $contents = "[client]\n";
        foreach ($options as $key => $value) {
            $value = (string) $value;
            if (str_contains($value, "\n") || str_contains($value, "\r")) {
                throw new RuntimeException('A database connection setting contains a newline.');
            }
            $contents .= $key.'="'.addcslashes($value, '\\"')."\"\n";
        }
        if (file_put_contents($path, $contents, LOCK_EX) === false || ! chmod($path, 0600)) {
            throw new RuntimeException('Could not create the protected database credentials file.');
        }
    }

    private function dumpDatabase(string $databaseName, string $credentials, string $path): void
    {
        $gzip = gzopen($path, 'wb6');
        if ($gzip === false) {
            throw new RuntimeException("Could not open backup output for {$databaseName}.");
        }
        $stderr = '';
        try {
            $process = new Process([
                (string) config('database_backups.dump_binary'),
                '--defaults-extra-file='.$credentials,
                '--single-transaction', '--quick', '--routines', '--triggers', '--events', '--no-tablespaces',
                '--default-character-set=utf8mb4', '--hex-blob', $databaseName,
            ]);
            $process->setTimeout(null);
            $process->run(function (string $type, string $data) use ($gzip, &$stderr): void {
                if ($type === Process::OUT) {
                    if (gzwrite($gzip, $data) === false) {
                        throw new RuntimeException('Writing a compressed database dump failed.');
                    }
                } else {
                    $stderr = substr($stderr.$data, -2000);
                }
            });
            if (! $process->isSuccessful()) {
                throw new RuntimeException("Database export failed for {$databaseName}: ".trim($stderr));
            }
        } finally {
            gzclose($gzip);
        }
        if (filesize($path) < 30) {
            throw new RuntimeException("The {$databaseName} export is unexpectedly empty.");
        }
        $verification = new Process(['gzip', '-t', $path]);
        $verification->run();
        if (! $verification->isSuccessful()) {
            throw new RuntimeException("The {$databaseName} export failed gzip verification.");
        }
    }

    private function validateSftpSettings(): void
    {
        foreach (['sftp_host', 'sftp_user', 'sftp_directory', 'sftp_private_key', 'sftp_known_hosts'] as $key) {
            if (! config('database_backups.'.$key)) {
                throw new RuntimeException('Configure DB_BACKUP_'.strtoupper($key).' before enabling remote backups.');
            }
        }
        foreach (['sftp_host', 'sftp_user'] as $key) {
            if (! preg_match('/\A[a-zA-Z0-9_.@-]+\z/D', (string) config('database_backups.'.$key))) {
                throw new RuntimeException("The {$key} setting contains unsupported characters.");
            }
        }
        $remoteDirectory = (string) config('database_backups.sftp_directory');
        if (! preg_match('~\A/[a-zA-Z0-9_./-]+\z~D', $remoteDirectory) || str_contains($remoteDirectory, '..')) {
            throw new RuntimeException('The SFTP backup directory must be an existing absolute path without spaces or parent traversal.');
        }
        foreach (['sftp_private_key', 'sftp_known_hosts'] as $key) {
            if (! is_readable((string) config('database_backups.'.$key))) {
                throw new RuntimeException("The {$key} file is not readable.");
            }
        }
    }

    private function uploadAndVerify(string $archive, string $remoteName, string $directory): void
    {
        $remotePartial = $this->remotePath($remoteName.'.partial');
        $verifiedCopy = $directory.'/.verify-'.$remoteName;
        try {
            $this->runSftp('put '.$this->sftpQuote($archive).' '.$this->sftpQuote($remotePartial)."\n".
                'chmod 600 '.$this->sftpQuote($remotePartial)."\n".
                'get '.$this->sftpQuote($remotePartial).' '.$this->sftpQuote($verifiedCopy)."\n");
            if (! is_file($verifiedCopy) || ! hash_equals(hash_file('sha256', $archive), hash_file('sha256', $verifiedCopy))) {
                throw new RuntimeException('The uploaded backup did not pass SFTP download verification.');
            }
            $this->runSftp('rename '.$this->sftpQuote($remotePartial).' '.$this->sftpQuote($this->remotePath($remoteName))."\n");
        } catch (\Throwable $exception) {
            try {
                $this->runSftp('rm '.$this->sftpQuote($remotePartial)."\n");
            } catch (\Throwable) {
                // The incomplete upload has a unique .partial name and cannot replace a valid backup.
            }
            throw $exception;
        } finally {
            if (is_file($verifiedCopy)) {
                unlink($verifiedCopy);
            }
        }
    }

    private function runSftp(string $commands): void
    {
        $process = new Process([
            (string) config('database_backups.sftp_binary'), '-b', '-', '-P', (string) config('database_backups.sftp_port'),
            '-i', (string) config('database_backups.sftp_private_key'),
            '-oBatchMode=yes', '-oStrictHostKeyChecking=yes',
            '-oUserKnownHostsFile='.(string) config('database_backups.sftp_known_hosts'),
            config('database_backups.sftp_user').'@'.config('database_backups.sftp_host'),
        ]);
        $process->setInput($commands);
        $process->setTimeout(null);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('SFTP backup transfer failed: '.substr(trim($process->getErrorOutput()), -1200));
        }
    }

    private function remotePath(string $filename): string
    {
        return rtrim((string) config('database_backups.sftp_directory'), '/').'/'.$filename;
    }

    private function sftpQuote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /** @return array{remote_archive?: string, retired?: list<string>} */
    private function readState(string $directory): array
    {
        $path = $directory.'/latest.json';
        if (! is_file($path)) {
            return [];
        }
        $state = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($state)) {
            throw new RuntimeException('The previous backup state is invalid.');
        }
        foreach (array_filter([$state['remote_archive'] ?? null, ...($state['retired'] ?? [])]) as $name) {
            if (! is_string($name) || ! preg_match('/\Auddog-db-[a-zA-Z0-9-]+\.tar\z/D', $name)) {
                throw new RuntimeException('The previous remote backup name is invalid.');
            }
        }

        return $state;
    }

    /** @param array{remote_archive: string, retired: list<string>} $state */
    private function writeState(string $directory, array $state): void
    {
        $temporary = $directory.'/.latest-'.bin2hex(random_bytes(4));
        if (file_put_contents($temporary, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false
            || ! chmod($temporary, 0600) || ! rename($temporary, $directory.'/latest.json')) {
            throw new RuntimeException('Could not save the verified backup state.');
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                unlink($directory.'/'.$name);
            }
        }
        rmdir($directory);
    }
}
