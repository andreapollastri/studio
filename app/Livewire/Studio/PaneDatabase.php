<?php

namespace App\Livewire\Studio;

use App\Livewire\Tools\DatabaseClient;
use Livewire\Attributes\Locked;

/**
 * The SQL client of Tools → Databases inside the project's Db tab: the same
 * client, sized to the pane, and its place (database, table, view) kept in
 * the component instead of the page's URL, which is the conversation's.
 */
class PaneDatabase extends DatabaseClient
{
    #[Locked]
    public bool $embedded = true;

    public ?string $db = null;

    public ?string $schema = null;

    public ?string $table = null;

    public string $tab = 'data';
}
