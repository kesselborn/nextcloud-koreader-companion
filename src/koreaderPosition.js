/**
 * Translation between KOReader reading positions and epub.js positions.
 *
 * The two describe the same place in a book in completely different ways.
 *
 * KOReader (really the CoolReader engine underneath) concatenates every spine
 * item into one synthetic document, wrapping each in a <DocFragment>, and points
 * into it with an XPath plus a character offset:
 *
 *     /body/DocFragment[10]/body/div/p[28]/text()[1].164
 *      \________________/ \___________________/ \_/ \_/
 *       spine item 10          XPath in that     text  offset
 *       (1-based)              item's own body   node
 *
 * epub.js uses EPUB CFI, which addresses the spine item by position in the
 * package document and then walks the DOM in even/odd steps:
 *
 *     epubcfi(/6/14!/4/2/1:164)
 *
 * Neither can read the other, so this converts. The mapping was verified against
 * a real device position: for a book whose spine item 10 spans 9.41%-12.59% of
 * the text, KOReader reported DocFragment[10] at 12.34% and the XPath resolved --
 * the alternative indexing would have placed it before the chapter began.
 *
 * Where it is approximate: CoolReader normalises markup as it renders, so a path
 * it produced may not resolve in the raw XHTML. Every function here fails soft --
 * a position that cannot be resolved returns null and the caller falls back to
 * percentage, which is always present.
 */

/** KOReader counts spine items from 1; epub.js indexes them from 0. */
const DOC_FRAGMENT_BASE = 1

/**
 * Parse `/body/DocFragment[N]/body/div/p[28]/text()[1].164`.
 *
 * @param {string} xpointer
 * @return {?{spineIndex: number, steps: Array, textNodeIndex: number, offset: number}}
 */
export function parseKoreaderPointer(xpointer) {
	if (typeof xpointer !== 'string' || !xpointer.includes('DocFragment')) {
		return null
	}

	const fragment = xpointer.match(/DocFragment\[(\d+)\]/)
	if (!fragment) {
		return null
	}
	const spineIndex = Number(fragment[1]) - DOC_FRAGMENT_BASE
	if (spineIndex < 0) {
		return null
	}

	// Everything after the DocFragment step is an XPath into that item's own body.
	let rest = xpointer.slice(xpointer.indexOf(fragment[0]) + fragment[0].length)

	// A trailing `.N` is the character offset. Match only at the very end, so a
	// decimal inside the path could never be mistaken for it.
	let offset = 0
	const tail = rest.match(/\.(\d+)$/)
	if (tail) {
		offset = Number(tail[1])
		rest = rest.slice(0, tail.index)
	}

	// The last step may be text() or text()[k]; k defaults to 1.
	let textNodeIndex = 1
	const textStep = rest.match(/\/text\(\)(?:\[(\d+)\])?$/)
	if (textStep) {
		if (textStep[1]) {
			textNodeIndex = Number(textStep[1])
		}
		rest = rest.slice(0, textStep.index)
	}

	const steps = rest.split('/').filter(Boolean).map((step) => {
		const m = step.match(/^([A-Za-z_][\w.-]*)(?:\[(\d+)\])?$/)
		return m ? { name: m[1].toLowerCase(), index: m[2] ? Number(m[2]) : 1 } : null
	})

	// No path at all is legitimate -- `/body/DocFragment[7]` means the start of
	// that spine item, which is what a device reports before you have moved
	// within a chapter.
	if (steps.some((s) => s === null)) {
		return null
	}

	return { spineIndex, steps, textNodeIndex, offset }
}

/** Element children of `parent` with the given tag name, in document order. */
function elementsNamed(parent, name) {
	return Array.from(parent.children).filter((el) => el.localName.toLowerCase() === name)
}

/** Direct text-node children, which is what KOReader's text() indexes. */
function textNodesOf(element) {
	return Array.from(element.childNodes).filter((n) => n.nodeType === Node.TEXT_NODE)
}

/**
 * Walk a parsed pointer to a DOM node in a spine item's document.
 *
 * @return {?{node: Node, offset: number}}
 */
