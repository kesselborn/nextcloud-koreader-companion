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
				:aria-label-combobox="t('koreader_companion', 'Sort order')"
				class="library__sort"
				@update:model-value="reload" />

			<span class="library__count">
				{{ n('koreader_companion', '%n book', '%n books', books.length) }}
			</span>
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
				@edit="$emit('edit', book)" />
		</ul>

		<NcLoadingIcon v-if="loading" :size="32" class="library__loading" />

		<!-- Sentinel for infinite scroll; only rendered while more pages may exist. -->
		<div v-if="hasMore && !loading" ref="sentinel" class="library__sentinel" />
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import BookOpenVariant from 'vue-material-design-icons/BookOpenVariantOutline.vue'

import BookCard from '../components/BookCard.vue'
import { fetchBooks } from '../api.js'

const PER_PAGE = 50

export default {
	name: 'LibraryView',

	components: {
		BookCard,
		BookOpenVariant,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
	},

	emits: ['edit'],

	data() {
		return {
			books: [],
			page: 1,
			query: '',
			// The backend has supported these all along; the old UI never sent a
			// sort parameter and instead re-sorted whatever rows were in the DOM.
			sort: 'title',
			loading: false,
			hasMore: true,
			observer: null,
			debounce: null,
		}
	},

	computed: {
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
	},

	updated() {
		this.attachObserver()
	},

	methods: {
		onQueryInput() {
			clearTimeout(this.debounce)
			this.debounce = setTimeout(() => this.reload(), 300)
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
