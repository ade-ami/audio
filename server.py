import os
import re
import requests
import json
import glob
import time
# Tambahkan render_template di baris import ini
from flask import Flask, request, send_file, jsonify, after_this_request, render_template
from flask_cors import CORS
import yt_dlp

# Beri tahu Flask bahwa folder template-nya sekarang bernama 'mains'
app = Flask(__name__, template_folder='mains')
# Mengizinkan file HTML kita untuk mengakses server ini
CORS(app) 

@app.route('/')
def index():
    # Menggunakan render_template, dan Flask akan mencarinya di folder 'mains'
    return render_template('index.html')

@app.route('/info', methods=['POST'])
def get_info():
    url = request.json.get('url', '')
    platform = request.json.get('platform', 'spotify').lower()
    
    if platform == 'spotify':
        # Deteksi ID dan Tipe dari URL Spotify
        match = re.search(r'(track|playlist|album)/([a-zA-Z0-9]+)', url)
        if not match:
            return jsonify({'error': 'Tautan Spotify tidak valid.'}), 400
            
        entity_type = match.group(1)
        entity_id = match.group(2)
        
        # FIX: Metode Scraping Embed Tanpa Token
        embed_url = f"https://open.spotify.com/embed/{entity_type}/{entity_id}"
        
        try:
            headers = {'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'}
            res = requests.get(embed_url, headers=headers)
            
            # Ekstrak data JSON mentah yang tersembunyi dari halaman HTML
            next_data_match = re.search(r'<script id="__NEXT_DATA__" type="application/json">(.*?)</script>', res.text)
            
            if not next_data_match:
                return jsonify({'error': 'Gagal mengekstrak data dari Spotify Embed.'}), 500
                
            data = json.loads(next_data_match.group(1))
            entity = data.get('props', {}).get('pageProps', {}).get('state', {}).get('data', {}).get('entity', {})
            
            if not entity:
                return jsonify({'error': 'Data lagu/playlist tidak ditemukan.'}), 404
                
            # Ambil Judul & Cover Utama
            title = entity.get('name', 'Unknown Title')
            
            cover_url = ''
            if 'coverArt' in entity and entity['coverArt'] and 'sources' in entity['coverArt']:
                cover_url = entity['coverArt']['sources'][0]['url']
            elif 'visuals' in entity and entity['visuals'] and 'avatarImage' in entity['visuals']:
                cover_url = entity['visuals']['avatarImage']['sources'][0]['url']

            if entity_type == 'track':
                return jsonify({
                    'type': 'track',
                    'title': title,
                    'artist': entity.get('subtitle', 'Unknown Artist'),
                    'cover': cover_url
                })
                
            elif entity_type in ['playlist', 'album']:
                track_list = []
                # Embed API menyimpan track di dalam 'trackList'
                tracks = entity.get('trackList', []) 
                
                for t in tracks:
                    dur_ms = t.get('duration', 0)
                    
                    # Coba cari cover individual, jika gagal, paksakan pakai Cover Playlist
                    # agar gambar tidak kosong.
                    t_cover = cover_url 
                    
                    track_list.append({
                        'id': t.get('id', 'unknown'),
                        'title': t.get('title', 'Unknown'),
                        'artist': t.get('subtitle', 'Unknown Artist'),
                        'duration': f"{dur_ms // 60000}:{(dur_ms // 1000) % 60:02d}",
                        'img': t_cover
                    })

                return jsonify({
                    'type': 'playlist',
                    'title': title,
                    'cover': cover_url,
                    'total': len(track_list),
                    'tracks': track_list
                })
        except Exception as e:
            return jsonify({'error': f"Kesalahan sistem (Spotify): {str(e)}"}), 500

    else:
        # Penanganan untuk YouTube, Facebook, Instagram, TikTok menggunakan yt-dlp info extractor
        ydl_opts = {
            'quiet': True, 
            'noplaylist': True,
            'extract_flat': False # Paksa ambil metadata penuh
        }
        try:
            with yt_dlp.YoutubeDL(ydl_opts) as ydl:
                info = ydl.extract_info(url, download=False)
                
                title = info.get('title', 'Video Tanpa Judul')
                thumbnail = info.get('thumbnail', '')
                duration_str = ''
                
                if info.get('duration'):
                    mins, secs = divmod(info.get('duration'), 60)
                    duration_str = f"{int(mins)}:{int(secs):02d}"
                else:
                    duration_str = info.get('duration_string', '-:-')
                
                # Nama platform asli untuk estetika UI
                platform_display = platform.capitalize()
                if platform == 'youtube': platform_display = 'YouTube'
                elif platform == 'tiktok': platform_display = 'TikTok'
                
                # Ekstrak resolusi video yang tersedia
                available_resolutions = set()
                for f in info.get('formats', []):
                    height = f.get('height')
                    vcodec = f.get('vcodec')
                    # Hanya ambil format yang memiliki video stream dan informasi resolusi
                    if height and isinstance(height, int) and vcodec and vcodec != 'none':
                        available_resolutions.add(height)
                
                # Urutkan dari resolusi terbesar ke terkecil
                sorted_res = sorted(list(available_resolutions), reverse=True)
                resolutions_list = [f"{h}p" for h in sorted_res]
                
                # Fallback jika yt-dlp tidak mendeteksi list resolusi
                if not resolutions_list:
                    resolutions_list = ["best"]
                
                return jsonify({
                    'type': 'video',
                    'platform': platform_display,
                    'title': title,
                    'thumbnail': thumbnail,
                    'duration': duration_str,
                    'url': url,
                    'resolutions': resolutions_list
                })
        except Exception as e:
            return jsonify({'error': f"Gagal mengekstrak video. Pastikan tautan bersifat publik. ({str(e)})"}), 500

