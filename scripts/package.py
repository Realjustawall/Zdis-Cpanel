from pathlib import Path
import hashlib,json,zipfile
root=Path(__file__).resolve().parents[1];out=root/'build';out.mkdir(exist_ok=True)
archive=out/'ZDis-cPanel-PHP-preview.zip';entries=[]
for directory in ['application','source','scripts','.github']:
 for p in (root/directory).rglob('*'):
  if not p.is_file():continue
  rel=p.relative_to(root)
  if any(part in {'.git','node_modules','dist','__pycache__'} for part in rel.parts):continue
  if p.suffix in {'.pyc','.tsbuildinfo'}:continue
  if rel.parts[:2]==('application','storage') and p.name!='.gitkeep':continue
  if rel.as_posix()=='application/config.php':continue
  entries.append(p)
entries += [root/n for n in ['LICENSE','README.md','UPSTREAM.json','rebuild-frontend.mjs','.gitignore']]
checksums={p.relative_to(root).as_posix():hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(entries)}
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
 for p in sorted(entries):z.write(p,'ZDis-cPanel-PHP-preview/'+p.relative_to(root).as_posix())
 z.writestr('ZDis-cPanel-PHP-preview/SHA256SUMS.json',json.dumps(checksums,indent=2))
with zipfile.ZipFile(archive) as z:assert z.testzip() is None
checksum=hashlib.sha256(archive.read_bytes()).hexdigest()
(archive.with_suffix('.zip.sha256')).write_text(checksum+'  '+archive.name+'\n')
print(json.dumps({'archive':str(archive),'files':len(entries)+1,'sha256':checksum,'bytes':archive.stat().st_size}))
