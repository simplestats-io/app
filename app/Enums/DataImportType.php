<?php

namespace App\Enums;

use App\Contracts\StatsImporter;
use App\Imports\Fathom\FathomImport;
use App\Imports\StatsPageViewImport;
use App\Imports\StatsPaymentsImport;
use App\Imports\StatsUserImport;
use App\Imports\StatsVisitorImport;
use App\Models\DataImport;
use App\Models\StatsPayment;
use App\Models\StatsUser;
use App\Models\StatsVisitor;
use LaracraftTech\LaravelUsefulAdditions\Traits\UsefulEnums;

enum DataImportType: string
{
    use UsefulEnums;

    case USERS = 'users';
    //    case LOGINS = 'logins';
    case PAYMENTS = 'payments';
    case VISITORS = 'visitors';
    case PAGE_VIEWS = 'page_views';
    case FATHOM = 'fathom';

    /**
     * Only the raw import types, the tool imports get their own card in the frontend.
     *
     * @return list<array{id: string, name: string}>
     */
    public static function toOptions(): array
    {
        return collect(self::cases())->reject(fn (DataImportType $type): bool => $type->isToolImport())->map(fn (DataImportType $type): array => [
            'id' => $type->value,
            'name' => $type->title(),
        ])->values()->all();
    }

    /**
     * The tool imports, each rendered as its own card in the frontend.
     *
     * @return list<array{id: string, name: string, domain: string|null, instructions: string|null}>
     */
    public static function toolOptions(): array
    {
        return collect(self::cases())->filter(fn (DataImportType $type): bool => $type->isToolImport())->map(fn (DataImportType $type): array => [
            'id' => $type->value,
            'name' => $type->title(),
            'domain' => $type->toolDomain(),
            'instructions' => $type->toolInstructions(),
        ])->values()->all();
    }

    public function title(): string
    {
        return match ($this) {
            self::PAGE_VIEWS => 'Page Views',
            self::FATHOM => 'Fathom Analytics',
            default => ucfirst($this->value),
        };
    }

    public function getImporter(DataImport $dataImport): StatsImporter
    {
        return match ($this) {
            self::USERS => new StatsUserImport($dataImport),
            //            self::LOGINS => new StatsLoginsImport($dataImport),
            self::PAYMENTS => new StatsPaymentsImport($dataImport),
            self::VISITORS => new StatsVisitorImport($dataImport),
            self::PAGE_VIEWS => new StatsPageViewImport($dataImport),
            self::FATHOM => new FathomImport($dataImport),
        };
    }

    public function getModel(): string
    {
        return match ($this) {
            self::USERS => StatsUser::class,
            //            self::LOGINS => StatsLogin::class,
            self::PAYMENTS => StatsPayment::class,
            self::VISITORS, self::PAGE_VIEWS, self::FATHOM => StatsVisitor::class,
        };
    }

    /**
     * Tool imports take another analytics tool's export as it is and map it onto the raw imports.
     */
    public function isToolImport(): bool
    {
        return $this === self::FATHOM;
    }

    /**
     * The tool's own domain, its favicon is shown as the logo on the card.
     */
    public function toolDomain(): ?string
    {
        return match ($this) {
            self::FATHOM => 'usefathom.com',
            default => null,
        };
    }

    public function toolInstructions(): ?string
    {
        return match ($this) {
            self::FATHOM => 'Create a Pageviews export in your team\'s timezone, grouped Daily or Hourly, with the dimensions Page, Referrer and UTM Parameters and the metrics Pageviews, Entries and Page Visitors.',
            default => null,
        };
    }

    /**
     * Page views carry no data_import_id. They are rolled back with their visitors instead.
     */
    public function canRollBack(): bool
    {
        return $this !== self::PAGE_VIEWS;
    }
}
