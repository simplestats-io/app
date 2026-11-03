<?php

namespace App\Concerns;

use App\Helpers\AppHelper;
use Illuminate\Database\Eloquent\Builder;

/**
 * Limits project-owned rows to the projects the authenticated team member may
 * access (see UserBase::accessibleProjectIds()). Unrestricted members, API
 * project tokens and console code without a user are not filtered.
 */
trait HasProjectAccessScope
{
    public static function bootHasProjectAccessScope(): void
    {
        static::addGlobalScope('project_access', function (Builder $query): void {
            $projectIds = AppHelper::accessibleProjectIds();

            if ($projectIds !== null) {
                $query->whereIn($query->qualifyColumn($query->getModel()->projectAccessColumn()), $projectIds);
            }
        });
    }

    public function projectAccessColumn(): string
    {
        return 'project_id';
    }
}
