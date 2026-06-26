declare module 'qrcode' {
  interface QRCodeToDataURLOptions {
    width?: number
    margin?: number
    color?: { dark?: string; light?: string }
    type?: 'image/png' | 'image/webp'
  }

  export function toDataURL(
    text: string,
    options?: QRCodeToDataURLOptions,
  ): Promise<string>

  export function toString(
    text: string,
    options?: { type?: 'utf8' | 'svg' | 'terminal' },
  ): Promise<string>
}
