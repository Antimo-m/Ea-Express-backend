import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createNotificationMonitor } from '../../resources/js/notification-monitor.js';
function environment(t) {
  const originals = Object.fromEntries(['window','document','localStorage'].map(key => [key, Object.getOwnPropertyDescriptor(globalThis,key)]));
  const store = new Map();
  let tones = 0;
  class Audio {
    state = 'running'; currentTime = 0; destination = {};
    async resume() { this.state = 'running'; }
    async close() { this.state = 'closed'; }
    createOscillator() { return { frequency: { setValueAtTime() {} }, connect() {}, disconnect() {}, start() { tones++; }, stop() {} }; }
    createGain() { return { gain: { setValueAtTime() {}, linearRampToValueAtTime() {}, exponentialRampToValueAtTime() {} }, connect() {}, disconnect() {} }; }
  }
  Object.defineProperty(globalThis,'window',{configurable:true,value:Object.assign(new EventTarget(),{AudioContext:Audio})});
  Object.defineProperty(globalThis,'document',{configurable:true,value:Object.assign(new EventTarget(),{hidden:false})});
  Object.defineProperty(globalThis,'localStorage',{configurable:true,value:{getItem:key=>store.get(key)??null,setItem:(key,value)=>store.set(key,value)}});
  const monitors=[];
  t.after(()=>{for(const monitor of monitors) monitor.dispose(); for(const [key,descriptor] of Object.entries(originals)) { if(descriptor) Object.defineProperty(globalThis,key,descriptor); else delete globalThis[key]; }});
  return { store, tones:()=>tones, monitor(options){const monitor=createNotificationMonitor(options);monitors.push(monitor);return monitor;} };
}
const flush = () => new Promise(resolve=>setImmediate(resolve));
test('Il primo caricamento non suona; nuovi eventi producono un solo avviso per gruppo di aggiornamenti',async t=>{
  const env=environment(t);let items=[{id:'old',read:false}];
  const monitor=env.monitor({scope:'customer:1',load:async()=>({items,unread:items.length})});
  await flush();await monitor.toggle();assert.equal(env.tones(),0);
  items=[{id:'new-1',read:false},{id:'new-2',read:false},...items];
  await monitor.refresh();assert.equal(env.tones(),1);
  await monitor.refresh();assert.equal(env.tones(),1);
});
test('Eventi già letti e recupero dopo un errore di rete non ripetono suoni',async t=>{
  const env=environment(t);let items=[],offline=false;
  const monitor=env.monitor({scope:'staff:1',load:async()=>{if(offline) throw new Error();return {items,unread:0};}});
  await flush();await monitor.toggle();items=[{id:'read',read:true}];await monitor.refresh();assert.equal(env.tones(),0);
  items.push({id:'new',read:false});await monitor.refresh();assert.equal(env.tones(),1);
  offline=true;await monitor.refresh();offline=false;await monitor.refresh();assert.equal(env.tones(),1);
});
test('Refresh e più schede condividono gli identificativi ricevuti senza riprodurli nuovamente',async t=>{
  const env=environment(t);let items=[];
  const load=async()=>({items,unread:items.length});
  const first=env.monitor({scope:'customer:2',load});await flush();await first.toggle();
  const second=env.monitor({scope:'customer:2',load});await flush();window.dispatchEvent(new Event('pointerdown'));await flush();
  items=[{id:'single-event',read:false}];await first.refresh();await second.refresh();assert.equal(env.tones(),1);
  first.dispose();second.dispose();env.monitor({scope:'customer:2',load});await flush();assert.equal(env.tones(),1);
});
test('La disattivazione silenzia i nuovi eventi e riattivare non ripete quelli ricevuti nel frattempo',async t=>{
  const env=environment(t);let items=[];
  const monitor=env.monitor({scope:'customer:3',load:async()=>({items,unread:items.length})});await flush();
  items=[{id:'silent',read:false}];await monitor.refresh();assert.equal(env.tones(),0);
  await monitor.toggle();await monitor.refresh();assert.equal(env.tones(),0);
  items.push({id:'audible',read:false});await monitor.refresh();assert.equal(env.tones(),1);
  await monitor.toggle();items.push({id:'muted',read:false});await monitor.refresh();assert.equal(env.tones(),1);
});
