import { useCallback, useEffect, useRef, useState, type ImgHTMLAttributes } from 'react'
import { ImageOff } from 'lucide-react'

interface Props extends ImgHTMLAttributes<HTMLImageElement> {
  fallback?: string
}

export default function ImageWithFallback({ alt, className = '', fallback, ...props }: Props) {
  const [error, setError] = useState(false)
  const [loaded, setLoaded] = useState(false)
  const imgRef = useRef<HTMLImageElement | null>(null)
  const prevSrcRef = useRef<string | undefined>(props.src)

  const handleLoad = useCallback(() => setLoaded(true), [])
  const handleError = useCallback(() => setError(true), [])

  useEffect(() => {
    if (prevSrcRef.current !== props.src) {
      prevSrcRef.current = props.src
      setError(false)
      setLoaded(false)
    }

    // If the image is already cached (complete), the browser fires the load
    // event synchronously when img.src is set, before React attaches the
    // onLoad handler.  Check complete here to catch that race condition.
    const img = imgRef.current
    if (img?.complete) {
      if (img.naturalWidth > 0) {
        setLoaded(true)
      } else {
        setError(true)
      }
    }
  }, [props.src])

  if (error || !props.src) {
    return (
      <div className={`flex items-center justify-center bg-surface-tertiary ${className}`}>
        {fallback ? (
          <span className="text-lg font-bold text-muted">{fallback}</span>
        ) : (
          <ImageOff className="h-6 w-6 text-muted" />
        )}
      </div>
    )
  }

  return (
    <>
      {!loaded && (
        <div className={`animate-pulse bg-surface-tertiary ${className}`} />
      )}
      <img
        ref={imgRef}
        {...props}
        alt={alt}
        onError={handleError}
        onLoad={handleLoad}
        className={`${className} ${loaded ? '' : 'hidden'}`}
      />
    </>
  )
}
