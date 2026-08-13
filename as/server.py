import os
import re
import requests
import json
import glob
import time
from flask import Flask, request, send_file, jsonify, after_this_request, render_template
from flask_cors import CORS
import yt_dlp

app = Flask(__name__, template_folder='mains')
CORS(app) 

@app.route('/')
def index():
    return render_template('index.html')

@app.route('/info', methods=['POST'])
def get_info():
    url = request.json.get('url', '')
    platform = request.json.get('platform', 'spotify').lower()
    media_mode = request.json.get('media_mode', 'single') # 'single' or 'playlist'
    
    if platform == 'spotify':
        match = re.search(r'(track|playlist|album)/([a-zA-Z0-9]+)', url)
        if not match:
            return jsonify({'error': 'Tautan Spotify tidak valid.'}), 400
            
        entity_type = match.group(1)
        entity_id = match.group(2)
        embed_url = f"https://open.spotify.com/embed/{entity_type}/{entity_id}"
        
        try:
            headers = {'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'}
            res = requests.get(embed_url, headers=headers)
            
            next_data_match = re.search(r'<script id="__NEXT_DATA__" type="application/json">(.*?)</script>', res.text)
            if not next_data_match:
                return jsonify({'error': 'Gagal mengekstrak data dari Spotify Embed.'}), 500
                
            data = json.loads(next_data_match.group(1))
            entity = data.get('props', {}).get('pageProps', {}).get('state', {}).get('data', {}).get('entity', {})
            
            if not entity:
                return jsonify({'error': 'Data lagu/playlist tidak ditemukan.'}), 404
                
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
                tracks = entity.get('trackList', []) 
                
                for t in tracks:
                    dur_ms = t.get('duration', 0)
                    track_list.append({
                        'id': t.get('id', 'unknown'),
                        'title': t.get('title', 'Unknown'),
                        'artist': t.get('subtitle', 'Unknown Artist'),
                        'duration': f"{dur_ms // 60000}:{(dur_ms // 1000) % 60:02d}",
                        'img': cover_url,
                        'url': '' # Kosong karena Spotify akan dicari via ytsearch
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

    elif platform == 'youtube':
        # Yt-Dlp Options menyesuaikan mode (Playlist vs Single)
        ydl_opts = {
            'quiet': True, 
            'extract_flat': True if media_mode == 'playlist' else False,
            'noplaylist': False if media_mode == 'playlist' else True,
        }
        
        try:
            with yt_dlp.YoutubeDL(ydl_opts) as ydl:
                info = ydl.extract_info(url, download=False)
                
                if media_mode == 'playlist' and 'entries' in info:
                    playlist_title = info.get('title', 'YouTube Playlist')
                    # Ambil thumbnail playlist jika ada
                    cover_url = info.get('thumbnails', [{'url': 'https://placehold.co/600x600/121212/ffffff?text=Playlist'}])[-1]['url']
                    
                    track_list = []
                    for idx, entry in enumerate(info.get('entries', [])):
                        if not entry: continue
                        
                        dur = entry.get('duration')
                        dur_str = f"{int(dur//60)}:{int(dur%60):02d}" if dur else "-:-"
                        
                        t_thumb = cover_url
                        if entry.get('thumbnails'):
                            t_thumb = entry['thumbnails'][-1]['url']
                            
                        vid_id = entry.get('id', f'yt_{idx}')
                        track_list.append({
                            'id': vid_id,
                            'title': entry.get('title', 'Unknown Video'),
                            'artist': entry.get('uploader', entry.get('channel', 'Unknown Channel')),
                            'duration': dur_str,
                            'img': t_thumb,
                            'url': entry.get('url', f"https://www.youtube.com/watch?v={vid_id}")
                        })
                        
                    return jsonify({
                        'type': 'playlist',
                        'platform': 'YouTube',
                        'title': playlist_title,
                        'cover': cover_url,
                        'total': len(track_list),
                        'tracks': track_list
                    })
                
                else:
                    title = info.get('title', 'Video Tanpa Judul')
                    thumbnail = info.get('thumbnail', '')
                    
                    if info.get('duration'):
                        mins, secs = divmod(info.get('duration'), 60)
                        duration_str = f"{int(mins)}:{int(secs):02d}"
                    else:
                        duration_str = info.get('duration_string', '-:-')
                    
                    available_resolutions = set()
                    for f in info.get('formats', []):
                        height = f.get('height')
                        vcodec = f.get('vcodec')
                        if height and isinstance(height, int) and vcodec and vcodec != 'none':
                            available_resolutions.add(height)
                    
                    sorted_res = sorted(list(available_resolutions), reverse=True)
                    resolutions_list = [f"{h}p" for h in sorted_res]
                    if not resolutions_list: resolutions_list = ["best"]
                    
                    return jsonify({
                        'type': 'video',
                        'platform': 'YouTube',
                        'title': title,
                        'thumbnail': thumbnail,
                        'duration': duration_str,
                        'url': url,
                        'resolutions': resolutions_list
                    })
        except Exception as e:
            return jsonify({'error': f"Gagal mengekstrak YouTube. ({str(e)})"}), 500

    else:
        ydl_opts = {'quiet': True, 'noplaylist': True, 'extract_flat': False}
        try:
            with yt_dlp.YoutubeDL(ydl_opts) as ydl:
                info = ydl.extract_info(url, download=False)
                
                title = info.get('title', 'Video Tanpa Judul')
                thumbnail = info.get('thumbnail', '')
                
                if info.get('duration'):
                    mins, secs = divmod(info.get('duration'), 60)
                    duration_str = f"{int(mins)}:{int(secs):02d}"
                else:
                    duration_str = info.get('duration_string', '-:-')
                
                return jsonify({
                    'type': 'video',
                    'platform': platform.capitalize(),
                    'title': title,
                    'thumbnail': thumbnail,
                    'duration': duration_str,
                    'url': url,
                    'resolutions': ['best']
                })
        except Exception as e:
            return jsonify({'error': f"Gagal mengekstrak video. Pastikan publik. ({str(e)})"}), 500

def cleanup_old_files():
    if not os.path.exists('temp'):
        os.makedirs('temp')
    now = time.time()
    for f in glob.glob("temp/temp_*"):
        if os.path.isfile(f):
            if now - os.path.getmtime(f) > 1000:
                try: os.remove(f)
                except: pass

@app.route('/download', methods=['POST'])
def download():
    cleanup_old_files()
    data = request.json
    mode = data.get('mode', 'spotify') 
    
    if not os.path.exists('temp'):
        os.makedirs('temp')
        
    temp_filename = f"temp/temp_media_{os.urandom(4).hex()}"
    
    if mode == 'spotify':
        track_name = data.get('track_name', 'Unknown')
        artist_name = data.get('artist_name', 'Unknown')
        audio_format = data.get('audio_format', 'mp3').lower()
        search_query = f"{track_name} {artist_name} audio"
        
        if audio_format not in ['mp3', 'flac']: audio_format = 'mp3'
            
        ydl_opts = {
            'format': 'bestaudio/best',
            'outtmpl': f'{temp_filename}.%(ext)s',
            'postprocessors': [{'key': 'FFmpegExtractAudio', 'preferredcodec': audio_format}],
            'postprocessor_args': {'ffmpeg': ['-metadata', f'title={track_name}', '-metadata', f'artist={artist_name}']},
            'noplaylist': True,
            'quiet': True
        }
        if audio_format == 'mp3': ydl_opts['postprocessors'][0]['preferredquality'] = '192'

        try:
            with yt_dlp.YoutubeDL(ydl_opts) as ydl:
                ydl.extract_info(f"ytsearch1:{search_query}", download=True)
            
            final_file = f"{temp_filename}.{audio_format}"
            nama_file_untuk_user = f"{track_name} - {artist_name}.{audio_format}"
            
            @after_this_request
            def remove_file(response):
                try: os.remove(final_file)
                except: pass
                return response
                
            return send_file(final_file, as_attachment=True, download_name=nama_file_untuk_user)
            
        except Exception as e:
            return jsonify({'error': str(e)}), 500

    elif mode == 'direct':
        url = data.get('url')
        format_type = data.get('format', 'mp4') 
        title = data.get('title', 'Video')
        resolution = data.get('resolution', 'best')
        audio_quality = data.get('audio_quality', '192')
        
        clean_title = "".join(x for x in title if x.isalnum() or x in " -_")
        if not clean_title: clean_title = "Download"

        ydl_opts = {
            'outtmpl': f'{temp_filename}.%(ext)s',
            'noplaylist': True,
            'quiet': True,
            'merge_output_format': 'mp4'
        }
        
        # Penambahan fitur unduh FLAC/MP3 untuk mode Direct (YouTube Playlist)
        if format_type in ['mp3', 'flac']:
            ydl_opts['format'] = 'bestaudio/best'
            ydl_opts['postprocessors'] = [{
                'key': 'FFmpegExtractAudio',
                'preferredcodec': format_type,
            }]
            if format_type == 'mp3':
                ydl_opts['postprocessors'][0]['preferredquality'] = str(audio_quality)
            
            final_file = f"{temp_filename}.{format_type}"
            download_name = f"{clean_title}.{format_type}"
        else:
            if resolution != 'best':
                res_int = resolution
                ydl_opts['format'] = f'bestvideo[height<={res_int}][ext=mp4]+bestaudio[ext=m4a]/bestvideo[height<={res_int}]+bestaudio/best[height<={res_int}]/best'
            else:
                ydl_opts['format'] = 'bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]/best'
                
            final_file = f"{temp_filename}.mp4"
            download_name = f"{clean_title}.mp4"
            
        try:
            with yt_dlp.YoutubeDL(ydl_opts) as ydl:
                ydl.extract_info(url, download=True)
                
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