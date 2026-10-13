{{-- How to create the token of a git account: what the workspaces need (clone, push, pull requests) and what this page does; $admin adds what creating repositories and deploying sites needs. --}}
<details class="rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700">
    <summary class="cursor-pointer font-medium">{{ __('How to create the token') }}</summary>
    <ol class="mt-2 list-decimal space-y-1 ps-5 text-zinc-600 dark:text-zinc-300">
        @switch($forge)
            @case('gitlab')
                <li>{{ __('On GitLab: avatar → Edit profile → Access tokens → Add new token.') }}</li>
                <li>{{ __('Scope: api. It covers clone and push, merge requests, issues and pipelines, and for administrators new repositories and the push webhook.') }}</li>
                <li>{{ __('Expiration: GitLab asks for a date. Replace the token here before it expires.') }}</li>
                <li>{{ __('Copy the token here, with the address of your GitLab if it is not gitlab.com. It starts with glpat-.') }}</li>
                @break
            @case('bitbucket')
                <li>{{ __('id.atlassian.com → Account settings → Security → Create and manage API tokens → Create API token with scopes → Bitbucket.') }}</li>
                <li>{{ __('Scopes (each ending in :bitbucket): read:user, read:workspace, read:repository, write:repository, read:pullrequest, write:pullrequest, read:issue, write:issue, read:pipeline, write:pipeline.') }}</li>
                @if ($admin)
                    <li>{{ __('Administrators also add read:project, admin:repository, delete:repository, read:webhook, write:webhook and delete:webhook: they create repositories and register the push webhook.') }}</li>
                @endif
                <li>{{ __('Copy the token here. App passwords are retired; git signs in with this token.') }}</li>
                @break
            @case('azure')
                <li>{{ __('https://dev.azure.com/<organization> → User settings → Personal access tokens → New Token, for that organization.') }}</li>
                <li>{{ __('Scopes: Custom defined → Code: Read, write & manage · Work Items: Read & write · Build: Read & execute · Project and Team: Read.') }}</li>
                @if ($admin)
                    <li>{{ __('Registering the push service hook of a project site also needs the Project Administrator role.') }}</li>
                @endif
                <li>{{ __('Expiration: at most a year, or less if the organization says so. Replace the token here before it expires.') }}</li>
                <li>{{ __('Copy the token here, with the address of the organization. Another organization is another account.') }}</li>
                @break
            @default
                <li>{{ __('GitHub → Settings → Developer settings → Personal access tokens → Tokens (classic) → Generate new token.') }}</li>
                <li>{{ __('Scopes: repo, workflow, read:org, user:email and notifications.') }}</li>
                @if ($admin)
                    <li>{{ __('Administrators also add admin:repo_hook, to deploy sites, and delete_repo to delete repositories from Studio.') }}</li>
                @endif
                <li>{{ __('A fine-grained token works too, for one owner (the organization may have to approve it): Contents, Pull requests, Issues and Actions → Read and write, Metadata → Read-only; administrators add Administration and Webhooks → Read and write. GitHub does not let it read notifications.') }}</li>
                <li>{{ __('Organizations with SAML single sign-on: Configure SSO next to the token and authorise it.') }}</li>
        @endswitch
    </ol>
</details>
