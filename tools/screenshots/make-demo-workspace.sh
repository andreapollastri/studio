#!/usr/bin/env bash
# A throwaway Laravel-ish repository with a branch, a diff and a custom skill,
# so the bridge has something to show in the Changes tab and the skill buttons.
set -euo pipefail
W=${1:?usage: make-demo-workspace.sh <dir>}
rm -rf "$W"; mkdir -p "$W/app/Models" "$W/app/Http/Controllers" "$W/tests/Feature" "$W/.larapilot/skills/acme-predeploy-gate"
cd "$W" && git init -q -b develop
cat > app/Models/Order.php <<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = ['number', 'customer_id', 'total', 'status'];
}
PHP
cat > app/Http/Controllers/OrderController.php <<'PHP'
<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = Order::query()
            ->when($request->input('number'), fn ($q, $n) => $q->where('number', $n))
            ->latest()
            ->paginate(25);

        return view('orders.index', compact('orders'));
    }
}
PHP
cat > .larapilot/skills/acme-predeploy-gate/SKILL.md <<'MD'
---
name: acme-predeploy-gate
description: "Pre-deploy gate before a production release — GO or NO-GO."
---
# Acme — Pre-deploy gate
MD
printf 'vendor/\nnode_modules/\n.env\n' > .gitignore
G="git -c user.name=demo -c user.email=demo@example.test"
git add -A && $G commit -q -m "Orders list with search by number"
# a little history, so the History view has a graph to draw: a release, a fix on a branch, its merge
GIT_AUTHOR_DATE="2026-09-20T10:00:00+02:00" GIT_COMMITTER_DATE="2026-09-20T10:00:00+02:00" $G commit -q --amend --no-edit
printf 'v1.3.0\n' > VERSION && git add -A && GIT_AUTHOR_DATE="2026-09-24T09:30:00+02:00" GIT_COMMITTER_DATE="2026-09-24T09:30:00+02:00" $G commit -q -m "Release 1.3"
git tag v1.3.0
git checkout -q -b fix/returns-badge
printf '/* returns badge */\n' > app/returns.css && git add -A && GIT_AUTHOR_DATE="2026-09-26T15:10:00+02:00" GIT_COMMITTER_DATE="2026-09-26T15:10:00+02:00" $G -c user.name="Luca Bianchi" -c user.email=luca@agency.test commit -q -m "Fix the returns badge colour"
git checkout -q develop
printf 'fix: typo in checkout\n' > CHANGELOG.md && git add -A && GIT_AUTHOR_DATE="2026-09-27T11:00:00+02:00" GIT_COMMITTER_DATE="2026-09-27T11:00:00+02:00" $G -c user.name="Maria Rossi" -c user.email=maria@agency.test commit -q -m "Fix a typo on the checkout page"
GIT_AUTHOR_DATE="2026-09-29T17:45:00+02:00" GIT_COMMITTER_DATE="2026-09-29T17:45:00+02:00" $G -c user.name="Maria Rossi" -c user.email=maria@agency.test merge -q --no-ff -m "Merge fix/returns-badge into develop" fix/returns-badge
git branch -q -D fix/returns-badge
git checkout -q -b feature/US-014-date-filter
cat > app/Models/Order.php <<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = ['number', 'customer_id', 'total', 'status'];

    /** Orders created inside a closed date range, both ends included. */
    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('created_at', '<=', $to));
    }
}
PHP
cat > app/Http/Controllers/OrderController.php <<'PHP'
<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'number' => ['nullable', 'string', 'max:20'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $orders = Order::query()
            ->when($validated['number'] ?? null, fn ($q, $n) => $q->where('number', $n))
            ->between($validated['from'] ?? null, $validated['to'] ?? null)
            ->latest()
            ->paginate(25);

        return view('orders.index', compact('orders'));
    }
}
PHP
cat > tests/Feature/OrderFilterTest.php <<'PHP'
<?php

use App\Models\Order;

test('orders can be filtered by a date range', function () {
    Order::factory()->create(['created_at' => '2026-09-10']);
    Order::factory()->create(['created_at' => '2026-10-02']);

    $this->get('/orders?from=2026-09-01&to=2026-09-30')
        ->assertOk()
        ->assertSee('2026-09-10')
        ->assertDontSee('2026-10-02');
});
PHP
echo "demo workspace at $W"
