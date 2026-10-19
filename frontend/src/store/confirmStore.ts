import { create } from 'zustand'

interface ConfirmState {
  isOpen: boolean;
  message: string;
  resolvePromise: ((value: boolean) => void) | null;
  confirm: (message: string) => Promise<boolean>;
  handleConfirm: () => void;
  handleCancel: () => void;
  reset: () => void;
}

export const useConfirmStore = create<ConfirmState>((set, get) => ({
  isOpen: false,
  message: '',
  resolvePromise: null,

  confirm: (message: string) => {
    return new Promise<boolean>((resolve) => {
      set({ isOpen: true, message, resolvePromise: resolve })
    })
  },

  handleConfirm: () => {
    const { resolvePromise } = get()
    if (resolvePromise) resolvePromise(true)
    set({ isOpen: false, resolvePromise: null })
  },

  handleCancel: () => {
    const { resolvePromise } = get()
    if (resolvePromise) resolvePromise(false)
    set({ isOpen: false, resolvePromise: null })
  },

  reset: () => {
    const { resolvePromise } = get()
    if (resolvePromise) resolvePromise(false)
    set({ isOpen: false, message: '', resolvePromise: null })
  },
}))
