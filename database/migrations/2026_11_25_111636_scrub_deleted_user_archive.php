<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Arr;
use Spatie\DeletedModels\Models\DeletedModel;

return new class extends Migration
{
    /**
     * Until now every deleted user was archived with all attributes, including the
     * password hash and 2FA secrets. Users who were never flagged as inactive were
     * deleted on request and are removed completely, the inactive ones keep only
     * what announcements to former users need.
     */
    public function up(): void
    {
        DeletedModel::query()
            ->where('model', (new User)->getMorphClass())
            ->lazyById()
            ->each(function (DeletedModel $deletedModel): void {
                if (blank($deletedModel->values['inactive_since'] ?? null)) {
                    $deletedModel->delete();

                    return;
                }

                $deletedModel->update(['values' => Arr::only($deletedModel->values, User::ARCHIVED_ATTRIBUTES)]);
            });
    }
};
