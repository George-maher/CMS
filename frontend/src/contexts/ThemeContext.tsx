import { createContext, useContext, useEffect, useState, useCallback, type ReactNode } from 'react'
import i18n from 'i18next'

type Theme = 'light' | 'dark'
type Lang = 'en' | 'ar'

interface ThemeContextType {
  theme: Theme
  toggleTheme: () => void
  language: Lang
  setLanguage: (lang: Lang) => void
  dir: 'ltr' | 'rtl'
}

const ThemeContext = createContext<ThemeContextType | undefined>(undefined)

function getInitialTheme(): Theme {
  const stored = localStorage.getItem('theme')
  if (stored === 'light' || stored === 'dark') return stored
  return 'dark'
}

function getInitialLang(): Lang {
  const stored = localStorage.getItem('i18nextLng')
  if (stored === 'ar' || stored === 'en') return stored
  return 'en'
}

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [theme, setTheme] = useState<Theme>(getInitialTheme)
  const [language, setLang] = useState<Lang>(getInitialLang)
  const [dir, setDir] = useState<'ltr' | 'rtl'>(getInitialLang() === 'ar' ? 'rtl' : 'ltr')

  useEffect(() => {
    const root = document.documentElement
    root.classList.toggle('dark', theme === 'dark')
    localStorage.setItem('theme', theme)
  }, [theme])

  useEffect(() => {
    const root = document.documentElement
    const d = language === 'ar' ? 'rtl' : 'ltr'
    root.setAttribute('dir', d)
    root.setAttribute('lang', language)
    i18n.changeLanguage(language)
    setDir(d)
  }, [language])

  const toggleTheme = useCallback(() => {
    setTheme((prev) => (prev === 'light' ? 'dark' : 'light'))
  }, [])

  const setLanguage = useCallback((lang: Lang) => {
    setLang(lang)
    localStorage.setItem('i18nextLng', lang)
  }, [])

  return (
    <ThemeContext.Provider value={{ theme, toggleTheme, language, setLanguage, dir }}>
      {children}
    </ThemeContext.Provider>
  )
}

export function useTheme() {
  const context = useContext(ThemeContext)
  if (!context) throw new Error('useTheme must be used within ThemeProvider')
  return context
}
