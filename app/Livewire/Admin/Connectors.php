<?php

namespace App\Livewire\Admin;

use App\Infrastructure\Connectors as Registry;
use App\Models\ConnectorAccount;
use App\Models\ConnectorSetting;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Which infrastructure connectors are on. An enabled connector shows up under
 * Infrastructure in the menu of the administrators, and only theirs; the
 * others are nowhere to be seen.
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
            : __(':connector is off: it is gone from the menu, its accounts stay.', ['connector' => Registry::make($connector)->label()]));
    }

    public function render(): View
    {
        return view('livewire.admin.connectors', [
            'connectors' => Registry::all(),
            'settings' => ConnectorSetting::query()->get()->keyBy('connector'),
            'accounts' => ConnectorAccount::query()->selectRaw('connector, count(*) as total')->groupBy('connector')->pluck('total', 'connector'),
        ]);
    }
}
