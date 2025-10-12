import connectToMongo from "./connections/mongodDB.js";

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

        // Hitung estimasi size sebelum insert
        const jsonData = JSON.stringify(logs);
        const totalBytes = Buffer.byteLength(jsonData);
        const totalKB = totalBytes / 1024;
        const totalMB = totalKB / 1024;

        console.log(`🚀 Starting benchmark for ${total.toLocaleString()} logs (batch size: ${batchSize})...`);
        console.log(`📦 Estimated total data size: ${totalMB.toFixed(2)} MB`);

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
        const throughput = totalMB / durationSec;

        console.log(`✅ Inserted ${total.toLocaleString()} logs in ${durationSec.toFixed(2)} seconds`);
        console.log(`⚡ Avg per insert: ${perInsert.toFixed(6)} s`);
        console.log(`📊 Throughput: ${throughput.toFixed(2)} MB/s`);

        // 🔍 Ambil statistik collection langsung dari server MongoDB
        const stats = await db.command({ collStats: "tracelog" });

        console.log("\n📊 Collection stats:");
        console.log(`📦 Storage size: ${(stats.storageSize / 1024 / 1024).toFixed(2)} MB`);
        console.log(`🗃️ Total index size: ${(stats.totalIndexSize / 1024 / 1024).toFixed(2)} MB`);
        console.log(`📄 Document count: ${stats.count.toLocaleString()}`);
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
