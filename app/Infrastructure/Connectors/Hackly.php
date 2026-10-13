<?php

namespace App\Infrastructure\Connectors;

use App\Infrastructure\Action;
use App\Infrastructure\ApiClient;
use App\Infrastructure\Column;
use App\Infrastructure\Connector;
use App\Infrastructure\Field;
use App\Infrastructure\Outcome;
use App\Infrastructure\Section;

/**
 * Hackly (https://hackly.web.ap.it), the security scanner, one workspace per
 * account through its API v1: posture, targets with their DNS verification,
 * repositories, scans, findings and their triage, penetration tests, GitHub
 * credentials and compliance.
 */
final class Hackly extends Connector
{
    private const PROFILES = ['quick' => 'Quick', 'standard' => 'Standard', 'deep' => 'Deep'];

    private const TRIAGE = ['open' => 'Open', 'ack' => 'Acknowledged', 'fixed' => 'Fixed', 'false_positive' => 'False positive'];

    private const SCAN = ['completed' => 'green', 'running' => 'blue', 'queued' => 'yellow', 'pending' => 'yellow', 'failed' => 'red', 'cancelled' => 'zinc'];

    public function key(): string
    {
        return 'hackly';
    }

    public function label(): string
    {
        return 'Hackly';
    }

    public function description(): string
    {
        return __('Security posture, targets and their DNS verification, repositories, scans, findings and triage, penetration tests and compliance, one workspace per account.');
    }

    public function icon(): string
    {
        return 'finger-print';
    }

    public function docs(): string
    {
        return 'https://hackly.web.ap.it/';
    }

    public function fields(): array
    {
        return [
            Field::text('url', __('Address'))->required()->placeholder('https://hackly.example.com'),
            Field::password('token', __('API token'))->required()->placeholder('hk_…')
                ->help(__('Profile → API tokens on Hackly, with the write ability (read only lets Studio look, not act).')),
        ];
    }

    public function client(array $credentials): ApiClient
    {
        return new ApiClient(self::instanceUrl($credentials['url'] ?? '').'/api/v1', ['Authorization' => 'Bearer '.self::string($credentials, 'token')]);
    }

    public function verify(object $api): string
    {
        /** @var ApiClient $api */
        $me = $api->get('/me');

        return 'ok · '.data_get($me, 'data.email', '').' · '.data_get($me, 'data.token.ability_label', '');
    }

    public function sections(): array
    {
        return [
            $this->posture(),
            $this->findings(),
            $this->targets(),
            $this->repositories(),
            $this->scans('scans', __('Target scans')),
            $this->scans('repo-scans', __('Repository scans')),
            $this->pentests(),
            $this->credentials(),
        ];
    }

