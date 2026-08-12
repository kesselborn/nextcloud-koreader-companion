<template>
	<NcModal
		size="full"
		:name="book.title"
		:close-button-contained="false"
		@close="requestClose">
		<div class="reader">
			<div class="reader__header">
				<span class="reader__title">{{ book.title }}</span>
				<span v-if="chapter" class="reader__chapter">{{ chapter }}</span>
				<!-- Say so when the book was opened somewhere the reader did not
				     leave it: being moved without explanation is disorienting. -->
				<span v-if="openedFrom" class="reader__resumed">
					{{ t('koreader_companion', 'Resumed from {device}', { device: openedFrom }) }}
				</span>
				<div class="reader__zoom">
					<NcButton
						:aria-label="t('koreader_companion', 'Smaller text')"
						type="tertiary"
						@click="setFontSize(fontSize - 10)">
						<template #icon>
							<FormatFontSizeDecrease :size="20" />
						</template>
					</NcButton>
					<NcButton
						:aria-label="t('koreader_companion', 'Larger text')"
						type="tertiary"
						@click="setFontSize(fontSize + 10)">
						<template #icon>
							<FormatFontSizeIncrease :size="20" />
						</template>
					</NcButton>
				</div>
			</div>

			<div class="reader__stage">
				<NcButton
					:aria-label="t('koreader_companion', 'Previous page')"
					type="tertiary"
					class="reader__page-button"
					:disabled="!ready"
					@click="prev">
					<template #icon>
						<ChevronLeft :size="24" />
					</template>
				</NcButton>

				<!-- epub.js writes into an iframe it owns, so this element must stay
				     empty and keep a stable size; the ResizeObserver relays every
				     size change to the rendition. -->
				<div ref="viewer" class="reader__viewer" />

				<NcButton
					:aria-label="t('koreader_companion', 'Next page')"
					type="tertiary"
					class="reader__page-button"
					:disabled="!ready"
					@click="next">
					<template #icon>
						<ChevronRight :size="24" />
					</template>
				</NcButton>

				<div v-if="!ready" class="reader__overlay">
					<NcLoadingIcon v-if="!error" :size="44" />
					<NcEmptyContent v-else :name="error">
						<template #icon>
							<AlertCircle />
						</template>
					</NcEmptyContent>
				</div>
			</div>

			<!-- Asked, never assumed: this position is shared with real devices, so
			     saving it silently could move where a Kobo resumes. -->
			<NcDialog
				v-if="askingToSave"
				:name="t('koreader_companion', 'Save your place?')"
				:message="saveDialogMessage"
				:buttons="saveDialogButtons"
				@closing="close" />

			<div class="reader__footer">
				<input
					type="range"
					min="0"
					max="100"
					step="1"
					:value="percent"
					:disabled="!locationsReady"
					:aria-label="t('koreader_companion', 'Position in book')"
					class="reader__slider"
					@change="seek">
				<span class="reader__percent">
					{{ positionLabel }}
				</span>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { markRaw } from 'vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcModal from '@nextcloud/vue/components/NcModal'
import AlertCircle from 'vue-material-design-icons/AlertCircleOutline.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import FormatFontSizeDecrease from 'vue-material-design-icons/FormatFontSizeDecrease.vue'
import FormatFontSizeIncrease from 'vue-material-design-icons/FormatFontSizeIncrease.vue'

import { fetchBookFile, fetchProgress, saveProgress } from '../api.js'
import { cfiToKoreaderPointer, koreaderPointerToCfi } from '../koreaderPosition.js'

// Where we left off, per book. Deliberately local: KOReader's own sync is
// device-to-device over the /sync API and uses its own progress model, and
// writing web-reader positions into it would confuse a real device.
const positionKey = (id) => `koreader_companion:position:${id}`
const FONT_SIZE_KEY = 'koreader_companion:font-size'
const MIN_FONT_SIZE = 70
const MAX_FONT_SIZE = 200

