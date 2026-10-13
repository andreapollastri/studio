# Screenshots for the docs

The images in `docs/img/` come from a real Studio with the demo data, captured through headless
Chrome over the DevTools protocol. One command does everything:

```bash
npm run build                      # the assets the pages load
tools/screenshots/run.sh           # → docs/img/*.png
```

`run.sh` never touches your database. In a throwaway folder it:

1. migrates and seeds a SQLite database of its own with `DemoSeeder` (an agency, five projects,
   roles per project, a service account, a Droplet attached to Shop Acme, live conversations);
2. starts the fake staging site and preview (`fake-staging/`, port 8766);
3. builds a throwaway workspace (`make-demo-workspace.sh`) and runs the bridge on it (port 4455), so
   the Changes, History, Files and Terminal tabs show real git data;
4. serves Studio three times on the same database through `router.php` (ports 8767, 8768, 8769):
   the local driver for most pages, the native driver with `tests/fixtures/fake-studio-admin` and a
   stable or a beta updater state for the System page;
5. fills the uuids into `jobs.json` and runs `shots.mjs`, which logs in through the real form as
   Maria (PM), Luca (developer here, PM there), Giulia (client) and Andrea (administrator),
   emulates a 1440 px light-mode viewport at 2×, and flags any horizontal overflow.

`router.php` serves Studio as `public/index.php` does, with `Http::fake(fake-apis.php)` registered
once the app is booted: GitHub, Azure DevOps and DigitalOcean answer with the demo agency's pull
requests, issues, runs, releases and Droplets. Nothing in the app knows about it; requests to the
bridge and to the fake staging site go out as usual.

Needs Google Chrome, Node 20+, Python 3 and `sqlite3`. The ports above (and 9333, Chrome's) must be
free: the script stops when one is taken.