    /**
     * Every page: Laravel resources with links.next.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private static function every(ApiClient $api, string $path, array $query = [], int $maxPages = 10): array
    {
        /** @var list<array<string, mixed>> */
        return $api->all(
            $path,
            ['per_page' => 100, ...array_filter($query, fn ($value) => $value !== null && $value !== '')],
            fn (array $answer) => array_values((array) ($answer['data'] ?? [])),
            fn (array $answer) => is_string($next = data_get($answer, 'links.next')) ? $next : null,
            $maxPages,
        );
    }

    /** @return array<string, string> */
    private static function targetOptions(ApiClient $api): array
    {
        return collect(self::every($api, '/targets'))->mapWithKeys(fn ($t) => [(string) $t['id'] => (string) $t['domain']])->all();
    }

    /** @return array<string, string> */
    private static function repositoryOptions(ApiClient $api): array
    {
        return collect(self::every($api, '/repositories'))->mapWithKeys(fn ($r) => [(string) $r['id'] => (string) $r['full_name']])->all();
    }

    private function posture(): Section
    {
        return Section::info('posture', __('Posture'), function (ApiClient $api) {
            $posture = (array) ($api->get('/posture')['data'] ?? []);
            $compliance = (array) ($api->get('/compliance')['data'] ?? []);
            $health = (array) ($api->get('/scanner-health')['data'] ?? []);

            return [
                __('Posture') => $posture,
                __('Compliance') => $compliance,
                __('Scanners') => $health,
            ];
        })->toolbar([
            Action::make('digest', __('Weekly digest'))->icon('newspaper')
                ->run(fn (ApiClient $api) => Outcome::show(__('Weekly digest'), data_get($api->get('/digest'), 'data.text') ?? data_get($api->get('/digest'), 'data', []))),
            Action::make('recheck', __('Re-probe scanners'))->icon('arrow-path')
                ->run(fn (ApiClient $api) => Outcome::show(__('Scanners'), $api->post('/scanner-health/recheck')['data'] ?? [])),
        ]);
    }

    private function findings(): Section
    {
        return Section::make('findings', __('Findings'))
            ->icon('exclamation-triangle')
            ->filters([
                Field::select('status', __('Status'), ['' => __('Any'), ...self::TRIAGE])->default('open'),
                Field::select('severity', __('Severity'), ['' => __('Any'), 'high' => __('High'), 'medium' => __('Medium'), 'low' => __('Low')]),
            ])
            ->list(fn (ApiClient $api, array $scope) => self::every($api, '/findings', ['status' => $scope['status'] ?? null, 'severity' => $scope['severity'] ?? null]))
            ->columns([
                Column::make('severity', __('Severity'))->badge(['high' => 'red', 'medium' => 'yellow', 'low' => 'blue']),
                Column::make('title', __('Finding')),
                Column::make('subject', __('On'))->mono(),
                Column::make('category_label', __('Category')),
                Column::make('status', __('Status'))->badge(['open' => 'red', 'ack' => 'yellow', 'fixed' => 'green', 'false_positive' => 'zinc']),
                Column::make('created_at', __('Found'))->date(),
            ])
            ->actions([
                Action::make('details', __('Details'))->icon('eye')->run(fn (ApiClient $api, array $row) => Outcome::show($row['title'], $api->get('/findings/'.$row['id'])['data'] ?? $row)),
                Action::make('markdown', __('As Markdown'))->icon('document-text')->run(fn (ApiClient $api, array $row) => Outcome::show($row['title'], $api->text('/findings/'.$row['id'].'/markdown'))),
                Action::make('prompt', __('Fix prompt for the agent'))->icon('sparkles')->run(fn (ApiClient $api, array $row) => Outcome::show($row['title'], $api->text('/findings/'.$row['id'].'/prompt'))),
                Action::make('triage', __('Triage'))->icon('check-circle')->fields([
                    Field::select('status', __('Status'), self::TRIAGE)->required()->default(fn ($row) => $row['status'] ?? 'ack'),
                    Field::textarea('note', __('Note'))->help(__('Required for a false positive')),
                ])->run(fn (ApiClient $api, array $row, array $data) => tap(__('Finding updated.'), fn () => $api->patch('/findings/'.$row['id'], array_filter($data)))),
                Action::make('share', __('Share a read-only link'))->icon('share')->when(fn ($row) => empty($row['shared']))
                    ->run(fn (ApiClient $api, array $row) => Outcome::show($row['title'], data_get($api->post('/findings/'.$row['id'].'/share'), 'data.share_url'), __('Link created.'))),
                Action::make('unshare', __('Stop sharing'))->icon('link-slash')->when(fn ($row) => ! empty($row['shared']))
                    ->run(fn (ApiClient $api, array $row) => tap(__('The link no longer works.'), fn () => $api->delete('/findings/'.$row['id'].'/share'))),
            ]);
    }

    private function targets(): Section
    {
        $verification = fn (array $target) => array_filter([
            'domain' => $target['domain'] ?? null,
            'verified' => $target['verified'] ?? null,
            'record' => $target['verification'] ?? null,
            'scanner_ip' => $target['scanner_ip'] ?? null,
        ], fn ($value) => $value !== null);

        return Section::make('targets', __('Targets'))
            ->icon('globe-alt')
            ->list(fn (ApiClient $api) => self::every($api, '/targets'))
            ->columns([
                Column::make('domain', __('Domain'))->mono(),
                Column::make('verified', __('Verified'))->bool(),
                Column::make('status', __('Status'))->badge(['active' => 'green', 'paused' => 'zinc']),
                Column::make('open_findings', __('Open findings')),
                Column::make('latest_scan.status', __('Last scan'))->badge(self::SCAN),
            ])
            ->create(__('Add a target'), [
                Field::text('domain', __('Domain'))->required()->placeholder('example.com'),
                Field::textarea('authorization_note', __('Authorization note'))->help(__('Who allowed scanning it, and when')),
            ], fn (ApiClient $api, array $row, array $data) => Outcome::show(__('Publish this TXT record'), $verification((array) ($api->post('/targets', array_filter($data))['data'] ?? [])), __('Target added: verify it with DNS before scanning.')))
            ->actions([
                Action::make('scan', __('Scan'))->icon('play')->when(fn ($row) => ! empty($row['verified']) && ($row['status'] ?? '') === 'active')->fields([
                    Field::select('profile', __('Profile'), self::PROFILES)->required()->default('standard'),
                    Field::checkbox('include_repositories', __('Scan the linked repositories too')),
                ])->run(fn (ApiClient $api, array $row, array $data) => tap(__('Scan queued.'), fn () => $api->post('/targets/'.$row['id'].'/scans', $data))),
                Action::make('verification', __('DNS verification'))->icon('finger-print')->when(fn ($row) => empty($row['verified']))
                    ->run(fn (ApiClient $api, array $row) => Outcome::show(__('Publish this TXT record'), $verification((array) ($api->post('/targets/'.$row['id'].'/verification')['data'] ?? $row)))),
                Action::make('check', __('Check the TXT record'))->icon('check-badge')->when(fn ($row) => empty($row['verified']))
                    ->run(fn (ApiClient $api, array $row) => data_get($api->post('/targets/'.$row['id'].'/verification/check'), 'data.verified') ? __('Verified.') : __('Not verified yet.')),
                Action::make('repositories', __('Linked repositories'))->icon('link')->fields([
                    Field::multiselect('repository_ids', __('Repositories'), fn (ApiClient $api) => self::repositoryOptions($api))->default(fn ($row) => array_column((array) ($row['repositories'] ?? []), 'id')),
                ])->run(fn (ApiClient $api, array $row, array $data) => tap(__('Links saved.'), fn () => $api->put('/targets/'.$row['id'].'/repositories', $data))),
                Action::make('pause', __('Pause'))->icon('pause')->when(fn ($row) => ($row['status'] ?? '') === 'active')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Paused.'), fn () => $api->post('/targets/'.$row['id'].'/pause'))),
                Action::make('resume', __('Resume'))->icon('play')->when(fn ($row) => ($row['status'] ?? '') === 'paused')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Resumed.'), fn () => $api->post('/targets/'.$row['id'].'/resume'))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the target and its scans?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Target deleted.'), fn () => $api->delete('/targets/'.$row['id']))),
            ]);
    }

    private function repositories(): Section
    {
        return Section::make('repositories', __('Repositories'))
            ->icon('code-bracket-square')
            ->list(fn (ApiClient $api) => self::every($api, '/repositories'))
            ->columns([
                Column::make('full_name', __('Repository'))->mono(),
                Column::make('default_branch', __('Branch'))->mono(),
                Column::make('language', __('Language')),
                Column::make('status', __('Status'))->badge(['active' => 'green', 'paused' => 'zinc']),
                Column::make('open_findings', __('Open findings')),
                Column::make('last_scanned_at', __('Last scan'))->date(),
            ])
            ->create(__('Add a repository'), [
                Field::text('full_name', __('Repository'))->required()->placeholder('owner/repo'),
                Field::select('github_credential_id', __('GitHub token'), fn (ApiClient $api) => collect(self::every($api, '/github-credentials'))->mapWithKeys(fn ($c) => [(string) $c['id'] => (string) $c['name']])->all())->required(),
                Field::multiselect('target_ids', __('Linked targets'), fn (ApiClient $api) => self::targetOptions($api)),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Repository added.'), fn () => $api->post('/repositories', array_filter($data))))
            ->actions([
                Action::make('scan', __('Scan'))->icon('play')->when(fn ($row) => ($row['status'] ?? '') === 'active')->fields([
                    Field::select('profile', __('Profile'), self::PROFILES)->required()->default('standard'),
                    Field::checkbox('include_targets', __('Scan the linked targets too')),
                ])->run(fn (ApiClient $api, array $row, array $data) => tap(__('Scan queued.'), fn () => $api->post('/repositories/'.$row['id'].'/scans', $data))),
                Action::make('refresh', __('Refresh from GitHub'))->icon('arrow-path')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Refreshed.'), fn () => $api->post('/repositories/'.$row['id'].'/refresh'))),
                Action::make('targets', __('Linked targets'))->icon('link')->fields([
                    Field::multiselect('target_ids', __('Targets'), fn (ApiClient $api) => self::targetOptions($api))->default(fn ($row) => array_column((array) ($row['targets'] ?? []), 'id')),
                ])->run(fn (ApiClient $api, array $row, array $data) => tap(__('Links saved.'), fn () => $api->put('/repositories/'.$row['id'].'/targets', $data))),
                Action::make('pause', __('Pause'))->icon('pause')->when(fn ($row) => ($row['status'] ?? '') === 'active')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Paused.'), fn () => $api->post('/repositories/'.$row['id'].'/pause'))),
                Action::make('resume', __('Resume'))->icon('play')->when(fn ($row) => ($row['status'] ?? '') === 'paused')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Resumed.'), fn () => $api->post('/repositories/'.$row['id'].'/resume'))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the repository and its scans?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Repository deleted.'), fn () => $api->delete('/repositories/'.$row['id']))),
            ]);
    }

    private function scans(string $key, string $label): Section
    {
        $path = '/'.$key;

        return Section::make($key, $label)
            ->icon('magnifying-glass-circle')
            ->list(fn (ApiClient $api) => self::every($api, $path, maxPages: 3))
            ->columns([
                Column::make('subject', __('On'))->mono()->using(fn ($row) => data_get($row, 'asset.domain') ?? data_get($row, 'repository.full_name') ?? ''),
                Column::make('profile_label', __('Profile')),
                Column::make('status', __('Status'))->badge(self::SCAN),
                Column::make('progress_percent', __('Progress'))->using(fn ($row) => isset($row['progress_percent']) ? $row['progress_percent'].'%' : ''),
                Column::make('findings', __('Findings'))->using(fn ($row) => collect((array) ($row['findings_summary'] ?? []))->map(fn ($count, $severity) => $severity.' '.(is_scalar($count) ? $count : ''))->implode(', ')),
                Column::make('created_at', __('Started'))->date(),
            ])
            ->actions([
                Action::make('details', __('Details'))->icon('eye')->run(fn (ApiClient $api, array $row) => Outcome::show($label, $api->get($path.'/'.$row['id'])['data'] ?? $row)),
                Action::make('report', __('Report (Markdown)'))->icon('document-text')->when(fn ($row) => ($row['status'] ?? '') === 'completed')
                    ->run(fn (ApiClient $api, array $row) => Outcome::show($label, $api->text($path.'/'.$row['id'].'/report/markdown'))),
                Action::make('cancel', __('Cancel'))->icon('x-circle')->confirm(__('Cancel the scan?'))->when(fn ($row) => in_array($row['status'] ?? '', ['queued', 'pending', 'running'], true))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Scan cancelled.'), fn () => $api->post($path.'/'.$row['id'].'/cancel'))),
                Action::make('rerun', __('Run again'))->icon('arrow-path')->when(fn ($row) => ! in_array($row['status'] ?? '', ['queued', 'pending', 'running'], true))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Scan queued.'), fn () => $api->post($path.'/'.$row['id'].'/rerun'))),
            ]);
    }

    private function pentests(): Section
    {
        return Section::make('penetration-tests', __('Penetration tests'))
            ->icon('bolt')
            ->list(fn (ApiClient $api) => self::every($api, '/penetration-tests'))
            ->columns([
                Column::make('label', __('Engagement')),
                Column::make('target.domain', __('Target'))->mono(),
                Column::make('authorization.state', __('Authorization')),
                Column::make('authorization.ready', __('Ready'))->bool(),
                Column::make('scanner_ip', __('Scanner IP'))->mono(),
                Column::make('created_at', __('Created'))->date(),
            ])
            ->actions([
                Action::make('details', __('Details'))->icon('eye')->run(fn (ApiClient $api, array $row) => Outcome::show((string) ($row['label'] ?? __('Penetration test')), $api->get('/penetration-tests/'.$row['id'])['data'] ?? $row)),
                Action::make('authorize', __('New DNS authorization'))->icon('finger-print')->when(fn ($row) => ! data_get($row, 'authorization.standing'))
                    ->run(fn (ApiClient $api, array $row) => Outcome::show(__('Publish this TXT record'), $api->post('/penetration-tests/'.$row['id'].'/authorization')['data'] ?? [])),
                Action::make('check', __('Check the TXT record'))->icon('check-badge')->when(fn ($row) => ! data_get($row, 'authorization.ready'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Authorization ready.'), fn () => $api->post('/penetration-tests/'.$row['id'].'/authorization/check'))),
                Action::make('run', __('Start'))->icon('play')->confirm(__('Start the penetration test now? Only on targets you are authorized to test.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Penetration test queued.'), fn () => $api->post('/penetration-tests/'.$row['id'].'/run'))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the engagement?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Deleted.'), fn () => $api->delete('/penetration-tests/'.$row['id']))),
            ]);
    }

    private function credentials(): Section
    {
        return Section::make('github-credentials', __('GitHub tokens'))
            ->icon('key')
            ->list(fn (ApiClient $api) => self::every($api, '/github-credentials'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('github_login', __('GitHub login'))->mono(),
                Column::make('validation_status', __('Status'))->badge(['valid' => 'green', 'invalid' => 'red', '*' => 'zinc']),
                Column::make('token_hint', __('Token'))->mono(),
                Column::make('last_validated_at', __('Checked'))->date(),
            ])
            ->create(__('Add a token'), [
                Field::text('name', __('Name'))->required(),
                Field::password('token', __('GitHub token'))->required()->help(__('Read access to the repositories to scan')),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Token stored encrypted at Hackly.'), fn () => $api->post('/github-credentials', $data)))
            ->actions([
                Action::make('validate', __('Check'))->icon('check-badge')
                    ->run(fn (ApiClient $api, array $row) => Outcome::show($row['name'], $api->post('/github-credentials/'.$row['id'].'/validate')['data'] ?? [])),
                Action::make('replace', __('Replace the token'))->icon('pencil')->fields([Field::password('token', __('New GitHub token'))->required()])
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Token replaced.'), fn () => $api->patch('/github-credentials/'.$row['id'], $data))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the token? Repositories must not use it.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Token deleted.'), fn () => $api->delete('/github-credentials/'.$row['id']))),
            ]);
    }
}