export default {
	name: 'ReaderModal',

	components: {
		AlertCircle,
		ChevronLeft,
		ChevronRight,
		FormatFontSizeDecrease,
		FormatFontSizeIncrease,
		NcButton,
		NcEmptyContent,
		NcDialog,
		NcLoadingIcon,
		NcModal,
	},

	props: {
		book: {
			type: Object,
			required: true,
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			// markRaw throughout: epub.js keeps internal object identities and
			// breaks when handed back a reactive proxy of itself.
			epub: null,
			rendition: null,
			resizeObserver: null,
			ready: false,
			error: '',
			percent: 0,
			chapter: '',
			locationsReady: false,
			// Set when the book was opened at a position synced from a device, so
			// the reader can say so rather than silently moving the user.
			openedFrom: '',
			pendingPercentage: null,
			saving: false,
			askingToSave: false,
			currentCfi: '',
			page: 0,
			pageTotal: 0,
			// True when the numbers come from the book's own page-list (printed page
			// numbers) rather than from epub.js's evenly-sized locations.
			realPages: false,
			fontSize: Number(localStorage.getItem(FONT_SIZE_KEY)) || 100,
		}
	},

	computed: {
		/**
		 * Percentage always; page numbers once they are known.
		 *
		 * A book with a page-list gives real printed page numbers. Without one the
		 * numbers come from epub.js's locations index, which is evenly sized rather
		 * than typeset, so they are labelled the same but are only ever "a page's
		 * worth of text".
		 */
		positionLabel() {
			if (!this.locationsReady) {
				return t('koreader_companion', 'Measuring…')
			}
			if (this.pageTotal > 0) {
				return t('koreader_companion', 'Page {page} of {total} · {percent}%', {
					page: this.page,
					total: this.pageTotal,
					percent: this.percent,
				})
			}
			return t('koreader_companion', '{percent}%', { percent: this.percent })
		},

		/**
		 * One sentence. "Resume here" carries the consequence -- that the old
		 * position is replaced -- without spelling out the mechanism.
		 */
		saveDialogMessage() {
			return t('koreader_companion', 'You are at {percent}%. Your devices will resume here.', {
				percent: this.percent,
			})
		},

		saveDialogButtons() {
			// Short labels: NcDialog truncates anything longer, and a button that
			// reads "Continue here on my de..." helps nobody.
			return [
				{
					label: t('koreader_companion', 'Not now'),
					callback: () => this.close(),
				},
				{
					label: t('koreader_companion', 'Save'),
					type: 'primary',
					disabled: this.saving,
					callback: () => this.saveAndClose(),
				},
			]
		},
	},

	mounted() {
		document.addEventListener('keyup', this.onKey)
		this.open()
	},

	beforeUnmount() {
		document.removeEventListener('keyup', this.onKey)
		this.resizeObserver?.disconnect()
		this.rendition?.destroy()
		this.epub?.destroy()
	},

	methods: {
		async open() {
			try {
				// epub.js drags in a ZIP implementation, so keep it out of the main
				// bundle -- only readers pay for it.
				const [{ default: ePub }, data] = await Promise.all([
					import('epubjs'),
					fetchBookFile(this.book.id),
				])

				this.epub = markRaw(ePub(data))
				this.rendition = markRaw(this.epub.renderTo(this.$refs.viewer, {
					width: '100%',
					height: '100%',
					flow: 'paginated',
					spread: 'auto',
					// SECURITY -- load-bearing, do not flip to true.
					//
					// An EPUB is a zip of arbitrary XHTML, and it is attacker-supplied:
					// anyone who can put a file in a user's library controls it. Three
					// things keep that content from executing in the Nextcloud origin,
					// and this is one:
					//   1. epub.js sets iframe.sandbox = "allow-same-origin" and only
					//      appends "allow-scripts" when this flag is true
					//      (epubjs/lib/managers/views/iframe.js)
					//   2. this flag, false
					//   3. the page CSP allows blob: for frames but never for scripts
					//      (PageController::index) -- blob: documents inherit the
					//      parent CSP, so a sandbox regression alone is not enough
					//
					// Setting this true would turn any uploaded book into same-origin
					// script execution against the reader's session. When bumping
					// epubjs, re-check its sandbox defaults in that file: a change there
					// silently removes layer 1. See docs/security-audit.html.
					allowScriptedContent: false,
				}))
				this.rendition.themes.fontSize(`${this.fontSize}%`)
				this.rendition.on('relocated', this.onRelocated)
				this.rendition.on('keyup', this.onKey)

				await this.rendition.display(await this.startingPosition())
				this.ready = true
				this.observeResize()
				this.loadLocations()
			} catch (error) {
				this.error = t('koreader_companion', 'This book could not be opened')
			}
		},

		/**
		 * Closing: offer to sync the position, unless there is nothing to sync.
		 */
		requestClose() {
			if (!this.ready || !this.currentPointer()) {
				this.close()
				return
			}
			this.askingToSave = true
		},

		close() {
			this.askingToSave = false
			this.$emit('close')
		},

		/**
		 * The current position as a KOReader xpointer.
		 *
		 * Null when the reader has not settled, or when the position cannot be
		 * expressed in KOReader's terms -- in which case there is nothing worth
		 * offering to save, since a device could not use it.
		 */
		currentPointer() {
			try {
				// From the relocated event rather than rendition.currentLocation(),
				// which is not reliably populated -- it is only set as a side effect
				// of the same event.
				const cfi = this.currentCfi
				const contents = this.rendition?.getContents()?.[0]
				if (!cfi || !contents) {
					return null
				}
				return cfiToKoreaderPointer(this.epub, contents, cfi)
			} catch (error) {
				return null
			}
		},

		async saveAndClose() {
			const pointer = this.currentPointer()
			if (!pointer) {
				this.close()
				return
			}

			this.saving = true
			try {
				await saveProgress(this.book.id, {
					progress: pointer,
					// Full precision, recomputed here rather than reusing the readout:
					// this.percent is rounded to a whole percent for display, and on a
					// 400-page book one percent is about four pages -- enough to land
					// in the previous chapter when saving at a chapter boundary, which
					// is exactly where people stop reading. The protocol carries a
					// fraction, not a percent.
					percentage: this.exactFraction(),
					device: t('koreader_companion', 'Nextcloud Web'),
				})
				showSuccess(t('koreader_companion', 'Reading position saved'))
				// The card still shows whatever progress it was rendered with, so
				// tell the library to re-read it rather than leaving a stale number.
				this.$emit('saved')
			} catch (error) {
				showError(t('koreader_companion', 'Could not save your reading position'))
			} finally {
				this.saving = false
				this.close()
			}
		},

		/**
		 * Where to open the book.
		 *
		 * A position synced from a device wins over the one this browser last
		 * stored: it is the shared notion of "where I am", and picking it up is the
		 * point of syncing at all. Its xpointer is converted to a CFI; if that
		 * cannot be resolved -- CoolReader normalises markup as it renders, so its
		 * paths do not always survive the round trip -- the percentage is used
		 * instead, which is always present but only lands near the right page.
		 */
		async startingPosition() {
			const local = localStorage.getItem(positionKey(this.book.id)) || undefined

			// Read from the server, not from the book handed over by the library.
			// That listing is fetched once; a device syncing afterwards leaves it
			// stale, and resuming from it silently ignores where you actually got to
			// on the device. Falls back to the snapshot if the request fails.
			let remote = this.book.progress
			try {
				remote = await fetchProgress(this.book.id) || remote
			} catch (error) {
				// Keep the snapshot; a stale position beats no position.
			}

			if (!remote?.progress_data) {
				return local
			}

			const cfi = await koreaderPointerToCfi(this.epub, remote.progress_data)
			if (cfi) {
				this.openedFrom = remote.device || ''
				return cfi
			}

			// Percentage needs the locations index, which is not built yet at open
			// time; remember it and seek once it is.
			this.pendingPercentage = Number(remote.percentage) / 100
			return local
		},

		/**
		 * Locations are what turn a CFI into a percentage, and generating them
		 * means walking the whole book -- seconds on a large one. Do it after the
		 * first page is on screen and cache the result.
		 */
		async loadLocations() {
			const key = `koreader_companion:locations:${this.book.id}`
			try {
				const stored = localStorage.getItem(key)
				if (stored) {
					this.epub.locations.load(stored)
				} else {
					await this.epub.locations.generate(1600)
					try {
						localStorage.setItem(key, this.epub.locations.save())
					} catch (quotaExceeded) {
						// Caching is an optimisation; losing it costs a few seconds
						// next time, not the position readout we just computed.
					}
				}
				this.locationsReady = true

				// Fallback for a device position whose exact path did not resolve.
				if (this.pendingPercentage !== null) {
					const target = this.epub.locations.cfiFromPercentage(this.pendingPercentage)
					this.pendingPercentage = null
					if (target) {
						await this.rendition.display(target)
					}
				}

				this.onRelocated(this.rendition.currentLocation())
			} catch (error) {
				// A book without a position readout is still a readable book.
			}
		},

		observeResize() {
			this.resizeObserver = markRaw(new ResizeObserver(() => {
				this.rendition?.resize(this.$refs.viewer.clientWidth, this.$refs.viewer.clientHeight)
			}))
			this.resizeObserver.observe(this.$refs.viewer)
		},

		onRelocated(location) {
			const cfi = location?.start?.cfi
			if (!cfi) {
				return
			}
			this.currentCfi = cfi
			localStorage.setItem(positionKey(this.book.id), cfi)
			if (this.locationsReady) {
				this.percent = Math.round(this.epub.locations.percentageFromCfi(cfi) * 100)
				this.updatePage(cfi)
			}
			this.chapter = this.chapterNameFor(location.start.href)
		},

		/**
		 * The current position as a 0..1 fraction, unrounded.
		 */
		exactFraction() {
			try {
				const exact = this.epub?.locations?.percentageFromCfi(this.currentCfi)
				if (typeof exact === 'number' && !isNaN(exact)) {
					return exact
				}
			} catch (error) {
				// Falls back to the rounded readout below.
			}

			return this.percent / 100
		},

		/**
		 * Which chapter the reader is in.
		 *
		 * navigation.get() is an exact href lookup, and it misses constantly: TOC
		 * entries usually carry a fragment (`part0018.html#c15`) while the rendered
		 * location does not, so the header simply stayed blank. Match on the path
		 * alone, walking nested TOC entries, and fall back to the heading in the
		 * page itself -- which is what a reader would call the chapter anyway.
		 */
		chapterNameFor(href) {
			const base = (href || '').split('#')[0]

			const search = (items) => {
				for (const item of items || []) {
					if ((item.href || '').split('#')[0] === base) {
						return item.label
					}
					const nested = search(item.subitems)
					if (nested) {
						return nested
					}
				}
				return null
			}

			const fromToc = search(this.epub?.navigation?.toc)
			if (fromToc?.trim()) {
				return fromToc.trim()
			}

			try {
				const doc = this.rendition?.getContents()?.[0]?.document
				const heading = doc?.querySelector('h1, h2')?.textContent?.trim()
				if (heading) {
					return heading.replace(/\s+/g, ' ')
				}
			} catch (error) {
				// Falls through to no chapter name, which is only cosmetic.
			}

			return ''
		},

		/**
		 * Page numbers, real ones if the book carries a page-list.
		 */
		updatePage(cfi) {
			try {
				const pageList = this.epub.pageList
				if (pageList?.totalPages > 0) {
					const page = pageList.pageFromCfi(cfi)
					if (page > 0) {
						this.realPages = true
						this.page = page
						this.pageTotal = pageList.lastPage
						return
					}
				}

				// No page-list: fall back to the locations index we already built for
				// the percentage readout. Evenly sized chunks, not typeset pages.
				const total = this.epub.locations.total
				if (total > 0) {
					this.page = this.epub.locations.locationFromCfi(cfi) + 1
					this.pageTotal = total
				}
			} catch (error) {
				// A missing page readout is not worth interrupting reading over.
			}
		},

		onKey(event) {
			if (event.key === 'ArrowLeft') {
				this.prev()
			} else if (event.key === 'ArrowRight') {
				this.next()
			}
		},

		prev() {
			this.rendition?.prev()
		},

		next() {
			this.rendition?.next()
		},

		seek(event) {
			const cfi = this.epub.locations.cfiFromPercentage(Number(event.target.value) / 100)
			this.rendition?.display(cfi)
		},

		setFontSize(size) {
			this.fontSize = Math.min(MAX_FONT_SIZE, Math.max(MIN_FONT_SIZE, size))
			localStorage.setItem(FONT_SIZE_KEY, String(this.fontSize))
			this.rendition?.themes.fontSize(`${this.fontSize}%`)
		},
	},
}
</script>

