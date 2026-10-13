@php
    $chip = 'inline-flex min-w-0 items-center gap-1.5 truncate rounded-md px-2 py-1 font-mono text-xs ring-1';
    $chipPlain = 'bg-white ring-zinc-200 dark:bg-zinc-800 dark:ring-zinc-700';
    $chipAccent = 'bg-violet-50 font-semibold text-violet-800 ring-violet-200 dark:bg-violet-950/50 dark:text-violet-300 dark:ring-violet-800';
    $box = 'rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2.5 dark:border-zinc-700 dark:bg-zinc-900/50';
    $dirty = collect($git['files'] ?? [])->contains(fn ($f) => ! $f['untracked']);
@endphp

{{-- A plain yes or no for the simpler actions; the trigger says what it asks --}}
<flux:modal name="git-confirm" class="w-full md:w-[28rem]">
    <div class="space-y-5">
        <div class="flex items-start gap-4">
            <div class="flex size-10 shrink-0 items-center justify-center rounded-full" :class="ask.danger ? 'bg-red-50 text-red-600 dark:bg-red-950/50 dark:text-red-400' : 'bg-violet-50 text-violet-600 dark:bg-violet-950/50 dark:text-violet-300'">
                <flux:icon.arrows-right-left x-show="ask.icon === 'merge'" class="size-5" />
                <flux:icon.trash x-show="ask.icon === 'trash'" class="size-5" />
                <flux:icon.arrow-uturn-left x-show="ask.icon === 'revert'" class="size-5" />
                <flux:icon.document-duplicate x-show="ask.icon === 'pick'" class="size-5" />
                <flux:icon.arrow-up x-show="ask.icon === 'push'" class="size-5" />
                <flux:icon.x-circle x-show="ask.icon === 'abort'" class="size-5" />
            </div>
            <div class="min-w-0 flex-1 pt-1">
                <flux:heading size="lg" class="break-words" x-text="ask.title" />
                <flux:text class="mt-1" x-text="ask.body" />
            </div>
        </div>
        <div x-show="ask.subject" class="{{ $box }} truncate font-mono text-xs" x-text="ask.subject"></div>
        <div class="flex justify-end gap-2">
            <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
            <flux:button variant="primary" x-show="! ask.danger" x-on:click="confirm()"><span x-text="ask.confirm"></span></flux:button>
            <flux:button variant="danger" x-show="ask.danger" x-on:click="confirm()"><span x-text="ask.confirm"></span></flux:button>
        </div>
    </div>
</flux:modal>

