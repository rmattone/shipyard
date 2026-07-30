<?php

namespace Database\Factories;

use App\Models\BackupRun;
use App\Models\Database;
use Illuminate\Database\Eloquent\Factories\Factory;

class BackupRunFactory extends Factory
{
    protected $model = BackupRun::class;

    public function definition(): array
    {
        return [
            'backup_config_id' => null,
            'database_id' => Database::factory(),
            'user_id' => null,
            'kind' => BackupRun::KIND_RESTORE,
            'trigger' => 'manual',
            'source' => BackupRun::SOURCE_UPLOAD,
            'status' => 'pending',
            'database_name' => 'shop',
            'original_filename' => 'dump.sql.gz',
            'format' => BackupRun::FORMAT_SQL_GZ,
            'upload_path' => 'restores/'.fake()->uuid().'.sql.gz',
            'size_bytes' => 1751020,
        ];
    }
}
