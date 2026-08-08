// Log into Moodle and screenshot local_reportsources pages.
//
// Usage:
//   MOODLE_URL=http://p53.local/mdl52 \
//   MOODLE_USER=admin MOODLE_PASS=secret \
//   node shoot.mjs <target> [<target> ...]
//
// A <target> is "name=path", e.g.:
//   list=/local/reportsources/index.php
//   report3=/reportbuilder/view.php?id=3
//   edit=/local/reportsources/edit.php?id=5
//
// With no targets, a small default set is shot. PNGs land in ./out/<name>.png.
//
// Env:
//   MOODLE_URL   base wwwroot (no trailing slash)              [required]
//   MOODLE_USER  login username                                [required]
//   MOODLE_PASS  login password                                [required]
//   CHROME       path to a Chrome/Chromium binary              [auto-detected]
//   WIDTH/HEIGHT viewport (default 1400x900)
//   FULLPAGE     "1" to capture the full scroll height
//   SELECTOR     CSS selector to crop to (overrides FULLPAGE)
//   CLICK        CSS selector to click before capturing (e.g. open a menu)
//   HEADLESS     "0" to watch the browser run

import { existsSync } from 'node:fs';
import { mkdir } from 'node:fs/promises';
import puppeteer from 'puppeteer-core';

const BASE = (process.env.MOODLE_URL || '').replace(/\/$/, '');
const USER = process.env.MOODLE_USER || '';
const PASS = process.env.MOODLE_PASS || '';
const WIDTH = parseInt(process.env.WIDTH || '1400', 10);
const HEIGHT = parseInt(process.env.HEIGHT || '900', 10);
const FULLPAGE = process.env.FULLPAGE === '1';
const SELECTOR = process.env.SELECTOR || '';
const CLICK = process.env.CLICK || '';
const HEADLESS = process.env.HEADLESS !== '0';

if (!BASE || !USER || !PASS) {
  console.error('Set MOODLE_URL, MOODLE_USER and MOODLE_PASS.');
  process.exit(2);
}

function findChrome() {
  if (process.env.CHROME && existsSync(process.env.CHROME)) return process.env.CHROME;
  const candidates = [
    '/usr/bin/google-chrome',
    '/usr/bin/chromium-browser',
    '/usr/bin/chromium',
    '/snap/bin/chromium',
  ];
  const found = candidates.find(existsSync);
  if (!found) {
    console.error('No Chrome/Chromium found. Set CHROME=/path/to/chrome.');
    process.exit(2);
  }
  return found;
}

// Default shots when none are passed on the CLI.
const DEFAULTS = [
  ['list', '/local/reportsources/index.php'],
];

function parseTargets(argv) {
  if (argv.length === 0) return DEFAULTS;
  return argv.map((a) => {
    const i = a.indexOf('=');
    if (i < 0) throw new Error(`Bad target "${a}". Use name=path.`);
    return [a.slice(0, i), a.slice(i + 1)];
  });
}

async function login(page) {
  await page.goto(`${BASE}/login/index.php`, { waitUntil: 'networkidle2' });
  await page.type('#username', USER);
  await page.type('#password', PASS);
  await Promise.all([
    page.click('#loginbtn'),
    page.waitForNavigation({ waitUntil: 'networkidle2' }),
  ]);
  const failed = await page.$('.loginerrors, .alert-danger');
  if (failed) {
    const msg = await page.evaluate((el) => el.textContent.trim(), failed);
    throw new Error(`Login failed: ${msg}`);
  }
}

async function shoot(page, name, path) {
  const url = path.startsWith('http') ? path : `${BASE}${path}`;
  await page.goto(url, { waitUntil: 'networkidle2' });
  // Give Report Builder tables/charts a moment to render client-side.
  await new Promise((r) => setTimeout(r, 1500));
  if (CLICK) {
    const target = await page.$(CLICK);
    if (!target) throw new Error(`CLICK selector "${CLICK}" not found on ${url}`);
    await target.click();
    await new Promise((r) => setTimeout(r, 600));
  }
  const file = `out/${name}.png`;
  if (SELECTOR) {
    const el = await page.$(SELECTOR);
    if (!el) throw new Error(`Selector "${SELECTOR}" not found on ${url}`);
    await el.screenshot({ path: file });
  } else {
    await page.screenshot({ path: file, fullPage: FULLPAGE });
  }
  console.log(`  ${name}  ->  ${file}  (${url})`);
}

const targets = parseTargets(process.argv.slice(2));
await mkdir('out', { recursive: true });

const browser = await puppeteer.launch({
  executablePath: findChrome(),
  headless: HEADLESS ? 'new' : false,
  args: ['--no-sandbox', '--disable-setuid-sandbox', `--window-size=${WIDTH},${HEIGHT}`],
});

try {
  const page = await browser.newPage();
  await page.setViewport({ width: WIDTH, height: HEIGHT });
  console.log(`Logging into ${BASE} as ${USER} ...`);
  await login(page);
  console.log('Logged in. Capturing:');
  for (const [name, path] of targets) {
    await shoot(page, name, path);
  }
  console.log('Done.');
} finally {
  await browser.close();
}
