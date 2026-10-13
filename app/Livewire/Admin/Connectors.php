<?php

namespace App\Livewire\Admin;

use App\Infrastructure\Connectors as Registry;
use App\Infrastructure\Git\Forges;
use App\Models\ConnectorAccount;
use App\Models\ConnectorSetting;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Which connectors are on. An enabled infrastructure connector shows up under
 * Infrastructure in the menu of the administrators, and only theirs. An
 * enabled git host shows up under Tools for everyone, who adds their own
 * accounts there. The others are nowhere to be seen.
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
        if (isset(Forges::ALL[$connector])) {
            $this->toggleForge($connector);

            return;
        }

        abort_unless(isset(Registry::ALL[$connector]), 404);

        $setting = ConnectorSetting::for($connector);
        $setting->enabled = ! $setting->enabled;
        $setting->save();

        Flux::toast(text: $setting->enabled
            ? __(':connector is on: it shows under Infrastructure.', ['connector' => Registry::make($connector)->label()])
            : __(':connector is off: it is gone from the menu, its accounts stay.', ['connector' => Registry::make($connector)->label()]));
    }

    /** A git host: the installation's own provider is on until turned off. */
    private function toggleForge(string $connector): void
    {
        $setting = ConnectorSetting::for($connector);
        $setting->enabled = ! Forges::isEnabled($connector);
        $setting->save();

        Flux::toast(text: $setting->enabled
            ? __(':connector is on: everyone finds it under Tools and adds their own accounts.', ['connector' => Forges::make($connector)->label()])
            : __(':connector is off: it is gone from the menu, everyone\'s accounts stay.', ['connector' => Forges::make($connector)->label()]));
    }

    public function render(): View
    {
        return view('livewire.admin.connectors', [
            'connectors' => Registry::all(),
            'forges' => Forges::all(),
            'enabled' => [
                ...ConnectorSetting::query()->pluck('enabled', 'connector')->map(fn ($on) => (bool) $on)->all(),
                ...collect(Forges::ALL)->map(fn ($class, string $key) => Forges::isEnabled($key))->all(),
            ],
            'accounts' => ConnectorAccount::query()->selectRaw('connector, count(*) as total')->groupBy('connector')->pluck('total', 'connector'),
        ]);
    }
}
