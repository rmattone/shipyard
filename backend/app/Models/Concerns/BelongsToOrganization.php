<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For tenant root models (organization_id column on the table).
 */
trait BelongsToOrganization
{
    protected static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        // saving (not creating) so the id is already filled when the
        // model's own booted() saving hooks run (e.g. GitProvider's
        // default-provider bookkeeping needs organization_id).
        static::saving(function (Model $model) {
            if (is_null($model->organization_id) && ($organizationId = CurrentOrganization::id())) {
                $model->organization_id = $organizationId;
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
