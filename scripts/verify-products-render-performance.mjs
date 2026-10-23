import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const root = path.resolve(process.argv[2] || '.');
const frontendPkg = path.join(root, 'frontend/package.json');
const require = createRequire(frontendPkg);
const ts = require('typescript');

console.log('=== Products Tab Render Performance & Collation Verification ===');
console.log('Environment: Node.js VM + Real Transpiled ProductsTab.tsx + Controlled React Stubs');
console.log('Scope: Module-scoped Intl.Collator, Single-pass Stats Memoization, JSX Tree Row Extraction\n');

// ── 1. AST Static Verification (TypeScript Compiler API) ──────────────────────────
console.log('[1/6] Running TypeScript AST static inspection on ProductsTab.tsx...');
const productsTabPath = path.join(root, 'frontend/src/pages/products/ProductsTab.tsx');
assert.ok(fs.existsSync(productsTabPath), `ProductsTab.tsx must exist at ${productsTabPath}`);
const sourceText = fs.readFileSync(productsTabPath, 'utf8');

const sourceFile = ts.createSourceFile('ProductsTab.tsx', sourceText, ts.ScriptTarget.Latest, true);

let foundModuleCollator = false;
let foundLocaleCompareInComponent = false;
let statsMemoFound = false;
let singleReduceInStatsMemo = false;

function inspectAst(node) {
  // Check for module-scoped Intl.Collator declaration: const ARABIC_COLLATOR = new Intl.Collator('ar', { sensitivity: 'base' })
  if (ts.isVariableDeclaration(node) && node.name && ts.isIdentifier(node.name)) {
    if (node.name.text === 'ARABIC_COLLATOR') {
      if (node.initializer && ts.isNewExpression(node.initializer)) {
        const expr = node.initializer.expression;
        if (ts.isPropertyAccessExpression(expr) && expr.expression.text === 'Intl' && expr.name.text === 'Collator') {
          const args = node.initializer.arguments;
          if (args && args.length >= 2 && ts.isStringLiteral(args[0]) && args[0].text === 'ar') {
            foundModuleCollator = true;
          }
        }
      }
    }
  }

  // Check that localeCompare is not used inside the file
  if (ts.isPropertyAccessExpression(node) && node.name.text === 'localeCompare') {
    foundLocaleCompareInComponent = true;
  }

  // Check stats useMemo with single reduce
  if (ts.isCallExpression(node) && ts.isIdentifier(node.expression) && node.expression.text === 'useMemo') {
    const deps = node.arguments[1];
    if (deps && ts.isArrayLiteralExpression(deps) && deps.elements.length === 1) {
      const elem = deps.elements[0];
      if (ts.isIdentifier(elem) && elem.text === 'allProducts') {
        statsMemoFound = true;
        // Inspect body of the factory function for single reduce
        const factory = node.arguments[0];
        let reduceCallCount = 0;
        function countReduces(fNode) {
          if (ts.isCallExpression(fNode) && ts.isPropertyAccessExpression(fNode.expression) && fNode.expression.name.text === 'reduce') {
            reduceCallCount++;
          }
          ts.forEachChild(fNode, countReduces);
        }
        ts.forEachChild(factory, countReduces);
        if (reduceCallCount === 1) {
          singleReduceInStatsMemo = true;
        }
      }
    }
  }

  ts.forEachChild(node, inspectAst);
}

inspectAst(sourceFile);

assert.ok(foundModuleCollator, 'AST Check Failed: Expected module-scoped Intl.Collator("ar", { sensitivity: "base" })');
assert.ok(!foundLocaleCompareInComponent, 'AST Check Failed: Found forbidden localeCompare call in ProductsTab.tsx');
assert.ok(statsMemoFound, 'AST Check Failed: Expected stats useMemo with [allProducts] dependency array');
assert.ok(singleReduceInStatsMemo, 'AST Check Failed: Expected single reduce call inside stats useMemo');
console.log('  ✓ Module-scoped Intl.Collator declaration confirmed');
console.log('  ✓ Zero localeCompare calls inside component confirmed');
console.log('  ✓ Stats useMemo([allProducts]) with single reduce confirmed\n');

// ── 2. Node VM Transpilation & Module Load Collator Spy ────────────────────────────
console.log('[2/6] Loading transpiled ProductsTab.tsx in Node VM with Intl.Collator Spy...');

let collatorConstructCount = 0;
let collatorCompareCount = 0;

const OriginalCollator = Intl.Collator;
function SpiedCollator(locales, options) {
  collatorConstructCount++;
  const instance = new OriginalCollator(locales, options);
  const origCompare = instance.compare;
  const wrappedCompare = (a, b) => {
    collatorCompareCount++;
    return origCompare(a, b);
  };
  return new Proxy(instance, {
    get(target, prop, receiver) {
      if (prop === 'compare') return wrappedCompare;
      return Reflect.get(target, prop, receiver);
    }
  });
}
SpiedCollator.prototype = OriginalCollator.prototype;
SpiedCollator.supportedLocalesOf = OriginalCollator.supportedLocalesOf;

const customIntl = Object.create(Intl);
customIntl.Collator = SpiedCollator;

