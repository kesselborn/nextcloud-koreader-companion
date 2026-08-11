<template>
	<div class="library">
		<!-- Rendered unconditionally, unlike the old template which omitted the
		     search box entirely when the library was empty -- that left the page
		     unable to recover without a reload. -->
		<div class="library__toolbar">
			<NcTextField
				v-model="query"
				:label="t('koreader_companion', 'Search by title or author')"
				trailing-button-icon="close"
				:show-trailing-button="query !== ''"
				class="library__search"
				@trailing-button-click="query = ''"
				@update:model-value="onQueryInput" />

			<NcSelect
				v-model="sort"
				:options="sortOptions"
				:clearable="false"
				label="label"
				:reduce="option => option.value"
				:input-label="t('koreader_companion', 'Sort by')"
				:aria-label-combobox="t('koreader_companion', 'Sort order')"
				class="library__sort"
				@update:model-value="reload" />

			<span class="library__count">
				{{ n('koreader_companion', '%n book', '%n books', books.length) }}
			</span>

			<!-- Only rendered while something is actually pending: extraction
			     normally happens on its own, and a permanent button would imply the
			     library needs manual upkeep. -->
			<NcNoteCard
				v-if="pendingCount > 0"
				type="info"
				class="library__pending-note">
				<div class="library__pending">
					<span>
						{{ n('koreader_companion',
							'%n book is being processed. Its details will appear here shortly.',
							'%n books are being processed. Their details will appear here shortly.',
							pendingCount) }}
					</span>
					<NcButton
						:disabled="extracting"
						class="library__pending-action"
						@click="extractNow">
						<template #icon>
							<NcLoadingIcon v-if="extracting" :size="20" />
							<Refresh v-else :size="20" />
						</template>
						{{ extracting
							? t('koreader_companion', 'Extracting…')
							: t('koreader_companion', 'Extract metadata now') }}
					</NcButton>
				</div>
			</NcNoteCard>
		</div>

		<NcEmptyContent
			v-if="!loading && books.length === 0"
			:name="query
				? t('koreader_companion', 'No books match your search')
				: t('koreader_companion', 'No books yet')"
			:description="query
				? t('koreader_companion', 'Try a different title or author.')
				: t('koreader_companion', 'Add EPUB, PDF or comic files to your eBooks folder, or upload them here.')">
			<template #icon>
				<BookOpenVariant />
			</template>
		</NcEmptyContent>

		<ul v-else class="library__grid">
			<BookCard
				v-for="book in books"
				:key="book.id"
				:book="book"
				@edit="$emit('edit', book)"
				@read="$emit('read', book)"
				@search-author="searchAuthor" />
		</ul>

		<NcLoadingIcon v-if="loading" :size="32" class="library__loading" />

		<!-- Sentinel for infinite scroll; only rendered while more pages may exist. -->
		<div v-if="hasMore && !loading" ref="sentinel" class="library__sentinel" />
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import BookOpenVariant from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

import BookCard from '../components/BookCard.vue'
import { fetchBooks, processPending } from '../api.js'

const PER_PAGE = 50

