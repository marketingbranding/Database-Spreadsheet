# Custom Date-Range Picker

## Intent

Ganti input tanggal native pada tab **Custom** dengan kalender range dua bulan seperti referensi, supaya pengguna memilih tanggal langsung dari grid kalender.

## Behavior

- Tab **Custom** menampilkan dua kalender berdampingan.
- Kalender kiri menampilkan bulan awal; kalender kanan menampilkan bulan berikutnya.
- Tombol navigasi mundur/maju menggeser pasangan bulan satu bulan.
- Klik tanggal pertama mengisi **Dari tanggal**.
- Klik tanggal kedua mengisi **Sampai tanggal**.
- Jika tanggal kedua lebih awal dari tanggal pertama, tanggal itu menjadi awal range baru.
- Hari di antara awal dan akhir diberi highlight.
- Tanggal terpilih tetap terlihat pada dua input ringkas di bagian atas.
- Ringkasan bawah menampilkan range aktif.
- **Batal** menutup picker tanpa mengubah filter aktif.
- **Terapkan** mengisi parameter GET lama: `period=custom`, `from`, `to`, lalu submit form.

## Goals

- Visual dan alur mendekati referensi pengguna.
- Keyboard-accessible: tombol navigasi, tanggal, Batal, dan Terapkan dapat difokuskan; tanggal punya label jelas.
- Desktop dua kalender; mobile kalender tetap terbaca tanpa horizontal overflow.
- Tidak mengubah controller, metrics, atau kontrak URL.

## Non-goals

- Tidak menambah dependency.
- Tidak membuat timezone/date library baru.
- Tidak mengubah Bulanan dan Kuartalan.
- Tidak menerapkan filter sebelum Terapkan.

## Technical design

Gunakan markup Blade untuk shell picker dan vanilla JS untuk state sementara. JS membuat grid kalender dari `year` dan `month`, termasuk hari sebelum/sesudah bulan aktif untuk melengkapi minggu. State berisi `viewStart`, `from`, dan `to`; semua tanggal disimpan sebagai `YYYY-MM-DD`. Kalender menggunakan `button` per tanggal dengan `aria-label`, `aria-pressed`, dan state selected/in-range.

Gunakan `Date.UTC` atau operasi tanggal berbasis komponen lokal yang tidak bergantung pada parsing timezone. Validasi hanya menerima tanggal lengkap dan memastikan `from <= to` sebelum Terapkan; jika salah satu tanggal belum dipilih, tetap di picker dan tampilkan pesan singkat.

## Acceptance

- Pilih 01/10/2026 lalu 31/12/2026 menghasilkan range `2026-10-01..2026-12-31`.
- Range lintas bulan terlihat highlight di dua kalender.
- Klik tanggal lebih awal setelah range ada memulai range baru.
- Navigasi pasangan bulan bekerja tanpa mengubah nilai filter aktif.
- Batal mempertahankan URL dan trigger lama.
- Terapkan menghasilkan URL dengan `period=custom`, `from`, dan `to` yang tepat.
- Desktop sekitar 1440×900 dan mobile sekitar 390×844 tidak overflow; console bersih.