{{-- Merge: what the branch brings, the conflicts foreseen, and how to merge it --}}
<flux:modal name="git-merge" class="w-full md:w-[34rem]">
    @if ($mergeBranch)
        @php
            $preview = $mergePreview;
            $into = $git['branch'] ?? 'HEAD';
            $conflicts = $preview['conflicts'] ?? null;
            $blocked = ! $preview || ($preview['ahead'] ?? 0) === 0 || $dirty || ! empty($conflicts) || ($git['operation'] ?? null);
        @endphp
        <form wire:submit="merge" class="space-y-4">
            <x-studio.modal-head icon="arrows-right-left" :title="__('Merge :branch into :current?', ['branch' => $mergeBranch, 'current' => $into])">
                {{ __('Its commits join :current. If anything conflicts, nothing is changed.', ['current' => $into]) }}
            </x-studio.modal-head>

            <div class="{{ $box }} flex items-center gap-2">
                <span class="{{ $chip }} {{ $chipPlain }}"><flux:icon.code-bracket variant="micro" class="size-3 shrink-0 text-zinc-400" /><span class="truncate">{{ $mergeBranch }}</span></span>
                <flux:icon.arrow-long-right variant="micro" class="size-4 shrink-0 text-zinc-400" />
                <span class="{{ $chip }} {{ $chipAccent }}"><flux:icon.code-bracket variant="micro" class="size-3 shrink-0" /><span class="truncate">{{ $into }}</span></span>
            </div>

            @if (! $preview)
                <flux:text class="text-xs">{{ $gitError ?: __('Loading…') }}</flux:text>
            @elseif ($preview['ahead'] === 0)
                <flux:callout variant="success" icon="check-circle" :heading="__('Already up to date')" :text="__(':branch has nothing that :current lacks.', ['branch' => $mergeBranch, 'current' => $into])" />
            @else
                <div class="grid grid-cols-3 gap-2 text-center">
                    <div class="{{ $box }}"><div class="text-lg font-semibold">{{ $preview['ahead'] }}</div><div class="text-[11px] text-zinc-500">{{ trans_choice('commit coming|commits coming', $preview['ahead']) }}</div></div>
                    <div class="{{ $box }}"><div class="text-lg font-semibold">{{ count($preview['files']) }}</div><div class="text-[11px] text-zinc-500">{{ trans_choice('file changed|files changed', count($preview['files'])) }}</div></div>
                    <div class="{{ $box }}">
                        @if ($conflicts === null)
                            <div class="text-lg font-semibold text-zinc-400">?</div><div class="text-[11px] text-zinc-500">{{ __('conflicts unknown') }}</div>
                        @elseif ($conflicts === [])
                            <div class="text-lg font-semibold text-emerald-600">0</div><div class="text-[11px] text-zinc-500">{{ __('conflicts') }}</div>
                        @else
                            <div class="text-lg font-semibold text-red-600">{{ count($conflicts) }}</div><div class="text-[11px] text-zinc-500">{{ trans_choice('conflict|conflicts', count($conflicts)) }}</div>
                        @endif
                    </div>
                </div>

                @if (! empty($conflicts))
                    <flux:callout variant="danger" icon="exclamation-triangle">
                        <flux:callout.heading>{{ __('These files would conflict') }}</flux:callout.heading>
                        <flux:callout.text>
                            <span class="block font-mono text-xs">{{ implode(', ', $conflicts) }}</span>
                            <span class="mt-1 block">{{ __('Ask Claude in the chat to merge :branch and resolve them, or align the two branches first.', ['branch' => $mergeBranch]) }}</span>
                        </flux:callout.text>
                    </flux:callout>
                @endif

                <div>
                    <div class="mb-1 text-[10px] uppercase tracking-wide text-zinc-500">{{ __('Commits') }}</div>
                    <ul class="max-h-40 overflow-auto rounded-lg border border-zinc-200 text-xs dark:border-zinc-700">
                        @foreach (array_slice($preview['commits'], 0, 30) as $c)
                            <li class="flex items-center gap-2 border-b border-zinc-100 px-2 py-1 last:border-0 dark:border-zinc-800">
                                <span class="font-mono text-zinc-500">{{ $c['short'] ?? '' }}</span>
                                <span class="min-w-0 flex-1 truncate">{{ $c['subject'] ?? '' }}</span>
                                <span class="hidden shrink-0 text-zinc-400 sm:inline">{{ $c['author'] ?? '' }}</span>
                            </li>
                        @endforeach
                        @if ($preview['ahead'] > 30)
                            <li class="px-2 py-1 text-zinc-500">{{ __('and :count more', ['count' => $preview['ahead'] - 30]) }}</li>
                        @endif
                    </ul>
                </div>

                <flux:radio.group wire:model="mergeMode" :label="__('How')">
                    <flux:radio value="merge" :label="$preview['fastForward'] ? __('Fast-forward') : __('Merge commit')" :description="$preview['fastForward'] ? __(':current simply moves forward to :branch: no merge commit.', ['current' => $into, 'branch' => $mergeBranch]) : __('A merge commit joins the two histories.')" />
                    @if ($preview['fastForward'])
                        <flux:radio value="no-ff" :label="__('Always a merge commit')" :description="__('Keeps a trace of the branch in the history.')" />
                    @endif
                    <flux:radio value="squash" :label="__('Squash')" :description="__('Every change staged as one, for a single commit you write.')" />
                </flux:radio.group>
            @endif

            @if ($dirty)
                <flux:callout variant="warning" icon="exclamation-triangle" :text="__('There are uncommitted changes: commit or stash them first.')" />
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="arrows-right-left" :disabled="$blocked">{{ __('Merge') }}</flux:button>
            </div>
        </form>
    @endif
</flux:modal>

{{-- Delete a local branch: forced only when asked, and on the remote too when asked --}}
<flux:modal name="git-delete-branch" class="w-full md:w-[28rem]">
    @if ($deleting)
        @php
            $merged = (bool) ($deleting['merged'] ?? false);
            $upstream = ($deleting['upstream'] ?? null) && ! ($deleting['gone'] ?? false) ? $deleting['upstream'] : null;
        @endphp
        <form wire:submit="deleteBranch" class="space-y-4">
            <x-studio.modal-head icon="trash" tone="red" :title="__('Delete the branch :branch?', ['branch' => $deleting['name']])">
                {{ $merged ? __('It is merged into :current: its commits stay there.', ['current' => $git['branch'] ?? 'HEAD']) : __('It is not merged into :current.', ['current' => $git['branch'] ?? 'HEAD']) }}
            </x-studio.modal-head>

            <div class="{{ $box }} flex items-center gap-2 text-xs">
                <flux:icon.code-bracket variant="micro" class="size-3.5 text-zinc-400" />
                <span class="min-w-0 flex-1 truncate font-mono">{{ $deleting['name'] }}</span>
                @if (! empty($deleting['sha']))<span class="font-mono text-zinc-500">{{ $deleting['sha'] }}</span>@endif
                @if ($merged)<flux:badge size="sm" color="green">{{ __('merged') }}</flux:badge>@else<flux:badge size="sm" color="amber">{{ __('not merged') }}</flux:badge>@endif
            </div>

            @if (! $merged)
                <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950/30">
                    <flux:checkbox wire:model.live="deleteForce" :label="__('Delete it anyway')" :description="$upstream ? __('Its commits that are only here are lost; those pushed to :upstream stay there.', ['upstream' => $upstream]) : __('Its commits are only on this branch: they are lost.')" />
                </div>
            @endif
            @if ($upstream)
                <flux:checkbox wire:model.live="deleteRemote" :label="__('Also delete :upstream on the remote', ['upstream' => $upstream])" :description="__('For everybody: nobody can check it out any more.')" />
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger" icon="trash" :disabled="! $merged && ! $deleteForce">{{ $deleteRemote && $upstream ? __('Delete here and on remote') : __('Delete branch') }}</flux:button>
            </div>
        </form>
    @endif
