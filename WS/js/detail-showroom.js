(() => {
  const canvas = document.querySelector("[data-detail-map]");
  if (!canvas) return;

  const placeholder = document.querySelector("[data-detail-map-placeholder]");
  const message = document.querySelector("[data-detail-map-message]");
  const lat = Number(canvas.dataset.lat);
  const long = Number(canvas.dataset.long);
  const nama = canvas.dataset.nama;
  const alamat = canvas.dataset.alamat;
  const kategori = ["mobil", "motor"].includes(canvas.dataset.kategori)
    ? canvas.dataset.kategori
    : "lain";

  function siapkanRute(L, map, tujuan) {
    const panel = document.querySelector("[data-detail-route]");
    const crosshair = document.querySelector("[data-route-crosshair]");
    const tautan = document.querySelector("[data-detail-google-directions]");
    const hasil = panel.querySelector("[data-route-result]");
    const jarak = panel.querySelector("[data-route-distance]");
    const durasi = panel.querySelector("[data-route-duration]");
    const labelDurasi = panel.querySelector("[data-route-time-label]");
    const catatanDurasi = panel.querySelector("[data-route-time-note]");
    const titikAwal = panel.querySelector("[data-route-origin]");
    const aksi = panel.querySelector("[data-route-actions]");
    const pilihan = panel.querySelector("[data-route-picking]");
    const lokasi = panel.querySelector("[data-route-locate]");
    const pilih = panel.querySelector("[data-route-pick]");
    const pilihLewat = panel.querySelector("[data-route-via]");
    const hapusLewat = panel.querySelector("[data-route-via-clear]");
    const daftarRute = panel.querySelector("[data-route-options]");
    const pemilihRute = panel.querySelector("[data-route-select]");
    const tengah = panel.querySelector("[data-route-center]");
    const batal = panel.querySelector("[data-route-cancel]");
    const status = panel.querySelector("[data-route-status]");
    const lapisan = L.layerGroup().addTo(map);
    const formatKm = new Intl.NumberFormat("id-ID", { maximumFractionDigits: 1 });
    const formatMeter = new Intl.NumberFormat("id-ID", { maximumFractionDigits: 0 });
    let memilih = "";
    let sibuk = false;
    let waktuPermintaan = -Infinity;
    let perjalanan;

    function beriPesan(teks, gagal = false) {
      status.toggleAttribute("data-route-error", gagal);
      status.textContent = teks;
    }

    function aturSibuk(aktif) {
      sibuk = aktif;
      [lokasi, pilih, pilihLewat, hapusLewat].forEach((tombol) => {
        tombol.setAttribute("aria-disabled", String(aktif));
      });
      pemilihRute.disabled = aktif;
    }

    function aturPemilihan(mode) {
      memilih = mode;
      const aktif = mode !== "";
      aksi.hidden = aktif;
      pilihan.hidden = !aktif;
      crosshair.hidden = !aktif;
      daftarRute.hidden = aktif || (perjalanan?.rute.length ?? 0) < 2;
      canvas.classList.toggle("vd-map-picking", aktif);
      if (aktif) {
        canvas.setAttribute("aria-describedby", status.id);
      } else {
        canvas.removeAttribute("aria-describedby");
      }
    }

    function formatJarak(meter) {
      return meter < 1000 ? formatMeter.format(meter) + " m" : formatKm.format(meter / 1000) + " km";
    }

    function formatWaktu(detik) {
      if (detik < 60) return "< 1 menit";

      const menit = Math.round(detik / 60);
      const jam = Math.floor(menit / 60);
      const sisa = menit % 60;
      if (!jam) return menit + " menit";
      return jam + " jam" + (sisa ? " " + sisa + " menit" : "");
    }

    function siapkanEstimasi(rute) {
      const dasar = rute.duration;
      const bobot = Math.min(1, Math.max(0, (rute.distance - 20000) / 60000));
      const estimasiDasar = { teks: formatWaktu(dasar), disesuaikan: false };
      if (bobot === 0) return estimasiDasar;

      let jarakTerbaca = 0;
      let meterJalanUmum = 0;
      let detikJalanUmum = 0;

      for (const { steps } of rute.legs ?? []) {
        for (const ruas of steps ?? []) {
          const { distance, duration } = ruas;
          if (!Number.isFinite(distance) || distance <= 0 || !Number.isFinite(duration) || duration < 0) continue;

          jarakTerbaca += distance;
          const kelas = ruas.intersections?.[0]?.classes ?? [];
          const tol = kelas.includes("motorway") || /^\s*(?:jalan\s+)?tol\b/i.test(ruas.name ?? "");
          if (tol || (ruas.mode && ruas.mode !== "driving")) continue;

          meterJalanUmum += distance;
          detikJalanUmum += duration;
        }
      }

      // Jika rincian ruas tidak mencakup hampir seluruh rute, tampilkan estimasi dasar OSRM.
      if (jarakTerbaca < rute.distance * 0.95) return estimasiDasar;

      function waktuDenganAcuan(kmPerJam) {
        const detikAcuan = meterJalanUmum * 3.6 / kmPerJam;
        return dasar + bobot * Math.max(0, detikAcuan - detikJalanUmum);
      }

      const cepat = waktuDenganAcuan(55);
      const santai = waktuDenganAcuan(45);
      if (santai === dasar) return estimasiDasar;
      const awal = formatWaktu(cepat);
      const akhir = formatWaktu(santai);
      return { teks: awal === akhir ? akhir : awal + " – " + akhir, disesuaikan: true };
    }

    function tampilkanRute(indeks) {
      const { rute, asal, labelAsal, lewat, titikPermintaan, catatan } = perjalanan;
      const dipilih = rute[indeks];
      const titik = dipilih.geometry.coordinates.map(([lng, lat]) => [lat, lng]);
      lapisan.clearLayers();
      L.polyline(titik, { color: "#fff", weight: 9, opacity: 0.9, interactive: false }).addTo(lapisan);
      L.polyline(titik, { color: "#4f46c7", weight: 5, opacity: 1, interactive: false }).addTo(lapisan);
      L.circleMarker(asal, {
        radius: 8, color: "#fff", weight: 3, fillColor: "#4f46c7", fillOpacity: 1,
      }).bindTooltip(labelAsal).addTo(lapisan);

      const batas = L.latLngBounds(titik).extend(asal).extend(tujuan);
      if (lewat) {
        L.circleMarker(lewat, {
          radius: 6, color: "#fff", weight: 2, fillColor: "#7355a8", fillOpacity: 1,
        }).bindTooltip("Titik lewat").addTo(lapisan);
        batas.extend(lewat);
      }
      map.fitBounds(batas, { padding: [32, 32], maxZoom: 17, animate: false });

      jarak.textContent = formatJarak(dipilih.distance);
      durasi.textContent = dipilih.estimasi.teks;
      labelDurasi.textContent = dipilih.estimasi.disesuaikan ? "Perkiraan waktu berkendara" : "Estimasi waktu OSRM";
      catatanDurasi.hidden = !dipilih.estimasi.disesuaikan;
      titikAwal.textContent = labelAsal;
      hasil.hidden = false;
      pilihLewat.hidden = false;
      hapusLewat.hidden = !lewat;

      const pembanding = new URL("https://www.google.com/maps/dir/");
      const posisi = titikPermintaan.map(([lng, lat]) => lat + "," + lng);
      pembanding.searchParams.set("api", "1");
      pembanding.searchParams.set("origin", posisi[0]);
      pembanding.searchParams.set("destination", posisi[posisi.length - 1]);
      pembanding.searchParams.set("travelmode", "driving");
      if (lewat) pembanding.searchParams.set("waypoints", posisi[1]);
      tautan.href = pembanding.href;

      beriPesan(catatan || (lewat ? "Rute melewati titik pilihanmu." : "Rute OSRM ditampilkan di peta."));
    }

    async function hitungRute(asal, labelAsal, lewat = null) {
      aturSibuk(true);
      beriPesan("Menghitung rute ke showroom…");

      const jeda = Math.ceil(1000 - (performance.now() - waktuPermintaan));
      if (jeda > 0) await new Promise((selesai) => setTimeout(selesai, jeda));

      const titikPermintaan = (lewat ? [asal, lewat, tujuan] : [asal, tujuan])
        .map(({ lat, lng }) => [lng.toFixed(6), lat.toFixed(6)]);
      const koordinat = titikPermintaan.map((titik) => titik.join(",")).join(";");
      const pilihanJalan = lewat ? "radiuses=1000;100;1000" : "radiuses=1000;1000&alternatives=true";
      const url = "https://routing.openstreetmap.de/routed-car/route/v1/driving/" + koordinat
        + "?overview=full&geometries=geojson&generate_hints=false&steps=true&" + pilihanJalan;

      const pembatal = new AbortController();
      const batasWaktu = setTimeout(() => pembatal.abort(), 12000);
      waktuPermintaan = performance.now();

      try {
        const respons = await fetch(url, {
          signal: pembatal.signal,
          credentials: "omit",
          cache: "no-store",
        });
        const data = await respons.json();

        if (data.code === "NoRoute" || data.code === "NoSegment") {
          beriPesan(lewat
            ? "Jalan lewat ini belum bisa digunakan. Coba pilih titik di atas jalan yang bisa dilalui mobil."
            : "Rute mobil belum ditemukan. Coba pilih titik awal yang lebih dekat ke jalan.", true);
          return;
        }
        if (!respons.ok || data.code !== "Ok" || !Array.isArray(data.routes)) {
          throw new Error("Respons rute tidak valid");
        }

        const rute = data.routes.filter((calon) =>
          calon?.geometry?.type === "LineString"
          && Array.isArray(calon.geometry.coordinates)
          && calon.geometry.coordinates.length >= 2
          && calon.geometry.coordinates.every((titik) =>
            Array.isArray(titik) && Number.isFinite(titik[0]) && Number.isFinite(titik[1]),
          )
          && Number.isFinite(calon.distance) && calon.distance >= 0
          && Number.isFinite(calon.duration) && calon.duration >= 0,
        ).map((calon) => ({ ...calon, estimasi: siapkanEstimasi(calon) }));
        if (!rute.length) throw new Error("Tidak ada rute valid");

        const namaTitik = lewat ? ["Titik awal", "Titik lewat", "Tujuan"] : ["Titik awal", "Tujuan"];
        const catatan = namaTitik.map((nama, i) => {
          const jarakKeJalan = data.waypoints?.[i]?.distance;
          if (!Number.isFinite(jarakKeJalan) || jarakKeJalan < 100) return "";
          return nama + " dicocokkan ke jalan sejauh " + formatJarak(jarakKeJalan) + " dari pin.";
        }).filter(Boolean).join(" ");

        perjalanan = { rute, asal, labelAsal, lewat, titikPermintaan, catatan };
        pemilihRute.replaceChildren();
        rute.forEach((calon, indeks) => {
          const opsi = document.createElement("option");
          opsi.value = String(indeks);
          const namaOpsi = indeks === 0 ? "Rekomendasi OSRM" : "Alternatif " + indeks;
          opsi.textContent = namaOpsi + " · " + formatJarak(calon.distance) + " · " + calon.estimasi.teks;
          pemilihRute.append(opsi);
        });
        pemilihRute.value = "0";
        daftarRute.hidden = rute.length < 2;
        tampilkanRute(0);
      } catch {
        beriPesan("Rute belum bisa dimuat. Coba lagi sebentar.", true);
      } finally {
        clearTimeout(batasWaktu);
        aturSibuk(false);
      }
    }

    pemilihRute.addEventListener("change", () => {
      const indeks = Number(pemilihRute.value);
      if (!sibuk && perjalanan?.rute[indeks]) tampilkanRute(indeks);
    });

    lokasi.addEventListener("click", () => {
      if (sibuk) return;
      if (!window.isSecureContext || !navigator.geolocation) {
        beriPesan("Lokasi perangkat tidak tersedia di halaman ini. Tentukan titik berangkat di peta.", true);
        return;
      }

      aturSibuk(true);
      beriPesan("Mencari lokasi perangkat…");
      navigator.geolocation.getCurrentPosition(
        ({ coords }) => hitungRute(L.latLng(coords.latitude, coords.longitude), "Lokasi kamu"),
        (error) => {
          aturSibuk(false);
          beriPesan(error.code === 1
            ? "Izin lokasi ditolak. Kamu bisa tentukan titik berangkat di peta."
            : "Lokasi perangkat belum ditemukan. Coba lagi atau tentukan titik berangkat di peta.", true);
        },
        { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 },
      );
    });

    function mulaiPemilihan(mode) {
      if (sibuk) return;
      map.closePopup();
      aturPemilihan(mode);
      const label = mode === "lewat" ? "ruas jalan yang ingin dilewati" : "titik berangkat";
      beriPesan("Ketuk " + label + " pada peta atau geser peta lalu gunakan titik tengah.");
      canvas.scrollIntoView({ block: "nearest" });
      canvas.focus({ preventScroll: true });
    }

    pilih.addEventListener("click", () => mulaiPemilihan("awal"));
    pilihLewat.addEventListener("click", () => {
      if (perjalanan) mulaiPemilihan("lewat");
    });
    hapusLewat.addEventListener("click", () => {
      if (!sibuk && perjalanan?.lewat) hitungRute(perjalanan.asal, perjalanan.labelAsal);
    });

    function pilihDariPeta(titik) {
      const mode = memilih;
      aturPemilihan("");
      if (mode === "lewat") {
        pilihLewat.focus({ preventScroll: true });
        hitungRute(perjalanan.asal, perjalanan.labelAsal, titik.wrap());
      } else {
        pilih.focus({ preventScroll: true });
        hitungRute(titik.wrap(), "Titik pilihanmu");
      }
    }

    map.on("click", (event) => {
      if (memilih) pilihDariPeta(event.latlng);
    });
    tengah.addEventListener("click", () => {
      if (memilih) pilihDariPeta(map.getCenter());
    });
    batal.addEventListener("click", () => {
      const kembali = memilih === "lewat" ? pilihLewat : pilih;
      aturPemilihan("");
      beriPesan(perjalanan?.catatan || "");
      kembali.focus();
    });

    panel.hidden = false;
  }

  function mulai() {
    const L = window.L;
    if (!L) {
      placeholder.textContent = "Peta belum bisa dimuat.";
      message.textContent = "Lokasi showroom masih bisa dibuka di Google Maps.";
      message.hidden = false;
      return;
    }

    const map = L.map(canvas, { scrollWheelZoom: true, maxZoom: 19 }).setView([lat, long], 16);
    placeholder.hidden = true;

    let ubinTerlihat = false;
    L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        + ' · <a href="https://www.openstreetmap.org/fixthemap">Perbaiki peta</a>',
    })
      .on("tileload", () => {
        ubinTerlihat = true;
        message.hidden = true;
      })
      .on("load", () => {
        if (ubinTerlihat) return;
        message.textContent = "Peta dasar belum tersedia.";
        message.hidden = false;
      })
      .addTo(map);

    const icon = L.divIcon({
      className: "vroom-map-marker vroom-map-marker--" + kategori,
      html: '<span aria-hidden="true"></span>',
      iconSize: [28, 34],
      iconAnchor: [14, 29],
      popupAnchor: [0, -30],
    });

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

    const marker = L.marker([lat, long], { icon, title: nama }).addTo(map);
    marker.getElement()?.setAttribute("aria-label", nama);
    marker.bindPopup(popup, { maxWidth: 260 });
    siapkanRute(L, map, L.latLng(lat, long));
  }

  if ("IntersectionObserver" in window) {
    const observer = new IntersectionObserver((entries) => {
      if (!entries.some((entry) => entry.isIntersecting)) return;
      observer.disconnect();
      mulai();
    });
    observer.observe(canvas);
  } else {
    mulai();
  }
})();