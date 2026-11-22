<?php

namespace App\Filament\Pages;

use App\Helpers\CountryHelper;
use App\Models\Project;
use App\Models\User;
use App\Services\ManualRevenueTracker;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Str;

class TrackRevenue extends Page
{
    /**
     * Common ISO-4217 currencies offered in the currency select. The acting
     * project's team currency is always merged in, so any team currency is
     * selectable even if it is not listed here.
     *
     * @var array<int, string>
     */
    protected const CURRENCIES = [
        'AUD', 'BRL', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD',
        'HUF', 'INR', 'JPY', 'MXN', 'NOK', 'NZD', 'PLN', 'RON', 'SEK', 'SGD',
        'TRY', 'USD', 'ZAR',
    ];

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Track Revenue';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Track Revenue';

    protected string $view = 'filament.pages.track-revenue';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'project_id' => $this->defaultProjectId(),
            'user_type' => 'existing',
            'currency' => $this->defaultCurrency($this->defaultProjectId()),
            'user_time' => now(),
            'payment_time' => now(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('project_id')
                    ->label('Project')
                    ->options($this->projectOptions())
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        $set('currency', $this->defaultCurrency($state));
                    })
                    ->helperText('The project the manual revenue is attributed to.'),

                Section::make('Stats user')
                    ->schema([
                        Radio::make('user_type')
                            ->label('User type')
                            ->options([
                                'existing' => 'Existing user',
                                'external' => 'External buyer (no account)',
                            ])
                            ->default('existing')
                            ->required()
                            ->live()
                            ->inline()
                            ->inlineLabel(false),

                        Select::make('user_id')
                            ->label('User')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => User::query()
                                ->where(fn (Builder $query) => $query
                                    ->where('name', 'like', "%{$search}%")
                                    ->orWhere('email', 'like', "%{$search}%"))
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (User $user): array => [$user->id => "{$user->name} ({$user->email})"])
                                ->all())
                            ->getOptionLabelUsing(fn ($value): ?string => User::find($value)?->email)
                            ->required(fn (Get $get): bool => $get('user_type') === 'existing')
                            ->visible(fn (Get $get): bool => $get('user_type') === 'existing')
                            ->helperText('The stats user id will be the selected user id, matching the self-tracking.'),

                        Select::make('location_country')
                            ->label('Country')
                            ->options(CountryHelper::options())
                            ->searchable()
                            ->nullable()
                            ->helperText('Stored as the geo country. Overwritten by the IP geo lookup if an IP is provided below.'),

                        TextInput::make('track_source')->label('Source')->maxLength(255),
                        TextInput::make('track_medium')->label('Medium')->maxLength(255),
                        TextInput::make('track_campaign')->label('Campaign')->maxLength(255),
                        TextInput::make('track_term')->label('Term')->maxLength(255),
                        TextInput::make('track_content')->label('Content')->maxLength(255),
                        TextInput::make('page_entry')->label('Entry page')->maxLength(255),
                        TextInput::make('ip')
                            ->label('IP address')
                            ->ipv4()
                            ->helperText('Optional. If set, country/region/city are derived from geo and override the country above.'),

                        DateTimePicker::make('user_time')
                            ->label('Registration time')
                            ->seconds(false)
                            ->required()
                            ->default(now()),
                    ])
                    ->columns(2),

                Section::make('Payment')
                    ->schema([
                        TextInput::make('gross')
                            ->label('Gross (cents)')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->helperText('Amount in cents, e.g. 9900 for 99.00.'),

                        TextInput::make('net')
                            ->label('Net (cents)')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->helperText('Amount in cents.'),

                        Select::make('currency')
                            ->label('Currency')
                            ->options(fn (Get $get): array => $this->currencyOptions($get('project_id')))
                            ->searchable()
                            ->required(),

                        DateTimePicker::make('payment_time')
                            ->label('Payment time')
                            ->seconds(false)
                            ->required()
                            ->default(now()),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('track')
                    ->footer([
                        Actions::make($this->getFormActions()),
                    ]),
            ]);
    }

    public function track(): void
    {
        $data = $this->form->getState();

        $project = Project::withoutGlobalScope('team')->findOrFail($data['project_id']);

        $statsUserId = ($data['user_type'] === 'existing')
            ? (string) $data['user_id']
            : (string) Str::uuid();

        $userData = array_filter([
            'id' => $statsUserId,
            'time' => CarbonImmutable::parse($data['user_time'])->format('Y-m-d H:i:s P'),
            'location_country' => $data['location_country'] ?? null,
            'track_source' => $data['track_source'] ?? null,
            'track_medium' => $data['track_medium'] ?? null,
            'track_campaign' => $data['track_campaign'] ?? null,
            'track_term' => $data['track_term'] ?? null,
            'track_content' => $data['track_content'] ?? null,
            'page_entry' => $data['page_entry'] ?? null,
            'ip' => $data['ip'] ?? null,
        ], fn ($value): bool => $value !== null && $value !== '');

        $paymentData = [
            'id' => (string) Str::uuid(),
            'stats_user_id' => $statsUserId,
            'gross' => (int) $data['gross'],
            'net' => (int) $data['net'],
            'currency' => $data['currency'],
            'time' => CarbonImmutable::parse($data['payment_time'])->format('Y-m-d H:i:s P'),
        ];

        resolve(ManualRevenueTracker::class)->track($project, $userData, $paymentData);

        $this->form->fill([
            'project_id' => $data['project_id'],
            'user_type' => 'existing',
            'currency' => $data['currency'],
            'user_time' => now(),
            'payment_time' => now(),
        ]);

        Notification::make()
            ->title('Revenue tracked')
            ->body("Recorded {$paymentData['gross']} cents ({$paymentData['currency']}) for project \"{$project->name}\".")
            ->success()
            ->send();
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('track')
                ->label('Track revenue')
                ->icon('heroicon-o-banknotes')
                ->requiresConfirmation()
                ->modalHeading('Track revenue')
                ->modalDescription('This creates a stats user and a stats payment for the selected project, exactly like the tracking API would.')
                ->action('track'),
        ];
    }

    /**
     * All projects across all teams, labelled "Team / Project".
     *
     * @return array<int, string>
     */
    protected function projectOptions(): array
    {
        return Project::withoutGlobalScope('team')
            ->with('team')
            ->get()
            ->mapWithKeys(fn (Project $project): array => [
                $project->id => trim(($project->team?->name ? "{$project->team->name} / " : '').$project->name),
            ])
            ->toArray();
    }

    protected function defaultProjectId(): ?int
    {
        return auth()->user()?->team?->projects()->value('id');
    }

    /**
     * @return array<string, string>
     */
    protected function currencyOptions(?int $projectId): array
    {
        $codes = collect(self::CURRENCIES);

        if ($teamCurrency = $this->defaultCurrency($projectId)) {
            $codes->push($teamCurrency);
        }

        return $codes->unique()->sort()->mapWithKeys(fn (string $code): array => [$code => $code])->all();
    }

    protected function defaultCurrency(?int $projectId): ?string
    {
        if (! $projectId) {
            return null;
        }

        return Project::withoutGlobalScope('team')
            ->with('team')
            ->find($projectId)?->team?->currency;
    }
}
