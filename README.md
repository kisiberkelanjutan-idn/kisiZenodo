# Kisi Zenodo DOI Integration Plugin (`kisiZenodo`) — OJS 3.5

Plugin Generic mandiri untuk **Open Journal Systems (OJS) 3.5** yang mengotomatiskan pembuatan, *pre-reserve*, upload dokumen, dan publikasi DOI ke **Zenodo (InvenioRDM REST API)** beserta pengiriman otomatis ke **Zenodo Community** jurnal.

## Fitur Utama
1. **Tahap 1 — Pre-Reserve DOI + Upload Draft `.docx` (Saat Masuk *Production*)**:
   - Aktif otomatis saat Editor memilih aksi **Send To Production** (memindahkan file `.docx` dari *Copyediting* ke *Production*), atau lewat tombol 1-klik di dashboard **Zenodo DOI Manager**.
   - Membuat *Draft Record* di Zenodo, mengunggah file `.docx` produksi ke draft, memesan nomor DOI resmi (`10.5281/zenodo.XXXXXXX` di Production atau `10.5072/zenodo.XXXXXXX` di Sandbox), dan langsung menyimpannya ke kolom **DOI** publikasi OJS 3.5 agar tim layout dapat mencantumkan DOI di header/footer PDF final.
2. **Tahap 2 — Ganti `.docx` ke `.pdf` Galley Final & Publish ke Zenodo Community**:
   - Saat artikel diterbitkan di OJS (*Publish*) atau lewat tombol **Upload PDF & Publish DOI**, plugin memperbarui seluruh metadata artikel OJS 3.5 (Judul, Abstrak, Penulis + Afiliasi + **ORCID** + **ROR**, Kata Kunci/SDGs dari `controlled_vocabs`, Volume/Nomor/Tahun/Halaman, ISSN, Lisensi `CC-BY-SA-4.0`, & tautan `isPublishedIn`), menghapus file kerja `.docx` sementara di draft Zenodo, mengunggah file **PDF Galley** final, lalu menerbitkan sekaligus memasukkannya ke **Zenodo Community** (mis. `kisibsmk`).
3. **Mode Sandbox & Production Terpisah**:
   - Mendukung pengujian penuh di `https://sandbox.zenodo.org` sebelum beralih ke `https://zenodo.org`.
   - Seluruh *Personal Access Token* disimpan aman di tabel `plugin_settings` database OJS (tidak pernah disimpan di dalam kode atau repository Git).

## Instalasi & Deployment via Git cPanel
1. Clone repository ini di **cPanel → Git™ Version Control** (mis. ke `/home/<user>/repositories/kisiZenodo`).
2. Buka **Manage → Pull or Deploy → Deploy HEAD Commit** (file `.cpanel.yml` otomatis menyalin plugin ke `public_html/plugins/generic/kisiZenodo`).
3. Di dashboard OJS 3.5:
   - Pastikan **DOI Plugin** bawaan aktif dan objek **Articles** dicentang di **Settings → Distribution → DOIs**.
   - Buka **Settings → Website → Plugins → Installed Plugins** → cari **Kisi Zenodo DOI Integration** → centang **Enable**.
   - Klik **Settings** pada plugin untuk mengisi *Personal Access Token* dan *Community Slug* (`kisibsmk`), atau klik **Zenodo DOI Manager** untuk memantau & memproses artikel secara langsung.
