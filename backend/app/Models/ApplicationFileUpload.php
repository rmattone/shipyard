<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganizationThroughParent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationFileUpload extends Model
{
    use BelongsToOrganizationThroughParent;

    protected $fillable = ['application_id', 'user_id', 'filename', 'path', 'size_bytes', 'replaced_existing'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'replaced_existing' => 'boolean'];
    }

    protected static function organizationParentRelation(): string
    {
        return 'application.server';
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
