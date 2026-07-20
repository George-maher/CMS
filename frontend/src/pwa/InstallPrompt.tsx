import { usePwaInstall } from './usePwaInstall'
import InstallPwaModal from './InstallPwaModal'

export default function InstallPrompt() {
  const pwa = usePwaInstall(2000)

  if (!pwa.show) return null

  return <InstallPwaModal {...pwa} />
}
