# RayEtun Events Calendar

An accessible event calendar for WordPress: recurring events; month, week, day
and list views; venues, organisers, speakers and programmes; iCalendar and CSV
import; feeds, subscribe links and `Event` structured data; four blocks and four
shortcodes.

Its own addition is the **Accessibility Guard** — twenty-one checks that run
against an event while you write it, each one saying what is wrong, how serious
it is, and what to do about it.

* Requires WordPress 6.9 and PHP 8.1
* Licensed GPL-2.0-or-later

## Layout

The repository root is the workspace; the plugin is the folder inside it.

```
rayetun-events-calendar/   the plugin — this folder is what ships
  includes/                PHP classes, autoloaded as class-foo-bar.php
  build/                   compiled admin, editor and block bundles (committed)
  assets/                  hand-written front-end CSS and JS
  templates/               front-end templates, overridable by a theme
src/                       JavaScript and CSS sources for build/
tests/                     PHPUnit suites, a stand-in runner, and the benchmark
tools/                     render.js — HTML and SVG to PNG, for the listing art
design/                    banner and icon sources
.wordpress-org/            icon, banner and screenshots for the plugin page
```

## Building

```
npm install
npm run build        # production bundles into rayetun-events-calendar/build/
npm start            # watch
```

## Testing

```
npm test                        # PHPUnit
php tests/run.php               # the same suites where PHPUnit is not installed
php tests/bench.php <wordpress> # seed, measure, remove
```

Coding standards are checked with PHPCS against the `WordPress` standard
(`phpcs.xml.dist`), and the plugin is expected to pass Plugin Check's
`plugin-review` ruleset with nothing reported.

## Releasing

`main` is what is live on WordPress.org. Work happens on `develop` and on
feature branches cut from it. A release is a merge to `main`, a version bump in
`rayetun-events-calendar.php`, `readme.txt` and `package.json`, a tag, and a
published GitHub release.
