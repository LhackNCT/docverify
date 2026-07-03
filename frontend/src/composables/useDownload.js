import { ref } from 'vue'
import api from '@/api/axios'

export function useDownload() {
  const downloading  = ref(false)
  const downloadError = ref(null)

  async function download(url, filename) {
    if (!url || typeof url !== 'string' || !url.startsWith('/')) {
      downloadError.value = 'URL de téléchargement invalide.'
      return
    }

    const safeFilename = filename
      ? String(filename).replace(/[^a-zA-Z0-9._\-\s]/g, '_').slice(0, 200)
      : 'fichier'

    downloading.value   = true
    downloadError.value = null

    try {
      const response    = await api.get(url, { responseType: 'blob' })
      const contentType = response.headers['content-type'] || 'application/octet-stream'
      const blob        = new Blob([response.data], { type: contentType })
      const blobUrl     = URL.createObjectURL(blob)
      const link        = document.createElement('a')
      link.href         = blobUrl
      link.download     = safeFilename
      document.body.appendChild(link)
      link.click()
      link.remove()
      URL.revokeObjectURL(blobUrl)
    } catch (e) {
      if (e.response?.data instanceof Blob) {
        const text = await e.response.data.text().catch(() => '')
        try { downloadError.value = JSON.parse(text)?.message ?? 'Téléchargement impossible.' }
        catch { downloadError.value = text || 'Téléchargement impossible.' }
      } else {
        downloadError.value = e.response?.data?.message ?? 'Téléchargement impossible.'
      }
    } finally {
      downloading.value = false
    }
  }

  return { download, downloading, downloadError }
}
