<?php
session_start();

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'music');
define('DB_USER', 'root');
define('DB_PASS', '');

// Database class
class Database {
    private $connection;

    public function __construct() {
        try {
            $this->connection = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Initialize database tables if they don't exist
            $this->initializeDatabase();
        } catch(PDOException $e) {
            die("Connection failed: " . $e->getMessage());
        }
    }

    public function getConnection() {
        return $this->connection;
    }

    private function initializeDatabase() {
        // Create tables if they don't exist
        $tables = [
            "CREATE TABLE IF NOT EXISTS songs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                artist VARCHAR(255) NOT NULL,
                album VARCHAR(255),
                duration INT DEFAULT 180,
                file_path VARCHAR(500) NOT NULL,
                cover_path VARCHAR(500),
                uploaded_by INT,
                uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            
            "CREATE TABLE IF NOT EXISTS playlists (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                created_by INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            
            "CREATE TABLE IF NOT EXISTS playlist_songs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                playlist_id INT,
                song_id INT,
                added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (playlist_id) REFERENCES playlists(id) ON DELETE CASCADE,
                FOREIGN KEY (song_id) REFERENCES songs(id) ON DELETE CASCADE
            )",
            
            "CREATE TABLE IF NOT EXISTS favorites (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                song_id INT,
                added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_favorite (user_id, song_id),
                FOREIGN KEY (song_id) REFERENCES songs(id) ON DELETE CASCADE
            )"
        ];

        foreach ($tables as $tableSql) {
            $this->connection->exec($tableSql);
        }
    }
}

// Authentication functions
function redirectIfNotLogged() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit();
    }
    
    // Ensure username is set in session
    if (!isset($_SESSION['username'])) {
        $_SESSION['username'] = 'User';
    }
}

function redirectIfLogged() {
    if (isset($_SESSION['user_id'])) {
        header("Location: index.php");
        exit();
    }
}

// Simple function to get audio duration (fallback if getID3 is not available)
function getAudioDuration($file_path) {
    if (!file_exists($file_path)) {
        return 180; // Default 3 minutes if file not found
    }
    
    // Try using PHP's built-in functions first
    if (function_exists('shell_exec') && is_executable('/usr/bin/ffmpeg')) {
        try {
            $cmd = "ffmpeg -i " . escapeshellarg($file_path) . " 2>&1";
            $output = shell_exec($cmd);
            
            if (preg_match('/Duration: (\d+):(\d+):(\d+)/', $output, $matches)) {
                $hours = intval($matches[1]);
                $minutes = intval($matches[2]);
                $seconds = intval($matches[3]);
                return $hours * 3600 + $minutes * 60 + $seconds;
            }
        } catch (Exception $e) {
            // Continue to fallback
        }
    }
    
    // Fallback: try to read ID3 tags with simple PHP
    try {
        $file_size = filesize($file_path);
        if ($file_size < 1024) return 180;
        
        // Very basic MP3 duration estimation (approximate)
        if (preg_match('/\.mp3$/i', $file_path)) {
            // Rough estimation: file_size / bitrate
            // Assume 128 kbps average
            $bitrate = 128000; // 128 kbps in bits per second
            $duration = ($file_size * 8) / $bitrate;
            return max(30, min(3600, $duration)); // Clamp between 30s and 60min
        }
    } catch (Exception $e) {
        // Ignore errors
    }
    
    return 180; // Default 3 minutes on error
}
?>