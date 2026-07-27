<?php

namespace App\Models\Concerns;

use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;

/**
 * For models without their own organization_id that are route-bound by
 * bare id at the top level (e.g. /applications/{application}); they
 * reach the organization through a parent relation. Nested routes are
 * already covered by the parent binding's own scope. The whereHas costs
 * an EXISTS subquery per query, acceptable at this scale.
 *
 * The using model must define organizationParentRelation(), e.g.
 * 'server' or 'application.server'.
 */
trait BelongsToOrganizationThroughParent
{
    protected static function bootBelongsToOrganizationThroughParent(): void
    {
        static::addGlobalScope('organizationThroughParent', function (Builder $builder) {
            if (CurrentOrganization::id()) {
                $builder->whereHas(static::organizationParentRelation());
            }
        });
    }
}
