# Visual regression tests

Catches unintended rendering changes in `assets/css.css` by screenshotting
static HTML fixtures and diffing against committed baseline images.

## Why static fixtures instead of the real site

This plugin is "nearly headless" — the actual grids render from live data via
REST calls into a real WordPress + MySQL + Pods stack (some of which, like the
custom user roles, are configured by hand and aren't in version control at
all). Standing that up inside CI is a real project of its own. The fixtures
under `fixtures/*.html` are hand-authored to match the *real* DOM shapes
(same classes, same structure Grid/List actually produce once mounted — see
each fixture's own comment) but need no WordPress, database, or JavaScript to
render. They `<link>` the actual `assets/css.css` directly, so a real CSS
change is what a diff here is catching.

This deliberately only covers layout/CSS rendering — not PHP, REST responses,
or real content. If this suite proves valuable, a good next step is standing
up a real WordPress instance in CI (Docker Compose: wordpress + mysql + Pods
+ this plugin, seeded with fixture content) for true end-to-end coverage.
That's a bigger effort and not what this starts with.

## Isolation from the plugin itself

This whole `tests/visual/` directory — `package.json`, `node_modules/`,
Playwright itself — is the **only** place Node/npm exist in this repo. The
plugin itself stays build-step-free (see the root `CLAUDE.md`). None of this
is deployed; `.github/workflows/deploy.yml`'s rsync only ships the plugin's
own files.

## Running locally

Needs Docker (Playwright's own npm install downloads browser binaries built
for your host OS, which render fonts differently than the Linux CI
container — running everything through the same Docker image both places is
what keeps local and CI diffs meaningful instead of just "your Mac/Windows
renders text differently than Ubuntu").

```sh
cd tests/visual
docker run --rm --ipc=host -v "$PWD/../..:/work" -w /work/tests/visual \
  mcr.microsoft.com/playwright:v1.48.2-jammy \
  bash -c "npm ci && npx playwright test"
```

(The image tag must match the `@playwright/test` version in `package.json`
exactly — see that file's own note.)

View the HTML report after a run (has side-by-side before/after/diff images
for anything that failed):

```sh
npx playwright show-report
```

## Updating baselines

When a CSS change is *intentional* and the new screenshots are correct,
regenerate the baselines the same way, with `--update-snapshots`:

```sh
cd tests/visual
docker run --rm --ipc=host -v "$PWD/../..:/work" -w /work/tests/visual \
  mcr.microsoft.com/playwright:v1.48.2-jammy \
  bash -c "npm ci && npx playwright test --update-snapshots"
```

This overwrites the PNGs under `tests/*-snapshots/`. **Review the new
images like you'd review any other diff** before committing — they're the
actual pass/fail criteria for every future run, not incidental output.

## Adding a new fixture/scenario

Keep the fixture count small and deliberate — each one is a maintenance
burden (every real layout change means reviewing and re-approving its
screenshots). Prefer adding another viewport size or state to an *existing*
fixture (see the `VIEWPORTS` object in each `.spec.js`) over creating a new
HTML file, unless you're covering a genuinely different DOM shape.
