<?php

namespace App\Enums;

use LaracraftTech\LaravelUsefulAdditions\Traits\UsefulEnums;
use Laravel\Mcp\Server\Registrar;

enum McpScope: string
{
    use UsefulEnums;

    case READ = Registrar::OAUTH_SCOPE;
    case WRITE = 'mcp:write';

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public static function toOptions(): array
    {
        return collect(self::cases())->map(fn (McpScope $scope): array => [
            'id' => $scope->value,
            'name' => $scope->title(),
        ])->all();
    }

    public function title(): string
    {
        return match ($this) {
            self::READ => 'Read your team\'s analytics, projects, campaigns and members',
            self::WRITE => 'Make changes: create and update projects, campaigns and ad spend, team settings, and invite members as editor or viewer',
        };
    }
}
