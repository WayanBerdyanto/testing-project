import connectToMongo from "./connections/mongodDB.js";
import { BSON } from "bson"; // pastikan sudah diinstall: npm install bson

async function readBenchmark() {
    let mongoConnection;
    try {
        // Connect ke MongoDB
        mongoConnection = await connectToMongo();
        const { db } = mongoConnection;

        const tracelogCollection = db.collection("tracelog");
        console.log("🚀 Starting read benchmark...");

        // 🔹 Ambil statistik koleksi untuk bandingkan ukuran
        const stats = await db.command({ collStats: "tracelog" });
        console.log("📊 Collection stats:");
        console.log(`📦 Storage size: ${(stats.storageSize / 1024 / 1024).toFixed(2)} MB`);
        console.log(`🗃️ Total index size: ${(stats.totalIndexSize / 1024 / 1024).toFixed(2)} MB`);
        console.log(`📄 Document count: ${stats.count}`);
        console.log("");

        // Mulai timer
        const start = process.hrtime.bigint();

        // Ambil semua dokumen
        const cursor = tracelogCollection.find({});
        const results = await cursor.toArray();

        // Akhiri timer
        const end = process.hrtime.bigint();
        const durationNs = end - start;
        const durationSec = Number(durationNs) / 1e9;

        // Hitung total ukuran BSON (bukan JSON)
        let totalBytes = 0;
        for (const doc of results) {
            const bsonBuffer = BSON.serialize(doc);
            totalBytes += bsonBuffer.byteLength;
        }

        const totalKB = totalBytes / 1024;
        const totalMB = totalKB / 1024;

        // Hitung waktu rata-rata per dokumen
        const count = results.length;
        const avgPerDoc = count > 0 ? durationSec / count : 0;

        // Log hasil benchmark
        console.log(
            `✅ Read ${count.toLocaleString()} logs in ${durationSec.toFixed(2)} seconds ` +
            `(${avgPerDoc.toFixed(6)} s per document)`
        );
        console.log(
            `📦 Total BSON data size: ${totalBytes.toLocaleString()} bytes ` +
            `(${totalKB.toFixed(2)} KB / ${totalMB.toFixed(2)} MB)`
        );
    } catch (error) {
        console.error("❌ Error:", error);
    } finally {
        if (mongoConnection?.client) {
            await mongoConnection.client.close();
            console.log("🔌 MongoDB connection closed.");
        }
    }
}

// Jalankan
readBenchmark().catch(console.error);
