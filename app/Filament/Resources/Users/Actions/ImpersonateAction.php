<?php

namespace App\Filament\Resources\Users\Actions;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

class ImpersonateAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'impersonate';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Impersonate')
            ->icon(Heroicon::OutlinedArrowRightOnRectangle)
            ->color('warning')
            ->hidden(fn (User $record): bool => $record->id === auth()->id())
            ->action(function (User $record): void {
                session()->put('impersonated_from_filament', true);

                auth()->user()->impersonate($record);

                $this->redirect('/dashboard');
            });
    }
}
