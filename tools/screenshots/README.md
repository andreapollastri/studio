# Screenshots for the docs

The images in `docs/img/` come from a real Studio with demo data, captured through headless
Chrome over the DevTools protocol. Nothing is mocked in the app: a fake staging site answers the
Larapilot API, a fake preview page stands in for the workspace app, and the bridge runs against a
throwaway repository with a diff and a custom skill.

```bash
# 1. demo data, pointing staging and preview at the fake server below
DEMO_STAGING_URL=http://127.0.0.1:8766 DEMO_PREVIEW_URL=http://127.0.0.1:8766/preview/ \
  php artisan migrate:fresh --seed --seeder=DemoSeeder

# 2. fake staging + preview
(cd tools/screenshots/fake-staging && python3 -m http.server 8766 --bind 127.0.0.1) &

# 3. a throwaway workspace and the bridge on it (the token matches .env's STUDIO_LOCAL_BRIDGE_TOKEN)
tools/screenshots/make-demo-workspace.sh /tmp/demo-workspace
BRIDGE_TOKEN=local-bridge-token BRIDGE_HOST=127.0.0.1 WORKSPACE_DIR=/tmp/demo-workspace node bridge/bin/bridge.js &

# 4. Studio, with the demo domain in the hostnames (STUDIO_DOMAIN=dev.agency.test in .env).
#    To keep your own database, point DB_DATABASE at a copy and pass --no-reload so the server keeps it.
php artisan serve --host 127.0.0.1 --port 8767 &

# 4b. the System page needs the native driver and an updater state: two more servers on the same
#     database, BASE2 with a stable-channel state, BASE3 with a beta one (see the state fields in
#     installer/studio-update), the helper replaced by tests/fixtures/fake-studio-admin
STUDIO_WORKSPACE_DRIVER=native STUDIO_HELPER=$PWD/tests/fixtures/fake-studio-admin STUDIO_HELPER_SUDO=false \
  STUDIO_UPDATE_STATE=/tmp/state.json php artisan serve --host 127.0.0.1 --port 8768 --no-reload &
STUDIO_WORKSPACE_DRIVER=native STUDIO_HELPER=$PWD/tests/fixtures/fake-studio-admin STUDIO_HELPER_SUDO=false \
  STUDIO_UPDATE_STATE=/tmp/state-beta.json php artisan serve --host 127.0.0.1 --port 8769 --no-reload &

# 5. capture (BASE, BASE2 and BASE3 are replaced in jobs.json)
cd tools/screenshots && sed -e "s#\${BASE}#http://127.0.0.1:8767#g" -e "s#\${BASE2}#http://127.0.0.1:8768#g" \
  -e "s#\${BASE3}#http://127.0.0.1:8769#g" jobs.json > /tmp/jobs.json && node shots.mjs /tmp/jobs.json ../../docs/img
```

`shots.mjs` logs in through the real form, emulates a 1440 px light-mode viewport at 2×, clicks
tabs with `eval`, and flags any horizontal overflow. Needs Google Chrome on the machine and Node 20+.
