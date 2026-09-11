interface Props {
  className?: string
  variant?: 'text' | 'circle' | 'rect' | 'card'
  lines?: number
  width?: string
  height?: string
}

function SkeletonPulse({ className = '', style }: { className?: string; style?: React.CSSProperties }) {
  return (
    <div
      className={`animate-pulse rounded bg-gray-200 dark:bg-gray-700 ${className}`}
      style={style}
    />
  )
}

export default function Skeleton({ variant = 'text', lines = 1, className = '', width, height }: Props) {
  if (variant === 'card') {
    return (
      <div className={`rounded-xl border border-border bg-surface p-4 space-y-3 ${className}`}>
        <SkeletonPulse className="h-5 w-1/3 rounded" />
        <SkeletonPulse className="h-4 w-full rounded" />
        <SkeletonPulse className="h-4 w-2/3 rounded" />
        <div className="flex gap-2 pt-2">
          <SkeletonPulse className="h-8 w-20 rounded-lg" />
          <SkeletonPulse className="h-8 w-20 rounded-lg" />
        </div>
      </div>
    )
  }

  if (variant === 'circle') {
    return <SkeletonPulse className={`rounded-full ${className}`} style={{ width: width || '2.5rem', height: height || '2.5rem' }} />
  }

  if (variant === 'rect') {
    return <SkeletonPulse className={`rounded-lg ${className}`} style={{ width: width || '100%', height: height || '10rem' }} />
  }

  // text variant
  return (
    <div className={`space-y-2 ${className}`}>
      {Array.from({ length: lines }).map((_, i) => (
        <SkeletonPulse
          key={i}
          className="h-4 rounded"
          style={{
            width: i === lines - 1 ? '60%' : '100%',
          }}
        />
      ))}
    </div>
  )
}

/**
 * Full page skeleton that replaces the spinner in AppLayout.
 * Shows a realistic page skeleton matching the sidebar + header + content layout.
 */
export function PageSkeleton() {
  return (
    <div className="flex h-screen bg-surface-secondary">
      {/* Sidebar skeleton */}
      <div className="hidden lg:flex w-64 flex-col border-r border-border bg-surface">
        <div className="h-16 border-b border-border p-4 flex items-center gap-3">
          <SkeletonPulse className="h-9 w-9 rounded-xl" />
          <div className="space-y-1.5">
            <SkeletonPulse className="h-4 w-24 rounded" />
            <SkeletonPulse className="h-2.5 w-16 rounded" />
          </div>
        </div>
        <div className="p-3 space-y-1">
          {Array.from({ length: 8 }).map((_, i) => (
            <div key={i} className="flex items-center gap-3 px-3 py-2.5 rounded-lg">
              <SkeletonPulse className="h-5 w-5 rounded" />
              <SkeletonPulse className="h-4 rounded" style={{ width: `${60 + (i % 3) * 15}%` }} />
            </div>
          ))}
        </div>
      </div>

      {/* Main content skeleton */}
      <div className="flex-1 flex flex-col min-w-0">
        {/* Header skeleton */}
        <div className="h-16 border-b border-border bg-surface flex items-center justify-between px-4">
          <SkeletonPulse className="h-8 w-8 rounded-lg lg:hidden" />
          <SkeletonPulse className="h-8 w-48 rounded" />
          <div className="flex items-center gap-3">
            <SkeletonPulse className="h-8 w-8 rounded-full" />
            <SkeletonPulse className="h-8 w-8 rounded-full" />
          </div>
        </div>

        {/* Page content skeleton */}
        <div className="flex-1 overflow-y-auto p-4 md:p-6 space-y-6">
          {/* Title */}
          <SkeletonPulse className="h-7 w-48 rounded" />

          {/* Stats cards */}
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            {Array.from({ length: 4 }).map((_, i) => (
              <div key={i} className="rounded-xl border border-border bg-surface p-4 space-y-2">
                <SkeletonPulse className="h-4 w-20 rounded" />
                <SkeletonPulse className="h-7 w-16 rounded" />
                <SkeletonPulse className="h-3 w-24 rounded" />
              </div>
            ))}
          </div>

          {/* Table skeleton */}
          <div className="rounded-xl border border-border bg-surface overflow-hidden">
            <div className="p-4 border-b border-border flex items-center justify-between">
              <SkeletonPulse className="h-5 w-32 rounded" />
              <SkeletonPulse className="h-8 w-24 rounded-lg" />
            </div>
            <div className="divide-y divide-border">
              {Array.from({ length: 5 }).map((_, i) => (
                <div key={i} className="p-4 flex items-center gap-4">
                  <SkeletonPulse className="h-10 w-10 rounded-full shrink-0" />
                  <div className="flex-1 space-y-2">
                    <SkeletonPulse className="h-4 w-1/3 rounded" />
                    <SkeletonPulse className="h-3 w-1/4 rounded" />
                  </div>
                  <SkeletonPulse className="h-8 w-20 rounded-lg hidden sm:block" />
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
