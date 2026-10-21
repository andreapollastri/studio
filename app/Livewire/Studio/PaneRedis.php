<?php

namespace App\Livewire\Studio;

use App\Livewire\Tools\RedisClient;
use Livewire\Attributes\Locked;

/** The Redis browser of Tools → Databases inside the project's Db tab, as PaneDatabase is the SQL client. */
class PaneRedis extends RedisClient
{
    #[Locked]
    public bool $embedded = true;

    public int $db = 0;

    public ?string $key = null;

    public string $tab = 'keys';
}
