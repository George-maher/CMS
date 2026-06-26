import { Loader2 } from 'lucide-react'

interface Props {
  size?: 'sm' | 'md' | 'lg'
  className?: string
}

const sizeMap = { sm: 'h-5 w-5', md: 'h-8 w-8', lg: 'h-12 w-12' }

export default function LoadingSpinner({ size = 'md', className = '' }: Props) {
  return (
    <div className={`flex items-center justify-center ${className}`}>
      <Loader2 className={`${sizeMap[size]} animate-spin gold-text`} />
    </div>
  )
}
