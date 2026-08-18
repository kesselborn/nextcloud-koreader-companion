#!/usr/bin/env node
/**
 * Collect the full licence texts of everything bundled into js/.
 *
 * The build already emits `js/*.license` sidecars listing each bundled package
 * with an SPDX identifier. That is not quite enough: BSD-2-Clause (epub.js) asks
 * that redistributions in binary form "reproduce the above copyright notice,
 * this list of conditions and the following disclaimer", and Apache-2.0
 * (localforage) asks for a copy of the licence. An SPDX identifier names a
 * licence; it does not reproduce it.
 *
 * So this writes js/THIRD-PARTY-LICENSES.txt with the actual text of every
 * licence that ships, taken from the packages themselves.
 *
 * Run as a postbuild step, so the file stays in step with the bundles it
 * describes. Output is sorted, so rebuilding an unchanged tree produces an
 * identical file and CI's "is the bundle current" check stays meaningful.
 */
import { execFileSync } from 'node:child_process'
import { readFileSync, writeFileSync, readdirSync, existsSync } from 'node:fs'
import { join } from 'node:path'

const OUT = 'js/THIRD-PARTY-LICENSES.txt'
const LICENCE_FILES = /^(LICEN[CS]E|COPYING|NOTICE)([.-].*)?$/i

/** Packages the build says it bundled, from the sidecars it emitted. */
function fromSidecars() {
	const found = new Map()
	for (const name of readdirSync('js').filter((f) => f.endsWith('.license'))) {
		const text = readFileSync(join('js', name), 'utf8')
		const re = /^- (\S+)\n\t- version: (\S+)\n\t- license: (.+)$/gm
		let m
		while ((m = re.exec(text)) !== null) {
			found.set(m[1], { version: m[2], license: m[3] })
		}
	}
	return found
}

/**
 * The installed production dependency tree, as npm resolves it.
 *
 * The sidecars under-report: pako's inflate code is demonstrably in a chunk (its
 * error strings are), yet pako appears in no sidecar, because jszip pulls it in
 * and the tooling credited only the direct import. This closes that gap.
 *
 * Asked of npm rather than derived here. Walking declared dependency names
 * over-collects badly (309 packages against npm's 81), because a package may
 * name dependencies npm never installed for production, and the lock file's
 * `dev` flags mark only packages reachable *exclusively* from devDependencies --
 * 419 of 636 entries here are not flagged.
 */
function fromNpm() {
	const found = new Map()
	// npm exits non-zero when it has anything to complain about -- a peer
	// dependency mismatch, for instance -- while still printing the complete tree
	// on stdout, which is all this needs. So take the output either way.
	let json
	try {
		json = execFileSync('npm', ['ls', '--omit=dev', '--all', '--json'], {
			encoding: 'utf8',
			maxBuffer: 64 * 1024 * 1024,
			stdio: ['ignore', 'pipe', 'ignore'],
		})
	} catch (error) {
		json = error.stdout
		if (!json) {
			throw error
		}
	}

	const walk = (node) => {
		for (const [name, info] of Object.entries(node.dependencies || {})) {
			if (!found.has(name)) {
				found.set(name, { version: info.version, license: undefined })
				walk(info)
			}
		}
	}
	walk(JSON.parse(json))

	// The tree does not carry licence fields; read them from the packages.
	for (const [name, info] of found) {
		const manifest = join('node_modules', name, 'package.json')
		if (existsSync(manifest)) {
			info.license = JSON.parse(readFileSync(manifest, 'utf8')).license
		}
	}

	return found
}

function licenceTexts(name) {
	const dir = join('node_modules', name)
	if (!existsSync(dir)) {
		return []
	}
	return readdirSync(dir)
		.filter((f) => LICENCE_FILES.test(f))
		.sort()
		.map((f) => ({ file: f, text: readFileSync(join(dir, f), 'utf8').trim() }))
}

const bundled = fromSidecars()
const packages = new Map([...fromNpm(), ...bundled])
packages.delete('koreader_companion')

// Grouped by identical licence text: GPL-3.0 alone is 35 kB and ten packages
// here ship a byte-identical copy of it. One copy per distinct text, listing
// everyone it covers, is both smaller and easier to read than 98 repetitions.
const byText = new Map()
const missing = []

for (const name of [...packages.keys()].sort()) {
	const { version, license } = packages.get(name)
	const texts = licenceTexts(name)

	if (texts.length === 0) {
		missing.push(`${name} (${license || 'license not declared'})`)
		continue
	}

	for (const { text } of texts) {
		if (!byText.has(text)) {
			byText.set(text, [])
		}
		byText.get(text).push(`${name} ${version || ''} -- ${license || '?'}`)
	}
}

const sections = [...byText.entries()]
	.sort((a, b) => a[1][0].localeCompare(b[1][0]))
	.map(([text, users]) => {
		const rule = '='.repeat(78)
		return `${rule}\n${users.map((u) => `  ${u}`).join('\n')}\n${rule}\n\n${text}\n`
	})

const header = `Third-party licences
====================

KOReader Companion is AGPL-3.0-or-later; see LICENSE. Its frontend bundle in js/
includes the packages below, under the licences reproduced here in full. The
per-file SPDX summaries live alongside the bundles as js/*.mjs.license.

Packages: ${packages.size}
`

const footer = missing.length
	? `\n${'='.repeat(78)}\nNo licence file shipped by these packages; the identifier above is what they\ndeclare in their package metadata:\n\n${missing.map((m) => `- ${m}`).join('\n')}\n`
	: ''

writeFileSync(OUT, `${header}\n${sections.join('\n')}${footer}`)
console.log(`${OUT}: ${packages.size} packages, ${sections.length} distinct licence texts, ${missing.length} without text`)
