{{-- How to create the token for the installation's git provider; $admin adds what creating repositories and deploying sites needs. --}}
<ol class="list-decimal space-y-1 ps-5">
    @switch($git->key())
        @case('gitlab')
            <li>{{ __('On :url: avatar → Edit profile → Access tokens → Add new token.', ['url' => $git->baseUrl()]) }}</li>
            <li>{{ __('Scope: api. It covers clone and push, merge requests through glab and Larapilot, and for administrators the new repositories and the push webhook.') }}</li>
            <li>{{ __('Expiration: GitLab asks for a date. Replace the token here before it expires.') }}</li>
            @if ($admin)
                <li>{{ __('Groups: a new repository goes to your namespace or to a group or subgroup where you are at least Developer and project creation is allowed. Registering the webhook needs the Maintainer role on the repository.') }}</li>
            @endif
            <li>{{ __('Copy the token here. It starts with glpat-.') }}</li>
            @break
        @case('bitbucket')
            <li>{{ __('id.atlassian.com → Account settings → Security → Create and manage API tokens → Create API token with scopes → Bitbucket.') }}</li>
            <li>{{ __('Scopes: read:user, read:workspace, read:repository, write:repository, read:pullrequest and write:pullrequest (each ending in :bitbucket).') }}</li>
            @if ($admin)
                <li>{{ __('Administrators also add read:project, admin:repository, read:webhook, write:webhook and delete:webhook: they create repositories and register the push webhook.') }}</li>
                <li>{{ __('Workspaces: the new-repository form lists every workspace you belong to and its projects. Without a project, Bitbucket puts the repository in the oldest project of the workspace.') }}</li>
            @endif
            <li>{{ __('Copy the token here. App passwords are retired; git signs in with this token.') }}</li>
            @break
        @case('azure')
            <li>{{ __(':url → User settings → Personal access tokens → New Token.', ['url' => $git->baseUrl()]) }}</li>
            <li>{{ __('Organization: :organization. Scopes: Custom defined → Code → Read & write.', ['organization' => $git->organization()]) }}</li>
            @if ($admin)
                <li>{{ __('Administrators choose Code → Read, write & manage and Project and Team → Read: they create repositories in the projects of the organization. Registering the push service hook also needs the Project Administrator role.') }}</li>
            @endif
            <li>{{ __('Expiration: at most a year, or less if the organization says so. Replace the token here before it expires.') }}</li>
            <li>{{ __('Copy the token here.') }}</li>
            @break
        @default
            <li>{{ __('GitHub → Settings → Developer settings → Personal access tokens → Fine-grained tokens → Generate new token.') }}</li>
            <li>{{ __('Resource owner: the organization that owns the projects. A fine-grained token reaches one owner, and the organization may have to approve it.') }}</li>
            <li>{{ __('Repository access: the repositories of the projects you work on, or all of them. Permissions: Contents → Read and write · Pull requests → Read and write · Metadata → Read-only.') }}</li>
            @if ($admin)
                <li>{{ __('Administrators also add Administration → Read and write, to create repositories, and Webhooks → Read and write, to deploy sites.') }}</li>
            @endif
            <li>{{ __('Copy the token here. It starts with github_pat_. Projects in several organizations: a classic token with repo, read:org and admin:repo_hook works too.') }}</li>
    @endswitch
</ol>
<p class="mt-2"><a href="https://studio.web.ap.it/#setup-git-{{ $git->key() }}" target="_blank" rel="noopener" class="underline">{{ __('Organizations, scopes and expiry in the documentation') }}</a></p>
