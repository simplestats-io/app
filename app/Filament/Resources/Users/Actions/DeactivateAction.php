<?php

namespace App\Filament\Resources\Users\Actions;

use App\Models\User;
use Filament\Actions\DeleteAction;

class DeactivateAction extends DeleteAction
{
    public static function getDefaultName(): ?string
    {
        return 'delete';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Deactivate')
            ->modalHeading('Deactivate user')
            ->modalDescription(fn (User $record): string => 'The user can no longer log in and is deleted for good after two months, like an inactive account. Until then "php artisan app:restore-user" brings them back.'.DeletePermanentlyAction::ownedTeamsWarning($record))
            ->modalSubmitActionLabel('Deactivate')
            ->successNotificationTitle('User deactivated')
            // A deactivated subscriber could no longer cancel, while Paddle keeps
            // billing until the purge two months later. Delete them permanently instead.
            ->hidden(fn (User $record): bool => $record->is(auth()->user())
                || $record->subscription()?->recurring()
                || $record->subscription()?->pastDue());
    }
}