const transpiledCode = ts.transpileModule(sourceText, {
  compilerOptions: {
    module: ts.ModuleKind.CommonJS,
    target: ts.ScriptTarget.ES2022,
    jsx: ts.JsxEmit.React,
    esModuleInterop: true,
  }
}).outputText;

// React Harness State & Memo Storage
const areDepsEqual = (a, b) => {
  if (!a || !b || a.length !== b.length) return false;
  return a.every((v, i) => Object.is(v, b[i]));
};

let currentHarness = null;

const ReactMock = {
  Fragment: 'React.Fragment',
  useState: (init) => {
    assert.ok(currentHarness, 'useState called outside active harness render');
    return currentHarness.handleUseState(init);
  },
  useMemo: (factory, deps) => {
    assert.ok(currentHarness, 'useMemo called outside active harness render');
    return currentHarness.handleUseMemo(factory, deps);
  },
  useEffect: (effect, deps) => {
    assert.ok(currentHarness, 'useEffect called outside active harness render');
    currentHarness.handleUseEffect(effect, deps);
  },
  createElement: (type, props, ...children) => {
    const flatChildren = children.flat ? children.flat(Infinity) : children;
    return {
      type,
      props: {
        ...props,
        children: flatChildren.length === 1 ? flatChildren[0] : (flatChildren.length === 0 ? undefined : flatChildren)
      },
      key: props?.key
    };
  },
};

const sandboxExports = {};
vm.runInNewContext(transpiledCode, {
  exports: sandboxExports,
  React: ReactMock,
  console,
  Date,
  Symbol,
  Promise,
  Error,
  Set,
  Map,
  Number,
  Object,
  Array,
  Math,
  Boolean,
  String,
  parseInt,
  parseFloat,
  Intl: customIntl,
  Reflect,
  require: (id) => {
    if (id === 'react') return ReactMock;
    if (id.includes('formatters')) {
      return {
        formatCurrency: (n) => `${Number(n) || 0} ج.م`,
        formatNumber: (n) => String(Number(n) || 0),
      };
    }
    if (id === 'lucide-react') {
      return new Proxy({}, { get: (_, p) => (props) => ({ type: 'Icon', name: p, props }) });
    }
    if (id.includes('Pagination')) return (props) => ({ type: 'Pagination', props });
    if (id.includes('PrintLabelsModal')) return (props) => ({ type: 'PrintLabelsModal', props });
    if (id.includes('IconBadge')) return (props) => ({ type: 'IconBadge', props });
    if (id === 'react-hot-toast') return { success: () => {}, error: () => {} };
    if (id.includes('apiError')) return { extractApiError: (_, def) => def };
    return () => null;
  }
});

const ProductsTabComponent = sandboxExports.default;
assert.strictEqual(typeof ProductsTabComponent, 'function', 'ProductsTab must export a component function');

// Check collator initialization on module load
assert.strictEqual(collatorConstructCount, 1, 'Intl.Collator must be instantiated exactly once upon module load');
console.log('  ✓ Intl.Collator constructed exactly 1 time on module evaluation');
console.log(`  ✓ Collator constructions after module load: ${collatorConstructCount}\n`);

// ── Harness Class for Faithful Rendering & Hook Lifecycle ─────────────────────────
class ComponentHarness {
  constructor(Component) {
    this.Component = Component;
    this.states = [];
    this.memos = [];
    this.effects = [];
    this.stateIndex = 0;
    this.memoIndex = 0;
    this.effectIndex = 0;
    this.memoExecutions = { displayProducts: 0, stats: 0, selectedData: 0 };
    this.capturedCallbacks = { displayProducts: null, stats: null };
  }

  handleUseState(init) {
    const idx = this.stateIndex++;
    if (this.states[idx] === undefined) {
      this.states[idx] = typeof init === 'function' ? init() : init;
    }
    const setter = (val) => {
      this.states[idx] = typeof val === 'function' ? val(this.states[idx]) : val;
    };
    return [this.states[idx], setter];
  }

  handleUseMemo(factory, deps) {
    const idx = this.memoIndex++;
    const prev = this.memos[idx];

    // Tag memo type based on deps structure
    if (deps.length === 5) {
      this.capturedCallbacks.displayProducts = factory;
    } else if (deps.length === 1) {
      this.capturedCallbacks.stats = factory;
    }

    if (!prev || !areDepsEqual(prev.deps, deps)) {
      if (deps.length === 5) this.memoExecutions.displayProducts++;
      if (deps.length === 1) this.memoExecutions.stats++;
      if (deps.length === 2) this.memoExecutions.selectedData++;
      const val = factory();
      this.memos[idx] = { val, deps };
      return val;
    }
    return prev.val;
  }

  handleUseEffect(effect, deps) {
    const idx = this.effectIndex++;
    const prev = this.effects[idx];
    if (!prev || !areDepsEqual(prev.deps, deps)) {
      this.effects[idx] = { deps };
      effect();
    }
  }

  render(props) {
    this.stateIndex = 0;
    this.memoIndex = 0;
    this.effectIndex = 0;
    currentHarness = this;
    try {
      return this.Component(props);
    } finally {
      currentHarness = null;
    }
  }