export function resolvePointerInDocument(doc, parsed) {
	if (!doc || !parsed) {
		return null
	}

	// The path starts at the fragment's <body>, which is the document's body.
	let current = doc.body || doc.querySelector('body')
	if (!current) {
		return null
	}

	// Skip a leading 'body' step, which addresses the element we already have --
	// but only if it is actually there, since not every device emits it.
	const steps = parsed.steps[0]?.name === 'body' ? parsed.steps.slice(1) : parsed.steps

	for (const step of steps) {
		const candidates = elementsNamed(current, step.name)
		const next = candidates[step.index - 1]
		if (!next) {
			return null
		}
		current = next
	}

	const texts = textNodesOf(current)
	const textNode = texts[parsed.textNodeIndex - 1]

	if (!textNode) {
		// The element exists but has no matching text node -- markup differences
		// between CoolReader's DOM and the raw XHTML. The element is close enough.
		return { node: current, offset: 0 }
	}

	return { node: textNode, offset: Math.min(parsed.offset, textNode.length) }
}

/**
 * KOReader position -> EPUB CFI.
 *
 * @param {object} book An epub.js Book.
 * @param {string} xpointer
 * @return {Promise<?string>} A CFI, or null if it could not be resolved.
 */
export async function koreaderPointerToCfi(book, xpointer) {
	const parsed = parseKoreaderPointer(xpointer)
	if (!parsed) {
		return null
	}

	try {
		const section = book.spine.get(parsed.spineIndex)
		if (!section) {
			return null
		}

		// Loading the section gives us its document without rendering it.
		await section.load(book.load.bind(book))
		const doc = section.document
		const hit = resolvePointerInDocument(doc, parsed)
		if (!hit) {
			return null
		}

		const range = doc.createRange()
		range.setStart(hit.node, hit.offset)
		range.setEnd(hit.node, hit.offset)

		return section.cfiFromRange(range)
	} catch (error) {
		return null
	} finally {
		// Sections cache their document; unload so a long reading session does not
		// hold every chapter it ever peeked at.
		try {
			book.spine.get(parsed.spineIndex)?.unload()
		} catch (error) {
			// Nothing to do; this is housekeeping.
		}
	}
}

/**
 * Build the XPath portion of a KOReader pointer for a node.
 *
 * @return {?string} e.g. `/body/div/p[28]`
 */
function pathFromNode(element) {
	const steps = []
	let current = element

	while (current && current.nodeType === Node.ELEMENT_NODE) {
		const name = current.localName.toLowerCase()

		if (name === 'body') {
			steps.unshift('body')
			break
		}

		const parent = current.parentNode
		if (!parent || parent.nodeType !== Node.ELEMENT_NODE) {
			return null
		}

		const siblings = elementsNamed(parent, name)
		const index = siblings.indexOf(current) + 1
		// KOReader omits [1] only for the body step; keeping it explicit elsewhere
		// matches what real devices emit.
		steps.unshift(`${name}[${index}]`)
		current = parent
	}

	return steps[0] === 'body' ? '/' + steps.join('/') : null
}

/**
 * EPUB CFI -> KOReader position.
 *
 * @param {object} book An epub.js Book.
 * @param {object} contents The epub.js Contents for the rendered section.
 * @param {string} cfi
 * @return {?string} A KOReader xpointer, or null if it could not be built.
 */
export function cfiToKoreaderPointer(book, contents, cfi) {
	try {
		const section = book.spine.get(cfi)
		if (!section || typeof section.index !== 'number') {
			return null
		}

		const range = contents.range(cfi)
		if (!range) {
			return null
		}

		let node = range.startContainer
		let offset = range.startOffset
		let textNodeIndex = 1

		if (node.nodeType === Node.TEXT_NODE) {
			const parent = node.parentNode
			textNodeIndex = textNodesOf(parent).indexOf(node) + 1
			node = parent
		} else {
			// A CFI can land on an element; treat it as the start of its first text.
			offset = 0
		}

		const path = pathFromNode(node)
		if (!path) {
			return null
		}

		const fragment = section.index + DOC_FRAGMENT_BASE

		return `/body/DocFragment[${fragment}]${path}/text()[${textNodeIndex}].${offset}`
	} catch (error) {
		return null
	}
}
