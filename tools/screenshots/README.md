# Screenshot helper

Logs into Moodle with Puppeteer (driving the system Chrome/Chromium) and captures
`local_reportsources` pages as PNGs. Used to refresh the screenshots in `../../docs/`.

`node_modules/` and `out/` are gitignored; only `shoot.mjs`, `package.json` and this
README are tracked.

## Setup (once)

```bash
cd tools/screenshots
npm install        # pulls puppeteer-core only (uses the system Chrome, no download)
```

## Use

```bash
MOODLE_URL=http://p53.local/mdl52 \
MOODLE_USER=admin MOODLE_PASS='yourpass' \
npm run shot -- list=/local/reportsources/index.php \
                report3=/reportbuilder/view.php?id=3
```

Each `name=path` target writes `out/<name>.png`. With no targets a small default
set is shot.

### Options (env vars)

| Var | Default | Meaning |
|---|---|---|
| `MOODLE_URL` | — | wwwroot, no trailing slash (required) |
| `MOODLE_USER` / `MOODLE_PASS` | — | login credentials (required) |
| `CHROME` | auto | path to a Chrome/Chromium binary |
| `WIDTH` / `HEIGHT` | 1400 / 900 | viewport size |
| `FULLPAGE` | — | `1` captures the full scroll height |
| `SELECTOR` | — | crop to a CSS selector (overrides `FULLPAGE`) |
| `HEADLESS` | — | `0` shows the browser window |

### Examples

Crop to just the report card on a Report Builder page:

```bash
MOODLE_URL=... MOODLE_USER=... MOODLE_PASS=... SELECTOR='.reportbuilder-report' \
npm run shot -- report=/reportbuilder/view.php?id=3
```

Full-page edit form:

```bash
MOODLE_URL=... MOODLE_USER=... MOODLE_PASS=... FULLPAGE=1 \
npm run shot -- edit=/local/reportsources/edit.php?id=5
```

## Notes

- Use a **test/admin account on a dev site**. Credentials are read from the
  environment, never committed.
- The login step targets the standard Moodle form (`#username`, `#password`,
  `#loginbtn`). If your site uses an SSO/OAuth-only login page, this needs adapting.
