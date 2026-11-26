<?php

namespace App\Filament\Resources\Users\Actions;

use App\Jobs\DeleteUserPermanently;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
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
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->hidden(fn (User $record): bool => $record->is(auth()->user()))
            ->modalIcon(Heroicon::OutlinedTrash)
            ->modalIconColor('danger')
            ->modalWidth('lg')
            ->modalHeading('Delete user permanently')
            ->modalDescription(fn (User $record): string => 'Deletes the user, every team they own with all projects and stats, and cancels their subscription. This cannot be undone.'.static::ownedTeamsWarning($record))
            ->modalSubmitActionLabel('Continue')
            ->schema([
                // A select instead of an email text field: password managers fill a text
                // field next to a password field with the admin's own login.
                Select::make('user')
                    ->label('Select the user to confirm')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => User::query()
                        ->whereLike('email', "%{$search}%")
                        ->orWhereLike('name', "%{$search}%")
                        ->limit(20)
                        ->get()
                        ->mapWithKeys(fn (User $user): array => [$user->uuid => "{$user->name} ({$user->email})"])
                        ->all())
                    ->getOptionLabelUsing(fn (string $value): ?string => ($user = User::firstWhere('uuid', $value)) ? "{$user->name} ({$user->email})" : null)
                    ->required()
                    ->in(fn (User $record): array => [$record->uuid])
                    ->validationMessages(['in' => 'This is not the user you are deleting.']),
                TextInput::make('password')
                    ->label('Your password')
                    ->password()
                    ->autocomplete('current-password')
                    ->required()
                    ->currentPassword(),
            ])
            ->registerModalActions([
                Action::make('confirmDeletePermanently')
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record): string => "Really delete {$record->name}?")
                    ->modalDescription(fn (User $record): string => "You are about to delete {$record->email} with all owned teams, projects and stats. This is irreversible.")
                    ->modalSubmitActionLabel('Yes, delete permanently')
                    ->color('danger')
                    ->cancelParentActions()
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
                    }),
            ])
            ->action(function (Action $action, HasActions $livewire): void {
                $livewire->mountAction('confirmDeletePermanently');

                $action->halt();
            });
    }
}
