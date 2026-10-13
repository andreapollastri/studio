<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Infrastructure\Action;
use App\Infrastructure\Connector;
use App\Infrastructure\Connectors;
use App\Infrastructure\Section;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\ProjectResourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing at a provider an administrator attached to a project: a droplet, a
 * Cloudflare zone, a Cipi app, a Boogle project. The project's members see it in
 * the project's Infrastructure tab when their role is among those that see it,
 * and run on it only the actions ticked for the roles that act on it. The child
 * lists it opens (the records of a zone, the exceptions of a project) come with
 * it: seeing them is seeing it, changing them is an action like any other.
 *
 * Action keys: a row action of the resource's own list by its key ("reboot"); in a
 * child list, "<section>.<key>" for a row action and "<section>.@<key>" for a list
 * action ("records.edit", "records.@create").
 *
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property int $connector_account_id
 * @property string $section
 * @property string $resource_id
 * @property string $label
 * @property array<string, string>|null $scope
 * @property list<string> $view_roles
 * @property list<string> $act_roles
 * @property list<string> $actions
 * @property int|null $added_by
 * @property-read Project $project
 * @property-read ConnectorAccount $account
 */
#[Fillable(['project_id', 'connector_account_id', 'section', 'resource_id', 'label', 'scope', 'view_roles', 'act_roles', 'actions', 'added_by'])]
class ProjectResource extends Model
{
    /** @use HasFactory<ProjectResourceFactory> */
    use HasFactory;

    use HasPublicUuid;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'view_roles' => 'array',
            'act_roles' => 'array',
            'actions' => 'array',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ConnectorAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ConnectorAccount::class, 'connector_account_id');
    }

    public function connector(): ?Connector
    {
        return Connectors::find($this->account->connector);
    }

    public function definition(): ?Section
    {
        return $this->connector()?->section($this->section);
    }

    /**
     * The resources of the projects this person may open whose roles let them see them.
     *
     * @param  Builder<ProjectResource>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $roles = [];

        foreach (UserRole::projectRoles() as $role) {
            if (($ids = $user->projectIdsAs($role)) !== []) {
                $roles[$role->value] = $ids;
            }
        }

        if ($roles === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $query) use ($roles) {
            foreach ($roles as $role => $ids) {
                $query->orWhere(fn (Builder $q) => $q->whereIn('project_id', $ids)
                    ->where(fn (Builder $q) => $q->whereJsonContains('view_roles', $role)->orWhereJsonContains('act_roles', $role)));
            }
        });
    }

    public function isVisibleTo(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $role = $user->roleIn($this->project);

        return $role !== null && (in_array($role->value, $this->view_roles, true) || in_array($role->value, $this->act_roles, true));
    }

    /** Whether this person may run this action (see the keys above) on the resource. */
    public function allows(User $user, string $action): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $role = $user->roleIn($this->project);

        return $role !== null && in_array($role->value, $this->act_roles, true) && in_array($action, $this->actions, true);
    }

    /**
     * Every action an administrator may hand over on a resource of this section: the
     * row actions of its list and, in each child list it opens, the row and list
     * actions there. Opening a child list is seeing the resource, not an action.
     *
     * @return array<string, array{label: string, danger: bool, list: string}> key => what it is
     */
    public static function actionsFor(Connector $connector, Section $section): array
    {
        $actions = [];
        $own = fn (Action $action) => $action->opens === null && $action->run !== null;

        foreach (array_filter($section->actions, $own) as $action) {
            $actions[$action->key] = ['label' => $action->label, 'danger' => $action->danger, 'list' => $section->label];
        }

        foreach (self::children($connector, $section) as $child) {
            foreach (array_filter($child->actions, $own) as $action) {
                $actions[$child->key.'.'.$action->key] = ['label' => $action->label, 'danger' => $action->danger, 'list' => $child->label];
            }

            foreach (array_filter($child->toolbar, $own) as $action) {
                $actions[$child->key.'.@'.$action->key] = ['label' => $action->label, 'danger' => $action->danger, 'list' => $child->label];
            }
        }

        return $actions;
    }

    /**
     * The child lists a resource of this section opens, and theirs in turn.
     *
     * @return list<Section>
     */
    public static function children(Connector $connector, Section $section): array
    {
        $found = [];
        $queue = [$section];

        while ($current = array_shift($queue)) {
            foreach ($current->actions as $action) {
                $child = $action->opens !== null ? $connector->section($action->opens) : null;

                if ($child !== null && $child->key !== $section->key && ! isset($found[$child->key])) {
                    $found[$child->key] = $child;
                    $queue[] = $child;
                }
            }
        }

        return array_values($found);
    }
}
