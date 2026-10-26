<?php

return [
    'directory' => env('DB_BACKUP_DIRECTORY') ?: storage_path('app/private/database-backups'),
    'dump_binary' => env('DB_BACKUP_DUMP_BINARY', 'mysqldump'),
    'sftp_binary' => env('DB_BACKUP_SFTP_BINARY', 'sftp'),
    'sftp_host' => env('DB_BACKUP_SFTP_HOST'),
    'sftp_port' => (int) env('DB_BACKUP_SFTP_PORT', 22),
    'sftp_user' => env('DB_BACKUP_SFTP_USER'),
    'sftp_directory' => env('DB_BACKUP_SFTP_DIRECTORY'),
    'sftp_private_key' => env('DB_BACKUP_SFTP_PRIVATE_KEY'),
    'sftp_known_hosts' => env('DB_BACKUP_SFTP_KNOWN_HOSTS'),
];
