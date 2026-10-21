import {execFileSync} from 'node:child_process';
for(const file of ['verify-transition-preview.php','verify-transition-receipt.php','verify-channel-transition-flow.php'])execFileSync(process.env.PHP_BINARY||'php',['scripts/'+file],{stdio:'inherit',windowsHide:true});
