/*
 * Peta & rute di halaman detail showroom.
 * - Mode "google": Google Maps JavaScript API (Routes API + Places API New), waktu tempuh memperhitungkan lalu lintas.
 * - Mode "osm"   : Leaflet + OpenStreetMap (tanpa API key). Waktu tempuh disesuaikan ke kecepatan rata-rata
 *                  lalu lintas kota supaya tidak terlalu optimistis.
 * Titik berangkat: GPS, ketuk di peta, atau ketik alamat.
 */
(() => {
  const canvas = document.querySelector("[data-detail-map]");
  const panel = document.querySelector("[data-rute]");
  if (!canvas || !panel) return;

  const ambil = (nama) => document.querySelector("[data-" + nama + "]");
  const el = {
    placeholder: ambil("detail-map-placeholder"),
    pesanPeta: ambil("detail-map-message"),
    bidik: ambil("rute-bidik"),
    pilihBar: ambil("rute-pilih-bar"),
    pilihTengah: ambil("rute-pilih-tengah"),
    pilihBatal: ambil("rute-pilih-batal"),
    gps: ambil("rute-gps"),
    peta: ambil("rute-peta"),
    form: ambil("rute-cari"),
    input: ambil("rute-cari-input"),
    saran: ambil("rute-saran"),
    status: ambil("rute-status"),
    hasil: ambil("rute-hasil"),
    durasi: ambil("rute-durasi"),
    durasiKet: ambil("rute-durasi-ket"),
    jarak: ambil("rute-jarak"),
    lewat: ambil("rute-lewat"),
    alternatif: ambil("rute-alternatif"),
    asal: ambil("rute-asal"),
    navigasi: ambil("rute-navigasi"),
    ganti: ambil("rute-ganti"),
    sumber: ambil("rute-sumber"),
  };

  const tujuan = { lat: Number(canvas.dataset.lat), lng: Number(canvas.dataset.long) };
  const nama = canvas.dataset.nama || "Showroom";
  const alamat = canvas.dataset.alamat || "";
  const kategori = ["mobil", "motor"].includes(canvas.dataset.kategori) ? canvas.dataset.kategori : "lain";
  const pakaiGoogle = canvas.dataset.mesin === "google" && Boolean(canvas.dataset.googleKey);

  // Kotak kira-kira Provinsi Sumatera Utara, untuk membatasi saran alamat.
  const KOTAK_SUMUT = { barat: 97.0, selatan: 0.1, timur: 100.5, utara: 4.4 };

  const formatKm = new Intl.NumberFormat("id-ID", { maximumFractionDigits: 1 });
  const formatMeter = new Intl.NumberFormat("id-ID", { maximumFractionDigits: 0 });

  let mesin;
  let sibuk = false;
  let memilih = false;
  let nomorPermintaan = 0;
  let perjalanan = null;

  /* ---------- Format ---------- */

  function formatJarak(meter) {
    return meter < 1000 ? formatMeter.format(meter) + " m" : formatKm.format(meter / 1000) + " km";
  }

  function bulatkanMenit(detik) {
    const menit = Math.max(1, Math.round(detik / 60));
    return menit > 30 ? Math.round(menit / 5) * 5 : menit;
  }

  function teksMenit(menit) {
    const jam = Math.floor(menit / 60);
    const sisa = menit % 60;
    if (!jam) return menit + " menit";
    return jam + " jam" + (sisa ? " " + sisa + " menit" : "");
  }

  function formatWaktu(detik) {
    return detik < 60 ? "< 1 menit" : teksMenit(bulatkanMenit(detik));
  }

  function formatRentang(cepat, lama) {
    const a = bulatkanMenit(cepat);
    const b = bulatkanMenit(lama);
    if (a >= b) return teksMenit(b);
    if (b < 60) return a + "–" + b + " menit";
    return teksMenit(a) + " – " + teksMenit(b);
  }

  function teksKoordinat({ lat, lng }) {
    return lat.toFixed(6) + "," + lng.toFixed(6);
  }

  /* ---------- Status & tampilan ---------- */

  function beriPesan(teks, gagal = false) {
    el.status.toggleAttribute("data-gagal", gagal);
    el.status.toggleAttribute("data-sibuk", false);
    el.status.textContent = teks;
  }

  function pesanSibuk(teks) {
    el.status.removeAttribute("data-gagal");
    el.status.setAttribute("data-sibuk", "");
    el.status.textContent = teks;
  }

  function aturSibuk(aktif, tombol = null) {
    sibuk = aktif;
    [el.gps, el.peta].forEach((t) => {
      t.setAttribute("aria-disabled", String(aktif));
      t.classList.toggle("vr-memuat", aktif && t === tombol);
    });
    panel.toggleAttribute("data-sibuk", aktif);
  }

  function aturPemilihan(aktif) {
    memilih = aktif;
    el.bidik.hidden = !aktif;
    el.pilihBar.hidden = !aktif;
    canvas.classList.toggle("vd-map-picking", aktif);
    el.peta.setAttribute("aria-pressed", String(aktif));
  }

  function tandaiPilihan(tombol) {
    [el.gps, el.peta].forEach((t) => t.classList.toggle("vr-aktif", t === tombol));
  }

  function tutupSaran() {
    el.saran.hidden = true;
    el.saran.replaceChildren();
    el.input.setAttribute("aria-expanded", "false");
    el.input.removeAttribute("aria-activedescendant");
    daftarSaran = [];
    sorotan = -1;
  }

  /* ---------- Hitung & tampilkan rute ---------- */

  function tampilkanRute(indeks) {
    const { rute, asal, label, teksAsal } = perjalanan;
    const dipilih = rute[indeks];
    perjalanan.indeks = indeks;

    mesin.gambar(rute, indeks, asal, label);

    el.durasi.textContent = dipilih.waktu;
    el.durasiKet.textContent = dipilih.ketWaktu;
    el.jarak.textContent = formatJarak(dipilih.jarak);
    el.lewat.textContent = dipilih.lewat ? "lewat " + dipilih.lewat : "rute mobil";
    el.asal.textContent = label;

    el.alternatif.replaceChildren();
    el.alternatif.hidden = rute.length < 2;
    rute.forEach((calon, i) => {
      const tombol = document.createElement("button");
      tombol.type = "button";
      tombol.className = "vr-alt";
      tombol.setAttribute("role", "radio");
      tombol.setAttribute("aria-checked", String(i === indeks));
      const judul = document.createElement("strong");
      judul.textContent = calon.waktuSingkat;
      const sub = document.createElement("span");
      sub.textContent = (i === 0 ? "Tercepat · " : "Alternatif · ") + formatJarak(calon.jarak);
      tombol.append(judul, sub);
      tombol.addEventListener("click", () => {
        if (!sibuk && perjalanan?.indeks !== i) tampilkanRute(i);
      });
      el.alternatif.append(tombol);
    });

    const url = new URL("https://www.google.com/maps/dir/");
    url.search = new URLSearchParams({
      api: "1",
      origin: teksAsal,
      destination: teksKoordinat(tujuan),
      travelmode: "driving",
    });
    el.navigasi.href = url.href;
    el.sumber.textContent = mesin.sumber;
    el.hasil.hidden = false;
  }

  async function hitungRute(asal, label, tombol = null, teksAsal = null) {
    const nomor = ++nomorPermintaan;
    aturSibuk(true, tombol);
    pesanSibuk("Menghitung rute ke showroom…");

    try {
      const rute = await mesin.hitung(asal);
      if (nomor !== nomorPermintaan) return;
      if (!rute.length) {
        beriPesan("Rute mobil belum ditemukan dari titik itu. Coba titik lain yang dekat jalan.", true);
        return;
      }
      perjalanan = { rute, asal, label, teksAsal: teksAsal || teksKoordinat(asal), indeks: 0 };
      tampilkanRute(0);
      beriPesan("");
      el.hasil.scrollIntoView({ block: "nearest", behavior: "smooth" });
    } catch (galat) {
      if (nomor !== nomorPermintaan) return;
      console.warn("[vroom rute]", galat);
      beriPesan("Rute belum bisa dimuat. Periksa koneksi internet lalu coba lagi.", true);
    } finally {
      if (nomor === nomorPermintaan) aturSibuk(false);
    }
  }

  /* ---------- Pilihan titik berangkat ---------- */

  el.gps.addEventListener("click", () => {
    if (sibuk) return;
    aturPemilihan(false);
    tandaiPilihan(el.gps);
    if (!window.isSecureContext || !navigator.geolocation) {
      beriPesan("Lokasi perangkat tidak tersedia di halaman ini. Coba ketik alamat atau tentukan di peta.", true);
      return;
    }
    const nomor = ++nomorPermintaan;
    aturSibuk(true, el.gps);
    pesanSibuk("Mencari lokasimu sekarang…");
    navigator.geolocation.getCurrentPosition(
      ({ coords }) => {
        if (nomor !== nomorPermintaan) return;
        hitungRute({ lat: coords.latitude, lng: coords.longitude }, "Lokasi kamu sekarang", el.gps);
      },
      (galat) => {
        if (nomor !== nomorPermintaan) return;
        aturSibuk(false);
        beriPesan(galat.code === 1
          ? "Izin lokasi ditolak. Izinkan lokasi di browser, atau ketik alamat awal."
          : "Lokasimu belum ditemukan. Coba lagi, atau ketik alamat awal.", true);
      },
      { enableHighAccuracy: true, timeout: 12000, maximumAge: 30000 },
    );
  });

  el.peta.addEventListener("click", () => {
    if (sibuk) return;
    if (memilih) {
      aturPemilihan(false);
      beriPesan("");
      return;
    }
    tandaiPilihan(el.peta);
    mesin.tutupPopup();
    aturPemilihan(true);
    beriPesan("Ketuk titik berangkat di peta, atau geser peta lalu pilih “Pakai titik tengah”.");
    canvas.scrollIntoView({ block: "nearest", behavior: "smooth" });
  });

  function pakaiTitikPeta(titik) {
    if (!memilih || !titik) return;
    aturPemilihan(false);
    hitungRute(titik, "Titik pilihanmu di peta", el.peta);
  }

  el.pilihTengah.addEventListener("click", () => pakaiTitikPeta(mesin.tengah()));
  el.pilihBatal.addEventListener("click", () => {
    aturPemilihan(false);
    tandaiPilihan(null);
    beriPesan("");
  });

  el.ganti.addEventListener("click", () => {
    nomorPermintaan++;
    perjalanan = null;
    mesin.bersihkan();
    el.hasil.hidden = true;
    tandaiPilihan(null);
    aturSibuk(false);
    beriPesan("");
    mesin.kembali();
    panel.scrollIntoView({ block: "nearest", behavior: "smooth" });
  });

  /* ---------- Ketik alamat + saran ---------- */

  let daftarSaran = [];
  let sorotan = -1;
  let jedaKetik;
  let nomorSaran = 0;

  function sorot(indeks) {
    const item = [...el.saran.children];
    sorotan = indeks;
    item.forEach((li, i) => li.setAttribute("aria-selected", String(i === indeks)));
    if (item[indeks]) {
      el.input.setAttribute("aria-activedescendant", item[indeks].id);
      item[indeks].scrollIntoView({ block: "nearest" });
    } else {
      el.input.removeAttribute("aria-activedescendant");
    }
  }

  function tampilkanSaran(saran) {
    daftarSaran = saran;
    sorotan = -1;
    el.saran.replaceChildren();
    if (!saran.length) {
      const kosong = document.createElement("li");
      kosong.className = "vr-saran-kosong";
      kosong.textContent = "Alamat tidak ditemukan. Coba tulis nama jalan atau tempat yang lebih lengkap.";
      el.saran.append(kosong);
    }
    saran.forEach((s, i) => {
      const li = document.createElement("li");
      li.id = "vr-saran-" + i;
      li.setAttribute("role", "option");
      li.setAttribute("aria-selected", "false");
      const judul = document.createElement("strong");
      judul.textContent = s.judul;
      li.append(judul);
      if (s.sub) {
        const sub = document.createElement("span");
        sub.textContent = s.sub;
        li.append(sub);
      }
      li.addEventListener("mousedown", (e) => e.preventDefault());
      li.addEventListener("click", () => pilihSaran(i));
      el.saran.append(li);
    });
    el.saran.hidden = false;
    el.input.setAttribute("aria-expanded", "true");
  }

  async function cariSaran(teks) {
    const nomor = ++nomorSaran;
    try {
      const saran = await mesin.saran(teks);
      if (nomor !== nomorSaran || el.input.value.trim() !== teks) return null;
      tampilkanSaran(saran);
      return saran;
    } catch (galat) {
      if (nomor !== nomorSaran) return null;
      console.warn("[vroom alamat]", galat);
      tutupSaran();
      beriPesan("Pencarian alamat sedang bermasalah. Coba lagi sebentar.", true);
      return null;
    }
  }

  async function pilihSaran(indeks) {
    const s = daftarSaran[indeks];
    if (!s || sibuk) return;
    tutupSaran();
    el.input.value = s.judul;
    aturPemilihan(false);
    tandaiPilihan(null);
    const nomor = ++nomorPermintaan;
    aturSibuk(true);
    pesanSibuk("Mencari lokasi alamat…");
    try {
      const titik = await s.ambil();
      if (nomor !== nomorPermintaan) return;
      const label = s.sub ? s.judul + ", " + s.sub : s.judul;
      hitungRute(titik, label, null, label);
    } catch (galat) {
      if (nomor !== nomorPermintaan) return;
      console.warn("[vroom alamat]", galat);
      aturSibuk(false);
      beriPesan("Lokasi alamat itu belum bisa diambil. Coba pilih saran lain.", true);
    }
  }

  el.input.addEventListener("input", () => {
    clearTimeout(jedaKetik);
    const teks = el.input.value.trim();
    if (teks.length < 3) {
      nomorSaran++;
      tutupSaran();
      return;
    }
    jedaKetik = setTimeout(() => cariSaran(teks), 350);
  });

  el.input.addEventListener("keydown", (e) => {
    const jumlah = daftarSaran.length;
    if (e.key === "ArrowDown" && jumlah) {
      e.preventDefault();
      sorot((sorotan + 1) % jumlah);
    } else if (e.key === "ArrowUp" && jumlah) {
      e.preventDefault();
      sorot((sorotan - 1 + jumlah) % jumlah);
    } else if (e.key === "Escape" && !el.saran.hidden) {
      e.preventDefault();
      tutupSaran();
    }
  });

  el.input.addEventListener("blur", () => setTimeout(tutupSaran, 150));

  el.form.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (sibuk) return;
    clearTimeout(jedaKetik);
    if (sorotan >= 0 && daftarSaran[sorotan]) {
      pilihSaran(sorotan);
      return;
    }
    const teks = el.input.value.trim();
    if (teks.length < 3) {
      beriPesan("Ketik alamat awal minimal 3 huruf.", true);
      el.input.focus();
      return;
    }
    pesanSibuk("Mencari alamat…");
    const saran = await cariSaran(teks);
    if (saran?.length) {
      beriPesan("");
      pilihSaran(0);
    } else if (saran) {
      beriPesan("Alamat tidak ditemukan. Coba tulis nama jalan atau tempat yang lebih lengkap.", true);
    }
  });

  /* ---------- Popup info showroom ---------- */

  function isiPopup() {
    const popup = document.createElement("div");
    popup.className = "vroom-map-popup";
    const judul = document.createElement("strong");
    judul.textContent = nama;
    popup.append(judul);
    if (alamat) {
      const teks = document.createElement("p");
      teks.textContent = alamat;
      popup.append(teks);
    }
    return popup;
  }

  function elemenTitikAsal() {
    const titik = document.createElement("span");
    titik.className = "vr-penanda-asal";
    return titik;
  }

  /* ============================================================
   * MESIN 1: Google Maps (Maps JS + Routes API + Places API New)
   * ============================================================ */

  function mesinGoogle() {
    const kunci = canvas.dataset.googleKey;
    const mapId = canvas.dataset.googleMapId || "DEMO_MAP_ID";
    let map;
    let info;
    let garis = [];
    let penandaAsal;
    let lib = {};
    let tokenSesi;
    let gagalAutentikasi = false;

    function muatSkrip() {
      if (window.google?.maps?.importLibrary) return Promise.resolve();
      return new Promise((selesai, gagal) => {
        const skrip = document.createElement("script");
        const batas = setTimeout(() => gagal(new Error("Waktu tunggu Google Maps habis")), 15000);
        window.vroomGoogleMapsSiap = () => {
          clearTimeout(batas);
          delete window.vroomGoogleMapsSiap;
          selesai();
        };
        skrip.onerror = () => {
          clearTimeout(batas);
          gagal(new Error("Skrip Google Maps gagal dimuat"));
        };
        skrip.src = "https://maps.googleapis.com/maps/api/js?" + new URLSearchParams({
          key: kunci, v: "weekly", loading: "async", callback: "vroomGoogleMapsSiap",
          language: "id", region: "ID",
        });
        skrip.async = true;
        document.head.append(skrip);
      });
    }

    const keLiteral = (p) => ({
      lat: typeof p.lat === "function" ? p.lat() : p.lat,
      lng: typeof p.lng === "function" ? p.lng() : p.lng,
    });

    return {
      sumber: "Waktu tempuh dari Google Maps, sudah memperhitungkan kondisi lalu lintas saat ini.",

      async siapkan() {
        window.gm_authFailure = () => {
          gagalAutentikasi = true;
          petaGagal("Google Maps menolak API key. Cek key, billing, API yang aktif, dan batasan domain di config-maps.php.");
        };
        await muatSkrip();
        const [maps, core, marker, routes] = await Promise.all([
          google.maps.importLibrary("maps"),
          google.maps.importLibrary("core"),
          google.maps.importLibrary("marker"),
          google.maps.importLibrary("routes"),
        ]);
        if (gagalAutentikasi) throw new Error("Autentikasi Google Maps gagal");
        lib = { ...maps, ...core, ...marker, ...routes };

        map = new lib.Map(canvas, {
          center: tujuan,
          zoom: 15,
          mapId,
          disableDefaultUI: true,
          zoomControl: true,
          fullscreenControl: true,
          mapTypeControl: true,
          mapTypeControlOptions: { position: google.maps.ControlPosition.TOP_LEFT },
          clickableIcons: false,
          gestureHandling: "cooperative",
        });

        info = new lib.InfoWindow({ content: isiPopup(), ariaLabel: nama });
        const pin = new lib.PinElement({
          background: kategori === "motor" ? "#146b81" : "#d90429",
          borderColor: "#ffffff",
          glyphColor: "#ffffff",
          scale: 1.15,
        });
        const penanda = new lib.AdvancedMarkerElement({
          map, position: tujuan, title: nama, content: pin.element, gmpClickable: true,
        });
        penanda.addEventListener("gmp-click", () => info.open({ map, anchor: penanda }));

        map.addListener("click", (e) => {
          if (memilih && e.latLng) pakaiTitikPeta(keLiteral(e.latLng));
        });
      },

      async hitung(asal) {
        const dasar = {
          origin: asal,
          destination: tujuan,
          travelMode: "DRIVING",
          routingPreference: "TRAFFIC_AWARE",
          language: "id",
          region: "id",
        };
        let hasil;
        try {
          hasil = await lib.Route.computeRoutes({
            ...dasar,
            computeAlternativeRoutes: true,
            fields: ["path", "distanceMeters", "durationMillis", "description"],
          });
        } catch (galat) {
          console.warn("[vroom rute] coba ulang tanpa alternatif", galat);
          hasil = await lib.Route.computeRoutes({
            ...dasar,
            fields: ["path", "distanceMeters", "durationMillis"],
          });
        }

        return (hasil?.routes ?? [])
          .filter((r) => r.path?.length >= 2 && Number.isFinite(r.distanceMeters) && Number.isFinite(r.durationMillis))
          .slice(0, 3)
          .map((r) => {
            const detik = r.durationMillis / 1000;
            return {
              jarak: r.distanceMeters,
              waktu: formatWaktu(detik),
              waktuSingkat: formatWaktu(detik),
              ketWaktu: "dengan lalu lintas saat ini",
              lewat: r.description || "",
              titik: r.path.map(keLiteral),
              detik,
            };
          })
          .sort((a, b) => a.detik - b.detik);
      },

      gambar(rute, indeks, asal, label) {
        this.bersihkan();
        const batas = new lib.LatLngBounds();
        rute.forEach((r, i) => {
          if (i === indeks) return;
          const alt = new lib.Polyline({
            map, path: r.titik, strokeColor: "#8d99ae", strokeOpacity: 0.85, strokeWeight: 5, zIndex: 1,
          });
          alt.addListener("click", () => {
            if (!sibuk) tampilkanRute(i);
          });
          garis.push(alt);
        });
        const utama = rute[indeks].titik;
        garis.push(new lib.Polyline({ map, path: utama, strokeColor: "#ffffff", strokeWeight: 9, zIndex: 2 }));
        garis.push(new lib.Polyline({ map, path: utama, strokeColor: "#d90429", strokeWeight: 5, zIndex: 3 }));
        penandaAsal = new lib.AdvancedMarkerElement({ map, position: asal, title: label, content: elemenTitikAsal() });
        utama.forEach((t) => batas.extend(t));
        batas.extend(asal).extend(tujuan);
        map.fitBounds(batas, 48);
      },

      bersihkan() {
        garis.forEach((g) => g.setMap(null));
        garis = [];
        if (penandaAsal) penandaAsal.map = null;
        penandaAsal = null;
      },

      kembali() {
        map.panTo(tujuan);
        map.setZoom(15);
      },

      tengah() {
        const c = map.getCenter();
        return c ? keLiteral(c) : null;
      },

      tutupPopup() {
        info?.close();
      },

      async saran(teks) {
        const { AutocompleteSuggestion, AutocompleteSessionToken } = await google.maps.importLibrary("places");
        tokenSesi ??= new AutocompleteSessionToken();
        const { suggestions } = await AutocompleteSuggestion.fetchAutocompleteSuggestions({
          input: teks,
          sessionToken: tokenSesi,
          language: "id",
          region: "id",
          includedRegionCodes: ["id"],
          locationBias: { center: tujuan, radius: 50000 },
        });
        return (suggestions ?? [])
          .map((s) => s.placePrediction)
          .filter(Boolean)
          .slice(0, 6)
          .map((p) => ({
            judul: p.mainText?.text || p.text?.text || String(p.text),
            sub: p.secondaryText?.text || "",
            ambil: async () => {
              const tempat = p.toPlace();
              await tempat.fetchFields({ fields: ["location", "displayName", "formattedAddress"] });
              tokenSesi = null;
              if (!tempat.location) throw new Error("Tempat tanpa koordinat");
              return keLiteral(tempat.location);
            },
          }));
      },
    };
  }

  /* ============================================================
   * MESIN 2: OpenStreetMap (tanpa API key)
   * ============================================================ */

  function mesinOsm() {
    const L = window.L;
    let map;
    let lapisan;

    // OSRM menghitung waktu dengan kecepatan jalan kosong, jadi 5–6 km bisa terbaca "6 menit".
    // Di sini waktunya disesuaikan ke kecepatan rata-rata yang realistis:
    // jalan kota ±18–25 km/jam, makin jauh (antarkota) bergeser ke ±40–55 km/jam.
    function estimasi(rute) {
      const km = rute.distance / 1000;
      const bobot = Math.min(1, Math.max(0, (km - 8) / 40));
      const lambat = 18 + bobot * (40 - 18);
      const cepat = 25 + bobot * (55 - 25);
      const detikCepat = Math.max(rute.duration * 1.1, (km / cepat) * 3600);
      const detikLama = Math.max(detikCepat, rute.duration * 1.3, (km / lambat) * 3600);
      return { detikCepat, detikLama };
    }

    function namaJalan(leg) {
      const pertama = (leg?.summary || "").split(",")[0].trim();
      return pertama;
    }

    return {
      sumber: "Waktu tempuh adalah perkiraan saat lalu lintas kota normal.",

      async siapkan() {
        if (!L) throw new Error("Leaflet tidak termuat");
        map = L.map(canvas, { scrollWheelZoom: false, maxZoom: 19 }).setView([tujuan.lat, tujuan.lng], 15);
        let ubinTerlihat = false;
        L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {
          maxZoom: 19,
          attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        })
          .on("tileload", () => {
            ubinTerlihat = true;
            el.pesanPeta.hidden = true;
          })
          .on("load", () => {
            if (ubinTerlihat) return;
            el.pesanPeta.textContent = "Peta dasar belum tersedia.";
            el.pesanPeta.hidden = false;
          })
          .addTo(map);

        map.on("focus", () => map.scrollWheelZoom.enable());
        map.on("blur", () => map.scrollWheelZoom.disable());

        const ikon = L.divIcon({
          className: "vroom-map-marker vroom-map-marker--" + kategori,
          html: '<span aria-hidden="true"></span>',
          iconSize: [28, 34],
          iconAnchor: [14, 29],
          popupAnchor: [0, -30],
        });
        const penanda = L.marker([tujuan.lat, tujuan.lng], { icon: ikon, title: nama, zIndexOffset: 500 }).addTo(map);
        penanda.getElement()?.setAttribute("aria-label", nama);
        penanda.bindPopup(isiPopup(), { maxWidth: 260 });
        lapisan = L.layerGroup().addTo(map);

        map.on("click", (e) => {
          if (memilih) pakaiTitikPeta({ lat: e.latlng.wrap().lat, lng: e.latlng.wrap().lng });
        });
      },

      async hitung(asal) {
        const koordinat = [asal, tujuan].map(({ lat, lng }) => lng.toFixed(6) + "," + lat.toFixed(6)).join(";");
        const url = "https://routing.openstreetmap.de/routed-car/route/v1/driving/" + koordinat
          + "?overview=full&geometries=geojson&alternatives=true&steps=true&radiuses=1000;1000";
        const pembatal = new AbortController();
        const batas = setTimeout(() => pembatal.abort(), 12000);
        try {
          const respons = await fetch(url, { signal: pembatal.signal, credentials: "omit" });
          const data = await respons.json();
          if (data.code === "NoRoute" || data.code === "NoSegment") return [];
          if (!respons.ok || data.code !== "Ok" || !Array.isArray(data.routes)) throw new Error("Respons OSRM tidak valid");

          return data.routes
            .filter((r) => r?.geometry?.coordinates?.length >= 2 && Number.isFinite(r.distance) && Number.isFinite(r.duration))
            .slice(0, 3)
            .map((r) => {
              const { detikCepat, detikLama } = estimasi(r);
              return {
                jarak: r.distance,
                waktu: formatRentang(detikCepat, detikLama),
                waktuSingkat: formatRentang(detikCepat, detikLama),
                ketWaktu: "perkiraan lalu lintas normal",
                lewat: namaJalan(r.legs?.[0]),
                titik: r.geometry.coordinates.map(([lng, lat]) => [lat, lng]),
                detik: detikCepat,
              };
            })
            .sort((a, b) => a.detik - b.detik);
        } finally {
          clearTimeout(batas);
        }
      },

      gambar(rute, indeks, asal, label) {
        lapisan.clearLayers();
        rute.forEach((r, i) => {
          if (i === indeks) return;
          L.polyline(r.titik, { color: "#8d99ae", weight: 5, opacity: 0.8 })
            .on("click", () => {
              if (!sibuk) tampilkanRute(i);
            })
            .addTo(lapisan);
        });
        const utama = rute[indeks].titik;
        L.polyline(utama, { color: "#fff", weight: 9, opacity: 0.95, interactive: false }).addTo(lapisan);
        L.polyline(utama, { color: "#d90429", weight: 5, opacity: 1, interactive: false }).addTo(lapisan);
        L.marker([asal.lat, asal.lng], {
          icon: L.divIcon({ className: "vr-penanda-asal-leaflet", html: '<span class="vr-penanda-asal"></span>', iconSize: [22, 22] }),
          title: label,
          keyboard: false,
        }).addTo(lapisan);
        const batas = L.latLngBounds(utama).extend([asal.lat, asal.lng]).extend([tujuan.lat, tujuan.lng]);
        map.fitBounds(batas, { padding: [40, 40], maxZoom: 17 });
      },

      bersihkan() {
        lapisan?.clearLayers();
      },

      kembali() {
        map.setView([tujuan.lat, tujuan.lng], 15);
      },

      tengah() {
        const c = map.getCenter().wrap();
        return { lat: c.lat, lng: c.lng };
      },

      tutupPopup() {
        map.closePopup();
      },

      async saran(teks) {
        const url = new URL("https://photon.komoot.io/api/");
        url.search = new URLSearchParams({
          q: teks,
          limit: "6",
          lat: String(tujuan.lat),
          lon: String(tujuan.lng),
          bbox: [KOTAK_SUMUT.barat, KOTAK_SUMUT.selatan, KOTAK_SUMUT.timur, KOTAK_SUMUT.utara].join(","),
        });
        const respons = await fetch(url, { credentials: "omit" });
        if (!respons.ok) throw new Error("Photon " + respons.status);
        const data = await respons.json();
        const sudah = new Set();
        return (data.features ?? [])
          .filter((f) => Array.isArray(f.geometry?.coordinates))
          .map((f) => {
            const p = f.properties ?? {};
            const jalan = [p.street, p.housenumber].filter(Boolean).join(" ");
            const judul = p.name || jalan || p.district || p.city || "Lokasi";
            const sub = [p.name ? jalan : "", p.district, p.city || p.county, p.state]
              .filter((bagian, i, semua) => bagian && bagian !== judul && semua.indexOf(bagian) === i)
              .join(", ");
            const [lng, lat] = f.geometry.coordinates;
            return { judul, sub, ambil: async () => ({ lat, lng }) };
          })
          .filter((s) => {
            const kunci = s.judul + "|" + s.sub;
            if (sudah.has(kunci)) return false;
            sudah.add(kunci);
            return true;
          });
      },
    };
  }

  /* ---------- Mulai ---------- */

  function petaGagal(teks) {
    el.placeholder.textContent = "Peta belum bisa dimuat.";
    el.placeholder.hidden = false;
    el.pesanPeta.textContent = teks;
    el.pesanPeta.hidden = false;
    panel.hidden = true;
  }

  async function mulai() {
    mesin = pakaiGoogle ? mesinGoogle() : mesinOsm();
    try {
      await mesin.siapkan();
      el.placeholder.hidden = true;
      panel.hidden = false;
    } catch (galat) {
      console.warn("[vroom peta]", galat);
      petaGagal(pakaiGoogle
        ? "Google Maps belum bisa dimuat. Cek API key di config-maps.php, atau buka lokasi lewat tombol Google Maps di atas."
        : "Peta belum bisa dimuat. Lokasi showroom masih bisa dibuka lewat tombol Google Maps di atas.");
    }
  }

  if ("IntersectionObserver" in window) {
    const pengamat = new IntersectionObserver((entri) => {
      if (!entri.some((e) => e.isIntersecting)) return;
      pengamat.disconnect();
      mulai();
    }, { rootMargin: "200px" });
    pengamat.observe(canvas);
  } else {
    mulai();
  }
})();
