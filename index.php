<?php
include 'config.php';
redirectIfNotLogged();

$database = new Database();
$db = $database->getConnection();

$current_page = isset($_GET['page']) ? $_GET['page'] : 'home';

// AJAX search endpoint
if (isset($_GET['ajax_search']) && isset($_GET['query'])) {
    $search_query = trim($_GET['query']);
    $results = [];
    
    if (!empty($search_query)) {
        // Search songs
        $songs_query = "SELECT s.*, 
                        (SELECT COUNT(*) FROM favorites f WHERE f.song_id = s.id AND f.user_id = :user_id) as is_favorite
                        FROM songs s 
                        WHERE s.title LIKE :search OR s.artist LIKE :search OR s.album LIKE :search
                        ORDER BY s.uploaded_at DESC";
        $songs_stmt = $db->prepare($songs_query);
        $songs_stmt->bindParam(':user_id', $_SESSION['user_id']);
        $search_param = "%$search_query%";
        $songs_stmt->bindParam(':search', $search_param);
        $songs_stmt->execute();
        $results['songs'] = $songs_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Search playlists
        $playlists_query = "SELECT p.*, COUNT(ps.song_id) as song_count 
                          FROM playlists p 
                          LEFT JOIN playlist_songs ps ON p.id = ps.playlist_id 
                          WHERE p.created_by = :user_id AND p.name LIKE :search
                          GROUP BY p.id 
                          ORDER BY p.created_at DESC";
        $playlists_stmt = $db->prepare($playlists_query);
        $playlists_stmt->bindParam(':user_id', $_SESSION['user_id']);
        $playlists_stmt->bindParam(':search', $search_param);
        $playlists_stmt->execute();
        $results['playlists'] = $playlists_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $results['songs'] = [];
        $results['playlists'] = [];
    }
    
    header('Content-Type: application/json');
    echo json_encode($results);
    exit();
}

// Get user's playlists with song counts
$playlist_query = "SELECT p.*, COUNT(ps.song_id) as song_count 
                  FROM playlists p 
                  LEFT JOIN playlist_songs ps ON p.id = ps.playlist_id 
                  WHERE p.created_by = :user_id 
                  GROUP BY p.id 
                  ORDER BY p.created_at DESC";
$playlist_stmt = $db->prepare($playlist_query);
$playlist_stmt->bindParam(':user_id', $_SESSION['user_id']);
$playlist_stmt->execute();
$playlists = $playlist_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all songs
$songs_query = "SELECT s.*, 
                (SELECT COUNT(*) FROM favorites f WHERE f.song_id = s.id AND f.user_id = :user_id) as is_favorite
                FROM songs s 
                ORDER BY s.uploaded_at DESC";
$songs_stmt = $db->prepare($songs_query);
$songs_stmt->bindParam(':user_id', $_SESSION['user_id']);
$songs_stmt->execute();
$songs = $songs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get favorite songs
$favorites_query = "SELECT s.* FROM songs s 
                   JOIN favorites f ON s.id = f.song_id 
                   WHERE f.user_id = :user_id 
                   ORDER BY f.added_at DESC";
$favorites_stmt = $db->prepare($favorites_query);
$favorites_stmt->bindParam(':user_id', $_SESSION['user_id']);
$favorites_stmt->execute();
$favorite_songs = $favorites_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle song upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['song_file'])) {
    $upload_dir = 'uploads/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $song_file = $_FILES['song_file'];
    $cover_file = $_FILES['cover_image'];
    
    $song_filename = uniqid() . '_' . basename($song_file['name']);
    $song_path = $upload_dir . $song_filename;
    
    $cover_path = null;
    if ($cover_file['size'] > 0) {
        $cover_filename = uniqid() . '_' . basename($cover_file['name']);
        $cover_path = $upload_dir . $cover_filename;
        move_uploaded_file($cover_file['tmp_name'], $cover_path);
    }
    
    if (move_uploaded_file($song_file['tmp_name'], $song_path)) {
        $title = $_POST['title'];
        $artist = $_POST['artist'];
        $album = $_POST['album'];
        $duration = getAudioDuration($song_path);
        
        $insert_query = "INSERT INTO songs (title, artist, album, duration, file_path, cover_path, uploaded_by) 
                        VALUES (:title, :artist, :album, :duration, :file_path, :cover_path, :uploaded_by)";
        $insert_stmt = $db->prepare($insert_query);
        $insert_stmt->bindParam(':title', $title);
        $insert_stmt->bindParam(':artist', $artist);
        $insert_stmt->bindParam(':album', $album);
        $insert_stmt->bindParam(':duration', $duration);
        $insert_stmt->bindParam(':file_path', $song_path);
        $insert_stmt->bindParam(':cover_path', $cover_path);
        $insert_stmt->bindParam(':uploaded_by', $_SESSION['user_id']);
        
        if ($insert_stmt->execute()) {
            header("Location: index.php");
            exit();
        }
    }
}

// Handle playlist creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_playlist'])) {
    $name = $_POST['playlist_name'];
    $description = $_POST['playlist_description'];
    
    $insert_query = "INSERT INTO playlists (name, description, created_by) VALUES (:name, :description, :created_by)";
    $insert_stmt = $db->prepare($insert_query);
    $insert_stmt->bindParam(':name', $name);
    $insert_stmt->bindParam(':description', $description);
    $insert_stmt->bindParam(':created_by', $_SESSION['user_id']);
    
    if ($insert_stmt->execute()) {
        header("Location: index.php?page=playlists");
        exit();
    }
}

// Handle add to playlist
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_to_playlist'])) {
    $playlist_id = $_POST['playlist_id'];
    $song_id = $_POST['song_id'];
    
    // Check if song already in playlist
    $check_query = "SELECT id FROM playlist_songs WHERE playlist_id = :playlist_id AND song_id = :song_id";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bindParam(':playlist_id', $playlist_id);
    $check_stmt->bindParam(':song_id', $song_id);
    $check_stmt->execute();
    
    if ($check_stmt->rowCount() == 0) {
        $insert_query = "INSERT INTO playlist_songs (playlist_id, song_id) VALUES (:playlist_id, :song_id)";
        $insert_stmt = $db->prepare($insert_query);
        $insert_stmt->bindParam(':playlist_id', $playlist_id);
        $insert_stmt->bindParam(':song_id', $song_id);
        $insert_stmt->execute();
    }
}

// Handle favorite/unfavorite
if (isset($_GET['toggle_favorite'])) {
    $song_id = $_GET['toggle_favorite'];
    
    // Check if already favorited
    $check_query = "SELECT id FROM favorites WHERE user_id = :user_id AND song_id = :song_id";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bindParam(':user_id', $_SESSION['user_id']);
    $check_stmt->bindParam(':song_id', $song_id);
    $check_stmt->execute();
    
    if ($check_stmt->rowCount() > 0) {
        // Remove from favorites
        $delete_query = "DELETE FROM favorites WHERE user_id = :user_id AND song_id = :song_id";
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->bindParam(':user_id', $_SESSION['user_id']);
        $delete_stmt->bindParam(':song_id', $song_id);
        $delete_stmt->execute();
    } else {
        // Add to favorites
        $insert_query = "INSERT INTO favorites (user_id, song_id) VALUES (:user_id, :song_id)";
        $insert_stmt = $db->prepare($insert_query);
        $insert_stmt->bindParam(':user_id', $_SESSION['user_id']);
        $insert_stmt->bindParam(':song_id', $song_id);
        $insert_stmt->execute();
    }
    
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit();
}

// Handle playlist deletion
if (isset($_GET['delete_playlist'])) {
    $playlist_id = $_GET['delete_playlist'];
    
    // Verify ownership
    $check_query = "SELECT id FROM playlists WHERE id = :playlist_id AND created_by = :user_id";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bindParam(':playlist_id', $playlist_id);
    $check_stmt->bindParam(':user_id', $_SESSION['user_id']);
    $check_stmt->execute();
    
    if ($check_stmt->rowCount() > 0) {
        $delete_query = "DELETE FROM playlists WHERE id = :playlist_id";
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->bindParam(':playlist_id', $playlist_id);
        $delete_stmt->execute();
    }
    
    header("Location: index.php?page=playlists");
    exit();
}

// Get song data for player (AJAX endpoint)
if (isset($_GET['get_song']) && isset($_GET['song_id'])) {
    $song_query = "SELECT * FROM songs WHERE id = :song_id";
    $song_stmt = $db->prepare($song_query);
    $song_stmt->bindParam(':song_id', $_GET['song_id']);
    $song_stmt->execute();
    $song_data = $song_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($song_data) {
        header('Content-Type: application/json');
        echo json_encode($song_data);
        exit();
    }
}

