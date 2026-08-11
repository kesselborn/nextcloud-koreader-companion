#!/usr/bin/env node
/**
 * dev/l10n-extract.mjs -- collect translatable strings and refresh l10n/.
 *
 * Nextcloud apps normally get this from Transifex, which this app is not wired
 * into. Rather than hand-maintain two files per language, scan the sources for
 * t()/n() calls and merge: new strings appear untranslated, existing
 * translations are kept, and strings that no longer exist are dropped.
 *
 *   node dev/l10n-extract.mjs          # refresh
 *   node dev/l10n-extract.mjs --check  # fail if out of date (used by CI)
 *
 * Nextcloud wants both formats per language:
 *   l10n/<lang>.json  read by the PHP side (IL10N)
 *   l10n/<lang>.js    registered into OC.L10N for the frontend
 */

import { readFileSync, writeFileSync, readdirSync, statSync, existsSync, mkdirSync } from 'fs'
import { join, dirname } from 'path'
import { fileURLToPath } from 'url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const APP = 'koreader_companion'
const SOURCE_DIRS = ['src', 'lib', 'templates']
const LANGUAGES = {
	// English is the source language, so Nextcloud needs no file for it -- an
	// untranslated string is already English.
	de: { pluralForm: 'nplurals=2; plural=(n != 1);' },
}

function walk(dir, out = []) {
	if (!existsSync(dir)) return out
	for (const entry of readdirSync(dir)) {
		const full = join(dir, entry)
		if (statSync(full).isDirectory()) walk(full, out)
		else if (/\.(vue|js|mjs|php)$/.test(entry)) out.push(full)
	}
	return out
}

/** Matches t('app', 'text') and n('app', 'singular', 'plural', …). */
function extract(source) {
	const singles = new Set()
	const plurals = new Set()
	const esc = "(?:[^'\\\\]|\\\\.)*"

	for (const m of source.matchAll(new RegExp(`\\bt\\(\\s*'${APP}'\\s*,\\s*'(${esc})'`, 'g'))) {
		singles.add(m[1])
	}
	for (const m of source.matchAll(new RegExp(`\\bn\\(\\s*'${APP}'\\s*,\\s*'(${esc})'\\s*,\\s*'(${esc})'`, 'g'))) {
		plurals.add(JSON.stringify([m[1], m[2]]))
	}
	return { singles, plurals }
}

const singles = new Set()
const plurals = new Set()
for (const dir of SOURCE_DIRS) {
	for (const file of walk(join(ROOT, dir))) {
		const found = extract(readFileSync(file, 'utf8'))
		found.singles.forEach(s => singles.add(s))
		found.plurals.forEach(s => plurals.add(s))
	}
}

// Unescape the JS string literals we captured.
const unescape = (s) => s.replace(/\\(['"\\nt])/g, (_, c) => ({ n: '\n', t: '\t' }[c] ?? c))

const keys = [
	...[...singles].map(unescape),
	...[...plurals].map(p => JSON.parse(p).map(unescape)),
]
keys.sort((a, b) => String(Array.isArray(a) ? a[0] : a).localeCompare(String(Array.isArray(b) ? b[0] : b)))

const l10nDir = join(ROOT, 'l10n')
if (!existsSync(l10nDir)) mkdirSync(l10nDir)

let stale = false

for (const [lang, { pluralForm }] of Object.entries(LANGUAGES)) {
	const jsonPath = join(l10nDir, `${lang}.json`)
	const existing = existsSync(jsonPath)
		? JSON.parse(readFileSync(jsonPath, 'utf8')).translations ?? {}
		: {}

	const translations = {}
	let untranslated = 0
	for (const key of keys) {
		if (Array.isArray(key)) {
			const [one, other] = key
			// Plurals are keyed by a composite identifier, not by the singular.
			// @nextcloud/l10n builds it as `_singular_::_plural_` (see
			// translatePlural in the package); keying by the bare singular means
			// the lookup silently misses and the English plural is shown.
			const id = `_${one}_::_${other}_`
			const prev = existing[id]
			translations[id] = Array.isArray(prev) && prev.length === 2 ? prev : [one, other]
			if (!Array.isArray(prev)) untranslated++
		} else {
			const prev = existing[key]
			translations[key] = typeof prev === 'string' && prev !== '' ? prev : key
			if (typeof prev !== 'string' || prev === '') untranslated++
		}
	}

	const json = JSON.stringify({ translations, pluralForm }, null, 4) + '\n'
	const js = `OC.L10N.register(\n    "${APP}",\n    ${
		JSON.stringify(translations, null, 4).split('\n').join('\n    ')
	},\n    "${pluralForm}");\n`

	const jsPath = join(l10nDir, `${lang}.js`)
	const changed = !existsSync(jsonPath) || readFileSync(jsonPath, 'utf8') !== json
		|| !existsSync(jsPath) || readFileSync(jsPath, 'utf8') !== js

	if (process.argv.includes('--check')) {
		if (changed) {
			console.error(`l10n/${lang}.* is out of date -- run: node dev/l10n-extract.mjs`)
			stale = true
		}
	} else {
		writeFileSync(jsonPath, json)
		writeFileSync(jsPath, js)
		console.log(`l10n/${lang}: ${keys.length} strings, ${untranslated} still untranslated`)
	}
}

if (stale) process.exit(1)
if (!process.argv.includes('--check')) {
	console.log(`${keys.length} translatable strings found across ${SOURCE_DIRS.join(', ')}`)
}