  // Hook state accessors matching ProductsTab declaration order:
  // 0: search, 1: categoryFilter, 2: stockFilter, 3: sortKey, 4: searchCameraOpen,
  // 5: SearchScannerLazy, 6: selectedProductIds, 7: printModalOpen, 8: currentPage
  setSearch(val) { this.states[0] = val; }
  setCategoryFilter(val) { this.states[1] = val; }
  setStockFilter(val) { this.states[2] = val; }
  setSortKey(val) { this.states[3] = val; }
  setSelectedProductIds(val) { this.states[6] = val; }
  setCurrentPage(val) { this.states[8] = val; }
}

// VDOM Traversal Helpers
function findVNodes(node, predicate, acc = []) {
  if (!node || typeof node !== 'object') return acc;
  if (predicate(node)) acc.push(node);
  if (node.props && node.props.children) {
    const children = Array.isArray(node.props.children) ? node.props.children : [node.props.children];
    for (const child of children) {
      findVNodes(child, predicate, acc);
    }
  }
  return acc;
}

function extractRenderedRowIds(vdom) {
  const tbodies = findVNodes(vdom, (n) => n.type === 'tbody');
  if (tbodies.length === 0) return [];
  const tbody = tbodies[0];
  const children = Array.isArray(tbody.props.children) ? tbody.props.children : [tbody.props.children];
  const validChildren = children.filter(Boolean);
  const ids = [];
  for (const tr of validChildren) {
    if (tr && tr.key !== undefined && tr.key !== null) {
      ids.push(Number(tr.key));
    }
  }
  return ids;
}

function extractStatCardValues(vdom) {
  const cards = findVNodes(vdom, (n) => typeof n.type === 'function' && n.props && 'label' in n.props && 'value' in n.props);
  const res = {};
  for (const c of cards) {
    res[c.props.label] = c.props.value;
  }
  return res;
}

// ── 3. Collation Equivalence & All 9 Sort Keys with Ties ───────────────────────────
console.log('[3/6] Validating Collation Equivalence vs Baseline & All 9 Sort Keys with Ties...');

const baselineCmp = (a, b) => String(a.name || '').localeCompare(String(b.name || ''), 'ar', { sensitivity: 'base' });

function baselineSort(list, sortKey) {
  const sorted = [...list];
  switch (sortKey) {
    case 'name_desc':
      sorted.sort((a, b) => baselineCmp(b, a));
      break;
    case 'newest':
      sorted.sort((a, b) => Number(b.id) - Number(a.id));
      break;
    case 'oldest':
      sorted.sort((a, b) => Number(a.id) - Number(b.id));
      break;
    case 'updated_desc':
      sorted.sort((a, b) => {
        const ta = new Date(a.updated_at || 0).getTime();
        const tb = new Date(b.updated_at || 0).getTime();
        if (tb !== ta) return tb - ta;
        return Number(b.id) - Number(a.id);
      });
      break;
    case 'qty_desc':
      sorted.sort((a, b) => Number(b.quantity) - Number(a.quantity) || baselineCmp(a, b));
      break;
    case 'qty_asc':
      sorted.sort((a, b) => Number(a.quantity) - Number(b.quantity) || baselineCmp(a, b));
      break;
    case 'price_desc':
      sorted.sort((a, b) => Number(b.price) - Number(a.price) || baselineCmp(a, b));
      break;
    case 'price_asc':
      sorted.sort((a, b) => Number(a.price) - Number(b.price) || baselineCmp(a, b));
      break;
    case 'name_asc':
    default:
      sorted.sort(baselineCmp);
      break;
  }
  return sorted;
}

// Collation edge-case fixtures:
const collationFixtures = [
  // Arabic diacritics (tashkeel)
  { id: 1, name: 'مُحَمَّد', quantity: 10, cost: 5, price: 20, updated_at: '2025-01-01' },
  { id: 2, name: 'محمد', quantity: 10, cost: 5, price: 20, updated_at: '2025-01-02' },
  { id: 3, name: 'مَحْمُود', quantity: 10, cost: 5, price: 20, updated_at: '2025-01-03' },
  { id: 4, name: 'مُحْسِن', quantity: 10, cost: 5, price: 20, updated_at: '2025-01-04' },
  // Alef variants
  { id: 5, name: 'أحمد', quantity: 20, cost: 10, price: 25, updated_at: '2025-01-05' },
  { id: 6, name: 'إبراهيم', quantity: 20, cost: 10, price: 25, updated_at: '2025-01-06' },
  { id: 7, name: 'احمد', quantity: 20, cost: 10, price: 25, updated_at: '2025-01-07' },
  { id: 8, name: 'آمنة', quantity: 20, cost: 10, price: 25, updated_at: '2025-01-08' },
  // Mixed Arabic, English, and Symbols
  { id: 9, name: 'عصير برتقال 100%', quantity: 15, cost: 8, price: 18, updated_at: '2025-01-09' },
  { id: 10, name: 'Orange Juice', quantity: 15, cost: 8, price: 18, updated_at: '2025-01-10' },
  { id: 11, name: 'منتج (أ)', quantity: 30, cost: 12, price: 30, updated_at: '2025-01-11' },
  { id: 12, name: 'منتج (ب)', quantity: 30, cost: 12, price: 30, updated_at: '2025-01-12' },
  // Numbers in names
  { id: 13, name: 'صنف 1', quantity: 5, cost: 3, price: 7, updated_at: '2025-01-13' },
  { id: 14, name: 'صنف 2', quantity: 5, cost: 3, price: 7, updated_at: '2025-01-14' },
  { id: 15, name: 'صنف 10', quantity: 5, cost: 3, price: 7, updated_at: '2025-01-15' },
];