def cleanup_old_files():
    """Fungsi ekstra untuk menghapus file sampah di dalam folder 'temp' yang tertinggal (Usia > 30 Menit)"""
    # Buat folder temp jika belum ada
    if not os.path.exists('temp'):
        os.makedirs('temp')
        
    now = time.time()
    # Pindai hanya ke dalam folder temp/
    for f in glob.glob("temp/temp_*"):
        if os.path.isfile(f):
            if now - os.path.getmtime(f) > 300: # 1800 detik = 30 menit
                try: os.remove(f)
                except: pass

@app.route('/download', methods=['POST'])
def download():
    # Jalankan cleanup tiap kali ada request download baru agar disk server aman
    cleanup_old_files()

    data = request.json
    mode = data.get('mode', 'spotify') # 'spotify' (ytsearch) atau 'direct' (yt-dlp langsung)
    
    # Pastikan folder temp ada sebelum memproses unduhan
    if not os.path.exists('temp'):
        os.makedirs('temp')
        
    # Nama file temporary diarahkan ke dalam folder temp/
    temp_filename = f"temp/temp_media_{os.urandom(4).hex()}"
    
    if mode == 'spotify':
        track_name = data.get('track_name', 'Unknown')
        artist_name = data.get('artist_name', 'Unknown')
        audio_format = data.get('audio_format', 'mp3').lower() # Tangkap format yang diinginkan
        search_query = f"{track_name} {artist_name} audio"
        
        if audio_format not in ['mp3', 'flac']:
            audio_format = 'mp3'
            
        ydl_opts = {
            'format': 'bestaudio/best',
            'outtmpl': f'{temp_filename}.%(ext)s',
            'postprocessors': [{
                'key': 'FFmpegExtractAudio',
                'preferredcodec': audio_format,
            }],
            # Sisipkan metadata ID3 tag untuk Spotify Tracks
            'postprocessor_args': {
                'ffmpeg': [
                    '-metadata', f'title={track_name}',
                    '-metadata', f'artist={artist_name}'
                ]
            },
            'noplaylist': True,
            'quiet': True
        }
        
        # Atur bitrate kualitas standar khusus untuk MP3 saja (FLAC bersifat lossless)
        if audio_format == 'mp3':
            ydl_opts['postprocessors'][0]['preferredquality'] = '192'

        try:
            with yt_dlp.YoutubeDL(ydl_opts) as ydl:
                ydl.extract_info(f"ytsearch1:{search_query}", download=True)
            
            final_file = f"{temp_filename}.{audio_format}"
            nama_file_untuk_user = f"{track_name} - {artist_name}.{audio_format}"
            
            # Hapus file secara instant setelah dikirimkan ke client
            @after_this_request
            def remove_file(response):
                try: os.remove(final_file)
                except: pass
                return response
                
            return send_file(final_file, as_attachment=True, download_name=nama_file_untuk_user)
            
        except Exception as e:
            return jsonify({'error': str(e)}), 500

    elif mode == 'direct':
        # Eksekusi Unduhan untuk YT, FB, IG, TikTok
        url = data.get('url')
        format_type = data.get('format', 'mp4') # 'mp4' atau 'mp3'
        title = data.get('title', 'Video')
        resolution = data.get('resolution', 'best')
        audio_quality = data.get('audio_quality', '192')
        
        # Bersihkan judul dari karakter aneh untuk mencegah error OS saat save
        clean_title = "".join(x for x in title if x.isalnum() or x in " -_")
        if not clean_title: clean_title = "Download"

        ydl_opts = {
            'outtmpl': f'{temp_filename}.%(ext)s',
            'noplaylist': True,
            'quiet': True,
            'merge_output_format': 'mp4' # Memastikan hasil akhir berbentuk MP4 jika formatnya terpisah
        }
        
        if format_type == 'mp3':
            ydl_opts['format'] = 'bestaudio/best'
            ydl_opts['postprocessors'] = [{
                'key': 'FFmpegExtractAudio',
                'preferredcodec': 'mp3',
                'preferredquality': str(audio_quality),
            }]
            final_file = f"{temp_filename}.mp3"
            download_name = f"{clean_title}.mp3"
        else:
            # Video Format menyesuaikan kualitas yg dipilih pengguna
            if resolution != 'best':
                res_int = resolution
                # Formula yt-dlp: Ambil video dengan tinggi maksimal = pilihan pengguna + audio terbaik
                ydl_opts['format'] = f'bestvideo[height<={res_int}][ext=mp4]+bestaudio[ext=m4a]/bestvideo[height<={res_int}]+bestaudio/best[height<={res_int}]/best'
            else:
                ydl_opts['format'] = 'bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]/best'
                
            final_file = f"{temp_filename}.mp4"
            download_name = f"{clean_title}.mp4"
            
        try:
            with yt_dlp.YoutubeDL(ydl_opts) as ydl:
                ydl.extract_info(url, download=True)
                
            # Hapus file seketika setelah selesai transfer
            @after_this_request
            def remove_file(response):
                try: os.remove(final_file)
                except: pass
                return response
                
            return send_file(final_file, as_attachment=True, download_name=download_name)
            
        except Exception as e:
            return jsonify({'error': str(e)}), 500

if __name__ == '__main__':
    print("==================================================")
    print("Server AIO Downloader Berjalan di http://localhost:5000")
    print("==================================================")
    app.run(host='0.0.0.0', port=5000, debug=True)