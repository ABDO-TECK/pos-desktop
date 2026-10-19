interface ElectronAPI {
  getVersion: () => Promise<string>;
  getQZCert: () => Promise<string | null>;
  signQZMessage: (data: string) => Promise<string | null>;
  backup?: {
    restore: () => Promise<{
      success: boolean;
      cancelled?: boolean;
      error?: string;
    }>;
  };
  auth?: {
    clearSession: () => Promise<{ success: boolean; error?: string }>;
    recoverPassword: (payload: { email: string; password: string }) => Promise<{
      success: boolean;
      error?: string;
    }>;
  };
  setup?: {
    getInitialAdmin: () => Promise<{
      email: string;
      name: string;
      password: string;
      forcePasswordChange: boolean;
    } | null>;
    acknowledgeInitialAdmin: () => Promise<{ success: boolean }>;
    factoryReset: (options: { confirmationToken: string }) => Promise<{
      success: boolean;
      cancelled?: boolean;
      adminSetupRequired?: boolean;
      error?: string;
    }>;
  };

  updater?: {
    download: () => Promise<UpdaterStatus>;
    install: () => Promise<UpdaterStatus>;
    getStatus: () => Promise<UpdaterStatus>;
    onStatus: (callback: (status: UpdaterStatus) => void) => () => void;
  };
}

interface Window {
  electronAPI?: ElectronAPI;
  posRuntime?: {
    getApiBaseUrl: () => Promise<string | null>;
    getDeltaCapability: () => Promise<{ capable: boolean; protocol: number }>;
    enableLanAccess: () => Promise<{
      enabled: boolean;
      port: number;
      protocol: 'https';
      firewallConfigured?: boolean;
      firewallRequired?: boolean;
      error?: string;
    }>;
    disableLanAccess: () => Promise<{
      enabled: boolean;
      port: number;
      protocol: 'https';
      firewallConfigured?: boolean;
      firewallRequired?: boolean;
      error?: string;
    }>;
    applyStagedDelta: (version: string) => Promise<{ ok: boolean; error?: string }>;
  };
  API_BASE_URL?: string;
}

interface UpdaterStatus {
  state:
    | 'idle'
    | 'checking'
    | 'update_available'
    | 'update_not_available'
    | 'downloading'
    | 'ready_to_install'
    | 'restarting'
    | 'developer_only'
    | 'error';
  isPackaged: boolean;
  updateInfo?: { version?: string; [key: string]: unknown } | null;
  progress?: { percent?: number; transferred?: number; total?: number } | null;
  error?: string | null;
  canInstall?: boolean;
}
