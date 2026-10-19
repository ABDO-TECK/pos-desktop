const required = ['CSC_LINK', 'CSC_KEY_PASSWORD', 'WINDOWS_SIGNING_PUBLISHER'];
const missing = required.filter((name) => !process.env[name]?.trim());

if (missing.length > 0) {
  console.error(`Production Windows publishing is blocked: missing ${missing.join(', ')}.`);
  console.error('Provide these only through CI secrets/variables. Local electron:build remains intentionally unsigned-capable.');
  process.exit(1);
}

console.log('Production Windows signing configuration is present. Credential values were not read or printed.');