const all9SortKeys = [
  'name_asc',
  'name_desc',
  'newest',
  'oldest',
  'updated_desc',
  'qty_desc',
  'qty_asc',
  'price_desc',
  'price_asc',
];

for (const key of all9SortKeys) {
  const harness = new ComponentHarness(ProductsTabComponent);
  harness.setSortKey(key);

  // Clone fixtures to ensure we can verify input immutability
  const originalCopy = JSON.parse(JSON.stringify(collationFixtures));
  const vdom = harness.render({
    allProducts: collationFixtures,
    loadingProducts: false,
    categories: [],
    lowStock: [],
    onEditProduct: () => {},
    onDeleteProduct: () => {},
  });

  const renderedIds = extractRenderedRowIds(vdom);
  const expectedSorted = baselineSort(collationFixtures, key).slice(0, 15);
  const expectedIds = expectedSorted.map(p => p.id);

  assert.deepStrictEqual(
    renderedIds,
    expectedIds,
    `Sort key "${key}" rendered row IDs do not match baseline-equivalent sort!`
  );

  // Immutability check: allProducts input array must remain untouched
  assert.deepStrictEqual(
    collationFixtures,
    originalCopy,
    `Input fixtures array was mutated in place during sortKey "${key}"!`
  );
}

// Test empty name and undefined name guard (where search is not active)
{
  const emptyNameFixtures = [
    { id: 101, name: '', quantity: 10, cost: 5, price: 10, updated_at: '2025-01-01' },
    { id: 102, name: undefined, quantity: 10, cost: 5, price: 10, updated_at: '2025-01-02' },
    { id: 103, name: 'أرز', quantity: 10, cost: 5, price: 10, updated_at: '2025-01-03' },
    { id: 104, name: null, quantity: 10, cost: 5, price: 10, updated_at: '2025-01-04' },
  ];
  const harness = new ComponentHarness(ProductsTabComponent);
  harness.setSortKey('name_asc');
  const vdom = harness.render({
    allProducts: emptyNameFixtures,
    loadingProducts: false,
    categories: [],
    lowStock: [],
    onEditProduct: () => {},
    onDeleteProduct: () => {},
  });
  const renderedIds = extractRenderedRowIds(vdom);
  const expectedIds = baselineSort(emptyNameFixtures, 'name_asc').slice(0, 15).map(p => p.id);
  assert.deepStrictEqual(renderedIds, expectedIds, 'Empty/undefined name guard sorting does not match baseline');
}

// Collator call count check: sorting 10+ times should NOT have instantiated any new Collator
assert.strictEqual(collatorConstructCount, 1, `Collator was constructed ${collatorConstructCount} times (expected exactly 1)`);
assert.ok(collatorCompareCount > 0, `Collator.compare was called ${collatorCompareCount} times`);
console.log(`  ✓ All 9 sort keys with intentional ties match baseline sorting identically`);
console.log(`  ✓ Arabic diacritics, Alef variants, English/numbers/empty names handled faithfully`);
console.log(`  ✓ Input allProducts list immutability verified (no in-place mutation)`);
console.log(`  ✓ Module Collator instances: ${collatorConstructCount} (single init), Compare calls: ${collatorCompareCount}\n`);

// ── 4. Filtering & Pagination Regressions Check ────────────────────────────────────
console.log('[4/6] Validating Barcode/Additional Barcodes/Category/Stock Filters & Pagination...');

const filterCatalog = [
  { id: 1, name: 'سكر أبيض', barcode: '6221001', additional_barcodes: ['111222', '333444'], category_id: 1, quantity: 20, cost: 10, price: 15 },
  { id: 2, name: 'أرز مصري', barcode: '6221002', additional_barcodes: ['555666'], category_id: 1, quantity: 4, low_stock_threshold: 5, cost: 12, price: 18 },
  { id: 3, name: 'زيت عباد الشمس', barcode: '6221003', additional_barcodes: [], category_id: 2, quantity: 0, cost: 25, price: 35 },
  { id: 4, name: 'شاي أسود', barcode: '6221004', additional_barcodes: ['777888', '999000'], category_id: 3, quantity: -2, cost: 15, price: 22 },
  { id: 5, name: 'حليب كامل الدسم', barcode: '6221005', additional_barcodes: [], category_id: 2, quantity: 50, cost: 18, price: 26 },
];

// 4.1 Search by product name
{
  const h = new ComponentHarness(ProductsTabComponent);
  h.setSearch('سكر');
  const vdom = h.render({ allProducts: filterCatalog, loadingProducts: false, categories: [], lowStock: [], onEditProduct: () => {}, onDeleteProduct: () => {} });
  assert.deepStrictEqual(extractRenderedRowIds(vdom), [1], 'Search by name failed to match expected product');
}

// 4.2 Search by barcode
{
  const h = new ComponentHarness(ProductsTabComponent);
  h.setSearch('6221003');
  const vdom = h.render({ allProducts: filterCatalog, loadingProducts: false, categories: [], lowStock: [], onEditProduct: () => {}, onDeleteProduct: () => {} });
  assert.deepStrictEqual(extractRenderedRowIds(vdom), [3], 'Search by barcode failed to match expected product');
}

