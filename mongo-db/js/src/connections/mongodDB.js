import { MongoClient } from "mongodb";

// MongoDB connection URI
const uri = "mongodb://localhost:27017";
const dbName = "Testing"; // Replace with your database name

// Create a new MongoClient
const client = new MongoClient(uri);

async function connectToMongo() {
    try {
        // Connect to MongoDB
        await client.connect();
        console.log("Successfully connected to MongoDB.");

        // Get database reference
        const db = client.db(dbName);

        return { db, client };  // Return both db and client
    } catch (error) {
        console.error("Connection to MongoDB failed:", error);
        throw error;
    }
}

export default connectToMongo;
