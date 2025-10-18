import connectToMongo from "./connections/mongodDB.js";
import { BSON } from 'bson';

async function main() {
    let mongoConnection;
    try {
        // Connect to MongoDB
        mongoConnection = await connectToMongo();
        const { db } = mongoConnection;
        const tracelogCollection = db.collection("tracelog");

        const total = 100000; // jumlah data yang akan diinsert
        const batchSize = 1000; // untuk insert bertahap
        const logs = [];

        // Generate data log
        for (let i = 1; i <= total; i++) {
            logs.push({
                type: "INFO",
                appname: "web.indopaket",
                version: "2.0.0",
                method: "jobRetryFailedExpireAWB",
                user: "cronjob",
                keyword: `batch_${i}`,
                log: `Job executed successfully #${i}`,
                created_at: new Date(),
                updated_at: new Date(),
            });
        }

        // Hitung estimasi size BSON sebelum insert
        let totalBsonBytes = logs.reduce((sum, doc) => sum + BSON.serialize(doc).length, 0);
        const totalBsonMB = totalBsonBytes / 1024 / 1024;
        console.log(`🚀 Starting benchmark for ${total.toLocaleString()} logs (batch size: ${batchSize})...`);
        console.log(`📦 Estimated total BSON payload: ${totalBsonMB.toFixed(2)} MB`);

        // Statistik sebelum insert
        const statsBefore = await db.command({ collStats: "tracelog" });

        // Mulai timer
        const start = process.hrtime.bigint();

        // Insert per batch agar efisien
        for (let i = 0; i < logs.length; i += batchSize) {
            const batch = logs.slice(i, i + batchSize);
            await tracelogCollection.insertMany(batch, { ordered: false });
        }

        // Selesai timer
        const end = process.hrtime.bigint();
        const durationSec = Number(end - start) / 1e9;
        const perInsert = durationSec / total;
        const throughput = totalBsonMB / durationSec;

        console.log(`✅ Inserted ${total.toLocaleString()} logs in ${durationSec.toFixed(2)} seconds`);
        console.log(`⚡ Avg per insert: ${perInsert.toFixed(6)} s`);
        console.log(`📊 Throughput: ${throughput.toFixed(2)} MB/s`);

        // Statistik setelah insert
        const statsAfter = await db.command({ collStats: "tracelog" });
        const deltaStorage = statsAfter.storageSize - statsBefore.storageSize;
        const deltaIndex = statsAfter.totalIndexSize - statsBefore.totalIndexSize;

        console.log("\n📊 Collection stats:");
        console.log(`📦 Storage size: ${(statsAfter.storageSize / 1024 / 1024).toFixed(2)} MB`);
        console.log(`🗃️ Total index size: ${(statsAfter.totalIndexSize / 1024 / 1024).toFixed(2)} MB`);
        console.log(`📄 Document count: ${statsAfter.count.toLocaleString()}`);
        console.log(`📊 Storage delta: ${(deltaStorage / 1024 / 1024).toFixed(2)} MB`);
        console.log(`📊 Index delta: ${(deltaIndex / 1024 / 1024).toFixed(2)} MB`);
    } catch (error) {
        console.error("❌ Error:", error);
    } finally {
        if (mongoConnection?.client) {
            await mongoConnection.client.close();
            console.log("🔌 MongoDB connection closed.");
        }
    }
}

main().catch(console.error);