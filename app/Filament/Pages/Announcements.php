<?php

namespace App\Filament\Pages;

use App\Models\Announcement;
use App\Services\AnnouncementService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class Announcements extends Page implements HasTable
{
    use InteractsWithTable;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationLabel = 'Announcements';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Announcements';

    protected string $view = 'filament.pages.announcements';

    public ?array $data = [];

    public ?int $loadedAnnouncementId = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('subject')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('e.g. What\'s new in SimpleStats'),
                MarkdownEditor::make('body')
                    ->required()
                    ->live(debounce: 1000)
                    ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                        if (filled($get('subject'))) {
                            return;
                        }

                        if (preg_match('/^\s{0,3}#{1,6}\s+(.+?)\s*#*\s*$/m', (string) $state, $matches)) {
                            $set('subject', Str::limit(trim($matches[1]), 255, ''));
                        }
                    })
                    ->columnSpanFull()
                    ->fileAttachmentsDisk('public')
                    ->fileAttachmentsDirectory('announcements'),
                Section::make('Send to your user base')
                    ->description('The announcement goes to all verified users. Use the toggles to narrow it down.')
                    ->disabled(fn (Get $get): bool => filled($get('custom_emails')))
                    ->schema([
                        Toggle::make('admins_only')
                            ->label('Send to admins only')
                            ->helperText('Only send to admin users (useful for previewing the announcement).'),
                        Toggle::make('include_deleted_users')
                            ->label('Include deleted users')
                            ->helperText('Also send to former users stored in deleted models.'),
                    ]),
                Section::make('Or send to custom addresses only')
                    ->description('When you enter addresses here, the announcement is sent only to them. Your user base receives nothing, and recipients do not need to be users.')
                    ->schema([
                        TagsInput::make('custom_emails')
                            ->label('Custom email addresses')
                            ->helperText('Type an address and press Enter, Tab or comma to add it.')
                            ->placeholder('name@example.com')
                            ->splitKeys(['Enter', 'Tab', ',', ' '])
                            ->trim()
                            ->nestedRecursiveRules(['email'])
                            ->live()
                            ->columnSpanFull(),
                    ]),
                Section::make('Schedule (optional)')
                    ->description('Pick a time to send the announcement later. Leave it empty to send right away.')
                    ->schema([
                        DateTimePicker::make('scheduled_at')
                            ->label('Send at')
                            ->seconds(false)
                            ->timezone(fn (): string => $this->adminTimezone())
                            ->helperText(fn (): string => "Time in {$this->adminTimezone()}.")
                            ->after('now')
                            ->live(),
                    ]),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('send')
                    ->footer([
                        Actions::make($this->getFormActions()),
                    ]),
                EmbeddedTable::make(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Announcement::query())
            ->heading('History')
            ->description('Load a previous or scheduled announcement to edit, re-send or reschedule it.')
            ->defaultSort('last_sent_at', 'desc')
            ->columns([
                TextColumn::make('subject')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('scheduled_at')
                    ->label('Scheduled for')
                    ->dateTime('M d, Y H:i')
                    ->timezone(fn (): string => $this->adminTimezone())
                    ->badge()
                    ->color('warning')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('last_sent_at')
                    ->label('Last sent')
                    ->dateTime('M d, Y H:i')
                    ->timezone(fn (): string => $this->adminTimezone())
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('load')
                    ->icon('heroicon-o-pencil-square')
                    ->action(function (Announcement $record): void {
                        $this->form->fill([
                            'subject' => $record->subject,
                            'body' => $record->body,
                            ...($record->scheduled_at ? [
                                ...($record->recipients ?? []),
                                'scheduled_at' => $record->scheduled_at,
                            ] : []),
                        ]);
                        $this->loadedAnnouncementId = $record->id;
                    }),
                Action::make('unschedule')
                    ->label('Cancel schedule')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->visible(fn (Announcement $record): bool => $record->scheduled_at !== null)
                    ->requiresConfirmation()
                    ->modalDescription('The announcement stays in the history but will not be sent.')
                    ->action(fn (Announcement $record) => $record->update(['scheduled_at' => null])),
                DeleteAction::make()
                    ->after(function (Announcement $record): void {
                        if ($this->loadedAnnouncementId === $record->id) {
                            $this->loadedAnnouncementId = null;
                        }
                    }),
            ])
            ->emptyStateHeading('No announcements yet')
            ->paginated([10, 25]);
    }

    public function send(): void
    {
        $data = $this->form->getState();
        $recipients = [
            'admins_only' => (bool) ($data['admins_only'] ?? false),
            'include_deleted_users' => (bool) ($data['include_deleted_users'] ?? false),
            'custom_emails' => array_values($data['custom_emails'] ?? []),
        ];

        if (filled($data['scheduled_at'] ?? null)) {
            $scheduledAt = CarbonImmutable::parse($data['scheduled_at'], config('app.timezone'));

            Announcement::recordSchedule($data['subject'], $data['body'], $scheduledAt, $recipients, auth()->user(), $this->loadedAnnouncementId);

            $this->resetAnnouncementForm();

            Notification::make()
                ->title('Announcement scheduled')
                ->body('It will be sent on '.$scheduledAt->setTimezone($this->adminTimezone())->format('M d, Y H:i').'.')
                ->success()
                ->send();

            return;
        }

        Announcement::recordSend($data['subject'], $data['body'], $this->loadedAnnouncementId);

        $recipientCount = resolve(AnnouncementService::class)->dispatch($data['subject'], $data['body'], $recipients, auth()->user());
        $target = filled($recipients['custom_emails']) ? 'custom email address(es)' : 'user(s)';

        $this->resetAnnouncementForm();

        Notification::make()
            ->title('Announcement queued')
            ->body("Sending to {$recipientCount} {$target}.")
            ->success()
            ->send();
    }

    protected function adminTimezone(): string
    {
        return auth()->user()?->currentTeam?->timezone ?? config('app.timezone');
    }

    protected function resetAnnouncementForm(): void
    {
        $this->form->fill();
        $this->loadedAnnouncementId = null;
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('send')
                ->label(fn (): string => filled($this->data['scheduled_at'] ?? null) ? 'Schedule announcement' : 'Send to all users')
                ->icon(fn (): string => filled($this->data['scheduled_at'] ?? null) ? 'heroicon-o-clock' : 'heroicon-o-paper-airplane')
                ->requiresConfirmation()
                ->modalHeading(fn (): string => filled($this->data['scheduled_at'] ?? null) ? 'Schedule announcement' : 'Send announcement')
                ->modalDescription(function (): string {
                    $customEmails = collect($this->data['custom_emails'] ?? [])->filter();

                    if (filled($this->data['scheduled_at'] ?? null)) {
                        return 'The announcement will be sent automatically at the scheduled time, using the recipients selected above.';
                    }

                    if ($customEmails->isNotEmpty()) {
                        return "This will only send the announcement to the {$customEmails->count()} custom email address(es) you entered.";
                    }

                    return ($this->data['admins_only'] ?? false)
                        ? 'This will only send the announcement to admin users.'
                        : 'Are you sure you want to send this announcement to all verified users?';
                })
                ->action('send'),
        ];
    }
}
