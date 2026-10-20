import fs from 'node:fs';
const version = JSON.parse(fs.readFileSync('version.json', 'utf8'));
console.log(`# POS Desktop ${version.version}\n\nChannel: ${version.channel}\n\n${version.changelog.map(item => `- ${item}`).join('\n')}\n\nWindows x64 Setup. Built and smoke-tested on GitHub Actions.\n\nThe installer is unsigned (no Windows Authenticode publisher certificate is configured). Physical paper printing is not covered by automated verification.\n\nIncludes deployment-inventory.json for signed delta generation; local business data and device credentials are excluded.\n`);
