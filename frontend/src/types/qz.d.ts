declare global {
  interface QZPortConfiguration {
    secure: number[];
    insecure: number[];
  }

  interface QZConfig {
    host?: string;
    signUrl?: string;
    certUrl?: string;
    port?: QZPortConfiguration;
    keepAlive?: number;
    retries?: number;
    delay?: number;
  }

  interface QZPromiseResolver<TValue> {
    (resolve: { (): void; (value: TValue): void }, reject: (reason?: unknown) => void): void;
  }

  interface QZSecurity {
    setCertificatePromise: (promise: QZPromiseResolver<string>) => void;
    setSignaturePromise: (promise: (toSign: string) => QZPromiseResolver<string>) => void;
    setSignatureAlgorithm: (algorithm: 'SHA1' | 'SHA256' | 'SHA512') => void;
  }

  interface QZPrinters {
    find: (query?: string) => Promise<string | string[]>;
    getDefault: () => Promise<string>;
  }

  interface QZConfigs {
    create: (printer: string, options?: QZPrintOptions) => QZPrinterConfig;
  }

  interface QZPrintOptions {
    orientation?: 'portrait' | 'landscape';
    margins?: number;
    altPrinting?: boolean;
    encoding?: string;
  }

  interface QZPrinterConfig {
    getPrinter: () => string | { name?: string; file?: string; host?: string; port?: string };
    getOptions: () => QZPrintOptions;
  }

  interface QZHtmlPrintData {
    type: 'html';
    format: 'plain';
    data: string;
  }

  interface QZPdfPrintData {
    type: 'pdf';
    format: 'base64';
    data: string;
  }

  interface QZPrint {
    print: (config: QZPrinterConfig, data: Array<QZHtmlPrintData | QZPdfPrintData>) => Promise<void>;
  }

  interface QZWebsocket {
    connect: (config?: QZConfig) => Promise<null>;
    disconnect: () => Promise<null>;
    isActive: () => boolean;
  }

  interface QZ {
    security: QZSecurity;
    printers: QZPrinters;
    configs: QZConfigs;
    print: QZPrint['print'];
    websocket: QZWebsocket;
  }
}

export {};
