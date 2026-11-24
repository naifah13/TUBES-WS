// Riwayat showroom yang sudah dilihat (tanpa akun).
// Disimpan di localStorage browser: tetap ada walau halaman di-refresh atau browser ditutup,
// dan hanya terlihat di browser/perangkat yang sama.
(function () {
  "use strict";

  const KUNCI = "vroom:riwayat:v1";
  const MAKS_RIWAYAT = 20;
  const MAKS_BERANDA = 6;
  const POLA_URI = /^http:\/\/www\.example\.org\/showroom#Showroom\d+$/;

  let elemenSebelum = null;
  let perbaruiNavigasiBeranda = null;

  /* ---------- Penyimpanan ---------- */

  function baca() {
    try {
      const data = JSON.parse(localStorage.getItem(KUNCI) || "[]");
      return Array.isArray(data) ? data.filter((s) => s && POLA_URI.test(s.uri)) : [];
    } catch (e) {
      return [];
    }
  }

  function simpan(daftar) {
    try {
      localStorage.setItem(KUNCI, JSON.stringify(daftar.slice(0, MAKS_RIWAYAT)));
    } catch (e) {
      /* penyimpanan penuh atau mode privat: abaikan */
    }
  }

  // Dipanggil di detail.php: showroom yang dibuka naik ke urutan paling atas.
  function catatKunjungan() {
    const el = document.querySelector("[data-riwayat-showroom]");
    if (!el) return;
    let baru;
    try {
      baru = JSON.parse(el.getAttribute("data-riwayat-showroom"));
    } catch (e) {
      return;
    }
    if (!baru || !POLA_URI.test(baru.uri)) return;

    const daftar = baca();
    const lama = daftar.find((s) => s.uri === baru.uri);
    baru.dilihat = Date.now();
    baru.kunjungan = (lama && lama.kunjungan ? lama.kunjungan : 0) + 1;
    simpan([baru].concat(daftar.filter((s) => s.uri !== baru.uri)));
  }

  /* ---------- Bantuan tampilan ---------- */

  function esc(teks) {
    return String(teks == null ? "" : teks).replace(/[&<>"']/g, (c) => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;",
    })[c]);
  }

  function urlDetail(s) {
    return "detail.php?uri=" + encodeURIComponent(s.uri);
  }

  function waktuLalu(ms) {
    const detik = Math.max(0, Math.round((Date.now() - ms) / 1000));
    if (detik < 60) return "baru saja";
    const menit = Math.round(detik / 60);
    if (menit < 60) return menit + " menit lalu";
    const jam = Math.round(menit / 60);
    if (jam < 24) return jam + " jam lalu";
    const hari = Math.round(jam / 24);
    if (hari === 1) return "kemarin";
    if (hari < 7) return hari + " hari lalu";
    return new Date(ms).toLocaleDateString("id-ID", { day: "numeric", month: "short", year: "numeric" });
  }

  const IKON = {
    lokasi: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>',
    jam: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg>',
    tutup: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>',
    jamKecil: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/></svg>',
    kosong: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/></svg>',
  };

  /* ---------- Status buka/tutup (logika sama dengan js/status-showroom.js) ---------- */

  const LABEL_STATUS = {
    buka: "Buka",
    "segera-tutup": "Segera tutup",
    tutup: "Tutup",
    "tidak-diketahui": "Jam belum pasti",
  };

  const formatWib = new Intl.DateTimeFormat("id-ID", {
    timeZone: "Asia/Jakarta", hourCycle: "h23", hour: "2-digit", minute: "2-digit",
  });

  function menitWib() {
    const bagian = formatWib.formatToParts(Date.now());
    return Number(bagian.find((b) => b.type === "hour").value) * 60 +
      Number(bagian.find((b) => b.type === "minute").value);
  }

  function statusSekarang(s) {
    if (!s.jadwal || typeof s.jadwal !== "object") return "tidak-diketahui";
    const menit = menitWib();
    const urut = Object.entries(s.jadwal).sort((a, b) => Number(a[0]) - Number(b[0]));
    let status = urut.length ? urut[0][1] : "tidak-diketahui";
    urut.forEach(([awal, st]) => { if (Number(awal) <= menit) status = st; });
    return LABEL_STATUS[status] ? status : "tidak-diketahui";
  }

  /* ---------- Kartu showroom: markup sama dengan daftar-kartu-showroom.php ---------- */

  function kartu(s) {
    const status = statusSekarang(s);
    const kategori = (s.kategori || "").toLowerCase();
    return (
      '<article class="showroom-card rw-kartu">' +
      '  <div class="showroom-card-top">' +
      '    <div class="showroom-logo">' +
      (s.logo ? '<img src="' + esc(s.logo) + '" alt="Logo ' + esc(s.merek) + '" loading="lazy">' : '<span aria-hidden="true">SS</span>') +
      "    </div>" +
      '    <div class="rw-kartu-aksi">' +
      '      <span class="showroom-status" data-status-showroom="' + status + '" title="Berdasarkan jadwal operasional harian (WIB)">' + LABEL_STATUS[status] + "</span>" +
      "    </div>" +
      "  </div>" +
      '  <div class="showroom-content">' +
      '    <div class="showroom-identity">' +
      '      <p class="showroom-brand">' + esc(s.merek || "-") + "</p>" +
      (kategori === "mobil" || kategori === "motor"
        ? '<span class="showroom-category">' + esc(kategori.charAt(0).toUpperCase() + kategori.slice(1)) + "</span>"
        : "") +
      "    </div>" +
      "    <h3>" + esc(s.nama) + "</h3>" +
      '    <div class="showroom-information">' +
      '      <p class="showroom-info">' + IKON.lokasi + "<span>" + esc(s.lokasi || "Sumatera Utara") + "</span></p>" +
      '      <p class="showroom-info">' + IKON.jam + "<span>" + esc(s.jam || "-") + (status !== "tidak-diketahui" ? "&nbsp;WIB" : "") + "</span></p>" +
      '      <p class="showroom-info rw-info-waktu">' + IKON.jamKecil + "<span>Dilihat " + esc(waktuLalu(s.dilihat)) + "</span></p>" +
      "    </div>" +
      '    <div class="showroom-bottom">' +
      '      <div class="showroom-rating">' +
      '        <span class="showroom-rating-star" aria-hidden="true">★</span>' +
      "        <span>" + esc(s.rating || "0") + "</span>" +
      "      </div>" +
      '      <a href="' + esc(urlDetail(s)) + '" class="showroom-detail">Lihat Detail <span aria-hidden="true">→</span></a>' +
      "    </div>" +
      "  </div>" +
      "</article>"
    );
  }

  // Versi mendatar (landscape) untuk panel riwayat, dengan warna dan gaya yang sama.
  function kartuPanel(s) {
    const status = statusSekarang(s);
    const kategori = (s.kategori || "").toLowerCase();
    return (
      '<article class="showroom-card rw-kartu rw-kartu-panel">' +
      '  <div class="showroom-logo">' +
      (s.logo ? '<img src="' + esc(s.logo) + '" alt="Logo ' + esc(s.merek) + '" loading="lazy">' : '<span aria-hidden="true">SS</span>') +
      "  </div>" +
      '  <div class="rw-panel-isi">' +
      '    <div class="rw-panel-atas">' +
      '      <div class="showroom-identity">' +
      '        <p class="showroom-brand">' + esc(s.merek || "-") + "</p>" +
      (kategori === "mobil" || kategori === "motor"
        ? '<span class="showroom-category">' + esc(kategori.charAt(0).toUpperCase() + kategori.slice(1)) + "</span>"
        : "") +
      "      </div>" +
      '      <div class="rw-kartu-aksi">' +
      '        <span class="showroom-status" data-status-showroom="' + status + '" title="Berdasarkan jadwal operasional harian (WIB)">' + LABEL_STATUS[status] + "</span>" +
      '        <button type="button" class="rw-hapus" data-rw-hapus="' + esc(s.uri) + '" aria-label="Hapus ' + esc(s.nama) + ' dari riwayat" title="Hapus dari riwayat">' + IKON.tutup + "</button>" +
      "      </div>" +
      "    </div>" +
      "    <h3>" + esc(s.nama) + "</h3>" +
      '    <div class="rw-panel-info">' +
      '      <p class="showroom-info">' + IKON.lokasi + "<span>" + esc(s.lokasi || "Sumatera Utara") + "</span></p>" +
      '      <p class="showroom-info">' + IKON.jam + "<span>" + esc(s.jam || "-") + (status !== "tidak-diketahui" ? "&nbsp;WIB" : "") + "</span></p>" +
      "    </div>" +
      '    <div class="showroom-bottom">' +
      '      <div class="rw-panel-nilai">' +
      '        <span class="showroom-rating"><span class="showroom-rating-star" aria-hidden="true">★</span> ' + esc(s.rating || "0") + "</span>" +
      '        <span class="rw-panel-waktu">' + IKON.jamKecil + "Dilihat " + esc(waktuLalu(s.dilihat)) + "</span>" +
      "      </div>" +
      '      <a href="' + esc(urlDetail(s)) + '" class="showroom-detail">Lihat Detail <span aria-hidden="true">→</span></a>' +
      "    </div>" +
      "  </div>" +
      "</article>"
    );
  }

  /* ---------- Panel riwayat (dibuat otomatis, tidak perlu markup di PHP) ---------- */

  function buatPanel() {
    const wadah = document.createElement("div");
    wadah.className = "rw-lapisan";
    wadah.hidden = true;
    wadah.innerHTML =
      '<div class="rw-latar" data-rw-tutup></div>' +
      '<aside class="rw-panel" role="dialog" aria-modal="true" aria-labelledby="rw-judul" tabindex="-1">' +
      '  <div class="rw-kepala">' +
      "    <div>" +
      '      <p class="rw-eyebrow">Riwayat</p>' +
      '      <h2 id="rw-judul">Showroom yang sudah dilihat</h2>' +
      "    </div>" +
      '    <button type="button" class="rw-tombol-tutup" data-rw-tutup aria-label="Tutup riwayat">' + IKON.tutup + "</button>" +
      "  </div>" +
      '  <div class="rw-isi" data-rw-isi></div>' +
      '  <div class="rw-kaki" data-rw-kaki></div>' +
      "</aside>";
    document.body.appendChild(wadah);

    wadah.addEventListener("click", function (e) {
      const t = e.target.closest("[data-rw-tutup],[data-rw-hapus],[data-rw-kosongkan]");
      if (!t) return;
      if (t.hasAttribute("data-rw-tutup")) return tutup();
      if (t.hasAttribute("data-rw-hapus")) {
        const uri = t.getAttribute("data-rw-hapus");
        simpan(baca().filter((s) => s.uri !== uri));
        return perbaruiSemua();
      }
      if (t.hasAttribute("data-rw-kosongkan")) {
        if (t.getAttribute("data-rw-yakin") === "1") {
          simpan([]);
          return perbaruiSemua();
        }
        t.setAttribute("data-rw-yakin", "1");
        t.textContent = "Yakin? Klik sekali lagi";
      }
    });

    return wadah;
  }

  let panel = null;

  function buka() {
    panel = panel || buatPanel();
    elemenSebelum = document.activeElement;
    render();
    panel.hidden = false;
    document.documentElement.classList.add("rw-terbuka");
    requestAnimationFrame(() => panel.classList.add("rw-tampil"));
    panel.querySelector(".rw-panel").focus();
  }

  function tutup() {
    if (!panel || panel.hidden) return;
    panel.classList.remove("rw-tampil");
    document.documentElement.classList.remove("rw-terbuka");
    setTimeout(() => { panel.hidden = true; }, 250);
    if (elemenSebelum && elemenSebelum.focus) elemenSebelum.focus();
  }

  function render() {
    if (!panel) return;
    const daftar = baca();
    const isi = panel.querySelector("[data-rw-isi]");
    const kaki = panel.querySelector("[data-rw-kaki]");

    if (daftar.length === 0) {
      isi.innerHTML =
        '<div class="rw-kosong">' + IKON.kosong +
        "<strong>Belum ada riwayat</strong>" +
        "<p>Showroom yang kamu buka detailnya akan muncul di sini, jadi kamu bisa kembali tanpa mencari ulang.</p>" +
        "</div>";
      kaki.innerHTML = "";
      kaki.hidden = true;
      return;
    }

    isi.innerHTML = '<div class="rw-daftar">' + daftar.map(kartuPanel).join("") + "</div>";
    kaki.hidden = false;
    kaki.innerHTML = '<button type="button" class="rw-tombol-teks" data-rw-kosongkan>Hapus semua riwayat</button>';
  }

  /* ---------- Badge navbar & bagian "Terakhir kamu lihat" di beranda ---------- */

  function perbaruiBadge() {
    const n = baca().length;
    document.querySelectorAll("[data-riwayat-jumlah]").forEach(function (b) {
      b.textContent = n > 9 ? "9+" : String(n);
      b.hidden = n === 0;
    });
  }

  function pasangNavigasiBeranda() {
    const bagian = document.querySelector("[data-riwayat-terakhir]");
    if (!bagian) return null;
    const wadah = bagian.querySelector("[data-riwayat-terakhir-isi]");
    const navigasi = bagian.querySelector("[data-riwayat-navigasi]");
    const kiri = bagian.querySelector("[data-riwayat-kiri]");
    const kanan = bagian.querySelector("[data-riwayat-kanan]");
    if (!wadah || !navigasi || !kiri || !kanan) return null;

    function perbarui() {
      const batas = wadah.scrollWidth - wadah.clientWidth;
      navigasi.hidden = batas <= 1;
      kiri.setAttribute("aria-disabled", String(wadah.scrollLeft <= 1));
      kanan.setAttribute("aria-disabled", String(wadah.scrollLeft >= batas - 1));
    }

    function geser(tombol, arah) {
      if (tombol.getAttribute("aria-disabled") === "true") return;
      const pertama = wadah.firstElementChild;
      const kedua = pertama?.nextElementSibling;
      if (!kedua) return;
      const langkah = kedua.getBoundingClientRect().left - pertama.getBoundingClientRect().left;
      const kurangiGerak = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
      wadah.scrollBy({ left: arah * langkah, behavior: kurangiGerak ? "instant" : "smooth" });
    }

    kiri.addEventListener("click", () => geser(kiri, -1));
    kanan.addEventListener("click", () => geser(kanan, 1));
    wadah.addEventListener("scroll", perbarui, { passive: true });
    if (typeof ResizeObserver === "function") {
      const pengamatUkuran = new ResizeObserver(perbarui);
      pengamatUkuran.observe(wadah);
    } else {
      window.addEventListener("resize", perbarui);
    }
    return perbarui;
  }

  function perbaruiBeranda() {
    const bagian = document.querySelector("[data-riwayat-terakhir]");
    if (!bagian) return;
    const daftar = baca().slice(0, MAKS_BERANDA);
    bagian.hidden = daftar.length === 0;
    const wadah = bagian.querySelector("[data-riwayat-terakhir-isi]");
    if (!wadah) return;
    const posisi = wadah.scrollLeft;
    wadah.innerHTML = daftar.map(kartu).join("");
    wadah.scrollLeft = posisi;
    perbaruiNavigasiBeranda?.();
  }

  function perbaruiSemua() {
    perbaruiBadge();
    perbaruiBeranda();
    render();
  }

  /* ---------- Mulai ---------- */

  function mulai() {
    catatKunjungan();
    perbaruiNavigasiBeranda = pasangNavigasiBeranda();
    perbaruiBadge();
    perbaruiBeranda();

    document.addEventListener("click", function (e) {
      const tombol = e.target.closest("[data-riwayat-buka]");
      if (!tombol) return;
      e.preventDefault();
      buka();
    });

    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape") tutup();
    });

    // Sinkron jika riwayat berubah di tab lain.
    window.addEventListener("storage", function (e) {
      if (e.key === KUNCI) perbaruiSemua();
    });

    // Perbarui teks "x menit lalu" tiap menit.
    setInterval(function () {
      perbaruiBeranda();
      if (panel && !panel.hidden) render();
    }, 60000);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", mulai);
  } else {
    mulai();
  }
})();
