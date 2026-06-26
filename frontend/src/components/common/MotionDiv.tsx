import { type ReactNode } from 'react'

type Animation = 'fade-in' | 'fade-in-up' | 'slide-up' | 'scale-in' | 'float'

interface Props {
  children: ReactNode
  className?: string
  animation?: Animation
  delay?: number
  as?: 'div' | 'section' | 'article' | 'span'
  style?: React.CSSProperties
}

const animations: Record<Animation, string> = {
  'fade-in': 'animate-fade-in',
  'fade-in-up': 'animate-fade-in-up',
  'slide-up': 'animate-slide-up',
  'scale-in': 'animate-scale-in',
  'float': 'animate-float',
}

export default function MotionDiv({ children, className = '', animation = 'fade-in-up', delay = 0, as: Tag = 'div', style }: Props) {
  return (
    <Tag
      className={`${animations[animation]} ${className}`}
      style={{ animationDelay: `${delay}ms`, animationFillMode: 'both', ...style }}
    >
      {children}
    </Tag>
  )
}