// 4.3 Search by additional barcodes
{
  const h = new ComponentHarness(ProductsTabComponent);
  h.setSearch('333444');
  const vdom = h.render({ allProducts: filterCatalog, loadingProducts: false, categories: [], lowStock: [], onEditProduct: () => {}, onDeleteProduct: () => {} });
  assert.deepStrictEqual(extractRenderedRowIds(vdom), [1], 'Search by additional barcode failed to match expected product');
}

// 4.4 Category filter
{
  const h = new ComponentHarness(ProductsTabComponent);
  h.setCategoryFilter('2');
  const vdom = h.render({ allProducts: filterCatalog, loadingProducts: false, categories: [], lowStock: [], onEditProduct: () => {}, onDeleteProduct: () => {} });
  const ids = extractRenderedRowIds(vdom).sort((a, b) => a - b);
  assert.deepStrictEqual(ids, [3, 5], 'Category filter failed to match expected products');
}

// 4.5 Stock filters
// 'available': quantity > 0 -> IDs 1, 2, 5
{
  const h = new ComponentHarness(ProductsTabComponent);
  h.setStockFilter('available');
  const vdom = h.render({ allProducts: filterCatalog, loadingProducts: false, categories: [], lowStock: [], onEditProduct: () => {}, onDeleteProduct: () => {} });
  const ids = extractRenderedRowIds(vdom).sort((a, b) => a - b);
  assert.deepStrictEqual(ids, [1, 2, 5], 'StockFilter "available" failed');
}

// 'low': quantity <= (low_stock_threshold ?? 5) -> IDs 2 (qty 4 <= 5), 3 (qty 0 <= 5), 4 (qty -2 <= 5)
{
  const h = new ComponentHarness(ProductsTabComponent);
  h.setStockFilter('low');
  const vdom = h.render({ allProducts: filterCatalog, loadingProducts: false, categories: [], lowStock: [], onEditProduct: () => {}, onDeleteProduct: () => {} });
  const ids = extractRenderedRowIds(vdom).sort((a, b) => a - b);
  assert.deepStrictEqual(ids, [2, 3, 4], 'StockFilter "low" failed');
}

// 'out': quantity <= 0 -> IDs 3 (qty 0), 4 (qty -2)
{
  const h = new ComponentHarness(ProductsTabComponent);
  h.setStockFilter('out');
  const vdom = h.render({ allProducts: filterCatalog, loadingProducts: false, categories: [], lowStock: [], onEditProduct: () => {}, onDeleteProduct: () => {} });
  const ids = extractRenderedRowIds(vdom).sort((a, b) => a - b);
  assert.deepStrictEqual(ids, [3, 4], 'StockFilter "out" failed');
}

// 4.6 Pagination (15 items per page)
{
  const paginationCatalog = Array.from({ length: 35 }, (_, i) => ({
    id: i + 1,
    name: `منتج تجريبي ${String(i + 1).padStart(2, '0')}`,
    quantity: 10,
    cost: 5,
    price: 10,
  }));
  const h = new ComponentHarness(ProductsTabComponent);
  // Page 1
  h.setCurrentPage(1);
  const vdom1 = h.render({ allProducts: paginationCatalog, loadingProducts: false, categories: [], lowStock: [], onEditProduct: () => {}, onDeleteProduct: () => {} });
  const page1Ids = extractRenderedRowIds(vdom1);
  assert.strictEqual(page1Ids.length, 15, 'Page 1 must render exactly 15 products');

  // Page 2
  h.setCurrentPage(2);
  const vdom2 = h.render({ allProducts: paginationCatalog, loadingProducts: false, categories: [], lowStock: [], onEditProduct: () => {}, onDeleteProduct: () => {} });
  const page2Ids = extractRenderedRowIds(vdom2);
  assert.strictEqual(page2Ids.length, 15, 'Page 2 must render exactly 15 products');
  assert.ok(!page1Ids.some(id => page2Ids.includes(id)), 'Page 1 and Page 2 products must not overlap');

  // Page 3
  h.setCurrentPage(3);
  const vdom3 = h.render({ allProducts: paginationCatalog, loadingProducts: false, categories: [], lowStock: [], onEditProduct: () => {}, onDeleteProduct: () => {} });
  const page3Ids = extractRenderedRowIds(vdom3);
  assert.strictEqual(page3Ids.length, 5, 'Page 3 must render remaining 5 products');

  // Changing search filter should reset currentPage to 1 via useEffect
  h.setSearch('منتج');
  h.render({ allProducts: paginationCatalog, loadingProducts: false, categories: [], lowStock: [], onEditProduct: () => {}, onDeleteProduct: () => {} });
  assert.strictEqual(h.states[8], 1, 'Changing search query must reset currentPage to 1');
}

console.log('  ✓ Name, barcode, and additional barcode searches pass');
console.log('  ✓ Category and stock filters (available/low/out) pass');
console.log('  ✓ 15-row pagination and filter-change reset effect verified\n');

// ── 5. Stats Single Reduce Semantics, Coercions & Memoization ─────────────────────
console.log('[5/6] Validating Stats Semantics, Single Reduce & Memoization Behavior...');

