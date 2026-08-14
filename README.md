# 🚀 All-in-One Media Downloader (AIO Downloader)

[🇮🇩 Bahasa Indonesia](#-bahasa-indonesia) | [🇺🇸 English](#-english)

---

## 🇮🇩 Bahasa Indonesia

AIO Downloader adalah aplikasi berbasis web yang kuat dan elegan untuk mengunduh media dari berbagai platform seperti **Spotify, YouTube, Facebook, Instagram, dan TikTok**. Aplikasi ini memiliki fitur unggulan berupa **Batch Playlist Download** dan konversi codec pintar agar video bisa langsung dikirim ke WhatsApp atau Instagram tanpa *error*.

Proyek ini menyediakan dua opsi *backend*: **PHP** (Sangat disarankan untuk pengguna XAMPP/Laragon) dan **Python (Flask)**.

### ✨ Fitur Utama
* **⚡ Dukungan Multi-Platform:** Unduh dari Spotify, YouTube, FB, IG, dan TikTok.
* **🎵 Spotify & YouTube Playlist:** Mendeteksi tautan *playlist* otomatis dan menampilkannya dalam UI antrean (Grid) untuk *batch download*.
* **📱 WhatsApp & Sosmed Friendly:** Memaksa konversi video MP4 ke codec **H.264 (avc1)** dan audio **AAC (m4a)**, sehingga dijamin bisa diputar di iPhone, status WhatsApp, dan Story IG.
* **🎧 Pilihan Kualitas Audio:** Tersedia opsi unduhan MP3 (hingga 320kbps) dan FLAC (Lossless).
* **🧹 Pembersihan Otomatis (Garbage Collector):** Menghapus file sampah (*temp files*) otomatis agar server/hardisk tidak penuh.

### ⚙️ Persyaratan Sistem (Prerequisites)
Apapun versi *backend* yang Anda gunakan, Anda **WAJIB** memiliki dua aplikasi inti ini di sistem Anda:
1. [**yt-dlp**](https://github.com/yt-dlp/yt-dlp/releases): Mesin utama untuk mengekstrak dan mengunduh video.
2. [**FFmpeg**](https://ffmpeg.org/download.html): Digunakan untuk menyatukan video/audio dan menyisipkan metadata (seperti judul lagu dan nama artis).

*(Sangat disarankan untuk menaruh file `yt-dlp.exe` dan `ffmpeg.exe` di folder khusus, misalnya `C:\ffmpeg\`, dan menggunakan **Absolute Path** di dalam kode).*

### 🚀 Cara Instalasi

#### OPSI A: Menggunakan PHP (Direkomendasikan untuk Laragon/XAMPP)
Versi ini sangat ringan, berjalan secara sinkron, dan mengelola file sementara dengan sangat rapi.

1. Pindahkan file `index.php` ke dalam direktori *web server* Anda (misal: `C:\laragon\www\aio-downloader\`).
2. Buka file `index.php` di *text editor*.
3. Ubah konfigurasi *Absolute Path* pada baris paling atas sesuai lokasi `yt-dlp` dan `ffmpeg` di komputer Anda:
   ```php
   // PERINGATAN: Pastikan Anda tetap menggunakan tanda kutip tunggal di luar tanda kutip ganda!
   $yt_dlp_path = '"C:\Lokasi\Anda\yt-dlp.exe"';
   $ffmpeg_path = '"C:\Lokasi\Anda\ffmpeg.exe"';
   ```
4. Jalankan Apache/Nginx melalui panel kontrol Anda.
5. Buka browser: `http://localhost/aio-downloader/`

#### OPSI B: Menggunakan Python (Flask)
Cocok jika Anda ingin menjalankannya sebagai *standalone service*.

1. Pastikan terinstal **Python 3.8+**.
2. Buka terminal/command prompt dan instal modul yang dibutuhkan:
   ```bash
   pip install flask flask-cors yt-dlp requests
   ```
3. Siapkan struktur folder Anda dan masukkan file yang telah diunduh ke dalamnya:
   ```text
   📂 Project Folder
   ├── 📂 mains
   │   └── 📄 index.html  <-- File Frontend UI
   └── 📄 server.py       <-- File Backend Python
   ```
4. Jalankan server:
   ```bash
   python server.py
   ```
5. Buka browser: `http://localhost:5000`

### 🛠️ Troubleshooting (Penyelesaian Masalah)
* **Video MP4 Error / Suara Tidak Ada:** Pastikan FFmpeg sudah terinstal dan lokasinya diarahkan dengan benar.
* **Download Gagal:** YouTube sering mengubah algoritma mereka. Perbarui `yt-dlp` ke versi terbaru.
  * Jika memakai versi `.exe`: Buka CMD dan jalankan `yt-dlp -U`.
  * Jika memakai versi Python (`pip`): Jalankan `pip install -U yt-dlp`.

---

## 🇺🇸 English

AIO Downloader is a powerful and elegant web-based application for downloading media from various platforms such as **Spotify, YouTube, Facebook, Instagram, and TikTok**. It features **Batch Playlist Downloading** and smart codec conversion, ensuring downloaded videos can be directly shared to WhatsApp or Instagram without errors.

This project provides two backend options: **PHP** (Highly recommended for XAMPP/Laragon users) and **Python (Flask)**.

### ✨ Key Features
* **⚡ Multi-Platform Support:** Download from Spotify, YouTube, FB, IG, and TikTok.
* **🎵 Spotify & YouTube Playlists:** Automatically detects playlist links and displays them in a queue (Grid) UI for batch downloading.
* **📱 WhatsApp & Social Media Friendly:** Forces video conversion to **H.264 (avc1)** codec and **AAC (m4a)** audio, guaranteeing compatibility with iPhones, WhatsApp statuses, and IG Stories.
* **🎧 Audio Quality Options:** Choose between MP3 (up to 320kbps) and FLAC (Lossless) downloads.
* **🧹 Automatic Cleanup (Garbage Collector):** Automatically deletes temporary files to prevent your server/hard drive from filling up.

### ⚙️ Prerequisites
Regardless of the backend version you choose, your system **MUST** have these two core applications installed:
1. [**yt-dlp**](https://github.com/yt-dlp/yt-dlp/releases): The main engine for extracting and downloading videos.
2. [**FFmpeg**](https://ffmpeg.org/download.html): Used for merging video/audio and embedding metadata (like track titles and artist names).

*(It is highly recommended to place `yt-dlp.exe` and `ffmpeg.exe` in a dedicated folder, e.g., `C:\ffmpeg\`, and use an **Absolute Path** in the code).*

### 🚀 Installation Guide

#### OPTION A: Using PHP (Recommended for Laragon/XAMPP)
This version is lightweight, runs synchronously, and manages temporary files cleanly.

1. Move the `index.php` file into your web server directory (e.g., `C:\laragon\www\aio-downloader\`).
2. Open `index.php` in your text editor.
3. Update the *Absolute Path* configuration at the very top of the file to match the locations of `yt-dlp` and `ffmpeg` on your computer:
   ```php
   // WARNING: Ensure you keep the single quotes outside the double quotes!
   $yt_dlp_path = '"C:\Your\Location\yt-dlp.exe"';
   $ffmpeg_path = '"C:\Your\Location\ffmpeg.exe"';
   ```
4. Start Apache/Nginx via your control panel.
5. Open your browser: `http://localhost/aio-downloader/`

#### OPTION B: Using Python (Flask)
Ideal if you prefer running it as a standalone service.

1. Ensure **Python 3.8+** is installed.
2. Open your terminal/command prompt and install the required modules:
   ```bash
   pip install flask flask-cors yt-dlp requests
   ```
3. Set up your folder structure and place the downloaded files inside:
   ```text
   📂 Project Folder
   ├── 📂 mains
   │   └── 📄 index.html  <-- Frontend UI File
   └── 📄 server.py       <-- Python Backend File
   ```
4. Run the server:
   ```bash
   python server.py
   ```
5. Open your browser: `http://localhost:5000`

### 🛠️ Troubleshooting
* **MP4 Video Error / No Sound:** Ensure FFmpeg is installed and its path is configured correctly.
* **Download Fails:** YouTube frequently changes its algorithms. Update `yt-dlp` to the latest version.
  * If using the `.exe` version: Open CMD and run `yt-dlp -U`.
  * If using the Python (`pip`) version: Run `pip install -U yt-dlp`.
