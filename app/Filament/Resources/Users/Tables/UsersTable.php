<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\Actions\DeactivateAction;
use App\Filament\Resources\Users\Actions\DeletePermanentlyAction;
use App\Filament\Resources\Users\Actions\ImpersonateAction;
use App\Models\User;
use App\Services\SubscriptionService;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        $plans = config('spark.billables.user.plans', []);
        $priceIdToPlan = [];
        foreach ($plans as $plan) {
            if (! empty($plan['monthly_id'])) {
                $priceIdToPlan[$plan['monthly_id']] = $plan;
            }
            if (! empty($plan['yearly_id'])) {
                $priceIdToPlan[$plan['yearly_id']] = $plan;
            }
        }

        $planNameToIcon = [];
        $planNameToColor = [];
        foreach ($plans as $plan) {
            $planNameToIcon[$plan['name']] = $plan['icon'] ?? 'heroicon-o-gift';
            $planNameToColor[$plan['name']] = $plan['color'] ?? 'gray';
        }

        $billableType = (new User)->getMorphClass();

        $planRankCases = '';
        foreach ($plans as $rank => $plan) {
            foreach (['monthly_id', 'yearly_id'] as $idKey) {
                if (! empty($plan[$idKey])) {
                    $priceId = $plan[$idKey];
                    $planRankCases .= " WHEN si.price_id = '{$priceId}' THEN {$rank}";
                }
            }
        }

        $planRankSql = "(SELECT CASE{$planRankCases} ELSE 0 END FROM subscriptions s JOIN subscription_items si ON si.subscription_id = s.id WHERE s.billable_id = users.id AND s.billable_type = '{$billableType}' AND s.type = 'default' LIMIT 1) as computed_plan_rank";

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['subscriptions.items', 'transactions'])
                ->addSelect([
                    'computed_total_paid' => DB::table('transactions')
                        ->selectRaw('COALESCE(SUM(CAST(total AS numeric)), 0)')
                        ->whereColumn('transactions.billable_id', 'users.id')
                        ->where('transactions.billable_type', $billableType)
                        ->where('transactions.status', 'completed'),
                ])
                ->selectRaw($planRankSql)
            )
            ->columns([
                TextColumn::make('id')
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('subscription_plan')
                    ->label('Plan')
                    ->getStateUsing(function (User $record) use ($priceIdToPlan): string {
                        $subscription = $record->subscriptions
                            ->where('type', 'default')
                            ->first();

                        if (! $subscription) {
                            return SubscriptionService::PLAN_FREE;
                        }

                        foreach ($subscription->items as $item) {
                            if (isset($priceIdToPlan[$item->price_id])) {
                                return $priceIdToPlan[$item->price_id]['name'];
                            }
                        }

                        return SubscriptionService::PLAN_FREE;
                    })
                    ->icon(fn (string $state): mixed => $planNameToIcon[$state] ?? 'heroicon-o-gift')
                    ->badge()
                    ->color(fn (string $state): mixed => $planNameToColor[$state] ?? 'gray')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('computed_plan_rank', $direction)),
                TextColumn::make('subscription_ends_at')
                    ->label('Ends At')
                    ->getStateUsing(fn (User $record): ?string => $record->subscriptions
                        ->where('type', 'default')
                        ->first()
                        ?->ends_at
                        ?->format('M d, Y'))
                    ->placeholder('--')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('computed_total_paid')
                    ->label('Total Paid')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => '$'.number_format((int) $state / 100, 2)),
                TextColumn::make('email_verified_at')
                    ->label('Verified')
                    ->dateTime('M d, Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_login_at')
                    ->label('Last Login')
                    ->dateTime('M d, Y H:i')
                    ->sortable(),
                TextColumn::make('inactive_since')
                    ->label('Inactive Since')
                    ->dateTime('M d, Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime('M d, Y')
                    ->sortable(),
            ])
            ->defaultSort('last_login_at', 'desc')
            ->filters([
                //
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DeletePermanentlyAction::make(),
                    DeactivateAction::make(),
                    ImpersonateAction::make(),
                ]),
            ]);
    }
}
