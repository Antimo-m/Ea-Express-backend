<?php

return [
    'enabled' => env('BACKUP_ENABLED', false),
    'path' => env('BACKUP_PATH', storage_path('app/private/backups')),
    'offsite_path' => env('BACKUP_OFFSITE_PATH'),
    'key' => env('BACKUP_KEY'),
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),
    'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
    'docker' => env('BACKUP_DOCKER', 'docker'),
    'mysql_restore_image' => env('BACKUP_MYSQL_RESTORE_IMAGE', 'mysql:8.4'),
];
