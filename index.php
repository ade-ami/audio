<?php
// ==============================================================================
// KONFIGURASI PATH ABSOLUT (UBAH JIKA PERLU)
// ==============================================================================
$yt_dlp_path = '"C:\ffmpeg\yt\yt-dlp_x86.exe"';
$ffmpeg_path = '"C:\ffmpeg\bin\ffmpeg.exe"';

// Set zona waktu
date_default_timezone_set('Asia/Jakarta');

// Pastikan folder temp ada
if (!is_dir('temp')) {
    mkdir('temp', 0777, true);
}

// Pembersihan Otomatis (Hapus file di folder temp yang usianya > 30 menit)
$files = glob("temp/temp_*");
$now = time();
foreach ($files as $file) {
    if (is_file($file) && ($now - filemtime($file) >= 1800)) {
        @unlink($file);
    }
}

// ==============================================================================
// BACKEND LOGIC (API ROUTER)
// ==============================================================================
$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Mencegah error PHP standar (berupa HTML) merusak output JSON
    ini_set('display_errors', 0);
    error_reporting(E_ALL);

    $inputJSON = file_get_contents('php://input');
    $data = json_decode($inputJSON, true);
    
    // --------------------------------------------------------------------------
    // ENDPOINT: /info
    // --------------------------------------------------------------------------
    if ($action === 'info') {
        header('Content-Type: application/json');
        
        try {
            $url = $data['url'] ?? '';
            $platform = strtolower($data['platform'] ?? 'spotify');
            $media_mode = $data['media_mode'] ?? 'single';
            
            // AUTO-DETECT: Mendeteksi platform otomatis berdasarkan isi URL
            if (strpos($url, 'spotify.com') !== false || strpos($url, 'spotify.link') !== false) {
                $platform = 'spotify';
            } elseif (strpos($url, 'youtube.com') !== false || strpos($url, 'youtu.be') !== false) {
                $platform = 'youtube';
            } elseif (strpos($url, 'tiktok.com') !== false) {
                $platform = 'tiktok';
            } elseif (strpos($url, 'instagram.com') !== false) {
                $platform = 'instagram';
            } elseif (strpos($url, 'facebook.com') !== false || strpos($url, 'fb.watch') !== false || strpos($url, 'fb.gg') !== false) {
                $platform = 'facebook';
            }

            if ($platform === 'spotify') {
                // Tangani shortlink Spotify (spotify.link)
                if (strpos($url, 'spotify.link') !== false) {
                    $ch = curl_init($url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    $html = curl_exec($ch);
                    $url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
                    curl_close($ch);
                }

                if (preg_match('/(track|playlist|album)\/([a-zA-Z0-9]+)/', $url, $matches)) {
                    $entity_type = $matches[1];
                    $entity_id = $matches[2];
                    $embed_url = "https://open.spotify.com/embed/{$entity_type}/{$entity_id}";
                    
                    $ch = curl_init($embed_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
                    $html = curl_exec($ch);
                    
                    if (curl_errno($ch)) {
                        throw new Exception('Gagal menghubungi server Spotify.');
                    }
                    curl_close($ch);
                    
                    $entity = null;
                    if (preg_match('/<script id="__NEXT_DATA__" type="application\/json">(.*?)<\/script>/s', $html, $script_matches)) {
                        $json_data = json_decode($script_matches[1], true);
                        $entity = $json_data['props']['pageProps']['state']['data']['entity'] ?? null;
                    }
                    
                    if (!$entity) {
                        throw new Exception('Data lagu/playlist tidak ditemukan atau struktur API Spotify telah berubah.');
                    }
                    
                    $title = $entity['name'] ?? 'Unknown Title';
                    $cover_url = '';
                    if (!empty($entity['coverArt']['sources'][0]['url'])) {
                        $cover_url = $entity['coverArt']['sources'][0]['url'];
                    } elseif (!empty($entity['visuals']['avatarImage']['sources'][0]['url'])) {
                        $cover_url = $entity['visuals']['avatarImage']['sources'][0]['url'];
                    }
                    
                    if ($entity_type === 'track') {
                        echo json_encode([
                            'type' => 'track',
                            'title' => $title,
                            'artist' => $entity['subtitle'] ?? 'Unknown Artist',
                            'cover' => $cover_url
                        ]); exit;
                    } else {
                        $track_list = [];
                        $tracks = $entity['trackList'] ?? [];
                        foreach ($tracks as $t) {
                            $dur_ms = $t['duration'] ?? 0;
                            $mins = floor($dur_ms / 60000);
                            $secs = str_pad(floor(($dur_ms / 1000) % 60), 2, '0', STR_PAD_LEFT);
                            $track_list[] = [
                                'id' => $t['id'] ?? 'unknown',
                                'title' => $t['title'] ?? 'Unknown',
                                'artist' => $t['subtitle'] ?? 'Unknown Artist',
                                'duration' => "{$mins}:{$secs}",
                                'img' => $cover_url,
                                'url' => '' // Spotify url kosongan, akan dicari via ytsearch saat unduh
                            ];
                        }
                        
                        if (empty($track_list)) {
                             throw new Exception('Playlist kosong atau tidak terbaca.');
                        }
                        
                        echo json_encode([
                            'type' => 'playlist',
                            'title' => $title,
                            'cover' => $cover_url,
                            'total' => count($track_list),
                            'tracks' => $track_list
                        ]); exit;
                    }
                } else {
                    throw new Exception('Tautan Spotify tidak valid. Pastikan ini adalah tautan lagu atau playlist.');
                }
            } 
            else {
                // Untuk YouTube, Facebook, TikTok dll menggunakan yt-dlp
                $cmd_opts = "--quiet --no-warnings -J ";
                $cmd_opts .= ($media_mode === 'playlist') ? "--flat-playlist " : "--no-playlist ";
                
                $cmd = $yt_dlp_path . " --ffmpeg-location " . escapeshellarg(trim($ffmpeg_path, '"')) . " " . $cmd_opts . escapeshellarg($url);
                
                $output = [];
                exec($cmd . " 2>&1", $output, $return_var); // 2>&1 agar error log ikut tertangkap
                $result_str = trim(implode("\n", $output));
                $info = json_decode($result_str, true);
                
                if (!$info) {
                    // yt-dlp sering mencetak log/peringatan sebelum JSON, cari awal kurawal JSON
                    $json_start = strpos($result_str, '{');
                    if ($json_start !== false) {
                        $clean_json = substr($result_str, $json_start);
                        $info = json_decode($clean_json, true);
                    }
                }
                
                if (!$info) {
                    // Tampilkan pesan asli yt-dlp agar ketahuan masalahnya (private, blokir wilayah, dll)
                    $error_msg = substr($result_str, 0, 250);
                    throw new Exception('Gagal ekstrak yt-dlp: ' . ($error_msg ?: 'Output kosong.'));
                }
                
                // Menangani mode Playlist
                if ((isset($info['_type']) && $info['_type'] === 'playlist') || (isset($info['entries']) && $media_mode === 'playlist')) {
                    $playlist_title = $info['title'] ?? 'Playlist';
                    $cover_url = isset($info['thumbnails']) ? end($info['thumbnails'])['url'] : 'https://placehold.co/600x600/121212/ffffff?text=Playlist';
                    
                    $track_list = [];
                    foreach ($info['entries'] as $idx => $entry) {
                        if (!$entry) continue;
                        $dur = $entry['duration'] ?? 0;
                        $mins = floor($dur / 60);
                        $secs = str_pad($dur % 60, 2, '0', STR_PAD_LEFT);
                        $dur_str = $dur ? "{$mins}:{$secs}" : "-:-";
                        
                        $t_thumb = isset($entry['thumbnails']) ? end($entry['thumbnails'])['url'] : $cover_url;
                        $vid_id = $entry['id'] ?? "vid_{$idx}";
                        
                        $track_list[] = [
                            'id' => $vid_id,
                            'title' => $entry['title'] ?? 'Unknown Video',
                            'artist' => $entry['uploader'] ?? ($entry['channel'] ?? 'Unknown Channel'),
                            'duration' => $dur_str,
                            'img' => $t_thumb,
                            'url' => $entry['url'] ?? $url 
                        ];
                    }
                    echo json_encode([
                        'type' => 'playlist',
                        'platform' => ucfirst($platform),
                        'title' => $playlist_title,
                        'cover' => $cover_url,
                        'total' => count($track_list),
                        'tracks' => $track_list
                    ]); exit;
                } 
                // Menangani mode Single Video
                else {
                    $title = $info['title'] ?? 'Video Tanpa Judul';
                    $thumbnail = $info['thumbnail'] ?? '';
                    $dur = $info['duration'] ?? 0;
                    $mins = floor($dur / 60);
                    $secs = str_pad($dur % 60, 2, '0', STR_PAD_LEFT);
                    $duration_str = $dur ? "{$mins}:{$secs}" : ($info['duration_string'] ?? '-:-');
                    
                    $platform_display = ucfirst($platform);
                    
                    $available_resolutions = [];
                    if (isset($info['formats'])) {
                        foreach ($info['formats'] as $f) {
                            if (isset($f['height']) && is_numeric($f['height']) && isset($f['vcodec']) && $f['vcodec'] !== 'none') {
                                $available_resolutions[] = (int)$f['height'];
                            }
                        }
                    }
                    $available_resolutions = array_unique($available_resolutions);
                    rsort($available_resolutions);
                    
                    $resolutions_list = [];
                    foreach ($available_resolutions as $res) {
                        $resolutions_list[] = "{$res}p";
                    }
                    if (empty($resolutions_list)) $resolutions_list = ["best"];
                    
                    echo json_encode([
                        'type' => 'video',
                        'platform' => $platform_display,
                        'title' => $title,
                        'thumbnail' => $thumbnail,
                        'duration' => $duration_str,
                        'url' => $url,
                        'resolutions' => $resolutions_list
                    ]); exit;
                }
            }
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    }
    
    // --------------------------------------------------------------------------
    // ENDPOINT: /download
    // --------------------------------------------------------------------------
    if ($action === 'download') {
        set_time_limit(0); 
        ini_set('display_errors', 0);
        
        try {
            $mode = $data['mode'] ?? 'spotify';
            $format_type = strtolower($data['format'] ?? ($data['audio_format'] ?? 'mp4'));
            $temp_id = bin2hex(random_bytes(4));
            $temp_base = "temp/temp_media_{$temp_id}";
            
            $ffmpeg_arg = " --ffmpeg-location " . escapeshellarg(trim($ffmpeg_path, '"')) . " ";
            $cmd = $yt_dlp_path . $ffmpeg_arg . "--quiet --no-playlist ";
            
            if ($mode === 'spotify') {
                $track_name = $data['track_name'] ?? 'Unknown';
                $artist_name = $data['artist_name'] ?? 'Unknown';
                $search_query = "{$track_name} {$artist_name} audio";
                $download_name = "{$track_name} - {$artist_name}.{$format_type}";
                
                $cmd .= "-x --audio-format {$format_type} ";
                if ($format_type === 'mp3') {
                    $cmd .= "--audio-quality 192K ";
                }
                $cmd .= "--parse-metadata \"title:%(title)s\" --parse-metadata \"artist:%(artist)s\" ";
                $cmd .= "-o " . escapeshellarg($temp_base . ".%(ext)s") . " " . escapeshellarg("ytsearch1:{$search_query}");
                
            } else {
                // Direct mode (YouTube, TikTok, dll)
                $url = $data['url'] ?? '';
                $title = $data['title'] ?? 'Media';
                $resolution = $data['resolution'] ?? 'best';
                $audio_quality = $data['audio_quality'] ?? '192';
                
                $clean_title = preg_replace('/[^a-zA-Z0-9 \-_]/', '', $title);
                if (empty(trim($clean_title))) $clean_title = "Download";
                $download_name = "{$clean_title}.{$format_type}";
                
                if ($format_type === 'mp3' || $format_type === 'flac') {
                    $cmd .= "-x --audio-format {$format_type} ";
                    if ($format_type === 'mp3') $cmd .= "--audio-quality {$audio_quality}K ";
                } else {
                    $cmd .= "--merge-output-format mp4 ";
                    // FIX: '/best' di bagian akhir digunakan sebagai fallback untuk Tiktok dll.
                    if ($resolution !== 'best') {
                        $res_int = str_replace('p', '', $resolution);
                        $cmd .= "-f \"bestvideo[height<={$res_int}][vcodec^=avc1][ext=mp4]+bestaudio[ext=m4a]/bestvideo[height<={$res_int}][ext=mp4]+bestaudio[ext=m4a]/best\" ";
                    } else {
                        $cmd .= "-f \"bestvideo[vcodec^=avc1][ext=mp4]+bestaudio[ext=m4a]/bestvideo[ext=mp4]+bestaudio[ext=m4a]/best\" ";
                    }
                }
                $cmd .= "-o " . escapeshellarg($temp_base . ".%(ext)s") . " " . escapeshellarg($url);
            }
            
            // Eksekusi Download
            $output = [];
            exec($cmd . " 2>&1", $output, $return_var);
            
            // Cari file yang dihasilkan
            $downloaded_file = null;
            $files = glob($temp_base . ".*");
            if (count($files) > 0) {
                $downloaded_file = $files[0];
            }
            
            if ($downloaded_file && file_exists($downloaded_file)) {
                if (ob_get_level()) ob_end_clean();
                
                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . basename($download_name) . '"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize($downloaded_file));
                
                readfile($downloaded_file);
                @unlink($downloaded_file);
                exit;
            } else {
                $error_msg = trim(implode("\n", $output));
                throw new Exception('Gagal mendownload. yt-dlp log: ' . substr($error_msg, 0, 200));
            }
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    }
}
// ==============================================================================
// FRONTEND (HTML / JS)
// ==============================================================================
?>
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All-in-One Downloader Ultimate</title>
		<link href="img/logo.png" rel="icon">
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            DEFAULT: '#1DB954', hover: '#1ed760', dark: '#121212', card: '#181818', text: '#b3b3b3'
                        }
                    },
                    fontFamily: { sans: ['Inter', 'sans-serif'] }
                }
            }
        }
    </script>
    <style>
        .bg-gradient-animate {
            background: linear-gradient(-45deg, #121212, #1a1a1a, #0a2e16, #121212);
            background-size: 400% 400%; animation: gradient 15s ease infinite;
        }
        @keyframes gradient { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #121212; }
        ::-webkit-scrollbar-thumb { background: #333; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
    </style>
</head>
<body class="bg-brand-dark text-white font-sans min-h-screen flex flex-col selection:bg-brand selection:text-black">

    <nav class="w-full px-4 py-3 border-b border-white/10 bg-brand-dark/90 backdrop-blur-md sticky top-0 z-50">
        <div class="max-w-6xl mx-auto flex flex-col md:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-2 text-white hover:text-brand transition-colors cursor-pointer w-full md:w-1/3" onclick="resetApp()">
                <div class="bg-brand p-1.5 rounded-full text-black shadow-[0_0_15px_rgba(29,185,84,0.3)]">
                    <i data-lucide="download-cloud" class="w-5 h-5"></i>
                </div>
                <span class="font-bold text-xl tracking-tight hidden sm:block">AIO Downloader <span class="text-xs text-brand font-normal border border-brand px-1 rounded ml-1">VIP</span></span>
            </div>
            
            <div id="mediaModeToggle" class="flex bg-black/50 border border-white/10 p-1 rounded-full w-full sm:w-auto justify-center">
                <button id="btnModeSingle" onclick="switchMediaMode('single')" class="flex-1 sm:flex-none px-6 py-2 rounded-full bg-brand text-black font-bold text-sm transition-all flex items-center justify-center gap-2 shadow-lg">
                    <i data-lucide="music" id="iconModeSingle" class="w-4 h-4"></i> <span id="textModeSingle">Lagu (Track)</span>
                </button>
                <button id="btnModePlaylist" onclick="switchMediaMode('playlist')" class="flex-1 sm:flex-none px-6 py-2 rounded-full text-brand-text hover:text-white font-medium text-sm transition-all flex items-center justify-center gap-2">
                    <i data-lucide="list-music" class="w-4 h-4"></i> Playlist
                </button>
            </div>
            <div class="hidden md:flex justify-end gap-6 text-sm font-medium text-brand-text w-1/3"></div>
        </div>
    </nav>

    <main class="flex-grow">
        <section class="relative pt-10 pb-12 px-6 bg-gradient-animate border-b border-white/5">
            <div class="max-w-3xl mx-auto text-center relative z-10">
                
                <div class="flex flex-wrap justify-center gap-2 mb-8" id="platformSelector">
                    <button onclick="setPlatform('spotify', this, '#1DB954')" class="platform-btn bg-[#1DB954] text-black px-4 py-2 rounded-full font-bold text-sm flex items-center gap-2 transition-transform active:scale-95"><i data-lucide="music"></i> Spotify</button>
                    <!-- Menggunakan ikon dasar Lucide agar console bebas dari warning pink missing icon -->
                    <button onclick="setPlatform('youtube', this, '#FF0000')" class="platform-btn bg-white/10 text-white hover:bg-white/20 px-4 py-2 rounded-full font-bold text-sm flex items-center gap-2 transition-transform active:scale-95"><i data-lucide="play-circle"></i> YouTube</button>
                    <button onclick="setPlatform('facebook', this, '#1877F2')" class="platform-btn bg-white/10 text-white hover:bg-white/20 px-4 py-2 rounded-full font-bold text-sm flex items-center gap-2 transition-transform active:scale-95"><i data-lucide="share-2"></i> Facebook</button>
                    <button onclick="setPlatform('instagram', this, '#E1306C')" class="platform-btn bg-white/10 text-white hover:bg-white/20 px-4 py-2 rounded-full font-bold text-sm flex items-center gap-2 transition-transform active:scale-95"><i data-lucide="camera"></i> Instagram</button>
                    <button onclick="setPlatform('tiktok', this, '#00f2fe')" class="platform-btn bg-white/10 text-white hover:bg-white/20 px-4 py-2 rounded-full font-bold text-sm flex items-center gap-2 transition-transform active:scale-95"><i data-lucide="smartphone"></i> TikTok</button>
                </div>

                <h1 class="text-4xl md:text-5xl font-extrabold mb-4 tracking-tight" id="heroTitle">
                    Unduh <span class="text-brand">Spotify</span> Gratis
                </h1>
                
                <div class="bg-brand-card p-2 md:p-3 rounded-2xl md:rounded-full border border-white/10 shadow-2xl flex flex-col md:flex-row gap-2 mt-8">
                    <div class="flex-grow flex items-center px-4 py-3 md:py-2 relative">
                        <i data-lucide="link" class="w-5 h-5 text-brand-text mr-3 shrink-0"></i>
                        <input type="text" id="urlInput" placeholder="Tempel URL Spotify di sini..." class="w-full bg-transparent border-none outline-none text-white text-sm md:text-base pr-10">
                        <button onclick="pasteFromClipboard()" class="absolute right-4 text-brand-text hover:text-white transition-colors" title="Paste Link">
                            <i data-lucide="clipboard-paste" class="w-5 h-5"></i>
                        </button>
                    </div>
                    <button id="processBtn" onclick="processUrl()" class="bg-brand hover:bg-brand-hover text-black font-bold py-3 md:py-3.5 px-8 rounded-xl md:rounded-full transition-transform active:scale-95 flex items-center justify-center gap-2 shrink-0">
                        <span>Cari Data</span>
                        <i data-lucide="search" class="w-5 h-5"></i>
                    </button>
                </div>
                <p id="errorMessage" class="text-red-400 text-sm mt-3 hidden animate-pulse"></p>
            </div>
        </section>

        <section id="resultSection" class="hidden py-8 px-4 md:px-6">
            <div class="max-w-4xl mx-auto">
                
                <div id="loadingState" class="hidden flex-col items-center justify-center py-16">
                    <div class="relative w-20 h-20 mb-6">
                        <div class="absolute inset-0 border-4 border-brand/20 border-t-brand rounded-full animate-spin"></div>
                        <i id="loadingIcon" data-lucide="loader" class="w-8 h-8 text-brand absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 animate-pulse"></i>
                    </div>
                    <h3 class="text-xl font-bold mb-2">Memproses Tautan...</h3>
                    <p class="text-brand-text text-sm">Sedang mengekstrak data dari server</p>
                </div>

                <div id="videoCard" class="hidden bg-brand-card rounded-2xl border border-white/10 p-6 md:p-8 flex-col md:flex-row gap-6 items-center shadow-xl">
                    <div class="w-full md:w-64 aspect-video rounded-lg overflow-hidden flex-shrink-0 shadow-lg bg-black relative">
                        <img id="videoImage" src="" alt="Thumbnail" class="w-full h-full object-cover">
                        <div class="absolute bottom-2 right-2 bg-black/70 text-white text-xs px-2 py-1 rounded font-mono" id="videoDuration">0:00</div>
                    </div>
                    <div class="flex-grow w-full text-center md:text-left flex flex-col justify-center">
                        <span id="videoPlatformBadge" class="text-xs font-bold uppercase tracking-wider text-white mb-2 px-2 py-1 bg-red-600 inline-block w-max mx-auto md:mx-0 rounded">Video Ditemukan</span>
                        <h3 id="videoTitle" class="text-xl md:text-2xl font-bold mb-6 line-clamp-2">Judul Video</h3>
                        
                        <div class="flex flex-col gap-3 justify-center md:justify-start mt-2">
                            <div class="flex flex-col sm:flex-row gap-2">
                                <select id="videoResolutionSelect" class="bg-black/50 border border-white/10 rounded-lg px-3 py-3 text-sm text-white focus:outline-none focus:border-brand cursor-pointer w-full sm:w-1/3">
                                </select>
                                <button id="btnDownloadMp4" onclick="downloadVideo('mp4', 'btnDownloadMp4')" class="bg-blue-600 hover:bg-blue-500 text-white font-bold py-3 px-6 rounded-lg transition-colors flex items-center justify-center gap-2 group relative overflow-hidden flex-1">
                                    <span class="relative z-10 flex items-center gap-2"><i data-lucide="video" class="w-5 h-5"></i> Unduh MP4</span>
                                    <div class="absolute left-0 top-0 h-full bg-white/30 w-0 transition-all duration-300 download-progress"></div>
                                </button>
                            </div>
                            <div class="flex flex-col sm:flex-row gap-2">
                                <select id="audioQualitySelect" class="bg-black/50 border border-white/10 rounded-lg px-3 py-3 text-sm text-white focus:outline-none focus:border-brand cursor-pointer w-full sm:w-1/3">
                                    <option value="320">320 kbps (Terbaik)</option>
                                    <option value="192" selected>192 kbps (Standar)</option>
                                    <option value="128">128 kbps (Rendah)</option>
                                </select>
                                <button id="btnDownloadVideoMp3" onclick="downloadVideo('mp3', 'btnDownloadVideoMp3')" class="bg-brand hover:bg-brand-hover text-black font-bold py-3 px-6 rounded-lg transition-colors flex items-center justify-center gap-2 group relative overflow-hidden flex-1">
                                    <span class="relative z-10 flex items-center gap-2"><i data-lucide="music" class="w-5 h-5"></i> Unduh MP3</span>
                                    <div class="absolute left-0 top-0 h-full bg-white/30 w-0 transition-all duration-300 download-progress"></div>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="singleTrackCard" class="hidden bg-brand-card rounded-2xl border border-white/10 p-6 md:p-8 flex-col md:flex-row gap-6 items-center shadow-xl">
                    <div class="w-40 h-40 md:w-48 md:h-48 rounded-lg overflow-hidden flex-shrink-0 shadow-lg bg-black">
                        <img id="singleTrackImage" src="" alt="Cover" class="w-full h-full object-cover">
                    </div>
                    <div class="flex-grow w-full text-center md:text-left flex flex-col justify-center">
                        <span class="text-xs font-bold uppercase tracking-wider text-brand mb-2 px-2 py-1 bg-brand/10 inline-block w-max mx-auto md:mx-0 rounded">Track Nyata Ditemukan</span>
                        <h3 id="singleTrackTitle" class="text-2xl md:text-3xl font-bold mb-1 line-clamp-2">Judul Lagu</h3>
                        <p id="singleTrackArtist" class="text-brand-text font-medium mb-6">Nama Artis</p>
                        
                        <div class="flex flex-col sm:flex-row gap-3 w-full md:w-auto">
                            <button id="btnDownloadSingleMp3" onclick="triggerSingleDownload('mp3')" class="w-full sm:flex-1 bg-brand hover:bg-brand-hover text-black font-bold py-4 px-6 rounded-lg transition-colors flex items-center justify-center gap-2 group relative overflow-hidden">
                                <span class="relative z-10 flex items-center gap-2">
                                    <i data-lucide="music" class="w-5 h-5"></i> Unduh MP3
                                </span>
                                <div class="absolute left-0 top-0 h-full bg-white/30 w-0 transition-all duration-300 download-progress"></div>
                            </button>
                            <button id="btnDownloadSingleFlac" onclick="triggerSingleDownload('flac')" class="w-full sm:flex-1 bg-purple-600 hover:bg-purple-500 text-white font-bold py-4 px-6 rounded-lg transition-colors flex items-center justify-center gap-2 group relative overflow-hidden">
                                <span class="relative z-10 flex items-center gap-2">
                                    <i data-lucide="disc" class="w-5 h-5"></i> Unduh FLAC
                                </span>
                                <div class="absolute left-0 top-0 h-full bg-white/30 w-0 transition-all duration-300 download-progress"></div>
                            </button>
                        </div>
                    </div>
                </div>

                <div id="playlistCard" class="hidden flex flex-col gap-6">
                    <div class="bg-brand-card rounded-2xl border border-white/10 p-6 flex flex-col sm:flex-row items-center sm:items-end gap-6 relative overflow-hidden">
                        <div class="w-32 h-32 md:w-40 md:h-40 rounded shadow-2xl z-10 flex-shrink-0 bg-black">
                            <img id="playlistImage" src="" class="w-full h-full object-cover rounded">
                        </div>
                        <div class="z-10 text-center sm:text-left flex-grow">
                            <span id="playlistTypeBadge" class="text-xs font-bold uppercase tracking-wider text-brand mb-2">Playlist Resmi Ditemukan</span>
                            <h2 id="playlistTitle" class="text-3xl md:text-4xl font-extrabold tracking-tight mb-2 line-clamp-2">Nama Playlist</h2>
                            <p class="text-brand-text text-sm mb-4"><span id="playlistTrackCount">0</span> media berhasil dimuat ke dalam antrean</p>
                            
                            <div class="flex flex-col sm:flex-row gap-2 items-center mx-auto sm:mx-0 w-full sm:w-auto">
                                <select id="playlistVideoQuality" class="hidden bg-black/50 border border-white/10 rounded-full px-4 py-2.5 text-sm font-bold text-white focus:outline-none focus:border-brand cursor-pointer shadow-lg w-full sm:w-auto">
                                    <option value="best">Kualitas Terbaik</option>
                                    <option value="1080">1080p</option>
                                    <option value="720">720p</option>
                                    <option value="480">480p</option>
                                    <option value="360">360p</option>
                                </select>
                                
                                <button id="btnDownloadSelectedMp3" onclick="downloadSelectedTracks('mp3')" disabled class="opacity-50 cursor-not-allowed bg-brand text-black font-bold py-2.5 px-6 rounded-full transition-all flex items-center justify-center gap-2 w-full sm:w-auto">
                                    <i data-lucide="check-square" class="w-5 h-5 batch-icon"></i> <span class="batch-text">Pilih Lagu</span>
                                </button>
                                <button id="btnDownloadSelectedFlac" onclick="downloadSelectedTracks('flac')" disabled class="opacity-50 cursor-not-allowed bg-purple-600 text-white font-bold py-2.5 px-6 rounded-full transition-all flex items-center justify-center gap-2 w-full sm:w-auto">
                                    <i data-lucide="check-square" class="w-5 h-5 batch-icon"></i> <span class="batch-text">Pilih Lagu</span>
                                </button>
                                <button id="btnDownloadSelectedMp4" onclick="downloadSelectedTracks('mp4')" disabled class="hidden opacity-50 cursor-not-allowed bg-blue-600 text-white font-bold py-2.5 px-6 rounded-full transition-all flex items-center justify-center gap-2 w-full sm:w-auto shadow-lg">
                                    <i data-lucide="check-square" class="w-5 h-5 batch-icon"></i> <span class="batch-text">Pilih Video</span>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-brand-dark/50 rounded-2xl border border-white/5 overflow-hidden">
                        <div class="flex flex-col md:flex-row justify-between items-center gap-4 px-4 py-4 border-b border-white/5">
                            <div class="relative w-full md:w-1/2">
                                <i data-lucide="search" class="absolute left-3 top-1/2 transform -translate-y-1/2 w-4 h-4 text-brand-text"></i>
                                <input type="text" id="playlistSearch" oninput="handlePlaylistSearch(this.value)" placeholder="Cari media atau artis dalam antrean..." class="w-full bg-black/50 border border-white/10 rounded-lg py-2 pl-10 pr-4 text-sm text-white focus:outline-none focus:border-brand transition-colors">
                            </div>
                            <div class="flex items-center gap-2 w-full md:w-auto justify-end">
                                <span class="text-xs text-brand-text">Tampilkan:</span>
                                <select id="itemsPerPage" onchange="handleItemsPerPage(this.value)" class="bg-black/50 border border-white/10 rounded-lg py-1.5 px-3 text-sm text-white focus:outline-none focus:border-brand cursor-pointer">
                                    <option value="10">10</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                </select>
                            </div>
                        </div>

                        <div id="playlistGridHeader" class="grid grid-cols-[30px_30px_minmax(120px,1fr)_90px] md:grid-cols-[40px_40px_minmax(150px,1fr)_minmax(100px,150px)_120px] gap-2 md:gap-4 px-4 py-3 border-b border-white/5 text-brand-text text-xs font-semibold uppercase tracking-wider items-center">
                            <div class="flex justify-center">
                                <input type="checkbox" id="selectAll" onclick="toggleSelectAll()" class="w-4 h-4 accent-brand cursor-pointer">
                            </div>
                            <div class="text-center">#</div> 
                            <div>Judul & Artis</div> 
                            <div class="hidden md:block">Durasi</div> 
                            <div class="text-center">Unduh</div>
                        </div>
                        <div id="playlistTracksContainer" class="flex flex-col max-h-[600px] overflow-y-auto">
                        </div>
                        
                        <div id="paginationControls" class="flex justify-center items-center gap-2 py-4 border-t border-white/5 bg-brand-dark/30">
                        </div>
                    </div>
                </div>

            </div>
        </section>
    </main>

    <script>
        lucide.createIcons();
        let currentPlatform = 'spotify';
        let currentMediaMode = 'single'; 
        let isDownloading = false;
        let activeVideoUrl = '';

        let currentPlaylistTracks = [];
        let filteredPlaylistTracks = [];
        let currentPage = 1;
        let itemsPerPage = 10;

        const API_BASE_URL = '?action=';

        const platformThemes = {
            'spotify': { color: '#1DB954', text: 'black', singleLabel: 'Lagu (Track)', singleIcon: 'music' },
            'youtube': { color: '#FF0000', text: 'white', singleLabel: 'Single Video', singleIcon: 'video' },
            'facebook': { color: '#1877F2', text: 'white' },
            'instagram': { color: '#E1306C', text: 'white' },
            'tiktok': { color: '#00f2fe', text: 'black' }
        };

        function setPlatform(platform, btnElement, colorCode) {
            currentPlatform = platform;
            
            document.querySelectorAll('.platform-btn').forEach(btn => {
                btn.className = 'platform-btn bg-white/10 text-white hover:bg-white/20 px-4 py-2 rounded-full font-bold text-sm flex items-center gap-2 transition-transform active:scale-95';
            });
            
            btnElement.className = `platform-btn bg-[${colorCode}] text-${platformThemes[platform].text} px-4 py-2 rounded-full font-bold text-sm flex items-center gap-2 transition-transform active:scale-95 shadow-lg`;

            document.getElementById('urlInput').placeholder = `Tempel URL ${platform.charAt(0).toUpperCase() + platform.slice(1)} di sini...`;
            document.getElementById('heroTitle').innerHTML = `Unduh <span class="text-[${colorCode}]">${platform.charAt(0).toUpperCase() + platform.slice(1)}</span> Gratis`;
            
            const mediaToggle = document.getElementById('mediaModeToggle');
            if(platform === 'spotify' || platform === 'youtube') {
                mediaToggle.classList.remove('hidden');
                mediaToggle.classList.add('flex');
                document.getElementById('textModeSingle').innerText = platformThemes[platform].singleLabel;
                document.getElementById('iconModeSingle').setAttribute('data-lucide', platformThemes[platform].singleIcon);
                lucide.createIcons();
            } else {
                mediaToggle.classList.add('hidden');
                mediaToggle.classList.remove('flex');
            }
            resetApp();
        }

        function switchMediaMode(mode) {
            currentMediaMode = mode;
            const btnSingle = document.getElementById('btnModeSingle');
            const btnPlaylist = document.getElementById('btnModePlaylist');
            
            if (mode === 'single') {
                btnSingle.className = "flex-1 sm:flex-none px-6 py-2 rounded-full bg-brand text-black font-bold text-sm transition-all flex items-center justify-center gap-2 shadow-lg";
                btnPlaylist.className = "flex-1 sm:flex-none px-6 py-2 rounded-full text-brand-text hover:text-white font-medium text-sm transition-all flex items-center justify-center gap-2";
            } else {
                btnPlaylist.className = "flex-1 sm:flex-none px-6 py-2 rounded-full bg-brand text-black font-bold text-sm transition-all flex items-center justify-center gap-2 shadow-lg";
                btnSingle.className = "flex-1 sm:flex-none px-6 py-2 rounded-full text-brand-text hover:text-white font-medium text-sm transition-all flex items-center justify-center gap-2";
            }
            resetApp();
        }

        async function pasteFromClipboard() {
            try {
                const text = await navigator.clipboard.readText();
                document.getElementById('urlInput').value = text;
            } catch (err) {
                alert('Gagal mengakses clipboard. Silakan paste manual (Ctrl+V).');
            }
        }

        function resetApp() {
            document.getElementById('urlInput').value = '';
            document.getElementById('resultSection').classList.add('hidden');
            document.getElementById('errorMessage').classList.add('hidden');
        }

        async function processUrl() {
            const input = document.getElementById('urlInput').value.trim();
            const errorMsg = document.getElementById('errorMessage');
            const resultSec = document.getElementById('resultSection');
            const loader = document.getElementById('loadingState');
            
            const singleCard = document.getElementById('singleTrackCard');
            const plCard = document.getElementById('playlistCard');
            const vidCard = document.getElementById('videoCard');

            if (input === "") {
                errorMsg.innerText = "Tautan tidak boleh kosong!";
                errorMsg.classList.remove('hidden'); return;
            }

            errorMsg.classList.add('hidden');
            resultSec.classList.remove('hidden');
            loader.classList.remove('hidden'); loader.classList.add('flex');
            singleCard.classList.add('hidden'); singleCard.classList.remove('flex');
            plCard.classList.add('hidden');
            vidCard.classList.add('hidden'); vidCard.classList.remove('flex');

            resultSec.scrollIntoView({ behavior: 'smooth', block: 'start' });

            try {
                const response = await fetch(`${API_BASE_URL}info`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        url: input, 
                        platform: currentPlatform, 
                        media_mode: currentMediaMode 
                    })
                });

                const data = await response.json();
                // Check if server returned an explicit error JSON via our try..catch block
                if (!response.ok || data.error) {
                    throw new Error(data.error || "Gagal menghubungi server backend.");
                }

                loader.classList.add('hidden'); loader.classList.remove('flex');

                if (data.type === 'track') {
                    document.getElementById('singleTrackTitle').innerText = data.title;
                    document.getElementById('singleTrackArtist').innerText = data.artist;
                    document.getElementById('singleTrackImage').src = data.cover;
                    singleCard.classList.remove('hidden'); singleCard.classList.add('flex');
                } 
                else if (data.type === 'playlist') {
                    document.getElementById('playlistTitle').innerText = data.title;
                    document.getElementById('playlistImage').src = data.cover;
                    document.getElementById('playlistTrackCount').innerText = data.total;
                    
                    const badge = document.getElementById('playlistTypeBadge');
                    if(data.platform === 'YouTube') {
                        badge.innerText = "YouTube Playlist";
                        badge.style.color = "#FF0000";
                    } else if(data.platform === 'Tiktok') {
                        badge.innerText = "TikTok Account / Sounds";
                        badge.style.color = "#00f2fe";
                    } else {
                        badge.innerText = "Spotify Playlist";
                        badge.style.color = "#1DB954";
                    }
                    
                    document.getElementById('selectAll').checked = false;
                    updateDownloadButtonText();

                    currentPlaylistTracks = data.tracks;
                    filteredPlaylistTracks = [...currentPlaylistTracks];
                    currentPage = 1;
                    document.getElementById('playlistSearch').value = '';
                    document.getElementById('itemsPerPage').value = '10';
                    itemsPerPage = 10;
                    
                    // Show MP4 option for platforms other than Spotify
                    if(data.platform !== 'Spotify') {
                        document.getElementById('playlistVideoQuality').classList.remove('hidden');
                        document.getElementById('btnDownloadSelectedMp4').classList.remove('hidden');
                        document.getElementById('playlistGridHeader').className = "grid grid-cols-[30px_30px_minmax(100px,1fr)_auto] md:grid-cols-[40px_40px_minmax(150px,1fr)_80px_auto] gap-2 md:gap-4 px-4 py-3 border-b border-white/5 text-brand-text text-xs font-semibold uppercase tracking-wider items-center";
                    } else {
                        document.getElementById('playlistVideoQuality').classList.add('hidden');
                        document.getElementById('btnDownloadSelectedMp4').classList.add('hidden');
                        document.getElementById('playlistGridHeader').className = "grid grid-cols-[30px_30px_minmax(120px,1fr)_90px] md:grid-cols-[40px_40px_minmax(150px,1fr)_minmax(100px,150px)_120px] gap-2 md:gap-4 px-4 py-3 border-b border-white/5 text-brand-text text-xs font-semibold uppercase tracking-wider items-center";
                    }
                    
                    renderPlaylistTracks();
                    plCard.classList.remove('hidden'); plCard.classList.add('flex');
                }
                else if (data.type === 'video') {
                    activeVideoUrl = data.url;
                    document.getElementById('videoTitle').innerText = data.title;
                    document.getElementById('videoImage').src = data.thumbnail || 'https://placehold.co/600x400/121212/ffffff?text=No+Thumbnail';
                    document.getElementById('videoDuration').innerText = data.duration || '-:-';
                    
                    const badge = document.getElementById('videoPlatformBadge');
                    badge.innerText = `${data.platform} Media`;
                    badge.style.backgroundColor = platformThemes[data.platform.toLowerCase()]?.color || '#333';
                    badge.style.color = platformThemes[data.platform.toLowerCase()]?.text || '#fff';

                    const resSelect = document.getElementById('videoResolutionSelect');
                    resSelect.innerHTML = '';
                    if (data.resolutions && data.resolutions.length > 0) {
                        data.resolutions.forEach(res => {
                            const option = document.createElement('option');
                            const resValue = res === 'best' ? 'best' : res.replace('p', '');
                            option.value = resValue;
                            option.text = res === 'best' ? 'Kualitas Terbaik' : `${res} (Video)`;
                            resSelect.appendChild(option);
                        });
                    } else {
                        resSelect.innerHTML = '<option value="best">Kualitas Terbaik</option>';
                    }

                    vidCard.classList.remove('hidden'); vidCard.classList.add('flex');
                }

            } catch (error) {
                loader.classList.add('hidden'); loader.classList.remove('flex');
                errorMsg.innerText = error.message;
                errorMsg.classList.remove('hidden');
            }
        }

        function renderPlaylistTracks() {
            const container = document.getElementById('playlistTracksContainer');
            container.innerHTML = '';
            
            const totalItems = filteredPlaylistTracks.length;
            const limit = parseInt(itemsPerPage);
            const totalPages = Math.max(1, Math.ceil(totalItems / limit));
            
            if (currentPage > totalPages) currentPage = totalPages;
            
            const startIndex = (currentPage - 1) * limit;
            const endIndex = Math.min(startIndex + limit, totalItems);
            const tracksToShow = filteredPlaylistTracks.slice(startIndex, endIndex);

            if (tracksToShow.length === 0) {
                container.innerHTML = `<div class="py-8 px-4 text-center text-brand-text">Tidak ada lagu yang cocok dengan pencarian.</div>`;
            } else {
                tracksToShow.forEach((track, index) => {
                    const actualIndex = startIndex + index;
                    const safeId = (track.id && track.id !== 'unknown') ? track.id : 'track_' + actualIndex;
                    
                    const isYT = document.getElementById('playlistVideoQuality').classList.contains('hidden') === false;
                    const gridClass = isYT 
                        ? "grid grid-cols-[30px_30px_minmax(100px,1fr)_auto] md:grid-cols-[40px_40px_minmax(150px,1fr)_80px_auto] gap-2 md:gap-4 px-4 py-3 hover:bg-white/5 border-b border-white/5 items-center transition-colors"
                        : "grid grid-cols-[30px_30px_minmax(120px,1fr)_90px] md:grid-cols-[40px_40px_minmax(150px,1fr)_minmax(100px,150px)_120px] gap-2 md:gap-4 px-4 py-3 hover:bg-white/5 border-b border-white/5 items-center transition-colors";
                    
                    const mp4Btn = isYT ? `
                        <button id="btn_${safeId}_mp4" onclick="downloadAudioFromPlaylist('${safeId}', 'mp4')" class="bg-blue-600 hover:bg-blue-500 text-white font-bold py-1.5 px-2 md:px-3 rounded text-xs transition-colors flex items-center justify-center relative overflow-hidden group w-full" title="Unduh MP4">
                            <span class="relative z-10 flex items-center justify-center">MP4</span>
                            <div class="absolute left-0 top-0 h-full bg-white/30 w-0 transition-all duration-300 download-progress"></div>
                        </button>
                    ` : '';
                    
                    const row = document.createElement('div');
                    row.id = `row_${safeId}`;
                    row.className = gridClass;
                    
                    row.innerHTML = `
                        <div class="flex justify-center">
                            <input type="checkbox" class="track-checkbox w-4 h-4 accent-brand cursor-pointer" value="${safeId}" onchange="updateDownloadButtonText()">
                        </div>
                        <div class="text-center text-brand-text font-medium text-sm md:text-base">${actualIndex + 1}</div>
                        <div class="flex items-center gap-3 overflow-hidden">
                            <img src="${track.img}" class="w-10 h-10 rounded object-cover shadow bg-black shrink-0 hidden sm:block">
                            <div class="flex flex-col truncate">
                                <span class="font-bold text-white truncate text-sm md:text-base">${track.title}</span>
                                <span class="text-xs md:text-sm text-brand-text truncate">${track.artist}</span>
                            </div>
                        </div>
                        <div class="text-sm text-brand-text hidden md:block">${track.duration}</div>
                        <div class="flex justify-center gap-1 md:gap-2 flex-wrap md:flex-nowrap min-w-max">
                            <button id="btn_${safeId}_mp3" onclick="downloadAudioFromPlaylist('${safeId}', 'mp3')" class="bg-brand hover:bg-brand-hover text-black font-bold py-1.5 px-2 md:px-3 rounded text-xs transition-colors flex items-center justify-center relative overflow-hidden group w-full" title="Unduh MP3">
                                <span class="relative z-10 flex items-center justify-center">MP3</span>
                                <div class="absolute left-0 top-0 h-full bg-white/30 w-0 transition-all duration-300 download-progress"></div>
                            </button>
                            <button id="btn_${safeId}_flac" onclick="downloadAudioFromPlaylist('${safeId}', 'flac')" class="bg-purple-600 hover:bg-purple-500 text-white font-bold py-1.5 px-2 md:px-3 rounded text-xs transition-colors flex items-center justify-center relative overflow-hidden group w-full" title="Unduh FLAC">
                                <span class="relative z-10 flex items-center justify-center">FLAC</span>
                                <div class="absolute left-0 top-0 h-full bg-white/30 w-0 transition-all duration-300 download-progress"></div>
                            </button>
                            ${mp4Btn}
                        </div>
                    `;
                    container.appendChild(row);
                });
            }
            
            document.getElementById('selectAll').checked = false;
            updateDownloadButtonText();
            renderPaginationControls(totalPages);
            lucide.createIcons();
        }

        function handlePlaylistSearch(query) {
            const lowerQuery = query.toLowerCase();
            filteredPlaylistTracks = currentPlaylistTracks.filter(track => 
                track.title.toLowerCase().includes(lowerQuery) || 
                track.artist.toLowerCase().includes(lowerQuery)
            );
            currentPage = 1;
            renderPlaylistTracks();
        }

        function handleItemsPerPage(value) {
            itemsPerPage = value;
            currentPage = 1;
            renderPlaylistTracks();
        }

        function changePage(page) {
            currentPage = page;
            renderPlaylistTracks();
        }

        function renderPaginationControls(totalPages) {
            const paginationDiv = document.getElementById('paginationControls');
            if (totalPages <= 1) { paginationDiv.innerHTML = ''; return; }

            let html = '';
            const prevDisabled = currentPage === 1 ? 'disabled class="px-2 py-1 text-gray-600 cursor-not-allowed"' : `onclick="changePage(${currentPage - 1})" class="px-2 py-1 text-brand hover:bg-white/10 rounded transition-colors"`;
            html += `<button ${prevDisabled}><i data-lucide="chevron-left" class="w-5 h-5"></i></button>`;
            
            for (let i = 1; i <= totalPages; i++) {
                if (i === 1 || i === totalPages || (i >= currentPage - 1 && i <= currentPage + 1)) {
                    const activeClass = currentPage === i ? 'bg-brand text-black font-bold' : 'text-brand-text hover:bg-white/10';
                    html += `<button onclick="changePage(${i})" class="w-8 h-8 flex items-center justify-center rounded text-sm transition-colors ${activeClass}">${i}</button>`;
                } else if (i === currentPage - 2 || i === currentPage + 2) {
                    html += `<span class="px-1 text-brand-text">...</span>`;
                }
            }

            const nextDisabled = currentPage === totalPages ? 'disabled class="px-2 py-1 text-gray-600 cursor-not-allowed"' : `onclick="changePage(${currentPage + 1})" class="px-2 py-1 text-brand hover:bg-white/10 rounded transition-colors"`;
            html += `<button ${nextDisabled}><i data-lucide="chevron-right" class="w-5 h-5"></i></button>`;
            paginationDiv.innerHTML = html;
        }

        function toggleSelectAll() {
            const isChecked = document.getElementById('selectAll').checked;
            const checkboxes = document.querySelectorAll('.track-checkbox');
            checkboxes.forEach(cb => cb.checked = isChecked);
            updateDownloadButtonText();
        }

        function updateDownloadButtonText() {
            const checkedCount = document.querySelectorAll('.track-checkbox:checked').length;
            const btns = [
                document.getElementById('btnDownloadSelectedMp3'), 
                document.getElementById('btnDownloadSelectedFlac'),
                document.getElementById('btnDownloadSelectedMp4')
            ];
            
            if (checkedCount > 0) {
                btns.forEach(btn => {
                    if(!btn) return;
                    let formatText = 'MP3';
                    if(btn.id.includes('Flac')) formatText = 'FLAC';
                    if(btn.id.includes('Mp4')) formatText = 'MP4';
                    
                    btn.querySelector('.batch-text').innerText = `Unduh ${checkedCount} ${formatText}`;
                    btn.querySelector('.batch-icon').setAttribute('data-lucide', 'download-cloud');
                    btn.disabled = false;
                    btn.classList.remove('opacity-50', 'cursor-not-allowed');
                    btn.classList.add('hover:scale-105');
                });
            } else {
                btns.forEach(btn => {
                    if(!btn) return;
                    let typeText = btn.id.includes('Mp4') ? 'Video' : 'Lagu';
                    btn.querySelector('.batch-text').innerText = `Pilih ${typeText}`;
                    btn.querySelector('.batch-icon').setAttribute('data-lucide', 'check-square');
                    btn.disabled = true;
                    btn.classList.add('opacity-50', 'cursor-not-allowed');
                    btn.classList.remove('hover:scale-105');
                });
            }
            lucide.createIcons();
            
            const allCheckboxes = document.querySelectorAll('.track-checkbox').length;
            const selectAllBtn = document.getElementById('selectAll');
            if(allCheckboxes > 0) selectAllBtn.checked = (checkedCount === allCheckboxes);
        }

        async function downloadSelectedTracks(format = 'mp3') {
            if (isDownloading) {
                alert("Proses unduhan lain sedang berjalan. Harap tunggu."); return;
            }

            const checkboxes = document.querySelectorAll('.track-checkbox:checked');
            if (checkboxes.length === 0) return;

            const btns = [
                document.getElementById('btnDownloadSelectedMp3'), 
                document.getElementById('btnDownloadSelectedFlac'),
                document.getElementById('btnDownloadSelectedMp4')
            ];
            btns.forEach(btn => { if(btn) btn.disabled = true; });
            
            let mainBtn = btns[0];
            if(format === 'flac') mainBtn = btns[1];
            if(format === 'mp4') mainBtn = btns[2];
            
            for (let i = 0; i < checkboxes.length; i++) {
                const trackId = checkboxes[i].value;
                mainBtn.innerHTML = `<i data-lucide="loader-2" class="w-5 h-5 animate-spin"></i> (${i+1}/${checkboxes.length}) Mengunduh...`;
                lucide.createIcons();
                
                const row = document.getElementById(`row_${trackId}`);
                row.classList.add('bg-white/10'); 

                await downloadAudioFromPlaylist(trackId, format, true);
                
                row.classList.remove('bg-white/10');
                await new Promise(r => setTimeout(r, 1000)); 
            }

            mainBtn.innerHTML = `<i data-lucide="check-circle" class="w-5 h-5"></i> Selesai!`;
            lucide.createIcons();
            
            document.getElementById('selectAll').checked = false;
            toggleSelectAll();
            setTimeout(() => { updateDownloadButtonText(); }, 3000);
        }

        function simulateProgress(progressBar) {
            let width = 0;
            return setInterval(() => {
                if(width < 90) { width += Math.random() * 5; progressBar.style.width = width + '%'; }
            }, 500);
        }

        function triggerSingleDownload(format) {
            const title = document.getElementById('singleTrackTitle').innerText;
            const artist = document.getElementById('singleTrackArtist').innerText;
            const btnId = format === 'mp3' ? 'btnDownloadSingleMp3' : 'btnDownloadSingleFlac';
            const dummyTrack = { id: 'single', title: title, artist: artist, url: '' }; 
            executeAudioDownloadRequest(dummyTrack, btnId, format, false);
        }

        function downloadAudioFromPlaylist(trackId, format = 'mp3', isFromQueue = false) {
            const track = currentPlaylistTracks.find(t => t.id === trackId);
            if(!track) return Promise.resolve();
            const btnId = `btn_${trackId}_${format}`;
            return executeAudioDownloadRequest(track, btnId, format, isFromQueue);
        }

        function executeAudioDownloadRequest(track, btnId, format, isFromQueue) {
            return new Promise(async (resolve) => {
                if (isDownloading && !isFromQueue) {
                    alert("Sedang mengunduh file lain. Harap tunggu."); resolve(); return;
                }
                isDownloading = true;

                const btn = document.getElementById(btnId);
                const progressBar = btn.querySelector('.download-progress');
                const spanText = btn.querySelector('span');
                const originalContent = spanText.innerHTML;

                spanText.innerHTML = `<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i>`;
                const originalBg = btn.className.match(/bg-[a-zA-Z0-9-]+/)[0];
                const originalHover = btn.className.match(/hover:bg-[a-zA-Z0-9-]+/)[0];
                btn.classList.replace(originalBg, 'bg-yellow-500');
                btn.classList.replace(originalHover, 'hover:bg-yellow-400');
                lucide.createIcons();
                
                const progressInterval = simulateProgress(progressBar);

                let resolution = 'best';
                if(format === 'mp4' && document.getElementById('playlistVideoQuality')) {
                    resolution = document.getElementById('playlistVideoQuality').value;
                }

                const payload = {
                    mode: track.url ? 'direct' : 'spotify',
                    track_name: track.title,
                    artist_name: track.artist,
                    audio_format: format,
                    url: track.url, 
                    title: format === 'mp4' ? track.title : `${track.title} - ${track.artist}`,
                    format: format,
                    resolution: resolution,
                    audio_quality: '192' 
                };

                try {
                    const response = await fetch(`${API_BASE_URL}download`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });

                    if (!response.ok) {
                        const errData = await response.json().catch(()=>({}));
                        throw new Error(errData.error || 'Gagal dari server');
                    }

                    const blob = await response.blob();
                    const urlObj = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = urlObj;
                    
                    const dlName = format === 'mp4' ? track.title : `${track.title} - ${track.artist}`;
                    a.download = `${dlName}.${format}`.replace(/[^a-zA-Z0-9 \-_.]/g, "");
                    
                    document.body.appendChild(a); a.click(); document.body.removeChild(a);
                    window.URL.revokeObjectURL(urlObj);

                    clearInterval(progressInterval); progressBar.style.width = '100%';
                    spanText.innerHTML = `<i data-lucide="check" class="w-4 h-4"></i>`;
                    btn.classList.replace('bg-yellow-500', originalBg);
                    btn.classList.replace('hover:bg-yellow-400', originalHover);

                } catch (error) {
                    clearInterval(progressInterval); progressBar.style.width = '0%';
                    spanText.innerHTML = `<i data-lucide="x" class="w-4 h-4"></i>`;
                    btn.classList.replace('bg-yellow-500', 'bg-red-500');
                    btn.classList.replace('hover:bg-yellow-400', 'hover:bg-red-400');
                    if(!isFromQueue) alert("Gagal mengunduh: " + error.message);
                } finally {
                    lucide.createIcons();
                    setTimeout(() => { 
                        isDownloading = false; 
                        progressBar.style.width = '0%';
                        spanText.innerHTML = originalContent;
                        btn.className = btn.className.replace(/bg-red-500|bg-yellow-500/g, originalBg).replace(/hover:bg-red-400|hover:bg-yellow-400/g, originalHover);
                        lucide.createIcons();
                        resolve(); 
                    }, 2000);
                }
            });
        }

        async function downloadVideo(format, btnId) {
            if (isDownloading) {
                alert("Sedang mengunduh file lain. Harap tunggu."); return;
            }
            if (!activeVideoUrl) return;
            isDownloading = true;

            const btn = document.getElementById(btnId);
            const progressBar = btn.querySelector('.download-progress');
            const spanText = btn.querySelector('span');
            const originalContent = spanText.innerHTML;

            spanText.innerHTML = `<i data-lucide="loader-2" class="w-5 h-5 animate-spin"></i> Memproses...`;
            const originalBg = btn.className.match(/bg-\S+/)[0];
            const originalHover = btn.className.match(/hover:bg-\S+/)[0];
            btn.classList.replace(originalBg, 'bg-yellow-500');
            btn.classList.replace(originalHover, 'hover:bg-yellow-400');
            lucide.createIcons();
            
            const progressInterval = simulateProgress(progressBar);

            try {
                const title = document.getElementById('videoTitle').innerText;
                const resSelect = document.getElementById('videoResolutionSelect').value;
                const audSelect = document.getElementById('audioQualitySelect').value;

                const response = await fetch(`${API_BASE_URL}download`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        mode: 'direct', 
                        url: activeVideoUrl, 
                        format: format, 
                        title: title,
                        resolution: resSelect,
                        audio_quality: audSelect
                    })
                });

                if (!response.ok) {
                    const errData = await response.json().catch(()=>({}));
                    throw new Error(errData.error || 'Gagal mengunduh dari server');
                }

                const blob = await response.blob();
                const urlObj = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = urlObj;
                
                const cleanTitle = title.replace(/[^a-zA-Z0-9 -]/g, "");
                a.download = `${cleanTitle}.${format}`;
                
                document.body.appendChild(a); a.click(); document.body.removeChild(a);
                window.URL.revokeObjectURL(urlObj);

                clearInterval(progressInterval); progressBar.style.width = '100%';
                spanText.innerHTML = `<i data-lucide="check" class="w-5 h-5"></i> Selesai!`;
                btn.classList.replace('bg-yellow-500', originalBg);
                btn.classList.replace('hover:bg-yellow-400', originalHover);

            } catch (error) {
                clearInterval(progressInterval); progressBar.style.width = '0%';
                spanText.innerHTML = `<i data-lucide="x" class="w-5 h-5"></i> Gagal`;
                btn.classList.replace('bg-yellow-500', 'bg-red-500');
                btn.classList.replace('hover:bg-yellow-400', 'hover:bg-red-400');
                alert("Gagal mengunduh: " + error.message);
            } finally {
                lucide.createIcons();
                setTimeout(() => { 
                    isDownloading = false; 
                    progressBar.style.width = '0%';
                    spanText.innerHTML = originalContent;
                    btn.className = btn.className.replace(/bg-red-500|bg-yellow-500/g, originalBg).replace(/hover:bg-red-400|hover:bg-yellow-400/g, originalHover);
                    lucide.createIcons();
                }, 3000);
            }
        }

        document.getElementById('urlInput').addEventListener('keypress', function (e) {
            if (e.key === 'Enter') processUrl();
        });
    </script>
</body>
</html>