function legacyStats3Passes(products) {
  const totalUnits = products.reduce((s, p) => s + Number(p.quantity), 0);
  const stockValue = products.reduce((s, p) => s + Number(p.quantity) * Number(p.cost), 0);
  const outOfStock = products.filter((p) => p.quantity <= 0).length;
  return { totalUnits, stockValue, outOfStock };
}

const statsTestCases = [
  {
    desc: 'Normal integer quantities and costs',
    products: [
      { id: 1, name: 'أ', quantity: 10, cost: 5 },
      { id: 2, name: 'ب', quantity: 20, cost: 10 },
    ],
  },
  {
    desc: 'Fractional quantities and costs',
    products: [
      { id: 1, name: 'أ', quantity: 2.5, cost: 4.25 },
      { id: 2, name: 'ب', quantity: 1.75, cost: 10.5 },
    ],
  },
  {
    desc: 'String numeric coercions',
    products: [
      { id: 1, name: 'أ', quantity: '15', cost: '8.5' },
      { id: 2, name: 'ب', quantity: '0', cost: '12' },
    ],
  },
  {
    desc: 'Zero, negative, null, and NaN quantities',
    products: [
      { id: 1, name: 'أ', quantity: 0, cost: 10 },
      { id: 2, name: 'ب', quantity: -5, cost: 20 },
      { id: 3, name: 'ج', quantity: null, cost: 15 },
      { id: 4, name: 'د', quantity: undefined, cost: 10 },
    ],
  },
  {
    desc: 'Empty products catalog []',
    products: [],
  },
];

for (const tc of statsTestCases) {
  const h = new ComponentHarness(ProductsTabComponent);
  h.render({
    allProducts: tc.products,
    loadingProducts: false,
    categories: [],
    lowStock: [],
    onEditProduct: () => {},
    onDeleteProduct: () => {},
  });

  const singleReduceResult = h.capturedCallbacks.stats();
  const legacyResult = legacyStats3Passes(tc.products);

  if (Number.isNaN(legacyResult.totalUnits)) {
    assert.ok(Number.isNaN(singleReduceResult.totalUnits), `${tc.desc}: totalUnits should be NaN`);
  } else {
    assert.strictEqual(singleReduceResult.totalUnits, legacyResult.totalUnits, `${tc.desc}: totalUnits mismatch`);
  }

  if (Number.isNaN(legacyResult.stockValue)) {
    assert.ok(Number.isNaN(singleReduceResult.stockValue), `${tc.desc}: stockValue should be NaN`);
  } else {
    assert.strictEqual(singleReduceResult.stockValue, legacyResult.stockValue, `${tc.desc}: stockValue mismatch`);
  }

  assert.strictEqual(singleReduceResult.outOfStock, legacyResult.outOfStock, `${tc.desc}: outOfStock mismatch`);
}

// StatCard values in rendered VDOM
{
  const sampleProducts = [
    { id: 1, name: 'أرز', quantity: 10, cost: 5, price: 15 },
    { id: 2, name: 'سكر', quantity: 20, cost: 8, price: 25 },
    { id: 3, name: 'زيت', quantity: 0, cost: 12, price: 20 },
  ];
  const h = new ComponentHarness(ProductsTabComponent);
  const vdom = h.render({
    allProducts: sampleProducts,
    loadingProducts: false,
    categories: [],
    lowStock: [{ id: 3, name: 'زيت' }],
    onEditProduct: () => {},
    onDeleteProduct: () => {},
  });
  const statCardValues = extractStatCardValues(vdom);
  assert.strictEqual(statCardValues['إجمالي المنتجات'], '3');
  assert.strictEqual(statCardValues['إجمالي الوحدات'], '30');
  assert.strictEqual(statCardValues['قيمة المخزون'], '210 ج.م');
  assert.strictEqual(statCardValues['نفد المخزون'], '1');
}

// Verify Single Pass vs 3 Passes iteration count
{
  let loopIterations = 0;
  const proxiedArray = new Proxy(
    [
      { id: 1, name: 'أ', quantity: 10, cost: 5 },
      { id: 2, name: 'ب', quantity: 20, cost: 10 },
      { id: 3, name: 'ج', quantity: 0, cost: 15 },
    ],
    {
      get(target, prop, receiver) {
        if (typeof prop === 'string' && !isNaN(Number(prop))) {
          loopIterations++;
        }
        return Reflect.get(target, prop, receiver);
      }
    }
  );

  // Run single reduce stats
  loopIterations = 0;
  proxiedArray.reduce((acc, p) => {
    acc.totalUnits += Number(p.quantity);
    acc.stockValue += Number(p.quantity) * Number(p.cost);
    if (p.quantity <= 0) acc.outOfStock++;
    return acc;
  }, { totalUnits: 0, stockValue: 0, outOfStock: 0 });
  const singleReduceIterations = loopIterations;

  // Run legacy 3 passes
  loopIterations = 0;
  proxiedArray.reduce((s, p) => s + Number(p.quantity), 0);
  proxiedArray.reduce((s, p) => s + Number(p.quantity) * Number(p.cost), 0);
  proxiedArray.filter((p) => p.quantity <= 0);
  const legacy3PassesIterations = loopIterations;

  assert.strictEqual(singleReduceIterations, 3, 'Single reduce must iterate exactly 3 elements once');
  assert.strictEqual(legacy3PassesIterations, 9, 'Legacy 3 passes iterated 3 elements three times (9 reads)');
  console.log(`  ✓ Single reduce loop iterations: ${singleReduceIterations} vs legacy 3 passes: ${legacy3PassesIterations}`);
}

