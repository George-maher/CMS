import { useEffect, useRef, useState, type DragEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Upload, X } from 'lucide-react'

interface Props {
  value: File | string | null
  onChange: (file: File | null) => void
}

export default function ImageUpload({ value, onChange }: Props) {
  const { t } = useTranslation()
  const inputRef = useRef<HTMLInputElement>(null)
  const [dragging, setDragging] = useState(false)
  const [objectUrl, setObjectUrl] = useState<string | null>(null)
  const objectUrlRef = useRef<string | null>(null)

  useEffect(() => {
    if (value instanceof File) {
      if (objectUrlRef.current) {
        URL.revokeObjectURL(objectUrlRef.current)
      }
      const url = URL.createObjectURL(value)
      objectUrlRef.current = url
      setObjectUrl(url)
      return () => {
        if (objectUrlRef.current) {
          URL.revokeObjectURL(objectUrlRef.current)
          objectUrlRef.current = null
        }
        setObjectUrl(null)
      }
    } else {
      if (objectUrlRef.current) {
        URL.revokeObjectURL(objectUrlRef.current)
        objectUrlRef.current = null
      }
      setObjectUrl(null)
    }
  }, [value])

  const previewUrl = value instanceof File ? objectUrl : value

  const handleDrop = (e: DragEvent) => {
    e.preventDefault()
    setDragging(false)
    const file = e.dataTransfer.files[0]
    if (file?.type.startsWith('image/')) onChange(file)
  }

  const handleFile = (file: File | null) => {
    if (file?.type.startsWith('image/')) onChange(file)
  }

  return (
    <div>
      <label className="label">{t('events.image')}</label>
      {previewUrl ? (
        <div className="relative rounded-lg overflow-hidden border">
          <img src={previewUrl} alt="Preview" className="w-full h-32 object-cover" />
          <button
            type="button"
            onClick={() => { if (objectUrlRef.current) { URL.revokeObjectURL(objectUrlRef.current); objectUrlRef.current = null; setObjectUrl(null) }; onChange(null) }}
            className="absolute top-2 right-2 rounded-full bg-black/60 p-1.5 text-white hover:bg-black/80 transition-colors"
          >
            <X className="h-4 w-4" />
          </button>
        </div>
      ) : (
        <div
          onDragOver={(e) => { e.preventDefault(); setDragging(true) }}
          onDragLeave={() => setDragging(false)}
          onDrop={handleDrop}
          onClick={() => inputRef.current?.click()}
          className={`flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed p-6 transition-colors ${
            dragging
              ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20'
              : 'border-border hover:border-muted'
          }`}
        >
          <Upload className="mb-2 h-8 w-8 text-muted" />
          <p className="text-sm text-secondary">{t('events.dragDrop')}</p>
          <p className="mt-1 text-xs text-muted">{t('events.imageFormats')}</p>
        </div>
      )}
      <input
        ref={inputRef}
        type="file"
        accept="image/jpeg,image/png,image/gif,image/webp"
        className="hidden"
        onChange={(e) => handleFile(e.target.files?.[0] ?? null)}
      />
    </div>
  )
}
