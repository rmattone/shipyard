<?php

namespace App\Models\Scopes;

use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filters every query to the request's organization. Evaluated at query
 * time: when no organization context is bound (queue workers, artisan,
 * webhooks) the scope is a no-op, which keeps SerializesModels
 * restoration and cross-org console commands working.
 */
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if ($organizationId = CurrentOrganization::id()) {
            $builder->where($model->qualifyColumn('organization_id'), $organizationId);
        }
    }
}
