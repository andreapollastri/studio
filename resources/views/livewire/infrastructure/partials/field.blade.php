{{-- One input of a connector form: $field (App\Infrastructure\Field), $model (the wire:model path). --}}
@php($choices = in_array($field->type, ['select', 'multiselect'], true) ? $this->options($field) : null)
@switch($field->type)
    @case('textarea')
        <flux:textarea wire:model="{{ $model }}" :label="$field->label" :description="$field->help" :placeholder="$field->placeholder" rows="4" class="font-mono text-xs" />
        @break
    @case('checkbox')
        <flux:checkbox wire:model="{{ $model }}" :label="$field->label" :description="$field->help" />
        @break
    @case('select')
        <flux:select wire:model="{{ $model }}" :label="$field->label" :description="$field->help" :placeholder="$field->required ? __('Choose…') : null">
            @unless ($field->required || array_key_exists('', $choices['options']))
                <flux:select.option value="">—</flux:select.option>
            @endunless
            @foreach ($choices['options'] as $value => $label)
                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
        @break
    @case('multiselect')
        <flux:checkbox.group wire:model="{{ $model }}" :label="$field->label" :description="$field->help">
            <div class="max-h-48 space-y-2 overflow-y-auto pe-1">
                @forelse ($choices['options'] as $value => $label)
                    <flux:checkbox value="{{ $value }}" :label="$label" />
                @empty
                    <flux:text class="text-xs">{{ __('Nothing to choose from yet.') }}</flux:text>
                @endforelse
            </div>
        </flux:checkbox.group>
        @break
    @case('number')
        <flux:input type="number" wire:model="{{ $model }}" :label="$field->label" :description="$field->help" :placeholder="$field->placeholder" />
        @break
    @case('password')
        <flux:input type="password" viewable autocomplete="off" wire:model="{{ $model }}" :label="$field->label" :description="$field->help" :placeholder="$field->placeholder" />
        @break
    @default
        <flux:input wire:model="{{ $model }}" :label="$field->label" :description="$field->help" :placeholder="$field->placeholder" />
@endswitch
@if ($choices['error'] ?? null)
    <flux:text class="-mt-3 text-xs text-red-600 dark:text-red-400">{{ __('Could not load the choices: :reason', ['reason' => $choices['error']]) }}</flux:text>
@endif
