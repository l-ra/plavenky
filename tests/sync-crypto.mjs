#!/usr/bin/env node
// Test derivation and AES-GCM without browser dependencies, using WebCrypto and VM.
import { readFileSync } from 'node:fs';
import { strict as assert } from 'node:assert';
import { webcrypto } from 'node:crypto';
import vm from 'node:vm';

const html = readFileSync('index-dev.html','utf8');
const first = html.indexOf('        // --- Optional E2EE synchronization');
const last = html.indexOf('        function getTotalItemsCount()',first);
assert(first >= 0 && last > first, 'Synchronization module not found');
const source = html.slice(first,last);
const sandbox = {
    crypto:webcrypto, TextEncoder, TextDecoder, URL,
    atob, btoa,
    localStorage:{getItem(){return null;},setItem(){},removeItem(){}},
    Storage:{get(){return [];},set(){}},
    chips:[],employees:[],borrowings:[],
    console
};
vm.runInNewContext(source + '\nthis.syncModule = SyncManager;',sandbox);
const sync = sandbox.syncModule;
const salt = new Uint8Array(32);
webcrypto.getRandomValues(salt);
const toB64 = bytes => Buffer.from(bytes).toString('base64url');
const kdf = {name:'PBKDF2-SHA256',iterations:600000,salt:toB64(salt)};
const a = await sync.derive('Correct Horse Battery Staple, long random phrase',kdf,'example.cz|group-1');
const b = await sync.derive('Correct Horse Battery Staple, long random phrase',kdf,'example.cz|group-1');
const c = await sync.derive('Correct Horse Battery Staple, long random phrase',kdf,'example.cz|group-2');
assert.equal(a.token,b.token);
assert.equal(a.verifier,b.verifier);
assert.notEqual(a.token,c.token);
assert.equal(a.token.length,43);
assert.equal(a.verifier.length,64);

sync.group='example.cz|group-1'; sync.keys=a;
const record = {
    protocolVersion:1,revision:1,epoch:1,snapshotId:'550e8400-e29b-41d4-a716-446655440000',
    parentSnapshotId:null,primaryInstanceId:'550e8400-e29b-41d4-a716-446655440000',
    chips:[{id:1,identifier:'<img src=x onerror=alert(1)>'}],
    employees:[{id:2,name:'Jana',employeeNumber:'42',department:'IT'}],
    borrowings:[{id:3,employeeId:2,chips:[1],startDate:'2026-01-01',endDate:null}]
};
const encrypted=await sync.encrypt(record);
assert(!encrypted.includes('Jana'));
assert(!encrypted.includes('onerror'));
const decoded=await sync.decrypt(encrypted);
assert.equal(JSON.stringify(decoded),JSON.stringify(record));
const wrong=await sync.derive('Another password with enough characters',kdf,'example.cz|group-1');
sync.keys=wrong;
await assert.rejects(async()=>sync.decrypt(encrypted));
sync.keys=a;sync.group='example.cz|group-2';
await assert.rejects(async()=>sync.decrypt(encrypted));
console.log('PASS: deterministic password derivation, domain separation, AES-GCM roundtrip and tamper detection');