</flux:modal>

<flux:modal name="git-rename-branch" class="w-full md:w-[26rem]">
    @if ($renameFrom)
        <form wire:submit="renameBranch" class="space-y-4">
            <x-studio.modal-head icon="pencil-square" :title="__('Rename :branch', ['branch' => $renameFrom])">
                {{ __('Only here: its branch on the remote keeps the old name until you push the new one.') }}
            </x-studio.modal-head>
            <flux:input wire:model="renameTo" :label="__('New name')" class="font-mono" autofocus />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Rename') }}</flux:button>
            </div>
        </form>
    @endif
</flux:modal>

{{-- Reset: the three ways of moving the branch back, the dangerous one in red --}}
<flux:modal name="git-reset" class="w-full md:w-[30rem]">
    @if ($resetTo)
        <form wire:submit="resetBranch" class="space-y-4">
            <x-studio.modal-head icon="backward" tone="amber" :title="__('Reset :branch to this commit?', ['branch' => $git['branch'] ?? 'HEAD'])">
                {{ __('The commits after it leave the branch. If they were pushed, a force push is needed to drop them on the remote too: prefer Revert for shared history.') }}
            </x-studio.modal-head>
            <div class="{{ $box }} truncate font-mono text-xs">{{ ($resetTo['short'] ?? '').' '.($resetTo['subject'] ?? '') }}</div>
            <flux:radio.group wire:model.live="resetMode">
                <flux:radio value="soft" :label="__('Soft')" :description="__('Their changes stay staged, ready to commit again.')" />
                <flux:radio value="mixed" :label="__('Mixed')" :description="__('Their changes stay in the working tree, not staged.')" />
                <flux:radio value="hard" :label="__('Hard')" :description="__('Their changes and every uncommitted change are thrown away.')" />
            </flux:radio.group>
            @if ($resetMode === 'hard')
                <flux:callout variant="danger" icon="exclamation-triangle" :text="__('This cannot be undone from here: uncommitted work is lost.')" />
            @endif
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" :variant="$resetMode === 'hard' ? 'danger' : 'primary'" icon="backward">{{ __('Reset') }}</flux:button>
            </div>
        </form>
    @endif
</flux:modal>

<flux:modal name="git-tag" class="w-full md:w-[28rem]">
    <form wire:submit="createTag" class="space-y-4">
        <x-studio.modal-head icon="tag" :title="__('New tag')">
            {{ $tagAt ? __('On commit :sha.', ['sha' => substr($tagAt, 0, 7)]) : __('On the current commit of :branch.', ['branch' => $git['branch'] ?? 'HEAD']) }}
        </x-studio.modal-head>
        <flux:input wire:model="tagName" :label="__('Name')" placeholder="v1.2.0" class="font-mono" />
        <flux:textarea wire:model="tagMessage" rows="2" :label="__('Message')" :description="__('Optional: with a message the tag is annotated, as releases usually are.')" />
        <div class="flex justify-end gap-2">
            <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
            <flux:button type="submit" variant="primary" icon="tag">{{ __('Create tag') }}</flux:button>
        </div>
    </form>
</flux:modal>

<flux:modal name="git-delete-tag" class="w-full md:w-[26rem]">
    @if ($deletingTag)
        @php($pushed = (bool) (collect($tags ?? [])->firstWhere('name', $deletingTag)['pushed'] ?? false))
        <form wire:submit="deleteTag" class="space-y-4">
            <x-studio.modal-head icon="trash" tone="red" :title="__('Delete the tag :tag?', ['tag' => $deletingTag])">
                {{ __('The commit stays; only the name goes.') }}
            </x-studio.modal-head>
            @if ($pushed)
                <flux:checkbox wire:model.live="deleteTagRemote" :label="__('Also delete it on the remote')" :description="__('Otherwise the next fetch brings it back.')" />
            @endif
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger" icon="trash">{{ $deleteTagRemote ? __('Delete here and on remote') : __('Delete tag') }}</flux:button>
            </div>
        </form>
    @endif
</flux:modal>

<flux:modal name="git-stash" class="w-full md:w-[26rem]">
    <form wire:submit="stash" class="space-y-4">
        <x-studio.modal-head icon="archive-box-arrow-down" :title="__('Stash the changes')">
            {{ __('They are put aside and the working tree is clean again; bring them back from Stashes, on this branch or another.') }}
        </x-studio.modal-head>
        <flux:input wire:model="stashMessage" :label="__('Message')" :placeholder="__('What these changes are, to find them later')" />
        <flux:checkbox wire:model="stashUntracked" :label="__('Include new files')" />
        <div class="flex justify-end gap-2">
            <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
            <flux:button type="submit" variant="primary" icon="archive-box-arrow-down">{{ __('Stash') }}</flux:button>
        </div>
    </form>
</flux:modal>
