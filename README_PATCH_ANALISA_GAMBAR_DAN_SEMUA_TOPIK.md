# Patch Telegram Adena — Analisa Gambar + Semua Topik

Patch ini menambahkan kemampuan:

1. Menangkap pesan dari semua topic/forum Telegram berdasarkan `message_thread_id`.
2. Menyimpan nama topik ke tabel `telegram_topics`.
3. Menangkap foto/gambar dari Telegram.
4. Mengunduh foto ke `uploads/telegram/YYYY/MM/`.
5. Menganalisa foto/struk dengan OpenAI Vision bila `OPENAI_API_KEY` aktif.
6. Merangkum teks + hasil analisa gambar dalam dashboard `summary.php`.
7. Menampilkan pesan, topik, media, dan hasil analisa di `messages.php`.
8. Menyediakan script backfill untuk membaca ulang `raw_payload` lama.

## File yang berubah / ditambah

- `config.sample.php`
- `webhook.php`
- `summary.php`
- `messages.php`
- `send_summary.php`
- `.htaccess`
- `uploads/telegram/.htaccess`
- `migrations/2026_05_31_add_topics_media_analysis.sql`
- `tools/backfill_topics_from_raw_payload.php`

Patch tidak menyertakan `config.php` produksi.

## Urutan pemasangan

1. Backup file web lama dan database.
2. Upload/replace file patch ke folder aplikasi Telegram Adena.
3. Jalankan SQL:

   `migrations/2026_05_31_add_topics_media_analysis.sql`

4. Update `config.php` produksi dengan konstanta baru berikut bila belum ada:

```php
define('OPENAI_VISION_MODEL', 'gpt-4.1-mini');
define('TELEGRAM_UPLOAD_DIR', __DIR__ . '/uploads/telegram');
define('TELEGRAM_UPLOAD_URL', 'https://domain-anda.com/path-ke-app/uploads/telegram');
define('ENABLE_IMAGE_ANALYSIS', true);
define('MAX_IMAGE_ANALYSIS_BYTES', 5 * 1024 * 1024);
define('APP_TIMEZONE', 'Asia/Jakarta');
```

Jika `TELEGRAM_UPLOAD_URL` dikosongkan, gambar tetap tersimpan lokal tetapi thumbnail/link publik tidak ditampilkan.

5. Pastikan folder berikut writable:

```text
uploads/telegram
```

Permission umum: `755` atau `775`, tergantung hosting.

6. Jalankan backfill data lama:

CLI:

```bash
php tools/backfill_topics_from_raw_payload.php
```

Atau via browser:

```text
https://domain-anda.com/path-ke-app/tools/backfill_topics_from_raw_payload.php?token=CRON_ACCESS_TOKEN
```

Backfill mengisi kolom baru dari `raw_payload` lama: `message_thread_id`, `topic_name`, `media_file_id`, `message_type`, dan tabel `telegram_topics`.

## Catatan penting

- Backfill tidak otomatis download ulang foto lama. Ia hanya mengisi metadata dari `raw_payload`.
- Foto baru setelah patch aktif akan dicoba download dan dianalisa.
- Jika OpenAI API kosong/quota habis, pesan dan foto tetap tersimpan. Kolom `image_analysis_status` akan `skipped` atau `error`.
- Jika topic tetap tidak muncul sama sekali, kemungkinan bot belum menerima update dari Telegram. Cek:
  - bot sudah masuk group;
  - bot admin atau privacy mode sudah sesuai;
  - webhook aktif;
  - pesan baru dikirim setelah webhook aktif.

## URL dashboard

```text
summary.php?token=APP_ACCESS_TOKEN
messages.php?token=APP_ACCESS_TOKEN
```

## Cron summary

```text
send_summary.php?token=CRON_ACCESS_TOKEN&period=today
send_summary.php?token=CRON_ACCESS_TOKEN&period=yesterday
send_summary.php?token=CRON_ACCESS_TOKEN&period=7days
```
