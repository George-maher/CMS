declare module 'html5-qrcode' {
  interface CameraDevice {
    id: string
    label: string
  }

  interface Html5QrcodeConfig {
    formatsToSupport?: number[]
    useBarCodeDetectorIfSupported?: boolean
  }

  interface QrBox {
    width: number
    height: number
  }

  interface Html5QrcodeFullConfig extends Html5QrcodeConfig {
    fps?: number
    qrbox?: QrBox | number | ((viewfinderWidth: number, viewfinderHeight: number) => QrBox)
  }

  export class Html5Qrcode {
    constructor(elementId: string, config?: Html5QrcodeConfig)
    start(
      cameraIdOrConfig: string | { facingMode: string },
      config: Html5QrcodeFullConfig | undefined,
      onSuccess: (decodedText: string, decodedResult: any) => void,
      onError?: (errorMessage: string, error: any) => void,
    ): Promise<void>
    stop(): Promise<void>
    clear(): void
    static getCameras(): Promise<CameraDevice[]>
  }
}
