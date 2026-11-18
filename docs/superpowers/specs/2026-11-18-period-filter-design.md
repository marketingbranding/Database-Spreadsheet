# Filter Periode Popover

## Intent

Ganti filter periode berbentuk select + dua tanggal menjadi satu kontrol periode ringkas dengan popover tiga mode. Pengguna dapat memilih bulan, kuartal, atau rentang tanggal tanpa request sampai menekan **Terapkan**.

## Goals

- Tampilkan periode aktif dalam satu tombol, misalnya `November 2026`.
- Sediakan tab `Bulanan`, `Kuartalan`, dan `Custom`.
- Bulanan: pilih tahun dan satu dari 12 bulan.
- Kuartalan: pilih tahun dan Q1–Q4.
- Custom: pilih `Dari tanggal` dan `Sampai tanggal`.
- **Batal** menutup popover tanpa mengubah filter aktif.
- **Terapkan** mengirim parameter URL lama: `period`, `from`, `to`.
- **Reset** tetap menghapus seluruh query filter dan mengembalikan periode default `Tahun berjalan`.
- Tetap keyboard-accessible dan responsif pada desktop/mobile.

## Non-goals

- Tidak mengubah query metrics atau kontrak backend.
- Tidak menambah dependency atau build step.
- Tidak membuat date-picker kalender custom; Custom memakai input tanggal native browser.

## Constraints

- Implementasi memakai Blade, CSS existing, dan vanilla JavaScript.
- Partial dipakai lintas halaman; periode hanya tampil saat `showPeriod=true`.
- URL lama tetap kompatibel: `ytd`, `2025`, `2026`, `all`, `custom`.
- Pilihan sementara di popover tidak boleh mengubah URL sebelum **Terapkan**.

## Approach

Pakai popover HTML ringan di `resources/views/partials/filters.blade.php`, dengan kontrol form tersembunyi/terlihat yang tetap bernama `period`, `from`, dan `to`. Vanilla JS mengelola buka/tutup, tab, pilihan bulan/kuartal, preview label, pembatalan, dan sinkronisasi field. CSS existing diperluas untuk layout panel, grid bulan/kuartal, focus state, dan mobile.

## Acceptance

- Dashboard dapat memilih September 2026, klik **Terapkan**, lalu URL memakai `period=custom&from=2026-09-01&to=2026-09-30` dan KPI berubah.
- Batal setelah memilih periode lain mempertahankan periode sebelumnya.
- Reset menghapus `project`, `from`, `to`, dan parameter lain; periode kembali `ytd`.
- Bulanan, kuartalan, Custom, tombol dan dialog dapat dipakai dengan keyboard.
- Existing full test suite dan Pint tetap lulus.
