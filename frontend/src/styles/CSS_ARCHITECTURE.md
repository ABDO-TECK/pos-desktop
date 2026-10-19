# Frontend CSS Architecture

This document describes the CSS architecture and styling convention of the POS system. We use a hybrid styling strategy combining **Global CSS Stylesheets** for foundational design elements and **CSS Modules** for component-level and page-level styling.

---

## 1. Global CSS Stylesheets

Located in the [frontend/src/styles/](file:///c:/xampp/htdocs/pos/frontend/src/styles/) directory.
These files define the global baseline and are loaded in a specific order via [_index.css](file:///c:/xampp/htdocs/pos/frontend/src/styles/_index.css).

### Files and Responsibilities:

1. **[global.css](file:///c:/xampp/htdocs/pos/frontend/src/styles/global.css)**
   - Holds CSS Custom Properties (variables) for colors, spacing, borders, shadows, and themes (light/dark modes).
   - Global reset rules and base HTML styles.
   - Text utility baseline styles.

2. **[layout.css](file:///c:/xampp/htdocs/pos/frontend/src/styles/layout.css)**
   - Core page structure classes (containers, grid baselines).
   - Layout-level components such as the Header, Sidebar container, and Main Content area wrapper.

3. **[sidebar.css](file:///c:/xampp/htdocs/pos/frontend/src/styles/sidebar.css)**
   - Scoped styles for the navigation menu sidebar drawer and item lists.

4. **[components.css](file:///c:/xampp/htdocs/pos/frontend/src/styles/components.css)**
   - Reusable global components styling (standard buttons, custom alerts, common inputs, table layouts, card wrappers).
   - All global component classes follow the **BEM (Block Element Modifier)** naming convention:
     - Block: `.pos-card`, `.pos-btn`, `.pos-modal`
     - Element: `.pos-card__header`, `.pos-btn__icon`
     - Modifier: `.pos-btn--primary`, `.pos-card--active`

5. **[responsive.css](file:///c:/xampp/htdocs/pos/frontend/src/styles/responsive.css)**
   - Media queries for adapting layouts and components across different viewport breakpoints.

---

## 2. CSS Modules (`*.module.css`)

Co-located directly with the React pages and components they style.

### When to use CSS Modules:
- For all page-specific styles (e.g. `Pos.module.css`, `Inventory.module.css`).
- For specialized, non-global components (e.g. `PaymentModal.module.css`, `BarcodeScanner.module.css`).

### Rules:
1. Class names inside CSS Modules should use **camelCase** (e.g. `styles.cardHeader`, `styles.submitButton`).
2. Do not use global BEM classes inside a module. Keep selector depth low.
3. Import CSS module styles inside components:
   ```tsx
   import styles from './MyComponent.module.css';
   // ...
   <div className={styles.wrapper}>
   ```

---

## 3. Best Practices & Rules

1. **No direct inline styles**: Avoid using the `style` prop in React elements unless calculations must be dynamic at runtime (e.g. tracking mouse coordinates, progress bar percentage).
2. **Reuse Custom Properties**: Always use design tokens (`var(--primary)`, `var(--bg)`, etc.) rather than hardcoding hex/rgb values. This ensures dark mode compatibility automatically.
3. **Keep `components.css` lean**: If a style is specific to one component, it belongs in a co-located `.module.css` file, not in the global `components.css` sheet.