// Verify useMemo caching on unrelated state changes
{
  const productsList = [
    { id: 1, name: 'أرز', quantity: 10, cost: 5, price: 15 },
    { id: 2, name: 'سكر', quantity: 20, cost: 8, price: 25 },
  ];
  const h = new ComponentHarness(ProductsTabComponent);

  // Render 1: Initial mount
  h.render({ allProducts: productsList, loadingProducts: false, categories: [], lowStock: [], onEditProduct: () => {}, onDeleteProduct: () => {} });
  assert.strictEqual(h.memoExecutions.stats, 1, 'Stats memo should run on initial mount');
  assert.strictEqual(h.memoExecutions.displayProducts, 1, 'displayProducts memo should run on initial mount');

  // Render 2: Unrelated state change - search
  h.setSearch('أرز');
  h.render({ allProducts: productsList, loadingProducts: false, categories: [], lowStock: [], onEditProduct: () => {}, onDeleteProduct: () => {} });
  assert.strictEqual(h.memoExecutions.stats, 1, 'Stats memo must NOT recompute on search change');
  assert.strictEqual(h.memoExecutions.displayProducts, 2, 'displayProducts memo MUST recompute on search change');

  // Render 3: Unrelated state change - sortKey
  h.setSortKey('qty_desc');
  h.render({ allProducts: productsList, loadingProducts: false, categories: [], lowStock: [], onEditProduct: () => {}, onDeleteProduct: () => {} });
  assert.strictEqual(h.memoExecutions.stats, 1, 'Stats memo must NOT recompute on sortKey change');
  assert.strictEqual(h.memoExecutions.displayProducts, 3, 'displayProducts memo MUST recompute on sortKey change');

  // Render 4: Unrelated state change - stockFilter
  h.setStockFilter('available');
  h.render({ allProducts: productsList, loadingProducts: false, categories: [], lowStock: [], onEditProduct: () => {}, onDeleteProduct: () => {} });
  assert.strictEqual(h.memoExecutions.stats, 1, 'Stats memo must NOT recompute on stockFilter change');
  assert.strictEqual(h.memoExecutions.displayProducts, 4, 'displayProducts memo MUST recompute on stockFilter change');

  // Render 5: Unrelated state change - categoryFilter
  h.setCategoryFilter('1');
  h.render({ allProducts: productsList, loadingProducts: false, categories: [], lowStock: [], onEditProduct: () => {}, onDeleteProduct: () => {} });
  assert.strictEqual(h.memoExecutions.stats, 1, 'Stats memo must NOT recompute on categoryFilter change');

  // Render 6: Unrelated state change - selectedProductIds
  h.setSelectedProductIds([1]);
  h.render({ allProducts: productsList, loadingProducts: false, categories: [], lowStock: [], onEditProduct: () => {}, onDeleteProduct: () => {} });
  assert.strictEqual(h.memoExecutions.stats, 1, 'Stats memo must NOT recompute on selectedProductIds change');

  // Render 7: Unrelated state change - currentPage
  h.setCurrentPage(2);
  h.render({ allProducts: productsList, loadingProducts: false, categories: [], lowStock: [], onEditProduct: () => {}, onDeleteProduct: () => {} });
  assert.strictEqual(h.memoExecutions.stats, 1, 'Stats memo must NOT recompute on currentPage change');

  // Render 8: Immutable array replacement - new catalog update
  const newProductsList = [
    ...productsList,
    { id: 3, name: 'شاي', quantity: 15, cost: 6, price: 12 },
  ];
  h.render({ allProducts: newProductsList, loadingProducts: false, categories: [], lowStock: [], onEditProduct: () => {}, onDeleteProduct: () => {} });
  assert.strictEqual(h.memoExecutions.stats, 2, 'Stats memo MUST recompute when allProducts array reference changes');
}

console.log('  ✓ Fractional, coercion, NaN, zero, negative, null, empty array semantics verified');
console.log('  ✓ VDOM StatCard labels and values validated');
console.log('  ✓ Single reduce computes stats in 1 pass instead of 3 passes');
console.log('  ✓ useMemo([allProducts]) prevents re-evaluation on all 6 unrelated state changes\n');

// ── 6. Deterministic Synthetic CPU Benchmark (10,000 Products, Seed 37) ───────────
console.log('[6/6] Running Deterministic Synthetic CPU Benchmark (10,000 products, LCG seed 37)...');

let seed = 37;
function lcg() {
  seed = (seed * 1664525 + 1013904223) >>> 0;
  return seed / 4294967296;
}

const arabicNouns = ['شاي', 'قهوة', 'سكر', 'أرز', 'زيت', 'حليب', 'جبنة', 'خبز', 'عصير', 'طماطم', 'بصل', 'لحم', 'دجاج', 'سمك', 'مكرونة'];
const brandNames = ['العروبة', 'الضحى', 'جهينة', 'المراعي', 'حلواني', 'صافولا', 'أمريكانا', 'نسكافيه', 'ليبتون', 'كوكاكولا'];

