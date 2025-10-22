'use strict';
import connectToMongo from "./connections/mongodDB.js";

function parseArgs(argv) {
  const args = {};
  for (const a of argv) {
    const m = a.match(/^--([^=]+)=(.+)$/);
    if (m) args[m[1]] = m[2];
  }
  return args;
}

(async () => {
  const args = parseArgs(process.argv.slice(2));
  const uri = process.env.MONGODB_URI || args.uri || 'mongodb://localhost:27017';
  const dbName = process.env.MONGODB_DB || args.db || 'Testing';
  const collName = process.env.MONGODB_COLLECTION || args.collection || 'tracelog';
  const interval = Number(process.env.INTERVAL_MS || args.interval || 1000);

  const { db, client } = await connectToMongo();
  const admin = db.admin();

  const sA = await admin.serverStatus();
  const cA = collName ? await db.command({ collStats: collName, scale: 1 }).catch(() => null) : null;
  await new Promise(r => setTimeout(r, interval));
  const sB = await admin.serverStatus();
  const cB = collName ? await db.command({ collStats: collName, scale: 1 }).catch(() => null) : null;

  function diff(path) {
    const parts = path.split('.');
    let a = sA, b = sB;
    for (const p of parts) { a = a?.[p]; b = b?.[p]; }
    return Number(b || 0) - Number(a || 0);
  }
  const secs = interval / 1000;
  const insertsPS = diff('opcounters.insert') / secs;
  const queriesPS = diff('opcounters.query') / secs;
  const updatesPS = diff('opcounters.update') / secs;
  const deletesPS = diff('opcounters.delete') / secs;
  const bytesInMBps = diff('network.bytesIn') / 1024 / 1024 / secs;
  const bytesOutMBps = diff('network.bytesOut') / 1024 / 1024 / secs;

  const cache = sB.wiredTiger?.cache;
  const maxCache = Number(cache?.['maximum bytes configured'] || 0);
  const curCache = Number(cache?.['bytes currently in the cache'] || 0);
  const cachePct = maxCache ? (curCache / maxCache * 100) : null;
  const pagesEvictedDelta = (Number(cache?.['pages evicted'] || 0) - Number(sA.wiredTiger?.cache?.['pages evicted'] || 0));

  let replLag = null;
  try {
    const rs = await admin.command({ replSetGetStatus: 1 });
    const primary = rs.members.find(m => m.stateStr === 'PRIMARY');
    if (primary) {
      const lagSecs = rs.members.filter(m => m.stateStr === 'SECONDARY').map(m => {
        const pd = new Date(primary.optimeDate);
        const sd = new Date(m.optimeDate);
        return (pd - sd) / 1000;
      });
      if (lagSecs.length) replLag = Math.max(...lagSecs);
    }
  } catch (_) { /* not a replicaset */ }

  console.log(`📡 MongoDB Metrics Snapshot (interval ${secs}s)`);
  console.log(`• Version: ${sB.version} | Process: ${sB.process} | Uptime: ${sB.uptime} s`);
  console.log(`• Ops/s: insert ${insertsPS.toFixed(2)}, query ${queriesPS.toFixed(2)}, update ${updatesPS.toFixed(2)}, delete ${deletesPS.toFixed(2)}`);
  console.log(`• Throughput: in ${bytesInMBps.toFixed(2)} MB/s | out ${bytesOutMBps.toFixed(2)} MB/s`);
  if (cachePct != null) console.log(`• WiredTiger cache: ${(curCache / 1024 / 1024).toFixed(2)} MB / ${(maxCache / 1024 / 1024).toFixed(0)} MB (${cachePct.toFixed(2)}%) | pages evicted Δ ${pagesEvictedDelta}`);
  if (replLag != null) console.log(`• Replication max lag: ${replLag.toFixed(2)} s`);
  if (cB) console.log(`• Collection ${collName}: size ${(cB.size / 1024 / 1024).toFixed(2)} MB | storage ${(cB.storageSize / 1024 / 1024).toFixed(2)} MB | index ${(cB.totalIndexSize / 1024 / 1024).toFixed(2)} MB | count ${cB.count}`);

  console.log('\n💡 Hints:');
  console.log('- If cache usage > 80% and evictions high, add RAM or shard.');
  console.log('- If replication lag grows under normal load, consider horizontal scaling.');

  await client.close();
  console.log('🔌 MongoDB connection closed.');
})().catch(e => { console.error('❌ Error:', e); process.exit(1); });