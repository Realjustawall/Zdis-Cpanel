import {spawnSync} from 'node:child_process';
import {cpSync,rmSync,mkdirSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
const base=fileURLToPath(new URL('.',import.meta.url));
rmSync(base+'source/client/dist',{recursive:true,force:true});
const build=spawnSync(process.platform==='win32'?'npm.cmd':'npm',['run','build','--workspace','client'],{cwd:base+'source',stdio:'inherit',shell:process.platform==='win32'});
if(build.status!==0)process.exit(build.status??1);
// Generated assets may have different hashes; keep only the current build.
rmSync(base+'application/public/assets',{recursive:true,force:true});
mkdirSync(base+'application/public',{recursive:true});
cpSync(base+'source/client/dist',base+'application/public',{recursive:true});
console.log('Frontend built and copied; PHP entry points preserved.');
