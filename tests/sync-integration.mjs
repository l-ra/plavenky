#!/usr/bin/env node
// Integration test: PHP 8 file server, authentication, atomic ETags, group isolation.
// Run from repository root: node tests/sync-integration.mjs
import { spawn } from 'node:child_process';
import { createHash, randomBytes } from 'node:crypto';
import { mkdtempSync, rmSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { strict as assert } from 'node:assert';
import { setTimeout as sleep } from 'node:timers/promises';
import { Script } from 'node:vm';

const html = readFileSync('index-dev.html', 'utf8');
const inline = html.match(/<script>\s*([\s\S]*?)<\/script>/);
assert(inline, 'Inline JS not found');
new Script(inline[1], {filename:'index-dev-inline.js'});
assert(html.includes('const SyncManager ='), 'Synchronizer missing');
assert(html.includes('if (!await DataStore.commit()) return;'), 'Data commit missing');

const temporary = mkdtempSync(join(tmpdir(), 'plavenky-sync-test-'));
const port = 19000 + Math.floor(Math.random() * 25000);
const origin = 'http://127.0.0.1:' + port;
const php = spawn('php', ['-S','127.0.0.1:' + port, '-t','.'], {
  env: {...process.env,
    PLAVENKY_SYNC_STORAGE_TEMPLATE: temporary + '/{domenaRequestu}/{cestaRequestu}/data',
    PLAVENKY_SYNC_DOMAIN:'localhost',
    PLAVENKY_SYNC_ALLOW_HTTP:'1',
    PLAVENKY_SYNC_ALLOWED_ORIGINS:origin
  }, stdio: ['ignore', 'pipe', 'pipe']
});
let logs = '';
php.stderr.on('data', d => { logs += String(d); });
const url = origin + '/sync.php/skupina-a';
const b64 = bytes => Buffer.from(bytes).toString('base64url');
const tokenBytes = randomBytes(32);
const token = b64(tokenBytes);
const auth = {Authorization:'Bearer ' + token};
const verifier = createHash('sha256').update(tokenBytes).digest('hex');
const fakeEncrypted = JSON.stringify({
  v:1,alg:'AES-256-GCM',iv:b64(randomBytes(12)),ciphertext:b64(randomBytes(80))
});
const initial = {
  protocolVersion:1,
  kdf:{name:'PBKDF2-SHA256',iterations:600000,salt:b64(randomBytes(32))},
  authVerifier:verifier,snapshot:b64(Buffer.from(fakeEncrypted))
};
const request = (target, opts={}) => fetch(target, {cache:'no-store',...opts});
try {
  let running = false;
  for(let i=0;i<100;i++) {
    try { await request(url); running = true; break; }
    catch { await sleep(50); }
  }
  assert(running, 'PHP server did not start: ' + logs);
  const pre = await request(url); assert.equal(pre.status,200,await pre.clone().text());
  let check = await pre.json();
  assert.equal(check.configured,true);
  assert.equal(check.initialized,false);
  let bad = await request(url + '?object=current'); assert.equal(bad.status,404);
  const init = await request(url + '?object=initialize', {
    method:'POST',headers:{...auth, 'Content-Type':'application/json','If-None-Match':'*'},
    body:JSON.stringify(initial)
  });
  assert.equal(init.status,201, await init.text());
  const repeat = await request(url + '?object=initialize', {
    method:'POST',headers:{...auth,'Content-Type':'application/json','If-None-Match':'*'},
    body:JSON.stringify(initial)
  });
  assert.equal(repeat.status,412);
  check = await (await request(url)).json();
  assert.equal(check.initialized,true);
  assert.equal(check.kdf.salt,initial.kdf.salt);
  const unauthorized = await request(url + '?object=current');
  assert.equal(unauthorized.status,401);
  const wrong = await request(url + '?object=current', {headers:{Authorization:'Bearer ' + b64(randomBytes(32))}});
  assert.equal(wrong.status,401);
  const current = await request(url + '?object=current',{headers:auth});
  assert.equal(current.status,200);
  assert.equal(await current.text(),fakeEncrypted);
  const etag = current.headers.get('etag');
  assert(etag && etag.startsWith('"sha256-'));
  const nextEncrypted = JSON.stringify({v:1,alg:'AES-256-GCM',iv:b64(randomBytes(12)),ciphertext:b64(randomBytes(80))});
  const opts = {method:'PUT',body:nextEncrypted,headers:{...auth,'Content-Type':'application/octet-stream','If-Match':etag}};
  const [a,b] = await Promise.all([
    request(url + '?object=current',opts),
    request(url + '?object=current',opts)
  ]);
  assert.deepEqual([a.status,b.status].sort(),[200,412]);
  assert.equal(await (await request(url + '?object=current',{headers:auth})).text(),nextEncrypted);
  const missing = await request(url + '?object=current',{method:'PUT',headers:auth,body:fakeEncrypted});
  assert.equal(missing.status,428);
  const historyId = '550e8400-e29b-41d4-a716-446655440000';
  const h1 = await request(url + '?object=history/' + historyId, {
    method:'PUT',headers:{...auth,'If-None-Match':'*'},body:fakeEncrypted
  });
  assert.equal(h1.status,201);
  const h2 = await request(url + '?object=history/' + historyId, {
    method:'PUT',headers:{...auth,'If-None-Match':'*'},body:fakeEncrypted
  });
  assert.equal(h2.status,412);
  const list = await (await request(url + '?object=history',{headers:auth})).json();
  assert(list.versions.includes(historyId));
  const other = await (await request(origin + '/sync.php/skupina-b')).json();
  assert.equal(other.initialized,false);
  const badPath = await request(origin + '/sync.php/%25traversal');
  assert.equal(badPath.status,400);
  console.log('PASS: JS syntax, diagnostics, initialization, auth, ETag CAS, history, group isolation and path validation');
} finally {
  php.kill('SIGTERM');
  rmSync(temporary,{recursive:true,force:true});
}