<style scoped lang="scss">
.reader {
	display: flex;
	flex-direction: column;
	height: 100%;
	box-sizing: border-box;

	&__header {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 3);
		padding: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 12)
			calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 4);
		border-block-end: 1px solid var(--color-border);
	}

	&__title {
		font-weight: 600;
	}

	&__title,
	&__chapter {
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__chapter {
		color: var(--color-text-maxcontrast);
		font-size: .9em;
	}

	&__resumed {
		color: var(--color-text-maxcontrast);
		font-size: .85em;
		white-space: nowrap;
	}

	&__zoom {
		display: flex;
		gap: var(--default-grid-baseline);
		margin-inline-start: auto;
	}

	&__stage {
		position: relative;
		flex: 1;
		display: flex;
		align-items: center;
		min-height: 0;
	}

	&__viewer {
		flex: 1;
		height: 100%;
		min-width: 0;
		// The book brings its own typography on a light page; forcing the
		// Nextcloud dark theme onto arbitrary EPUB CSS produces unreadable
		// combinations far more often than it helps.
		background-color: #fff;
	}

	&__page-button {
		flex: 0 0 auto;
		margin-inline: var(--default-grid-baseline);
	}

	&__overlay {
		position: absolute;
		inset: 0;
		display: flex;
		align-items: center;
		justify-content: center;
		background-color: var(--color-main-background);
	}

	&__footer {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 4);
		padding: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 4);
		border-block-start: 1px solid var(--color-border);
	}

	&__slider {
		flex: 1;
		min-width: 0;
	}

	&__percent {
		color: var(--color-text-maxcontrast);
		font-size: .9em;
		font-variant-numeric: tabular-nums;
		min-width: 6em;
		text-align: end;
	}
}
</style>
