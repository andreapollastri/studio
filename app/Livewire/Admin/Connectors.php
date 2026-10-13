<?php

namespace App\Livewire\Admin;

use App\Infrastructure\Connectors as Registry;
use App\Models\ConnectorAccount;
use App\Models\ConnectorSetting;
use App\Models\ProjectResource;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The services Studio drives through their APIs. An infrastructure connector that
 * is on shows under Infrastructure, to the administrators, who attach what it
 * manages to projects for their roles. Every git host is there for everyone, who
 * add their own accounts in Settings → Accounts; here the administrators keep the
 * team's service accounts, that create and deploy the project sites.
 */
#[Layout('layouts::app')]
#[Title('Connectors · Administration')]
class Connectors extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
    }

    public function toggle(string $connector): void
    {
        abort_unless(isset(Registry::ALL[$connector]), 404);

        $setting = ConnectorSetting::for($connector);
        $setting->enabled = ! $setting->enabled;
        $setting->save();

        Flux::toast(text: $setting->enabled
            ? __(':connector is on: it shows under Infrastructure.', ['connector' => Registry::make($connector)->label()])
            : __(':connector is off: it is gone from the menu, its accounts and what is attached to projects stay.', ['connector' => Registry::make($connector)->label()]));
    }

    public function render(): View
    {
        return view('livewire.admin.connectors', [
            'connectors' => Registry::all(),
            'enabled' => ConnectorSetting::query()->pluck('enabled', 'connector')->map(fn ($on) => (bool) $on)->all(),
            'accounts' => ConnectorAccount::query()->whereNull('user_id')->selectRaw('connector, count(*) as total')->groupBy('connector')->pluck('total', 'connector'),
            'attached' => ProjectResource::query()->join('connector_accounts', 'connector_accounts.id', '=', 'project_resources.connector_account_id')
                ->selectRaw('connector_accounts.connector as connector, count(*) as total')->groupBy('connector_accounts.connector')->pluck('total', 'connector'),
        ]);
    }
}
