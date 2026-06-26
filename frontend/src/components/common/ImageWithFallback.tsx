import { useEffect, useRef, useState, type ImgHTMLAttributes } from 'react'
import { ImageOff } from 'lucide-react'

interface Props extends ImgHTMLAttributes<HTMLImageElement> {
  fallback?: string
}

export default function ImageWithFallback({ alt, className = '', fallback, ...props }: Props) {
  const [error, setError] = useState(false)
  const [loaded, setLoaded] = useState(false)
  const prevSrcRef = useRef<string | undefined>(props.src)

  useEffect(() => {
    if (prevSrcRef.current !== props.src) {
      prevSrcRef.current = props.src
      setError(false)
      setLoaded(false)
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
        {...props}
        alt={alt}
        loading="lazy"
        onError={() => setError(true)}
        onLoad={() => setLoaded(true)}
        className={`${className} ${loaded ? '' : 'hidden'}`}
      />
    </>
  )
}
