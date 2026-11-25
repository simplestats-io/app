<?php

namespace App\Filament\Resources\Users\Actions;

use App\Jobs\DeleteUserPermanently;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class DeletePermanentlyAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'deletePermanently';
    }

    /**
     * Deleting or deactivating an owner takes their whole team down, including
     * colleagues who use it every day, so the modal says so.
     */
    public static function ownedTeamsWarning(User $user): string
    {
        $members = $user->ownedTeams()->withCount('users')->get()->sum('users_count');

        return $members > 0
            ? " Their own teams have {$members} other member(s), who lose access too."
            : '';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Delete permanently')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->hidden(fn (User $record): bool => $record->is(auth()->user()))
            ->requiresConfirmation()
            ->modalHeading('Delete user permanently')
            ->modalDescription(fn (User $record): string => 'Deletes the user, every team they own with all projects and stats, and cancels their subscription. This cannot be undone.'.static::ownedTeamsWarning($record))
            ->modalSubmitActionLabel('Delete permanently')
            ->schema([
                TextInput::make('email')
                    ->label("Type the user's email to confirm")
                    ->required()
                    ->in(fn (User $record): array => [$record->email])
                    ->validationMessages(['in' => 'The email does not match this user.']),
                TextInput::make('password')
                    ->label('Your password')
                    ->password()
                    ->required()
                    ->currentPassword(),
            ])
            ->action(function (User $record): void {
                // Soft-deleting first blocks the login and hides the user right away,
                // the queued job then removes everything for good.
                $record->delete();

                dispatch(new DeleteUserPermanently($record));

                Notification::make()
                    ->title('User deletion queued')
                    ->body("{$record->email} and all owned teams are being deleted.")
                    ->success()
                    ->send();
            });
    }
}
