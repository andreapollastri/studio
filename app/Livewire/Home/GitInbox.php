<?php

namespace App\Livewire\Home;

use App\Infrastructure\ConnectorException;
use App\Models\ConnectorAccount;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * What waits for the person on one of their git accounts (pull requests asking
 * for their review, their own, issues assigned to them): the host's overview,
 * read after the page is drawn so a slow host never holds the home page.
 */
#[Lazy]
class GitInbox extends Component
{
    #[Locked]
    public string $account = '';

    public function mount(string $account): void
    {
        $this->account = $account;
    }

    public function placeholder(): View
    {
        return view('livewire.home.git-inbox-placeholder');
    }

    public function render(): View
    {
        $account = ConnectorAccount::query()->gitFor(auth()->user())->where('uuid', $this->account)->firstOrFail();
        $definition = $account->definition();
        $overview = $definition->sections()[0] ?? null;
        $groups = [];
        $error = null;

        try {
            $read = $overview?->kind === 'info' && $overview->list !== null
                ? decrypt(Cache::remember($account->cacheKey('home', $overview->key), 120, fn () => encrypt(($overview->list)($account->client(), []))))
                : [];

            foreach ((array) $read as $heading => $rows) {
                // the lists of things; the account's own figures stay on the Git page
                if ($heading !== __('Account') && is_array($rows)) {
                    $groups[(string) $heading] = $rows;
                }
            }
        } catch (ConnectorException $e) {
            $error = $e->getMessage();
        } catch (\Throwable $e) {
            report($e);
            $error = __('Studio could not make sense of the answer: :reason', ['reason' => $e->getMessage()]);
        }

        return view('livewire.home.git-inbox', [
            'item' => $account,
            'definition' => $definition,
            'groups' => $groups,
            'error' => $error,
        ]);
    }
}
