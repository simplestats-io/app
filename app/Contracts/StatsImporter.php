<?php

namespace App\Contracts;

use Carbon\CarbonInterface;

interface StatsImporter
{
    /**
     * The stats model whose rows carry this import's data_import_id. Used for the
     * aggregation invalidation after the import and for the rollback.
     *
     * @return class-string
     */
    public function getModelClass(): string;

    public function getLowestImportedDate(): CarbonInterface;

    public function updateProgress(): void;

    /**
     * Keys are the allowed heading row fields of the import file.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;
}
