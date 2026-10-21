<?php

namespace App\Livewire\Admin;

use App\Enums\DomainStatus;
use App\Jobs\ConfigureProjectSite;
use App\Models\Domain;
use App\Server\DomainDns;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Domains other than Studio's own, for the projects: added here, accepted once their DNS
 * points at this server, then chosen in a project's Settings → Server for its site or its
 * previews. Checked again every day. Online, its hosts open to anybody without the Studio login.
 */
#[Layout('layouts::app')]
#[Title('Domains · Administration')]
class Domains extends Component
{
    public string $name = '';

    public bool $wildcard = true;

    public function mount(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
    }

    public function add(DomainDns $dns): void
    {
        $this->name = strtolower(trim($this->name));
        $studio = strtolower((string) config('studio.domain'));

        $this->validate([
            'name' => ['required', 'string', 'max:253', 'regex:'.Domain::PATTERN, Rule::unique('domains', 'name'), function (string $attribute, mixed $value, \Closure $fail) use ($studio): void {
                if ($value === $studio || str_ends_with((string) $value, '.'.$studio)) {
                    $fail(__('Hosts under :domain are Studio\'s already: add another domain.', ['domain' => $studio]));
                }
            }],
        ], [
            'name.regex' => __('A domain name, like client.com or staging.client.com, without https://.'),
            'name.unique' => __('This domain is in the list already.'),
        ]);

        $domain = Domain::query()->create([
            'name' => $this->name,
            'wildcard' => $this->wildcard,
            'status' => DomainStatus::Pending,
            'user_id' => auth()->id(),
        ]);
        $dns->check($domain);
        Log::notice("Domain {$domain->name} added by ".auth()->user()->email.": {$domain->status->value}");

        $this->reset(['name']);
        $this->wildcard = true;

        Flux::toast(variant: $domain->isVerified() ? 'success' : 'warning', text: $domain->isVerified()
            ? __(':name points here: it is ready for the projects.', ['name' => $domain->name])
            : __(':name added. Create its DNS records, then check it again.', ['name' => $domain->name]));
    }

    public function check(string $uuid, DomainDns $dns): void
    {
        $domain = $dns->check($this->domain($uuid));

        Flux::toast(variant: $domain->isVerified() ? 'success' : 'warning', text: $domain->isVerified()
            ? __(':name points here.', ['name' => $domain->name])
            : (string) $domain->last_error);
    }

    /**
     * Online: the sites and previews at this domain open to anybody, without the Studio login
     * (an address list still narrows them, the /larapilot dashboard still wants the login), and
     * never show a debug page. The projects that use it are set up again on the server.
     */
    public function toggleOnline(string $uuid): void
    {
        $domain = $this->domain($uuid);
        $domain->update(['online' => ! $domain->online]);
        Log::notice("Domain {$domain->name} ".($domain->online ? 'online' : 'not online').' by '.auth()->user()->email);

        $domain->projects()->each(fn ($project) => ConfigureProjectSite::dispatch($project));

        Flux::toast(variant: $domain->online ? 'warning' : 'success', text: $domain->online
            ? __(':name is online: its sites and previews open to anybody, without the Studio login.', ['name' => $domain->name])
            : __(':name is not online: its sites and previews want the Studio login again.', ['name' => $domain->name]));
    }

    public function remove(string $uuid): void
    {
        $domain = $this->domain($uuid);

        if ($domain->inUse()) {
            Flux::toast(variant: 'danger', text: __('Projects use :name: move them to another address in their settings first.', ['name' => $domain->name]));

            return;
        }

        $domain->delete();
        Log::notice("Domain {$domain->name} removed by ".auth()->user()->email);
        Flux::toast(variant: 'success', text: __(':name removed. Caddy gets no certificate for it any more.', ['name' => $domain->name]));
    }

    private function domain(string $uuid): Domain
    {
        return Domain::query()->where('uuid', $uuid)->firstOrFail();
    }

    public function render(): View
    {
        $addresses = app(DomainDns::class)->serverAddresses();

        return view('livewire.admin.domains', [
            'domains' => Domain::query()->with(['sites:id,uuid,name,slug,site_domain_id', 'previewProjects:id,uuid,name,slug,preview_domain_id'])->orderBy('name')->get(),
            'ip' => $addresses[0] ?? __('this server\'s IP'),
            'studioDomain' => (string) config('studio.domain'),
        ]);
    }
}
