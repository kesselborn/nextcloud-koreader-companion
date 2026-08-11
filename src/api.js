import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

// @nextcloud/axios attaches the CSRF token automatically, which matters now that
// the state-changing endpoints no longer carry #[NoCSRFRequired]. The old
// hand-rolled XHRs set the header manually and three of them forgot.
const url = (path) => generateUrl('/apps/koreader_companion' + path)

/** Cover URL via the core preview endpoint -- session auth, cached by Nextcloud. */
export function coverUrl(fileId, width = 256, height = 384) {
	return generateUrl('/core/preview?fileId={fileId}&x={width}&y={height}&a=0&forceIcon=0', {
		fileId,
		width,
		height,
	})
}

/** Raw EPUB bytes for the reader; epub.js unpacks the archive client-side. */
export async function fetchBookFile(id) {
	const { data } = await axios.get(url(`/books/${id}/file`), { responseType: 'arraybuffer' })
	return data
}

export async function fetchBooks({ page = 1, query = '', sort = 'title' } = {}) {
	const { data } = await axios.get(url('/'), {
		params: { page, q: query, sort },
		headers: { Accept: 'application/json' },
	})
	return Array.isArray(data) ? data : []
}

/**
 * Extract metadata now for books still marked pending.
 *
 * Nextcloud cannot be asked to run one specific background job, so this does the
 * work in-request rather than triggering the queue. Bounded server-side; the
 * response reports what is left so the caller can offer another round.
 */
export async function processPending() {
	const { data } = await axios.post(url('/books/process-pending'))
	return data
}

/**
 * Save a reading position so devices can pick it up.
 *
 * `progress` must be a KOReader xpointer, not a CFI -- the sync table is shared
 * with real devices and they cannot parse a CFI.
 */
export async function saveProgress(id, { progress, percentage, device }) {
	const { data } = await axios.put(url(`/books/${id}/progress`), { progress, percentage, device })
	return data
}

export async function getSettings() {
	const { data } = await axios.get(url('/settings'))
	return data
}

export async function setFolder(folder) {
	const { data } = await axios.put(url('/settings/folder'), { folder })
	return data
}

export async function setAutoRename(enabled) {
	return axios.put(url('/settings/auto-rename'), { auto_rename: enabled ? 'yes' : 'no' })
}

export async function batchRename() {
	const { data } = await axios.post(url('/settings/batch-rename'), { auto_rename: 'yes' })
	return data
}

export async function getBatchRenameProgress() {
	const { data } = await axios.get(url('/settings/batch-rename-progress'))
	return data
}

export async function setSyncPassword(password) {
	return axios.put(url('/settings/koreader-password'), { password })
}

export async function updateMetadata(id, metadata) {
	const { data } = await axios.put(url(`/books/${id}/metadata`), metadata)
	return data
}

export async function deleteBook(id) {
	return axios.delete(url(`/books/${id}`))
}

/** Two-step upload: extract metadata for confirmation, then commit the file. */
export async function extractMetadata(file) {
	const form = new FormData()
	form.append('file', file)
	const { data } = await axios.post(url('/extract-metadata'), form)
	return data
}

export async function uploadBook(file, metadata, onProgress) {
	const form = new FormData()
	form.append('file', file)
	Object.entries(metadata || {}).forEach(([k, v]) => {
		if (v !== null && v !== undefined && v !== '') {
			form.append(k, v)
		}
	})

	const { data } = await axios.post(url('/upload'), form, {
		onUploadProgress: (e) => {
			if (onProgress && e.total) {
				onProgress(Math.round((e.loaded / e.total) * 100))
			}
		},
	})
	return data
}