// Get playlist songs (AJAX endpoint)
if (isset($_GET['get_playlist_songs']) && isset($_GET['playlist_id'])) {
    $playlist_songs_query = "SELECT s.* FROM songs s 
                            JOIN playlist_songs ps ON s.id = ps.song_id 
                            WHERE ps.playlist_id = :playlist_id 
                            ORDER BY ps.added_at DESC";
    $playlist_songs_stmt = $db->prepare($playlist_songs_query);
    $playlist_songs_stmt->bindParam(':playlist_id', $_GET['playlist_id']);
    $playlist_songs_stmt->execute();
    $playlist_songs = $playlist_songs_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($playlist_songs);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NeonBeats - Music Player</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #6c5ce7;
            --secondary: #a29bfe;
            --accent: #00cec9;
            --dark: #121212;
            --darker: #0a0a0a;
            --light: #f5f6fa;
            --neon-pink: #fd79a8;
            --neon-blue: #0984e3;
            --neon-green: #00b894;
            --neon-purple: #a29bfe;
            --visualizer-opacity: 0.4;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: linear-gradient(135deg, var(--darker) 0%, var(--dark) 100%);
            color: var(--light);
            min-height: 100vh;
            overflow-x: hidden;
            transition: all 0.5s ease;
        }

        body.fullscreen-visualizer {
            background: transparent !important;
        }

        body.fullscreen-visualizer .container,
        body.fullscreen-visualizer .header,
        body.fullscreen-visualizer .sidebar,
        body.fullscreen-visualizer .main-content,
        body.fullscreen-visualizer .player,
        body.fullscreen-visualizer .floating-btn {
            opacity: 0;
            pointer-events: none;
            transition: all 0.5s ease;
        }

        body.fullscreen-visualizer #visualizer-container {
            opacity: 1 !important;
            z-index: 9999;
        }

        /* Enhanced Music Visualizer Background */
        #visualizer-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            opacity: var(--visualizer-opacity);
            transition: opacity 0.5s ease;
        }

        #visualizer {
            width: 100%;
            height: 100%;
        }

        /* Enhanced Visualizer Controls in Sidebar */
        .visualizer-section {
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid rgba(162, 155, 254, 0.3);
        }

        .visualizer-section h3 {
            color: var(--secondary);
            margin-bottom: 15px;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .visualizer-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 0;
            color: var(--light);
            cursor: pointer;
            transition: color 0.3s ease;
            font-size: 0.9rem;
        }

        .visualizer-toggle:hover {
            color: var(--neon-pink);
        }

        .visualizer-settings {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            padding: 15px;
            margin-top: 10px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(162, 155, 254, 0.2);
        }

        .settings-group {
            margin-bottom: 15px;
            position: relative;
        }

        .settings-group:last-child {
            margin-bottom: 0;
        }

        .settings-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--secondary);
            font-size: 0.8rem;
            font-weight: 500;
        }

        .settings-group select, .settings-group input {
            width: 100%;
            padding: 8px 10px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--neon-purple);
            border-radius: 6px;
            color: var(--light);
            font-size: 0.8rem;
            transition: all 0.3s ease;
        }

        .settings-group select:focus, .settings-group input:focus {
            outline: none;
            border-color: var(--neon-pink);
            box-shadow: 0 0 10px rgba(253, 121, 168, 0.3);
        }

        .settings-group input[type="range"] {
            -webkit-appearance: none;
            height: 6px;
            background: linear-gradient(90deg, var(--neon-purple), var(--neon-pink));
            border-radius: 3px;
            outline: none;
        }

        .settings-group input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 18px;
            height: 18px;
            background: var(--neon-pink);
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 0 10px rgba(253, 121, 168, 0.5);
        }

        .settings-group input[type="range"]::-webkit-slider-thumb:hover {
            background: var(--neon-purple);
            transform: scale(1.2);
            box-shadow: 0 0 15px rgba(162, 155, 254, 0.7);
        }

        .range-value {
            position: absolute;
            right: 0;
            top: 0;
            background: var(--neon-purple);
            color: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: bold;
        }

        .sub-settings {
            margin-top: 10px;
            padding-left: 15px;
            border-left: 2px solid var(--neon-purple);
            display: none;
        }

        .sub-settings.active {
            display: block;
        }

        .sub-setting {
            margin-bottom: 8px;
            padding: 8px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 6px;
            border: 1px solid rgba(162, 155, 254, 0.2);
        }

        .sub-setting label {
            font-size: 0.75rem;
            margin-bottom: 4px;
        }

        .fullscreen-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px;
            background: linear-gradient(45deg, var(--neon-pink), var(--neon-purple));
            border: none;
            border-radius: 8px;
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.85rem;
            font-weight: 500;
            margin-top: 10px;
            width: 100%;
            justify-content: center;
        }

        .fullscreen-toggle:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(253, 121, 168, 0.4);
        }

        .fullscreen-toggle i {
            font-size: 1rem;
        }

        /* Advanced Settings Toggle */
        .advanced-toggle {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 0;
            color: var(--secondary);
            cursor: pointer;
            transition: color 0.3s ease;
            font-size: 0.8rem;
        }

        .advanced-toggle:hover {
            color: var(--neon-pink);
        }

        .advanced-toggle i {
            transition: transform 0.3s ease;
        }

        .advanced-toggle.active i {
            transform: rotate(180deg);
        }

        /* Rest of your existing CSS styles remain exactly the same */
        .container {
            display: flex;
            flex-direction: column;
            height: 100vh;
            transition: all 0.5s ease;
        }

        .header {
            background: rgba(18, 18, 18, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--neon-purple);
            padding: 15px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
            min-height: 70px;
            transition: all 0.5s ease;
        }

        .search-bar {
            flex: 1;
            max-width: 400px;
            margin: 0 15px;
        }

        .search-bar input {
            width: 100%;
            padding: 10px 15px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--neon-purple);
            border-radius: 20px;
            color: var(--light);
            font-size: 0.9rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar {
            width: 35px;
            height: 35px;
            background: linear-gradient(45deg, var(--neon-pink), var(--neon-purple));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.9rem;
        }

        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            color: var(--light);
            font-size: 1.2rem;
            cursor: pointer;
        }

        .main-wrapper {
            display: flex;
            flex: 1;
            overflow: hidden;
        }

        .sidebar {
            width: 280px;
            background: rgba(10, 10, 10, 0.95);
            border-right: 1px solid var(--neon-purple);
            padding: 20px;
            overflow-y: auto;
            transition: transform 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .logo {
            text-align: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid rgba(162, 155, 254, 0.3);
        }

        .logo h1 {
            font-size: 1.5rem;
            background: linear-gradient(45deg, var(--neon-pink), var(--neon-purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .nav-links {
            list-style: none;
            margin-bottom: 25px;
        }

        .nav-links li {
            margin-bottom: 8px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            color: var(--light);
            text-decoration: none;
            border-radius: 10px;
            transition: all 0.3s ease;
            font-size: 0.95rem;
        }

        .nav-links a:hover, .nav-links a.active {
            background: linear-gradient(45deg, var(--neon-pink), var(--neon-purple));
            transform: translateX(5px);
        }

        .playlists-section {
            margin-top: 25px;
        }

        .playlists-section h3 {
            color: var(--secondary);
            margin-bottom: 15px;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .playlist-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 0;
            color: var(--light);
            cursor: pointer;
            transition: color 0.3s ease;
            font-size: 0.9rem;
        }

        .playlist-item:hover {
            color: var(--neon-pink);
        }

        .main-content {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            background: rgba(18, 18, 18, 0.5);
            backdrop-filter: blur(5px);
            transition: all 0.5s ease;
        }

        .section-title {
            font-size: 1.4rem;
            margin-bottom: 20px;
            background: linear-gradient(45deg, var(--neon-pink), var(--neon-purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .songs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
            transition: all 0.5s ease;
        }

        .song-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 12px;
            transition: all 0.3s ease;
            border: 1px solid transparent;
            cursor: pointer;
            backdrop-filter: blur(10px);
        }

        .song-card:hover {
            transform: translateY(-3px);
            border-color: var(--neon-purple);
            box-shadow: 0 8px 20px rgba(162, 155, 254, 0.2);
        }

        .song-card.playing {
            border-color: var(--neon-pink);
            background: rgba(253, 121, 168, 0.1);
        }

        .song-cover {
            width: 100%;
            aspect-ratio: 1;
            border-radius: 8px;
            background: linear-gradient(45deg, var(--neon-pink), var(--neon-purple));
            margin-bottom: 8px;
            overflow: hidden;
        }

        .song-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .song-info h4 {
            font-size: 0.85rem;
            margin-bottom: 4px;
            line-height: 1.3;
        }

        .song-info p {
            font-size: 0.75rem;
            color: var(--secondary);
            line-height: 1.2;
        }

        .song-actions {
            display: flex;
            justify-content: space-between;
            margin-top: 8px;
        }

        .action-btn {
            background: none;
            border: none;
            color: var(--secondary);
            cursor: pointer;
            transition: color 0.3s ease;
            padding: 4px;
        }

        .action-btn:hover {
            color: var(--neon-pink);
        }

        .page-content {
            display: none;
        }

        .page-content.active {
            display: block;
        }

        .playlist-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .playlist-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .playlist-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 15px;
            transition: all 0.3s ease;
            border: 1px solid transparent;
            cursor: pointer;
            position: relative;
            backdrop-filter: blur(10px);
        }

        .playlist-card:hover {
            transform: translateY(-3px);
            border-color: var(--neon-purple);
            box-shadow: 0 8px 20px rgba(162, 155, 254, 0.2);
        }

        .playlist-cover {
            width: 100%;
            aspect-ratio: 1;
            border-radius: 8px;
            background: linear-gradient(45deg, var(--neon-pink), var(--neon-purple));
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }

        .playlist-info h3 {
            font-size: 0.95rem;
            margin-bottom: 4px;
            line-height: 1.3;
        }

        .playlist-info p {
            font-size: 0.75rem;
            color: var(--secondary);
            line-height: 1.2;
        }

        .playlist-actions {
            position: absolute;
            top: 12px;
            right: 12px;
            display: flex;
            gap: 8px;
        }

        .playlist-action-btn {
            background: rgba(0, 0, 0, 0.7);
            border: none;
            color: var(--light);
            width: 28px;
            height: 28px;
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .playlist-action-btn:hover {
            background: var(--neon-pink);
            transform: scale(1.1);
        }

        .songs-list {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 25px;
            overflow-x: auto;
            backdrop-filter: blur(10px);
        }

        .song-row {
            display: flex;
            align-items: center;
            padding: 10px;
            border-radius: 8px;
            transition: background 0.3s ease;
            cursor: pointer;
            min-width: 600px;
        }

        .song-row:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .song-row.playing {
            background: rgba(253, 121, 168, 0.2);
            border-left: 3px solid var(--neon-pink);
        }

        .song-number {
            width: 30px;
            text-align: center;
            color: var(--secondary);
            font-size: 0.85rem;
        }

        .song-title {
            flex: 2;
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 200px;
        }

        .song-title img {
            width: 35px;
            height: 35px;
            border-radius: 5px;
            object-fit: cover;
        }

        .song-artist {
            flex: 1;
            color: var(--secondary);
            font-size: 0.85rem;
            min-width: 120px;
        }

        .song-album {
            flex: 1;
            color: var(--secondary);
            font-size: 0.85rem;
            min-width: 120px;
        }

        .song-duration {
            width: 60px;
            text-align: right;
            color: var(--secondary);
            font-size: 0.85rem;
        }

        .song-actions {
            width: 80px;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .profile-section {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            backdrop-filter: blur(10px);
        }

        .profile-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(45deg, var(--neon-pink), var(--neon-purple));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: white;
            font-weight: bold;
        }

        .profile-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            backdrop-filter: blur(10px);
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            background: linear-gradient(45deg, var(--neon-pink), var(--neon-purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 5px;
        }

        .player {
            background: rgba(10, 10, 10, 0.95);
            border-top: 1px solid var(--neon-purple);
            padding: 15px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            min-height: 90px;
            backdrop-filter: blur(10px);
            transition: all 0.5s ease;
        }

        .now-playing {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            min-width: 0;
        }

        .now-playing-cover {
            width: 50px;
            height: 50px;
            border-radius: 8px;
            background: linear-gradient(45deg, var(--neon-pink), var(--neon-purple));
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .now-playing-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .now-playing-info {
            min-width: 0;
            flex: 1;
        }

        .now-playing-info h4 {
            font-size: 0.9rem;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .now-playing-info p {
            font-size: 0.75rem;
            color: var(--secondary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .player-controls {
            flex: 2;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            max-width: 400px;
        }

        .control-buttons {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .control-btn {
            background: none;
            border: none;
            color: var(--light);
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            padding: 5px;
        }

        .play-btn {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: linear-gradient(45deg, var(--neon-pink), var(--neon-purple));
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .control-btn:hover {
            color: var(--neon-pink);
        }

        .play-btn:hover {
            transform: scale(1.1);
        }

        .progress-container {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .time-display {
            font-size: 0.7rem;
            color: var(--secondary);
            min-width: 35px;
        }

        .progress-bar {
            flex: 1;
            height: 3px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 2px;
            cursor: pointer;
            position: relative;
        }

        .progress {
            position: absolute;
            height: 100%;
            background: linear-gradient(45deg, var(--neon-pink), var(--neon-purple));
            border-radius: 2px;
            width: 0%;
        }

        .player-actions {
            flex: 1;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .volume-control {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 120px;
}

.volume-slider {
    width: 100px;
    height: 6px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 10px;
    cursor: pointer;
    position: relative;
    transition: all 0.3s ease;
}

.volume-slider:hover {
    height: 8px;
    background: rgba(255, 255, 255, 0.2);
}

.volume-level {
    position: absolute;
    height: 100%;
    background: linear-gradient(45deg, var(--neon-pink), var(--neon-purple));
    border-radius: 10px;
    width: 80%;
    box-shadow: 0 0 10px rgba(162, 155, 254, 0.5);
    transition: all 0.3s ease;
}

.volume-slider:hover .volume-level {
    box-shadow: 0 0 15px rgba(162, 155, 254, 0.7);
}

.volume-percentage {
    font-size: 0.75rem;
    color: var(--secondary);
    min-width: 35px;
    text-align: center;
    font-weight: 500;
}

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            backdrop-filter: blur(5px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-content {
            background: var(--dark);
            border: 1px solid var(--neon-purple);
            border-radius: 15px;
            padding: 25px;
            width: 100%;
            max-width: 450px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 0 40px rgba(162, 155, 254, 0.3);
            backdrop-filter: blur(10px);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .close-btn {
            background: none;
            border: none;
            color: var(--light);
            font-size: 1.3rem;
            cursor: pointer;
            padding: 5px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: var(--secondary);
            font-size: 0.9rem;
        }

        .form-control {
            width: 100%;
            padding: 10px 12px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--neon-purple);
            border-radius: 8px;
            color: var(--light);
            font-size: 0.9rem;
        }

        .btn {
            padding: 10px 18px;
            background: linear-gradient(45deg, var(--neon-pink), var(--neon-purple));
            border: none;
            border-radius: 8px;
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(253, 121, 168, 0.4);
        }

        .floating-btn {
            position: fixed;
            bottom: 100px;
            right: 20px;
            width: 55px;
            height: 55px;
            border-radius: 50%;
            background: linear-gradient(45deg, var(--neon-pink), var(--neon-purple));
            border: none;
            color: white;
            font-size: 1.3rem;
            cursor: pointer;
            box-shadow: 0 5px 15px rgba(253, 121, 168, 0.4);
            transition: all 0.3s ease;
            z-index: 99;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .floating-btn:hover {
            transform: scale(1.1);
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--secondary);
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 12px;
            color: var(--neon-purple);
        }

        .add-to-playlist-modal .playlist-options {
            max-height: 200px;
            overflow-y: auto;
            margin: 12px 0;
        }

        .playlist-option {
            padding: 10px;
            border: 1px solid var(--neon-purple);
            border-radius: 6px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .playlist-option:hover {
            background: rgba(162, 155, 254, 0.2);
            border-color: var(--neon-pink);
        }

        .search-results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .search-query {
            color: var(--neon-pink);
            font-style: italic;
        }

        .results-count {
            color: var(--secondary);
            font-size: 0.9rem;
        }

        .no-results {
            text-align: center;
            padding: 40px 20px;
            color: var(--secondary);
        }

        .no-results i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: var(--neon-purple);
        }

        .search-loading {
            text-align: center;
            padding: 20px;
            color: var(--secondary);
        }

        .search-loading i {
            font-size: 2rem;
            margin-bottom: 10px;
            color: var(--neon-purple);
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .clear-search-btn {
            background: var(--neon-blue);
            margin-left: 10px;
        }

        .clear-search-btn:hover {
            background: var(--neon-purple);
        }

        /* Mobile Styles */
        @media (max-width: 768px) {
            .mobile-menu-btn {
                display: block;
            }

            .sidebar {
                position: fixed;
                top: 70px;
                left: 0;
                height: calc(100vh - 160px);
                transform: translateX(-100%);
                z-index: 99;
                width: 280px;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-wrapper {
                flex-direction: column;
            }

            .main-content {
                padding: 15px;
            }

            .header {
                padding: 12px 15px;
            }

            .search-bar {
                margin: 0 10px;
            }

            .user-info span {
                display: none;
            }

            .songs-grid {
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
                gap: 12px;
            }

            .playlist-grid {
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
                gap: 12px;
            }

            .player {
                padding: 12px 15px;
                gap: 10px;
                flex-wrap: wrap;
            }

            .now-playing {
                flex: 1 1 100%;
                margin-bottom: 10px;
            }

            .player-controls {
                flex: 2;
                max-width: none;
            }

            .player-actions {
                flex: 1;
            }

            .volume-control {
                display: none;
            }

            .floating-btn {
                bottom: 90px;
                right: 15px;
                width: 50px;
                height: 50px;
                font-size: 1.2rem;
            }
        }

        /* Tablet Styles */
        @media (min-width: 769px) and (max-width: 1024px) {
            .sidebar {
                width: 240px;
            }

            .songs-grid {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            }

            .playlist-grid {
                grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            }

            .player-controls {
                max-width: 350px;
            }
        }

        /* Small Mobile Styles */
        @media (max-width: 480px) {
            .songs-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .playlist-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .playlist-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .profile-header {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }

            .profile-stats {
                grid-template-columns: 1fr;
            }

            .song-row {
                min-width: 500px;
            }

            .modal-content {
                padding: 20px;
                margin: 10px;
            }
        }

        /* Large Desktop Styles */
        @media (min-width: 1440px) {
            .sidebar {
                width: 300px;
            }

            .songs-grid {
                grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
                gap: 20px;
            }

            .playlist-grid {
                grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
                gap: 20px;
            }

            .main-content {
                padding: 30px;
            }
        }

        .songs-list::-webkit-scrollbar {
            height: 6px;
        }

        .songs-list::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 3px;
        }

        .songs-list::-webkit-scrollbar-thumb {
            background: var(--neon-purple);
            border-radius: 3px;
        }

        .songs-list::-webkit-scrollbar-thumb:hover {
            background: var(--neon-pink);
        }
    </style>
</head>
<body>
    <!-- Enhanced Music Visualizer Background -->
    <div id="visualizer-container">
        <canvas id="visualizer"></canvas>
    </div>

    <div class="container">
        <!-- Header -->
        <header class="header">
            <button class="mobile-menu-btn" id="mobileMenuBtn">
                <i class="fas fa-bars"></i>
            </button>
            <div class="search-bar">
                <div class="search-container">
                    <input type="text" id="searchInput" placeholder="Search for songs, artists, or playlists...">
                    <button class="btn clear-search-btn" id="clearSearchBtn" style="display: none; padding: 8px 12px; margin-left: 8px;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <div class="user-info">
                <span class="desktop-only">Welcome, <?= $_SESSION['username'] ?></span>
                <div class="user-avatar">
                    <?= strtoupper(substr($_SESSION['username'], 0, 1)) ?>
                </div>
                <a href="logout.php" class="btn" style="padding: 8px 12px; font-size: 0.8rem;">Logout</a>
            </div>
        </header>

        <!-- Main Wrapper -->
        <div class="main-wrapper">
            <!-- Sidebar -->
            <aside class="sidebar" id="sidebar">
                <div class="logo">
                    <h1><i class="fas fa-music"></i> Beats</h1>
                </div>
                
                <ul class="nav-links">
                    <li><a href="#" class="nav-link <?= $current_page == 'home' ? 'active' : '' ?>" data-page="home"><i class="fas fa-home"></i> Home</a></li>
                    <li><a href="#" class="nav-link <?= $current_page == 'favorites' ? 'active' : '' ?>" data-page="favorites"><i class="fas fa-heart"></i> Favorites</a></li>
                    <li><a href="#" class="nav-link <?= $current_page == 'playlists' ? 'active' : '' ?>" data-page="playlists"><i class="fas fa-list-music"></i> Playlists</a></li>
                    <li><a href="#" class="nav-link <?= $current_page == 'profile' ? 'active' : '' ?>" data-page="profile"><i class="fas fa-user"></i> Profile</a></li>
                </ul>

                <div class="playlists-section">
                    <h3>Your Playlists</h3>
                    <?php foreach(array_slice($playlists, 0, 5) as $playlist): ?>
                        <div class="playlist-item" onclick="loadPlaylist(<?= $playlist['id'] ?>)">
                            <i class="fas fa-list-music"></i>
                            <span><?= htmlspecialchars($playlist['name']) ?></span>
                            <small style="color: var(--secondary); margin-left: auto;">
                                (<?= $playlist['song_count'] ?>)
                            </small>
                        </div>
                    <?php endforeach; ?>
                    <?php if(count($playlists) > 5): ?>
                        <div class="playlist-item" onclick="showPage('playlists')">
                            <i class="fas fa-ellipsis-h"></i>
                            <span>View All</span>
                        </div>
                    <?php endif; ?>
                    <div class="playlist-item" onclick="openModal('createPlaylistModal')">
                        <i class="fas fa-plus"></i>
                        <span>Create Playlist</span>
                    </div>
                </div>

                <!-- Enhanced Visualizer Section Below Playlists -->
                <div class="visualizer-section">
                    <h3>Visualizer Settings</h3>
                    
                    <div class="visualizer-toggle" onclick="toggleVisualizer()">
                        <i class="fas fa-wave-square"></i>
                        <span>Toggle Visualizer</span>
                        <small style="color: var(--secondary); margin-left: auto;" id="visualizerStatus">ON</small>
                    </div>

                    <div class="visualizer-settings" id="visualizerSettings">
                        <!-- Visualizer Type -->
                        <div class="settings-group">
                            <label for="visualizerType">Visualizer Type</label>
                            <select id="visualizerType">
                                <option value="frequencyBars">Frequency Bars</option>
                                <option value="circular">Circular Waves</option>
                                <option value="particle">Particle System</option>
                                <option value="waveform">Waveform</option>
                                <option value="nebula">Nebula</option>
                                <option value="energyOrbs">Energy Orbs</option>
                                <option value="gradientBars">Gradient Bars</option>
                                <option value="liquidWaves">Liquid Waves</option>
                            </select>
                        </div>

                        <!-- Opacity Control -->
                        <div class="settings-group">
                            <label for="visualizerOpacity">Background Opacity</label>
                            <input type="range" id="visualizerOpacity" min="10" max="100" value="40">
                            <span class="range-value" id="opacityValue">40%</span>
                        </div>

                        <!-- Sensitivity Control -->
                        <div class="settings-group">
                            <label for="sensitivity">Sensitivity</label>
                            <input type="range" id="sensitivity" min="50" max="200" value="100">
                            <span class="range-value" id="sensitivityValue">100%</span>
                        </div>

                        <!-- Color Scheme -->
                        <div class="settings-group">
                            <label for="colorScheme">Color Scheme</label>
                            <select id="colorScheme">
                                <option value="neon">Neon</option>
                                <option value="rainbow">Rainbow</option>
                                <option value="monochrome">Monochrome</option>
                                <option value="fire">Fire</option>
                                <option value="ocean">Ocean</option>
                                <option value="pastel">Pastel</option>
                                <option value="electric">Electric</option>
                            </select>
                        </div>

                        <!-- Advanced Settings Toggle -->
                        <div class="advanced-toggle" onclick="toggleAdvancedSettings()">
                            <i class="fas fa-chevron-down"></i>
                            <span>Advanced Settings</span>
                        </div>

                        <!-- Advanced Settings -->
                        <div class="sub-settings" id="advancedSettings">
                            <!-- Particle Count -->
                            <div class="sub-setting">
                                <label for="particleCount">Particle Count</label>
                                <input type="range" id="particleCount" min="50" max="500" value="150">
                                <span class="range-value" id="particleCountValue">150</span>
                            </div>

                            <!-- Wave Intensity -->
                            <div class="sub-setting">
                                <label for="waveIntensity">Wave Intensity</label>
                                <input type="range" id="waveIntensity" min="1" max="10" value="5">
                                <span class="range-value" id="waveIntensityValue">5</span>
                            </div>

                            <!-- Animation Speed -->
                            <div class="sub-setting">
                                <label for="animationSpeed">Animation Speed</label>
                                <input type="range" id="animationSpeed" min="1" max="10" value="5">
                                <span class="range-value" id="animationSpeedValue">5</span>
                            </div>

                            <!-- Gradient Style -->
                            <div class="sub-setting">
                                <label for="gradientStyle">Gradient Style</label>
                                <select id="gradientStyle">
                                    <option value="linear">Linear</option>
                                    <option value="radial">Radial</option>
                                    <option value="conic">Conic</option>
                                    <option value="mesh">Mesh</option>
                                </select>
                            </div>
                        </div>

                        <!-- Fullscreen Visualizer Button -->
                        <button class="fullscreen-toggle" onclick="toggleFullscreenVisualizer()">
                            <i class="fas fa-expand"></i>
                            <span id="fullscreenText">Fullscreen Visualizer</span>
                        </button>
                    </div>
                </div>
            </aside>

            <!-- Main Content -->
            <main class="main-content">
                <!-- Search Results Page -->
                <div id="search-results-page" class="page-content" style="display: none;">
                    <div class="search-results-header">
                        <h2 class="section-title">Search Results</h2>
                        <div class="results-count" id="resultsCount">
                            Found <span id="resultsNumber">0</span> result(s)
                        </div>
                    </div>
                    
                    <div id="searchResultsContainer">
                        <!-- Search results will be loaded here via AJAX -->
                    </div>
                </div>

                <!-- Home Page -->
                <div id="home-page" class="page-content <?= $current_page == 'home' ? 'active' : '' ?>">
                    <h2 class="section-title">Recently Added</h2>
                    <div class="songs-grid">
                        <?php foreach($songs as $song): ?>
                            <div class="song-card" data-song-id="<?= $song['id'] ?>" onclick="playSong(<?= $song['id'] ?>, this)">
                                <div class="song-cover">
                                    <?php if($song['cover_path']): ?>
                                        <img src="<?= $song['cover_path'] ?>" alt="<?= htmlspecialchars($song['title']) ?>">
                                    <?php else: ?>
                                        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;">
                                            <i class="fas fa-music" style="font-size:1.5rem;color:white;"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="song-info">
                                    <h4><?= htmlspecialchars($song['title']) ?></h4>
                                    <p><?= htmlspecialchars($song['artist']) ?></p>
                                    <p style="font-size:0.7rem;color:var(--neon-purple);">
                                        <?= gmdate("i:s", $song['duration']) ?>
                                    </p>
                                </div>
                                <div class="song-actions">
                                    <button class="action-btn" onclick="event.stopPropagation();openAddToPlaylistModal(<?= $song['id'] ?>)">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                    <button class="action-btn" onclick="event.stopPropagation();toggleFavorite(<?= $song['id'] ?>)">
                                        <i class="<?= $song['is_favorite'] ? 'fas' : 'far' ?> fa-heart" style="color: <?= $song['is_favorite'] ? 'var(--neon-pink)' : 'var(--secondary)' ?>"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Favorites Page -->
                <div id="favorites-page" class="page-content <?= $current_page == 'favorites' ? 'active' : '' ?>">
                    <div class="playlist-header">
                        <h2 class="section-title">Your Favorite Songs</h2>
                    </div>
                    
                    <?php if(count($favorite_songs) > 0): ?>
                        <div class="songs-list">
                            <?php foreach($favorite_songs as $index => $song): ?>
                                <div class="song-row" data-song-id="<?= $song['id'] ?>" onclick="playSong(<?= $song['id'] ?>, this)">
                                    <div class="song-number"><?= $index + 1 ?></div>
                                    <div class="song-title">
                                        <?php if($song['cover_path']): ?>
                                            <img src="<?= $song['cover_path'] ?>" alt="<?= htmlspecialchars($song['title']) ?>">
                                        <?php else: ?>
                                            <i class="fas fa-music"></i>
                                        <?php endif; ?>
                                        <span><?= htmlspecialchars($song['title']) ?></span>
                                    </div>
                                    <div class="song-artist"><?= htmlspecialchars($song['artist']) ?></div>
                                    <div class="song-album"><?= htmlspecialchars($song['album']) ?></div>
                                    <div class="song-duration"><?= gmdate("i:s", $song['duration']) ?></div>
                                    <div class="song-actions">
                                        <button class="action-btn" onclick="event.stopPropagation();openAddToPlaylistModal(<?= $song['id'] ?>)">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                        <button class="action-btn" onclick="event.stopPropagation();toggleFavorite(<?= $song['id'] ?>)">
                                            <i class="fas fa-heart" style="color: var(--neon-pink)"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-heart"></i>
                            <h3>No favorite songs yet</h3>
                            <p>Start adding songs to your favorites by clicking the heart icon</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Playlists Page -->
                <div id="playlists-page" class="page-content <?= $current_page == 'playlists' ? 'active' : '' ?>">
                    <div class="playlist-header">
                        <h2 class="section-title">Your Playlists</h2>
                        <button class="btn" onclick="openModal('createPlaylistModal')">
                            <i class="fas fa-plus"></i> Create Playlist
                        </button>
                    </div>
                    
                    <?php if(count($playlists) > 0): ?>
                        <div class="playlist-grid">
                            <?php foreach($playlists as $playlist): ?>
                                <div class="playlist-card" onclick="loadPlaylist(<?= $playlist['id'] ?>)">
                                    <div class="playlist-cover">
                                        <i class="fas fa-music"></i>
                                    </div>
                                    <div class="playlist-info">
                                        <h3><?= htmlspecialchars($playlist['name']) ?></h3>
                                        <p><?= $playlist['song_count'] ?> songs</p>
                                        <p style="font-size: 0.7rem; margin-top: 5px;"><?= htmlspecialchars($playlist['description']) ?></p>
                                    </div>
                                    <div class="playlist-actions">
                                        <button class="playlist-action-btn" onclick="event.stopPropagation();deletePlaylist(<?= $playlist['id'] ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-list-music"></i>
                            <h3>No playlists yet</h3>
                            <p>Create your first playlist to get started</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Profile Page -->
                <div id="profile-page" class="page-content <?= $current_page == 'profile' ? 'active' : '' ?>">
                    <div class="profile-section">
                        <div class="profile-header">
                            <div class="profile-avatar">
                                <?= strtoupper(substr($_SESSION['username'], 0, 1)) ?>
                            </div>
                            <div>
                                <h2><?= $_SESSION['username'] ?></h2>
                                <p style="color: var(--secondary);">Member since <?= date('F Y', strtotime($playlists[0]['created_at'] ?? 'now')) ?></p>
                            </div>
                        </div>
                        
                        <div class="profile-stats">
                            <div class="stat-card">
                                <div class="stat-number"><?= count($playlists) ?></div>
                                <div>Playlists</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-number"><?= count($favorite_songs) ?></div>
                                <div>Favorite Songs</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-number"><?= count($songs) ?></div>
                                <div>Total Songs</div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <h3 style="margin-bottom: 15px;">Account Settings</h3>
                            <button class="btn" onclick="openModal('changePasswordModal')" style="margin-right: 10px;">
                                <i class="fas fa-key"></i> Change Password
                            </button>
                            <a href="forgot_password.php" class="btn" style="background: var(--neon-blue);">
                                <i class="fas fa-unlock"></i> Reset Password
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Playlist Detail Page (Hidden by default) -->
                <div id="playlist-detail-page" class="page-content">
                    <div class="playlist-header">
                        <button class="btn" onclick="showPage('playlists')" style="margin-right: 15px;">
                            <i class="fas fa-arrow-left"></i> Back
                        </button>
                        <h2 class="section-title" id="playlist-detail-title">Playlist</h2>
                    </div>
                    <div id="playlist-songs-container">
                        <!-- Playlist songs will be loaded here -->
                    </div>
                </div>
            </main>
        </div>

        <!-- Player -->
        <div class="player">
            <div class="now-playing">
                <div class="now-playing-cover" id="nowPlayingCover">
                    <i class="fas fa-music" style="font-size:1.2rem;color:white;"></i>
                </div>
                <div class="now-playing-info">
                    <h4 id="nowPlayingTitle">No song selected</h4>
                    <p id="nowPlayingArtist">Select a song to play</p>
                </div>
            </div>
            
            <div class="player-controls">
                <div class="control-buttons">
                    <button class="control-btn" id="shuffleBtn"><i class="fas fa-random"></i></button>
                    <button class="control-btn" id="prevBtn"><i class="fas fa-step-backward"></i></button>
                    <button class="control-btn play-btn" id="playBtn"><i class="fas fa-play" id="playIcon"></i></button>
                    <button class="control-btn" id="nextBtn"><i class="fas fa-step-forward"></i></button>
                    <button class="control-btn" id="repeatBtn"><i class="fas fa-redo"></i></button>
                </div>
                <div class="progress-container">
                    <span class="time-display" id="currentTime">0:00</span>
                    <div class="progress-bar" id="progressBar">
                        <div class="progress" id="progress"></div>
                    </div>
                    <span class="time-display" id="duration">0:00</span>
                </div>
            </div>
            
            <div class="player-actions">
    <div class="volume-control">
        <button class="control-btn" id="volumeBtn"><i class="fas fa-volume-up"></i></button>
        <div class="volume-slider" id="volumeSlider">
            <div class="volume-level" id="volumeLevel"></div>
        </div>
        <span class="volume-percentage" id="volumePercentage">80%</span>
    </div>
</div>
    </div>

    <!-- Hidden audio element -->
    <audio id="audioPlayer" style="display: none;"></audio>

    <!-- Upload Button -->
    <button class="floating-btn" onclick="openModal('uploadModal')">
        <i class="fas fa-upload"></i>
    </button>

    <!-- Upload Modal -->
    <div id="uploadModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Upload Song</h3>
                <button class="close-btn" onclick="closeModal('uploadModal')">&times;</button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Song Title</label>
                    <input type="text" name="title" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Artist</label>
                    <input type="text" name="artist" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Album</label>
                    <input type="text" name="album" class="form-control">
                </div>
                <div class="form-group">
                    <label>Song File</label>
                    <input type="file" name="song_file" accept="audio/*" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Cover Image (Optional)</label>
                    <input type="file" name="cover_image" accept="image/*" class="form-control">
                </div>
                <button type="submit" class="btn">Upload Song</button>
            </form>
        </div>
    </div>

    <!-- Create Playlist Modal -->
    <div id="createPlaylistModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create Playlist</h3>
                <button class="close-btn" onclick="closeModal('createPlaylistModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="create_playlist" value="1">
                <div class="form-group">
                    <label>Playlist Name</label>
                    <input type="text" name="playlist_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="playlist_description" class="form-control" rows="3"></textarea>
                </div>
                <button type="submit" class="btn">Create Playlist</button>
            </form>
        </div>
    </div>

    <!-- Add to Playlist Modal -->
    <div id="addToPlaylistModal" class="modal add-to-playlist-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add to Playlist</h3>
                <button class="close-btn" onclick="closeModal('addToPlaylistModal')">&times;</button>
            </div>
            <div class="playlist-options" id="playlistOptions">
                <!-- Playlist options will be loaded here -->
            </div>
            <div style="text-align: center; margin-top: 15px;">
                <button class="btn" onclick="openModal('createPlaylistModal')">
                    <i class="fas fa-plus"></i> Create New Playlist
                </button>
            </div>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div id="changePasswordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Change Password</h3>
                <button class="close-btn" onclick="closeModal('changePasswordModal')">&times;</button>
            </div>
            <form method="POST" action="change_password.php">
                <div class="form-group">
                    <label>Current Password</label>
                    <input type="password" name="current_password" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-control" required>
                </div>
                <button type="submit" class="btn">Change Password</button>
            </form>
        </div>
    </div>

    <script>
        // Enhanced Music Visualizer Class
        class EnhancedMusicVisualizer {
            constructor() {
                this.canvas = document.getElementById('visualizer');
                this.ctx = this.canvas.getContext('2d');
                this.audioContext = null;
                this.analyser = null;
                this.source = null;
                this.dataArray = null;
                this.bufferLength = null;
                this.isVisualizing = true;
                this.animationId = null;
                this.isFullscreen = false;
                
                // Enhanced Visualizer settings
                this.settings = {
                    type: 'frequencyBars',
                    opacity: 0.4,
                    sensitivity: 1.0,
                    colorScheme: 'neon',
                    particleCount: 150,
                    waveIntensity: 5,
                    animationSpeed: 5,
                    gradientStyle: 'linear'
                };

                // Enhanced particle system
                this.particles = [];
                this.gradientCache = new Map();

                this.init();
                this.setupEventListeners();
                this.resizeCanvas();
                this.initParticles();
            }

            init() {
                // Initialize Web Audio API
                try {
                    this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
                    this.analyser = this.audioContext.createAnalyser();
                    this.analyser.fftSize = 2048;
                    this.bufferLength = this.analyser.frequencyBinCount;
                    this.dataArray = new Uint8Array(this.bufferLength);

                    // Connect to main audio player
                    const audioPlayer = document.getElementById('audioPlayer');
                    if (audioPlayer) {
                        const source = this.audioContext.createMediaElementSource(audioPlayer);
                        source.connect(this.analyser);
                        this.analyser.connect(this.audioContext.destination);
                    }
                } catch (error) {
                    console.warn('Web Audio API not supported:', error);
                }
            }

            initParticles() {
                this.particles = [];
                for (let i = 0; i < this.settings.particleCount; i++) {
                    this.particles.push({
                        x: Math.random() * this.canvas.width,
                        y: Math.random() * this.canvas.height,
                        size: Math.random() * 3 + 1,
                        speedX: Math.random() * 2 - 1,
                        speedY: Math.random() * 2 - 1,
                        color: this.getRandomColor(),
                        life: 1.0,
                        rotation: Math.random() * Math.PI * 2,
                        rotationSpeed: (Math.random() - 0.5) * 0.1
                    });
                }
            }

            getRandomColor() {
                const schemes = {
                    neon: ['#ff0080', '#00ff88', '#0080ff', '#ff8000', '#8000ff'],
                    rainbow: ['#ff0000', '#ff8000', '#ffff00', '#00ff00', '#0080ff', '#8000ff'],
                    monochrome: ['#ffffff', '#cccccc', '#999999'],
                    fire: ['#ff0000', '#ff8000', '#ffff00'],
                    ocean: ['#0066ff', '#00ccff', '#00ffff'],
                    pastel: ['#ff9aa2', '#ffb7b2', '#ffdac1', '#e2f0cb', '#b5ead7'],
                    electric: ['#00ffff', '#ff00ff', '#ffff00', '#00ff00', '#ff0000']
                };
                
                const colors = schemes[this.settings.colorScheme] || schemes.neon;
                return colors[Math.floor(Math.random() * colors.length)];
            }

            setupEventListeners() {
                window.addEventListener('resize', () => this.resizeCanvas());
                
                // Enhanced visualizer controls
                document.getElementById('visualizerOpacity').addEventListener('input', (e) => {
                    this.settings.opacity = e.target.value / 100;
                    document.getElementById('opacityValue').textContent = e.target.value + '%';
                    document.documentElement.style.setProperty('--visualizer-opacity', this.settings.opacity);
                });

                document.getElementById('sensitivity').addEventListener('input', (e) => {
                    this.settings.sensitivity = e.target.value / 100;
                    document.getElementById('sensitivityValue').textContent = e.target.value + '%';
                });

                document.getElementById('colorScheme').addEventListener('change', (e) => {
                    this.settings.colorScheme = e.target.value;
                    this.initParticles();
                    this.gradientCache.clear();
                });

                document.getElementById('visualizerType').addEventListener('change', (e) => {
                    this.settings.type = e.target.value;
                });

                // Advanced settings
                document.getElementById('particleCount').addEventListener('input', (e) => {
                    this.settings.particleCount = parseInt(e.target.value);
                    document.getElementById('particleCountValue').textContent = e.target.value;
                    this.initParticles();
                });

                document.getElementById('waveIntensity').addEventListener('input', (e) => {
                    this.settings.waveIntensity = parseInt(e.target.value);
                    document.getElementById('waveIntensityValue').textContent = e.target.value;
                });

                document.getElementById('animationSpeed').addEventListener('input', (e) => {
                    this.settings.animationSpeed = parseInt(e.target.value);
                    document.getElementById('animationSpeedValue').textContent = e.target.value;
                });

                document.getElementById('gradientStyle').addEventListener('change', (e) => {
                    this.settings.gradientStyle = e.target.value;
                    this.gradientCache.clear();
                });

                // Start visualization when audio plays
                const audioPlayer = document.getElementById('audioPlayer');
                if (audioPlayer) {
                    audioPlayer.addEventListener('play', () => {
                        if (this.isVisualizing) {
                            this.animate();
                        }
                    });
                }
            }

            resizeCanvas() {
                this.canvas.width = window.innerWidth;
                this.canvas.height = window.innerHeight;
                this.initParticles();
                this.gradientCache.clear();
            }

            toggle() {
                this.isVisualizing = !this.isVisualizing;
                const status = document.getElementById('visualizerStatus');
                status.textContent = this.isVisualizing ? 'ON' : 'OFF';
                status.style.color = this.isVisualizing ? 'var(--neon-green)' : 'var(--secondary)';
                
                if (this.isVisualizing) {
                    this.animate();
                } else {
                    this.clearCanvas();
                }
            }

            toggleFullscreen() {
                this.isFullscreen = !this.isFullscreen;
                document.body.classList.toggle('fullscreen-visualizer', this.isFullscreen);
                
                const fullscreenText = document.getElementById('fullscreenText');
                const fullscreenBtn = document.querySelector('.fullscreen-toggle i');
                
                if (this.isFullscreen) {
                    fullscreenText.textContent = 'Exit Fullscreen';
                    fullscreenBtn.className = 'fas fa-compress';
                } else {
                    fullscreenText.textContent = 'Fullscreen Visualizer';
                    fullscreenBtn.className = 'fas fa-expand';
                }
            }

            clearCanvas() {
                this.ctx.fillStyle = 'rgba(10, 10, 10, 0.1)';
                this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
            }

            animate() {
                if (!this.isVisualizing) return;

                if (this.analyser) {
                    this.analyser.getByteFrequencyData(this.dataArray);
                }
                
                // Enhanced trail effect with gradient
                this.ctx.fillStyle = 'rgba(10, 10, 10, 0.1)';
                this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);

                if (this.analyser && this.dataArray) {
                    const speedMultiplier = this.settings.animationSpeed / 5;
                    
                    switch (this.settings.type) {
                        case 'frequencyBars':
                            this.drawEnhancedFrequencyBars();
                            break;
                        case 'circular':
                            this.drawEnhancedCircularVisualizer();
                            break;
                        case 'particle':
                            this.drawEnhancedParticleSystem(speedMultiplier);
                            break;
                        case 'waveform':
                            this.drawEnhancedWaveform();
                            break;
                        case 'nebula':
                            this.drawEnhancedNebula();
                            break;
                        case 'energyOrbs':
                            this.drawEnhancedEnergyOrbs();
                            break;
                        case 'gradientBars':
                            this.drawGradientBars();
                            break;
                        case 'liquidWaves':
                            this.drawLiquidWaves();
                            break;
                    }
                }

                this.animationId = requestAnimationFrame(() => this.animate());
            }

            drawEnhancedFrequencyBars() {
                const barWidth = (this.canvas.width / this.bufferLength) * 2.5;
                let x = 0;

                for (let i = 0; i < this.bufferLength; i++) {
                    const barHeight = (this.dataArray[i] * this.settings.sensitivity) * (this.canvas.height / 256);
                    const gradientKey = `bar-${i}-${barHeight}`;
                    
                    let gradient;
                    if (this.gradientCache.has(gradientKey)) {
                        gradient = this.gradientCache.get(gradientKey);
                    } else {
                        gradient = this.createBarGradient(x, this.canvas.height - barHeight, barWidth, barHeight);
                        this.gradientCache.set(gradientKey, gradient);
                    }
                    
                    this.ctx.fillStyle = gradient;
                    this.ctx.fillRect(x, this.canvas.height - barHeight, barWidth, barHeight);
                    
                    // Add glow effect
                    this.ctx.shadowColor = this.getColorForFrequency(i);
                    this.ctx.shadowBlur = 10;
                    this.ctx.fillRect(x, this.canvas.height - barHeight, barWidth, barHeight);
                    this.ctx.shadowBlur = 0;
                    
                    x += barWidth + 1;
                }
            }

            createBarGradient(x, y, width, height) {
                const gradient = this.ctx.createLinearGradient(x, y, x, y + height);
                const color1 = this.getColorForFrequency(0);
                const color2 = this.getColorForFrequency(this.bufferLength - 1);
                
                gradient.addColorStop(0, color1);
                gradient.addColorStop(0.5, this.getColorForFrequency(Math.floor(this.bufferLength / 2)));
                gradient.addColorStop(1, color2);
                
                return gradient;
            }

            drawEnhancedCircularVisualizer() {
                const centerX = this.canvas.width / 2;
                const centerY = this.canvas.height / 2;
                const radius = Math.min(centerX, centerY) * 0.4;

                this.ctx.save();
                this.ctx.translate(centerX, centerY);

                for (let i = 0; i < this.bufferLength; i++) {
                    const angle = (i / this.bufferLength) * Math.PI * 2;
                    const amplitude = (this.dataArray[i] * this.settings.sensitivity) / 256;
                    const barLength = radius + (amplitude * radius * 2 * this.settings.waveIntensity);

                    this.ctx.save();
                    this.ctx.rotate(angle);
                    
                    const gradient = this.ctx.createLinearGradient(0, 0, barLength, 0);
                    gradient.addColorStop(0, this.getColorForFrequency(i));
                    gradient.addColorStop(0.7, this.getColorForFrequency(i, 0.5));
                    gradient.addColorStop(1, 'transparent');
                    
                    this.ctx.strokeStyle = gradient;
                    this.ctx.lineWidth = 3;
                    this.ctx.lineCap = 'round';
                    this.ctx.beginPath();
                    this.ctx.moveTo(radius, 0);
                    this.ctx.lineTo(barLength, 0);
                    this.ctx.stroke();
                    
                    this.ctx.restore();
                }

                this.ctx.restore();
            }

            drawEnhancedParticleSystem(speedMultiplier) {
                const bass = this.getBassLevel();
                const mid = this.getMidLevel();
                const treble = this.getTrebleLevel();

                for (let i = 0; i < this.particles.length; i++) {
                    const particle = this.particles[i];
                    
                    // Enhanced particle behavior
                    particle.rotation += particle.rotationSpeed * speedMultiplier;
                    particle.speedX += (bass - 0.5) * 0.1 * speedMultiplier;
                    particle.speedY += (mid - 0.5) * 0.1 * speedMultiplier;
                    particle.size = Math.max(1, treble * 4 * this.settings.waveIntensity);

                    particle.x += particle.speedX * speedMultiplier;
                    particle.y += particle.speedY * speedMultiplier;
                    particle.life -= 0.001 * speedMultiplier;

                    // Reset particle if it goes off screen or dies
                    if (particle.x < -50 || particle.x > this.canvas.width + 50 || 
                        particle.y < -50 || particle.y > this.canvas.height + 50 || 
                        particle.life <= 0) {
                        this.resetParticle(particle);
                    }

                    // Draw enhanced particle with rotation
                    this.ctx.save();
                    this.ctx.globalAlpha = particle.life * 0.8;
                    this.ctx.translate(particle.x, particle.y);
                    this.ctx.rotate(particle.rotation);
                    
                    // Create particle gradient
                    const gradient = this.ctx.createRadialGradient(0, 0, 0, 0, 0, particle.size);
                    gradient.addColorStop(0, particle.color);
                    gradient.addColorStop(1, 'transparent');
                    
                    this.ctx.fillStyle = gradient;
                    this.ctx.beginPath();
                    this.ctx.arc(0, 0, particle.size, 0, Math.PI * 2);
                    this.ctx.fill();
                    
                    this.ctx.restore();
                }
            }

            drawEnhancedWaveform() {
                if (this.analyser) {
                    this.analyser.getByteTimeDomainData(this.dataArray);
                }

                this.ctx.lineWidth = 3;
                this.ctx.lineCap = 'round';
                this.ctx.beginPath();

                const sliceWidth = this.canvas.width / this.bufferLength;
                let x = 0;

                for (let i = 0; i < this.bufferLength; i++) {
                    const v = this.dataArray[i] / 128.0;
                    const y = v * this.canvas.height / 2;

                    if (i === 0) {
                        this.ctx.moveTo(x, y);
                    } else {
                        // Create gradient for waveform
                        const gradient = this.ctx.createLinearGradient(x, y, x + sliceWidth, this.canvas.height / 2);
                        gradient.addColorStop(0, this.getColorForFrequency(i));
                        gradient.addColorStop(1, this.getColorForFrequency(i + 1));
                        this.ctx.strokeStyle = gradient;
                        
                        this.ctx.lineTo(x, y);
                    }

                    x += sliceWidth;
                }

                this.ctx.stroke();
            }

            drawEnhancedNebula() {
                const centerX = this.canvas.width / 2;
                const centerY = this.canvas.height / 2;

                for (let i = 0; i < this.bufferLength; i += 2) {
                    const angle = (i / this.bufferLength) * Math.PI * 2;
                    const amplitude = (this.dataArray[i] * this.settings.sensitivity) / 256;
                    const distance = Math.min(centerX, centerY) * 0.2 + (amplitude * Math.min(centerX, centerY) * 0.5 * this.settings.waveIntensity);

                    const x = centerX + Math.cos(angle) * distance;
                    const y = centerY + Math.sin(angle) * distance;

                    const size = 20 + amplitude * 40 * this.settings.waveIntensity;
                    const gradient = this.ctx.createRadialGradient(x, y, 0, x, y, size);
                    gradient.addColorStop(0, this.getColorForFrequency(i));
                    gradient.addColorStop(0.3, this.getColorForFrequency(i, 0.7));
                    gradient.addColorStop(1, 'transparent');

                    this.ctx.globalAlpha = 0.3 + amplitude * 0.7;
                    this.ctx.fillStyle = gradient;
                    this.ctx.beginPath();
                    this.ctx.arc(x, y, size, 0, Math.PI * 2);
                    this.ctx.fill();
                }
                this.ctx.globalAlpha = 1;
            }

            drawEnhancedEnergyOrbs() {
                const orbCount = 8;
                const centerX = this.canvas.width / 2;
                const centerY = this.canvas.height / 2;
                const baseRadius = Math.min(centerX, centerY) * 0.15;

                for (let i = 0; i < orbCount; i++) {
                    const angle = (i / orbCount) * Math.PI * 2;
                    const frequencyIndex = Math.floor((i / orbCount) * this.bufferLength);
                    const energy = (this.dataArray[frequencyIndex] * this.settings.sensitivity) / 256;
                    
                    const distance = baseRadius + energy * baseRadius * 1.5 * this.settings.waveIntensity;
                    const x = centerX + Math.cos(angle) * distance;
                    const y = centerY + Math.sin(angle) * distance;
                    const radius = 10 + energy * 30 * this.settings.waveIntensity;

                    // Create enhanced glowing effect
                    const gradient = this.ctx.createRadialGradient(x, y, 0, x, y, radius * 1.5);
                    gradient.addColorStop(0, this.getColorForFrequency(frequencyIndex));
                    gradient.addColorStop(0.4, this.getColorForFrequency(frequencyIndex, 0.8));
                    gradient.addColorStop(0.7, this.getColorForFrequency(frequencyIndex, 0.3));
                    gradient.addColorStop(1, 'transparent');

                    this.ctx.save();
                    this.ctx.globalAlpha = 0.8;
                    this.ctx.fillStyle = gradient;
                    this.ctx.beginPath();
                    this.ctx.arc(x, y, radius * 1.5, 0, Math.PI * 2);
                    this.ctx.fill();
                    
                    // Add pulse effect with multiple rings
                    for (let ring = 1; ring <= 3; ring++) {
                        this.ctx.strokeStyle = this.getColorForFrequency(frequencyIndex);
                        this.ctx.lineWidth = 1;
                        this.ctx.globalAlpha = 0.3 / ring;
                        this.ctx.beginPath();
                        this.ctx.arc(x, y, radius + energy * 20 * ring, 0, Math.PI * 2);
                        this.ctx.stroke();
                    }
                    this.ctx.restore();
                }
            }

            drawGradientBars() {
                const barWidth = (this.canvas.width / this.bufferLength) * 2.5;
                let x = 0;

                for (let i = 0; i < this.bufferLength; i++) {
                    const barHeight = (this.dataArray[i] * this.settings.sensitivity) * (this.canvas.height / 256);
                    
                    // Create multi-color gradient for each bar
                    const gradient = this.ctx.createLinearGradient(x, this.canvas.height - barHeight, x, this.canvas.height);
                    const segments = 5;
                    
                    for (let j = 0; j < segments; j++) {
                        const stop = j / segments;
                        const colorIndex = Math.floor((i + j) * (this.bufferLength / segments)) % this.bufferLength;
                        gradient.addColorStop(stop, this.getColorForFrequency(colorIndex));
                    }
                    
                    this.ctx.fillStyle = gradient;
                    this.ctx.fillRect(x, this.canvas.height - barHeight, barWidth, barHeight);
                    
                    x += barWidth + 1;
                }
            }

            drawLiquidWaves() {
                this.ctx.lineWidth = 4;
                this.ctx.lineCap = 'round';
                
                const centerY = this.canvas.height / 2;
                const amplitude = this.canvas.height / 4;
                
                for (let wave = 0; wave < 3; wave++) {
                    this.ctx.beginPath();
                    const sliceWidth = this.canvas.width / this.bufferLength;
                    let x = 0;
                    
                    for (let i = 0; i < this.bufferLength; i++) {
                        const v = (this.dataArray[i] * this.settings.sensitivity) / 256;
                        const y = centerY + Math.sin((x / this.canvas.width) * Math.PI * 4 + wave) * amplitude * v;
                        
                        if (i === 0) {
                            this.ctx.moveTo(x, y);
                        } else {
                            this.ctx.lineTo(x, y);
                        }
                        
                        x += sliceWidth;
                    }
                    
                    const gradient = this.ctx.createLinearGradient(0, 0, this.canvas.width, 0);
                    gradient.addColorStop(0, this.getColorForFrequency(wave * 50));
                    gradient.addColorStop(1, this.getColorForFrequency((wave + 1) * 50));
                    
                    this.ctx.strokeStyle = gradient;
                    this.ctx.stroke();
                }
            }

            resetParticle(particle) {
                particle.x = Math.random() * this.canvas.width;
                particle.y = Math.random() * this.canvas.height;
                particle.speedX = (Math.random() * 2 - 1) * this.settings.animationSpeed / 5;
                particle.speedY = (Math.random() * 2 - 1) * this.settings.animationSpeed / 5;
                particle.size = Math.random() * 3 + 1;
                particle.color = this.getRandomColor();
                particle.life = 1.0;
                particle.rotation = Math.random() * Math.PI * 2;
                particle.rotationSpeed = (Math.random() - 0.5) * 0.1 * this.settings.animationSpeed / 5;
            }

            getColorForFrequency(frequencyIndex, alpha = 1.0) {
                if (!this.dataArray) return `hsla(200, 100%, 50%, ${alpha})`;
                
                const hue = (frequencyIndex / this.bufferLength) * 360;
                const brightness = 50 + (this.dataArray[frequencyIndex] / 256) * 50;
                const saturation = 80 + (this.dataArray[frequencyIndex] / 256) * 20;
                
                switch (this.settings.colorScheme) {
                    case 'rainbow':
                        return `hsla(${hue}, ${saturation}%, ${brightness}%, ${alpha})`;
                    case 'monochrome':
                        return `rgba(255, 255, 255, ${alpha})`;
                    case 'fire':
                        return `hsla(${20 + hue * 0.1}, ${saturation}%, ${brightness}%, ${alpha})`;
                    case 'ocean':
                        return `hsla(${200 + hue * 0.2}, ${saturation}%, ${brightness}%, ${alpha})`;
                    case 'pastel':
                        return `hsla(${hue}, 70%, 80%, ${alpha})`;
                    case 'electric':
                        return `hsla(${hue}, 100%, 60%, ${alpha})`;
                    case 'neon':
                    default:
                        const colors = ['#ff0080', '#00ff88', '#0080ff', '#ff8000', '#8000ff'];
                        const color = colors[frequencyIndex % colors.length];
                        return color.replace(')', `, ${alpha})`).replace('rgb', 'rgba');
                }
            }

            getBassLevel() {
                if (!this.dataArray) return 0.5;
                let sum = 0;
                const bassCount = Math.floor(this.bufferLength * 0.1);
                for (let i = 0; i < bassCount; i++) {
                    sum += this.dataArray[i];
                }
                return (sum / bassCount) / 256;
            }

            getMidLevel() {
                if (!this.dataArray) return 0.5;
                let sum = 0;
                const start = Math.floor(this.bufferLength * 0.1);
                const end = Math.floor(this.bufferLength * 0.5);
                for (let i = start; i < end; i++) {
                    sum += this.dataArray[i];
                }
                return (sum / (end - start)) / 256;
            }

            getTrebleLevel() {
                if (!this.dataArray) return 0.5;
                let sum = 0;
                const start = Math.floor(this.bufferLength * 0.5);
                for (let i = start; i < this.bufferLength; i++) {
                    sum += this.dataArray[i];
                }
                return (sum / (this.bufferLength - start)) / 256;
            }
        }

        // Global variables
        let currentSongId = null;
        let isPlaying = false;
        let currentSongIndex = 0;
        let songs = <?= json_encode($songs) ?>;
        const audioPlayer = document.getElementById('audioPlayer');
        let currentPlaylistId = null;
        let currentSearchQuery = '';
        let isSearchActive = false;
        let enhancedMusicVisualizer = null;

        // Initialize enhanced music visualizer
        document.addEventListener('DOMContentLoaded', function() {
            enhancedMusicVisualizer = new EnhancedMusicVisualizer();
            enhancedMusicVisualizer.animate(); // Start visualization
            
            // Initialize the rest of the page
            initializePage();
        });

        // Enhanced Visualizer Control Functions
        function toggleVisualizer() {
            if (enhancedMusicVisualizer) {
                enhancedMusicVisualizer.toggle();
            }
        }

        function toggleFullscreenVisualizer() {
            if (enhancedMusicVisualizer) {
                enhancedMusicVisualizer.toggleFullscreen();
            }
        }

        function toggleAdvancedSettings() {
            const advancedToggle = document.querySelector('.advanced-toggle');
            const advancedSettings = document.getElementById('advancedSettings');
            
            advancedToggle.classList.toggle('active');
            advancedSettings.classList.toggle('active');
        }

        // Update range value displays
        document.addEventListener('input', function(e) {
            if (e.target.type === 'range') {
                const valueDisplay = e.target.parentElement.querySelector('.range-value');
                if (valueDisplay) {
                    if (e.target.id === 'visualizerOpacity') {
                        valueDisplay.textContent = e.target.value + '%';
                    } else if (e.target.id === 'sensitivity') {
                        valueDisplay.textContent = e.target.value + '%';
                    } else {
                        valueDisplay.textContent = e.target.value;
                    }
                }
            }
        });

        // Rest of your existing JavaScript functionality remains the same
        // ... (search functionality, player controls, page navigation, etc.)

        // Search functionality - AJAX based
        const searchInput = document.getElementById('searchInput');
        const clearSearchBtn = document.getElementById('clearSearchBtn');
        let searchTimeout;

        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();
            
            if (query === '') {
                clearSearch();
                return;
            }

            clearSearchBtn.style.display = 'inline-block';
            currentSearchQuery = query;
            
            searchTimeout = setTimeout(() => {
                performSearch(query);
            }, 500);
        });

        // Clear search
        clearSearchBtn.addEventListener('click', function() {
            clearSearch();
        });

        function clearSearch() {
            searchInput.value = '';
            clearSearchBtn.style.display = 'none';
            currentSearchQuery = '';
            hideSearchResults();
        }

        function hideSearchResults() {
            document.getElementById('search-results-page').style.display = 'none';
            // Show the current active page
            const activeNav = document.querySelector('.nav-link.active');
            if (activeNav) {
                const page = activeNav.getAttribute('data-page');
                showPage(page);
            }
            isSearchActive = false;
        }

        async function performSearch(query) {
            if (!query) return;

            // Show loading state
            document.getElementById('searchResultsContainer').innerHTML = `
                <div class="search-loading">
                    <i class="fas fa-spinner"></i>
                    <p>Searching for "${query}"...</p>
                </div>
            `;

            // Show search results page
            document.querySelectorAll('.page-content').forEach(content => {
                content.classList.remove('active');
                content.style.display = 'none';
            });
            document.getElementById('search-results-page').style.display = 'block';
            document.getElementById('search-results-page').classList.add('active');
            isSearchActive = true;

            try {
                const response = await fetch(`index.php?ajax_search=1&query=${encodeURIComponent(query)}`);
                const results = await response.json();

                displaySearchResults(results, query);
            } catch (error) {
                console.error('Search error:', error);
                document.getElementById('searchResultsContainer').innerHTML = `
                    <div class="no-results">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3>Search Error</h3>
                        <p>Unable to perform search. Please try again.</p>
                    </div>
                `;
            }
        }

        function displaySearchResults(results, query) {
            const container = document.getElementById('searchResultsContainer');
            const totalResults = results.songs.length + results.playlists.length;
            
            document.getElementById('resultsNumber').textContent = totalResults;

            if (totalResults === 0) {
                container.innerHTML = `
                    <div class="no-results">
                        <i class="fas fa-search"></i>
                        <h3>No results found</h3>
                        <p>No songs or playlists matching "${query}" were found.</p>
                    </div>
                `;
                return;
            }

            let html = '';

            // Display songs
            if (results.songs.length > 0) {
                html += `<h3 class="section-title" style="margin: 20px 0 15px 0;">Songs</h3>`;
                html += `<div class="songs-grid">`;
                results.songs.forEach(song => {
                    html += `
                        <div class="song-card" data-song-id="${song.id}" onclick="playSong(${song.id}, this)">
                            <div class="song-cover">
                                ${song.cover_path ? 
                                    `<img src="${song.cover_path}" alt="${song.title}">` : 
                                    `<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;">
                                        <i class="fas fa-music" style="font-size:1.5rem;color:white;"></i>
                                    </div>`
                                }
                            </div>
                            <div class="song-info">
                                <h4>${song.title}</h4>
                                <p>${song.artist}</p>
                                <p style="font-size:0.7rem;color:var(--neon-purple);">
                                    ${formatTime(song.duration)}
                                </p>
                            </div>
                            <div class="song-actions">
                                <button class="action-btn" onclick="event.stopPropagation();openAddToPlaylistModal(${song.id})">
                                    <i class="fas fa-plus"></i>
                                </button>
                                <button class="action-btn" onclick="event.stopPropagation();toggleFavorite(${song.id})">
                                    <i class="${song.is_favorite ? 'fas' : 'far'} fa-heart" style="color: ${song.is_favorite ? 'var(--neon-pink)' : 'var(--secondary)'}"></i>
                                </button>
                            </div>
                        </div>
                    `;
                });
                html += `</div>`;
            }

            // Display playlists
            if (results.playlists.length > 0) {
                html += `<h3 class="section-title" style="margin: 30px 0 15px 0;">Playlists</h3>`;
                html += `<div class="playlist-grid">`;
                results.playlists.forEach(playlist => {
                    html += `
                        <div class="playlist-card" onclick="loadPlaylist(${playlist.id})">
                            <div class="playlist-cover">
                                <i class="fas fa-music"></i>
                            </div>
                            <div class="playlist-info">
                                <h3>${playlist.name}</h3>
                                <p>${playlist.song_count} songs</p>
                                <p style="font-size: 0.7rem; margin-top: 5px;">${playlist.description || ''}</p>
                            </div>
                            <div class="playlist-actions">
                                <button class="playlist-action-btn" onclick="event.stopPropagation();deletePlaylist(${playlist.id})">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    `;
                });
                html += `</div>`;
            }

            container.innerHTML = html;
        }

        // Mobile menu functionality
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');

        mobileMenuBtn.addEventListener('click', function() {
            sidebar.classList.toggle('active');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !mobileMenuBtn.contains(event.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });

        // Page navigation
        document.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const page = this.getAttribute('data-page');
                showPage(page);
                
                // Close sidebar on mobile after navigation
                if (window.innerWidth <= 768) {
                    sidebar.classList.remove('active');
                }
            });
        });

        function showPage(page) {
            // Hide all pages
            document.querySelectorAll('.page-content').forEach(content => {
                content.classList.remove('active');
                content.style.display = 'none';
            });
            
            // Show selected page
            const pageElement = document.getElementById(page + '-page');
            if (pageElement) {
                pageElement.style.display = 'block';
                pageElement.classList.add('active');
            }
            
            // Update active nav link
            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active');
            });
            document.querySelector(`[data-page="${page}"]`).classList.add('active');
            
            // Clear search when navigating to other pages
            if (!isSearchActive) {
                clearSearch();
            }
        }

        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
            // Close sidebar on mobile when opening modal
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('active');
            }
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        };

        // Format time from seconds to MM:SS
        function formatTime(seconds) {
            const mins = Math.floor(seconds / 60);
            const secs = Math.floor(seconds % 60);
            return `${mins}:${secs < 10 ? '0' : ''}${secs}`;
        }

        // Play song function
        async function playSong(songId, element = null) {
            try {
                // Remove playing class from all songs
                document.querySelectorAll('.song-card, .song-row').forEach(item => {
                    item.classList.remove('playing');
                });

                // Add playing class to current song
                if (element) {
                    element.classList.add('playing');
                }

                // Get song data
                const response = await fetch(`index.php?get_song=1&song_id=${songId}`);
                const songData = await response.json();

                if (songData) {
                    currentSongId = songId;
                    
                    // Update audio source
                    audioPlayer.src = songData.file_path;
                    
                    // Update player UI
                    document.getElementById('nowPlayingTitle').textContent = songData.title;
                    document.getElementById('nowPlayingArtist').textContent = songData.artist;
                    document.getElementById('duration').textContent = formatTime(songData.duration);
                    
                    // Update cover image
                    const coverElement = document.getElementById('nowPlayingCover');
                    if (songData.cover_path) {
                        coverElement.innerHTML = `<img src="${songData.cover_path}" alt="${songData.title}">`;
                    } else {
                        coverElement.innerHTML = '<i class="fas fa-music" style="font-size:1.2rem;color:white;"></i>';
                    }

                    // Play the audio
                    await audioPlayer.play();
                    isPlaying = true;
                    document.getElementById('playIcon').className = 'fas fa-pause';
                    
                    // Update current song index based on current context
                    if (isSearchActive) {
                        // For search results, we need to find the song in the original songs array
                        currentSongIndex = songs.findIndex(song => song.id == songId);
                    } else {
                        currentSongIndex = songs.findIndex(song => song.id == songId);
                    }
                }
            } catch (error) {
                console.error('Error playing song:', error);
                alert('Error playing song. Please make sure the audio file is accessible.');
            }
        }

        // Player controls
        document.getElementById('playBtn').addEventListener('click', function() {
            if (audioPlayer.src) {
                if (isPlaying) {
                    audioPlayer.pause();
                    document.getElementById('playIcon').className = 'fas fa-play';
                } else {
                    audioPlayer.play();
                    document.getElementById('playIcon').className = 'fas fa-pause';
                }
                isPlaying = !isPlaying;
            }
        });

        // Progress bar update
        audioPlayer.addEventListener('timeupdate', function() {
            const progress = (audioPlayer.currentTime / audioPlayer.duration) * 100;
            document.getElementById('progress').style.width = `${progress}%`;
            document.getElementById('currentTime').textContent = formatTime(audioPlayer.currentTime);
        });

        // Click on progress bar to seek
        document.getElementById('progressBar').addEventListener('click', function(e) {
            const rect = this.getBoundingClientRect();
            const percent = (e.clientX - rect.left) / rect.width;
            audioPlayer.currentTime = percent * audioPlayer.duration;
        });

        // Song ended
        audioPlayer.addEventListener('ended', function() {
            document.getElementById('playIcon').className = 'fas fa-play';
            isPlaying = false;
            // Auto-play next song if available
            if (currentSongIndex < songs.length - 1) {
                playSong(songs[currentSongIndex + 1].id);
            }
        });

        // Next/Previous buttons
        document.getElementById('nextBtn').addEventListener('click', function() {
            if (currentSongIndex < songs.length - 1) {
                playSong(songs[currentSongIndex + 1].id);
            }
        });

        document.getElementById('prevBtn').addEventListener('click', function() {
            if (currentSongIndex > 0) {
                playSong(songs[currentSongIndex - 1].id);
            }
        });

        // Enhanced Volume control
const volumeSlider = document.getElementById('volumeSlider');
const volumePercentage = document.getElementById('volumePercentage');

volumeSlider.addEventListener('click', function(e) {
    const rect = this.getBoundingClientRect();
    const volume = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
    audioPlayer.volume = volume;
    document.getElementById('volumeLevel').style.width = `${volume * 100}%`;
    volumePercentage.textContent = `${Math.round(volume * 100)}%`;
    
    // Update volume icon based on level
    updateVolumeIcon(volume);
});

// Volume icon update function
function updateVolumeIcon(volume) {
    const volumeBtn = document.getElementById('volumeBtn');
    const icon = volumeBtn.querySelector('i');
    
    if (volume === 0) {
        icon.className = 'fas fa-volume-mute';
    } else if (volume < 0.3) {
        icon.className = 'fas fa-volume-off';
    } else if (volume < 0.7) {
        icon.className = 'fas fa-volume-down';
    } else {
        icon.className = 'fas fa-volume-up';
    }
}

// Initialize volume with icon
audioPlayer.volume = 0.8;
document.getElementById('volumeLevel').style.width = '80%';
volumePercentage.textContent = '80%';
updateVolumeIcon(0.8);

// Add volume button click to mute/unmute
document.getElementById('volumeBtn').addEventListener('click', function() {
    if (audioPlayer.volume > 0) {
        audioPlayer.volume = 0;
        document.getElementById('volumeLevel').style.width = '0%';
        volumePercentage.textContent = '0%';
        updateVolumeIcon(0);
    } else {
        audioPlayer.volume = 0.8;
        document.getElementById('volumeLevel').style.width = '80%';
        volumePercentage.textContent = '80%';
        updateVolumeIcon(0.8);
    }
});
        // Toggle favorite
        function toggleFavorite(songId) {
            window.location.href = `index.php?toggle_favorite=${songId}`;
        }

        // Load playlist details
        async function loadPlaylist(playlistId) {
            try {
                const response = await fetch(`index.php?get_playlist_songs=1&playlist_id=${playlistId}`);
                const playlistSongs = await response.json();
                
                currentPlaylistId = playlistId;
                
                // Show playlist detail page
                document.querySelectorAll('.page-content').forEach(content => {
                    content.classList.remove('active');
                    content.style.display = 'none';
                });
                document.getElementById('playlist-detail-page').style.display = 'block';
                document.getElementById('playlist-detail-page').classList.add('active');
                
                // Update playlist title
                const playlist = <?= json_encode($playlists) ?>.find(p => p.id == playlistId);
                document.getElementById('playlist-detail-title').textContent = playlist.name;
                
                // Render playlist songs
                const container = document.getElementById('playlist-songs-container');
                if (playlistSongs.length > 0) {
                    let html = '<div class="songs-list">';
                    playlistSongs.forEach((song, index) => {
                        html += `
                            <div class="song-row" data-song-id="${song.id}" onclick="playSong(${song.id}, this)">
                                <div class="song-number">${index + 1}</div>
                                <div class="song-title">
                                    ${song.cover_path ? 
                                        `<img src="${song.cover_path}" alt="${song.title}">` : 
                                        '<i class="fas fa-music"></i>'
                                    }
                                    <span>${song.title}</span>
                                </div>
                                <div class="song-artist">${song.artist}</div>
                                <div class="song-album">${song.album || '-'}</div>
                                <div class="song-duration">${formatTime(song.duration)}</div>
                                <div class="song-actions">
                                    <button class="action-btn" onclick="event.stopPropagation();removeFromPlaylist(${song.id})">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <button class="action-btn" onclick="event.stopPropagation();toggleFavorite(${song.id})">
                                        <i class="far fa-heart"></i>
                                    </button>
                                </div>
                            </div>
                        `;
                    });
                    html += '</div>';
                    container.innerHTML = html;
                } else {
                    container.innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-music"></i>
                            <h3>No songs in this playlist</h3>
                            <p>Add songs to this playlist to get started</p>
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Error loading playlist:', error);
            }
        }

        // Open add to playlist modal
        async function openAddToPlaylistModal(songId) {
            const playlists = <?= json_encode($playlists) ?>;
            const optionsContainer = document.getElementById('playlistOptions');
            
            if (playlists.length > 0) {
                let html = '';
                playlists.forEach(playlist => {
                    html += `
                        <div class="playlist-option" onclick="addSongToPlaylist(${playlist.id}, ${songId})">
                            <strong>${playlist.name}</strong>
                            <small style="color: var(--secondary);">${playlist.song_count} songs</small>
                        </div>
                    `;
                });
                optionsContainer.innerHTML = html;
            } else {
                optionsContainer.innerHTML = '<p style="text-align: center; color: var(--secondary);">No playlists found</p>';
            }
            
            openModal('addToPlaylistModal');
        }

        // Add song to playlist
        function addSongToPlaylist(playlistId, songId) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const playlistInput = document.createElement('input');
            playlistInput.name = 'playlist_id';
            playlistInput.value = playlistId;
            
            const songInput = document.createElement('input');
            songInput.name = 'song_id';
            songInput.value = songId;
            
            const actionInput = document.createElement('input');
            actionInput.name = 'add_to_playlist';
            actionInput.value = '1';
            
            form.appendChild(playlistInput);
            form.appendChild(songInput);
            form.appendChild(actionInput);
            document.body.appendChild(form);
            
            form.submit();
        }

        // Remove from playlist
        function removeFromPlaylist(songId) {
            if (confirm('Remove this song from playlist?')) {
                // This would typically be an AJAX call
                window.location.href = `remove_from_playlist.php?playlist_id=${currentPlaylistId}&song_id=${songId}`;
            }
        }

        // Delete playlist
        function deletePlaylist(playlistId) {
            if (confirm('Are you sure you want to delete this playlist? This action cannot be undone.')) {
                window.location.href = `index.php?delete_playlist=${playlistId}`;
            }
        }

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('active');
            }
        });

        // Initialize page
        function initializePage() {
            // Show the current page based on URL or default    
            const urlParams = new URLSearchParams(window.location.search);
            const page = urlParams.get('page') || 'home';
            showPage(page);
        }
    </script>
</body>
</html>  