export default {
	name: 'LibraryView',

	components: {
		BookCard,
		BookOpenVariant,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
		Refresh,
	},

	emits: ['edit', 'read'],

	data() {
		return {
			books: [],
			page: 1,
			query: '',
			// The backend has supported these all along; the old UI never sent a
			// sort parameter and instead re-sorted whatever rows were in the DOM.
			sort: 'title',
			loading: false,
			extracting: false,
			hasMore: true,
			observer: null,
			debounce: null,
			pendingPoll: null,
		}
	},

	computed: {
		pendingCount() {
			return this.books.filter(book => book.indexing_state === 'pending').length
		},
		sortOptions() {
			return [
				{ value: 'title', label: t('koreader_companion', 'Title') },
				{ value: 'author', label: t('koreader_companion', 'Author') },
				{ value: 'recent', label: t('koreader_companion', 'Recently added') },
				{ value: 'publication_date', label: t('koreader_companion', 'Publication date') },
			]
		},
	},

	mounted() {
		this.reload()
	},

	beforeUnmount() {
		this.observer?.disconnect()
		clearTimeout(this.debounce)
		clearInterval(this.pendingPoll)
	},

	watch: {
		// Extraction happens in a background job, so a freshly uploaded book stays
		// 'pending' until cron picks it up. Poll while any book is waiting so the
		// list fills itself in, and stop as soon as none are -- no timer ticking
		// away on an idle library.
		pendingCount(count) {
			if (count > 0 && !this.pendingPoll) {
				this.pendingPoll = setInterval(() => this.refreshInPlace(), 5000)
			} else if (count === 0 && this.pendingPoll) {
				clearInterval(this.pendingPoll)
				this.pendingPoll = null
			}
		},
	},

	updated() {
		this.attachObserver()
	},

	methods: {
		onQueryInput() {
			clearTimeout(this.debounce)
			this.debounce = setTimeout(() => this.reload(), 300)
		},

		/**
		 * Re-fetch the pages already on screen without collapsing the list, so a
		 * pending book resolving in place does not scroll the user to the top.
		 */
		async refreshInPlace() {
			const pages = Math.max(1, this.page - 1)
			try {
				const batches = await Promise.all(
					Array.from({ length: pages }, (_, i) => fetchBooks({
						page: i + 1,
						query: this.query,
						sort: this.sort,
					})),
				)
				this.books = batches.flat()
			} catch (error) {
				// A failed refresh is not worth interrupting the user; the next
				// tick tries again.
			}
		},

		/**
		 * Put an author into the search box and run the search.
		 *
		 * Goes through the same query the user could have typed rather than a
		 * dedicated author filter, so the box reflects what is on screen and can be
		 * edited or cleared normally.
		 */
		searchAuthor(author) {
			this.query = author
			clearTimeout(this.debounce)
			this.reload()
		},

		/**
		 * Do the extraction the background job would have done, now.
		 *
		 * The server works through a bounded batch, so a big backlog needs more
		 * than one press -- hence reporting what is left rather than implying the
		 * queue is drained.
		 */
		async extractNow() {
			this.extracting = true
			try {
				const { processed, failed, remaining } = await processPending()
				await this.refreshInPlace()

				if (failed > 0) {
					showError(n('koreader_companion',
						'%n book could not be read',
						'%n books could not be read',
						failed))
				}

				if (remaining > 0) {
					showSuccess(n('koreader_companion',
						'%n book still waiting. Press again to continue.',
						'%n books still waiting. Press again to continue.',
						remaining))
				} else if (processed > 0) {
					showSuccess(t('koreader_companion', 'Metadata extracted'))
				}
			} catch (error) {
				showError(t('koreader_companion', 'Could not extract metadata'))
			} finally {
				this.extracting = false
			}
		},

		async reload() {
			this.page = 1
			this.hasMore = true
			this.books = []
			await this.loadPage()
		},

		async loadPage() {
			if (this.loading) {
				return
			}
			this.loading = true
			try {
				const batch = await fetchBooks({
					page: this.page,
					query: this.query,
					sort: this.sort,
				})
				this.books.push(...batch)
				this.hasMore = batch.length === PER_PAGE
				this.page += 1
			} catch (error) {
				showError(t('koreader_companion', 'Could not load your library'))
				this.hasMore = false
			} finally {
				this.loading = false
			}
		},

		attachObserver() {
			const sentinel = this.$refs.sentinel
			if (!sentinel) {
				return
			}
			this.observer?.disconnect()
			this.observer = new IntersectionObserver((entries) => {
				if (entries.some(entry => entry.isIntersecting)) {
					this.loadPage()
				}
			}, { rootMargin: '400px' })
			this.observer.observe(sentinel)
		},
	},
}
</script>

<style scoped lang="scss">
.library {
	padding: calc(var(--default-grid-baseline) * 4);

	&__toolbar {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 3);
		margin-block-end: calc(var(--default-grid-baseline) * 4);
	}

	&__search {
		flex: 1 1 260px;
		max-width: 420px;
	}

	&__sort {
		flex: 0 0 200px;
		min-width: 180px;
	}

	&__pending-note {
		flex: 1 1 100%;
		margin-block: 0;
	}

	&__pending {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		justify-content: space-between;
		gap: calc(var(--default-grid-baseline) * 3);
	}

	&__pending-action {
		flex: 0 0 auto;
	}

	&__count {
		color: var(--color-text-maxcontrast);
		font-size: .9em;
		margin-inline-start: auto;
		font-variant-numeric: tabular-nums;
	}

	&__grid {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
		gap: calc(var(--default-grid-baseline) * 5);
		list-style: none;
		margin: 0;
		padding: 0;
	}

	&__loading {
		margin: calc(var(--default-grid-baseline) * 6) auto;
	}

	&__sentinel {
		height: 1px;
	}
}
</style>
