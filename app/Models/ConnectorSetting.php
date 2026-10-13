<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Whether an infrastructure connector is on. Connectors are for the
 * administrators only: nobody else sees them, whatever their role. A connector
 * without a row is off: it shows up only in Administration → Connectors.
 *
 * @property int $id
 * @property string $connector
 * @property bool $enabled
 */
#[Fillable(['connector', 'enabled'])]
class ConnectorSetting extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public static function for(string $connector): self
    {
        return static::query()->firstOrNew(['connector' => $connector], ['enabled' => false]);
    }

    public function allows(User $user): bool
    {
        return $this->enabled && $user->isAdmin();
    }
}
