<?php
/**
 * Database Migration Script
 * Run this script to update database schema with new tables
 * Usage: php migrate.php
 */

require_once __DIR__ . '/config.php';

echo "=== DomainAlert Database Migration ===\n\n";

try {
    $db = initDatabase();
    
    // Check if jobs table exists
    $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='jobs'");
    $jobsExists = $result->fetch();
    
    if (!$jobsExists) {
        echo "Creating jobs table...\n";
        $db->exec("
            CREATE TABLE IF NOT EXISTS jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                status TEXT DEFAULT 'pending',
                total INTEGER DEFAULT 0,
                processed INTEGER DEFAULT 0,
                errors INTEGER DEFAULT 0,
                data TEXT,
                result TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
        ");
        echo "✓ Jobs table created successfully\n";
    } else {
        echo "✓ Jobs table already exists\n";
    }
    
    echo "\n=== Migration completed successfully ===\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