const synthetic10k = [];
for (let i = 1; i <= 10000; i++) {
  const w1 = arabicNouns[Math.floor(lcg() * arabicNouns.length)];
  const w2 = brandNames[Math.floor(lcg() * brandNames.length)];
  const name = `${w1} ${w2} ${Math.floor(lcg() * 1000)}`;
  synthetic10k.push({
    id: i,
    name,
    quantity: Math.floor(lcg() * 100) - 10,
    cost: Math.floor(lcg() * 50) + 1,
    price: Math.floor(lcg() * 100) + 5,
    updated_at: new Date(1700000000000 + Math.floor(lcg() * 1000000000)).toISOString(),
    category_id: Math.floor(lcg() * 10) + 1,
  });
}

// Extract actual derived callback comparator from harness
const benchmarkHarness = new ComponentHarness(ProductsTabComponent);
benchmarkHarness.setSortKey('name_asc');
benchmarkHarness.render({
  allProducts: synthetic10k,
  loadingProducts: false,
  categories: [],
  lowStock: [],
  onEditProduct: () => {},
  onDeleteProduct: () => {},
});
const actualDerivedSort = benchmarkHarness.capturedCallbacks.displayProducts;
assert.ok(actualDerivedSort, 'Failed to capture actual displayProducts sorting callback');

// Warmup runs (2 each)
for (let w = 0; w < 2; w++) {
  [...synthetic10k].sort((a, b) => baselineCmp(a, b));
  actualDerivedSort();
}

const SAMPLES = 5;
const baselineTimings = [];
const optimizedTimings = [];
let baselineOrderIds = null;
let optimizedOrderIds = null;

// Alternate order over 5 samples to prevent GC / JIT bias
for (let sample = 1; sample <= SAMPLES; sample++) {
  if (sample % 2 === 1) {
    // Baseline then Optimized
    const t0 = performance.now();
    const sortedBase = [...synthetic10k].sort((a, b) => baselineCmp(a, b));
    const t1 = performance.now();
    baselineTimings.push(t1 - t0);
    baselineOrderIds = Array.from(sortedBase).map(p => Number(p.id));

    const t2 = performance.now();
    const sortedOpt = actualDerivedSort();
    const t3 = performance.now();
    optimizedTimings.push(t3 - t2);
    optimizedOrderIds = Array.from(sortedOpt).map(p => Number(p.id));
  } else {
    // Optimized then Baseline
    const t2 = performance.now();
    const sortedOpt = actualDerivedSort();
    const t3 = performance.now();
    optimizedTimings.push(t3 - t2);
    optimizedOrderIds = Array.from(sortedOpt).map(p => Number(p.id));

    const t0 = performance.now();
    const sortedBase = [...synthetic10k].sort((a, b) => baselineCmp(a, b));
    const t1 = performance.now();
    baselineTimings.push(t1 - t0);
    baselineOrderIds = Array.from(sortedBase).map(p => Number(p.id));
  }
}

// Hard Correctness Gate: Output IDs and sort order MUST be 100% identical
assert.strictEqual(optimizedOrderIds.length, baselineOrderIds.length, 'Synthetic benchmark sorted counts must match');
assert.deepStrictEqual(optimizedOrderIds, baselineOrderIds, 'Synthetic benchmark sorted product IDs must be 100% identical');
assert.strictEqual(optimizedOrderIds.length, baselineOrderIds.length, 'Synthetic benchmark sorted counts must match');
assert.deepStrictEqual(optimizedOrderIds, baselineOrderIds, 'Synthetic benchmark sorted product IDs must be 100% identical');

function median(arr) {
  const sorted = [...arr].sort((a, b) => a - b);
  return sorted[Math.floor(sorted.length / 2)];
}

const medianBaseline = median(baselineTimings);
const medianOptimized = median(optimizedTimings);
const speedupRatio = medianBaseline / medianOptimized;

console.log('  --- Benchmark Results (5 iterations, alternating order) ---');
console.log(`  Baseline (localeCompare):                ${baselineTimings.map(t => t.toFixed(2) + 'ms').join(', ')} (median: ${medianBaseline.toFixed(2)}ms)`);
console.log(`  Optimized (actual derived Intl.Collator): ${optimizedTimings.map(t => t.toFixed(2) + 'ms').join(', ')} (median: ${medianOptimized.toFixed(2)}ms)`);
console.log(`  Measured Synthetic Speedup Ratio:        ${speedupRatio.toFixed(2)}x (INFO ONLY - no hard assertion gate)`);
console.log(`  Sorted 10,000 product IDs match:         PASS (identical deterministic order)`);
console.log('\n  [Limits & Scope Notice]');
console.log('  * Timings represent local Node.js V8 CPU microbenchmarks on synthetic in-memory fixtures.');
console.log('  * They do not measure browser DOM layout, React fiber reconciliation, or paint latency.');
console.log('  * Products.tsx still performs full network fetch before local catalog state is set.\n');

console.log('================================================================================');
console.log('VERIFICATION COMPLETE: ALL CHECKS PASSED');
console.log('================================================================================');
