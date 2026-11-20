import assert from 'node:assert/strict';
import fs from 'node:fs';
import { createRequire } from 'node:module';
import vm from 'node:vm';

const frontendRequire = createRequire(new URL('../frontend/package.json', import.meta.url));
const ts = frontendRequire('typescript');
const source = fs.readFileSync(new URL('../frontend/src/main.tsx', import.meta.url), 'utf8');
const { outputText } = ts.transpileModule(source, {
  compilerOptions: {
    module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX,
    esModuleInterop: true,
  },
});

async function startRenderer(startupStatus) {
  const pendingSale = { localId: 'pending-at-restart', retryCount: 1 };
  const auth = { _hasHydrated: true, isAuthenticated: true, user: { id: 2, branch_id: 1 } };
  const App = () => {};
  const jsx = (component, props) => ({ component, props });
  let mounted = false;
  let renderedApp;
  const render = (element) => {
    for (const child of element.props.children.filter(Boolean)) {
      if (child.component === App) {
        renderedApp = child.props;
      } else {
        mounted = true;
        child.component(child.props);
      }
    }
  };
  const modules = {
    react: { StrictMode: Symbol('StrictMode'), useEffect: (effect) => effect() },
    'react/jsx-runtime': { jsx, jsxs: jsx },
    'react-dom/client': { createRoot: () => ({ render }) },
    './store/themeStore': {},
    './App': App,
    './i18n': {},
    './styles/_index.css': {},
    './utils/offlineSync.js': {
      // A sync against a fenced API would record a failed attempt for this
      // persisted sale, even though no sale could reach the server.
      initOfflineSync: () => { pendingSale.retryCount += 1; },
    },
    './utils/clientLogger': {},
    './store/authStore': (selector) => selector(auth),
  };
  await vm.runInNewContext(outputText, {
    exports: {},
    require: (id) => {
      assert.ok(Object.hasOwn(modules, id), 'Unexpected runtime dependency ' + id);
      return modules[id];
    },
    window: {
      location: { protocol: 'app:' },
      addEventListener: () => {},
      posRuntime: { getApiBaseUrl: async () => null, isShellStartupFenced: startupStatus },
    },
    document: { addEventListener: () => {}, getElementById: () => ({}) },
    navigator: {},
  }, { filename: 'frontend/src/main.tsx' });
  return { retryCount: pendingSale.retryCount, mounted, runtimeFenced: renderedApp.runtimeFenced };
}

const fenced = { retryCount: 1, mounted: false, runtimeFenced: true };
assert.deepEqual(await startRenderer(async () => true), fenced);
assert.deepEqual(await startRenderer(async () => { throw new Error('Startup state unavailable'); }), fenced);
const admitted = { retryCount: 2, mounted: true, runtimeFenced: false };
assert.deepEqual(await startRenderer(async () => false), admitted);
assert.deepEqual(await startRenderer(undefined), admitted, 'Legacy renderer boots must keep offline sync enabled.');
console.log('Verified renderer startup leaves the pending-sale queue untouched until shell admission.');
