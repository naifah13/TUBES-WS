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
    const hasil = panel.querySelector("[data-route-result]");
    const jarak = panel.querySelector("[data-route-distance]");
    const durasi = panel.querySelector("[data-route-duration]");
    const titikAwal = panel.querySelector("[data-route-origin]");
    const aksi = panel.querySelector("[data-route-actions]");
    const pilihan = panel.querySelector("[data-route-picking]");
    const lokasi = panel.querySelector("[data-route-locate]");
    const pilih = panel.querySelector("[data-route-pick]");
    const tengah = panel.querySelector("[data-route-center]");
    const batal = panel.querySelector("[data-route-cancel]");
    const status = panel.querySelector("[data-route-status]");
    const lapisan = L.layerGroup().addTo(map);
    const formatKm = new Intl.NumberFormat("id-ID", { maximumFractionDigits: 1 });
    const formatMeter = new Intl.NumberFormat("id-ID", { maximumFractionDigits: 0 });
    let memilih = false;
    let sibuk = false;
    let waktuPermintaan = -Infinity;

    function beriPesan(teks, gagal = false) {
      status.toggleAttribute("data-route-error", gagal);
      status.textContent = teks;
    }

    function aturSibuk(aktif) {
      sibuk = aktif;
      lokasi.setAttribute("aria-disabled", String(aktif));
      pilih.setAttribute("aria-disabled", String(aktif));
    }

    function aturPemilihan(aktif) {
      memilih = aktif;
      aksi.hidden = aktif;
      pilihan.hidden = !aktif;
      crosshair.hidden = !aktif;
      canvas.classList.toggle("vd-map-picking", aktif);
      if (aktif) {
        canvas.setAttribute("aria-describedby", status.id);
      } else {
        canvas.removeAttribute("aria-describedby");
      }
    }

    async function hitungRute(asal, labelAsal) {
      aturSibuk(true);
      hasil.hidden = true;
      lapisan.clearLayers();
      beriPesan("Menghitung rute ke showroom…");

      // Beri jeda antarpermintaan rute.
      const jeda = Math.ceil(1000 - (performance.now() - waktuPermintaan));
      if (jeda > 0) await new Promise((selesai) => setTimeout(selesai, jeda));

                  const koordinat = [asal, tujuan].map(
        ({ lat, lng }) => lng.toFixed(6) + "," + lat.toFixed(6),
      );
      const url = "https://routing.openstreetmap.de/routed-car/route/v1/driving/" + koordinat.join(";")
        + "?overview=full&geometries=geojson&generate_hints=false&radiuses=1000;1000";

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
          beriPesan("Rute mobil belum ditemukan. Coba pilih titik awal yang lebih dekat ke jalan.", true);
          return;
        }

        const rute = data.routes?.[0];
        const garis = rute?.geometry?.coordinates;
        if (!respons.ok || data.code !== "Ok" || rute?.geometry?.type !== "LineString"
          || !Array.isArray(garis) || garis.length < 2
          || !Number.isFinite(rute.distance) || rute.distance < 0
          || !Number.isFinite(rute.duration) || rute.duration < 0) {
          throw new Error("Respons rute tidak valid");
        }

        const titik = garis.map(([lng, lat]) => [lat, lng]);
        L.polyline(titik, { color: "#fff", weight: 9, opacity: 0.9, interactive: false }).addTo(lapisan);
        L.polyline(titik, { color: "#4f46c7", weight: 5, opacity: 1, interactive: false }).addTo(lapisan);
        L.circleMarker(asal, {
          radius: 8, color: "#fff", weight: 3, fillColor: "#4f46c7", fillOpacity: 1,
        }).bindTooltip(labelAsal).addTo(lapisan);

        const batas = L.latLngBounds(titik).extend(asal).extend(tujuan);
        map.fitBounds(batas, { padding: [32, 32], maxZoom: 17, animate: false });

        jarak.textContent = rute.distance < 1000
          ? formatMeter.format(rute.distance) + " m"
          : formatKm.format(rute.distance / 1000) + " km";
                // Batasi penyesuaian agar tidak terus bertambah pada perjalanan jauh.
        const jarakAcuanKm = Math.min(rute.distance / 1000, 10);
        const detikCepat = Math.max(rute.duration, jarakAcuanKm * 3600 / 30);
        const detikSantai = Math.max(rute.duration, jarakAcuanKm * 3600 / 25);
        const menitCepat = Math.ceil(detikCepat / 60);
        const menitSantai = Math.ceil(detikSantai / 60);

        if (detikSantai < 60) {
          durasi.textContent = "< 1 menit";
        } else if (menitCepat !== menitSantai) {
          durasi.textContent = `${menitCepat}–${menitSantai} menit`;
        } else {
          const jam = Math.floor(menitSantai / 60);
          const sisaMenit = menitSantai % 60;
          const bagianJam = jam ? jam + " jam" : "";
          const bagianMenit = sisaMenit ? sisaMenit + " menit" : "";
          durasi.textContent = [bagianJam, bagianMenit].filter(Boolean).join(" ");
        }
        titikAwal.textContent = labelAsal;
        hasil.hidden = false;
        beriPesan("Rute ditampilkan di peta.");
      } catch {
        lapisan.clearLayers();
        beriPesan("Rute belum bisa dimuat. Coba lagi sebentar.", true);
      } finally {
        clearTimeout(batasWaktu);
        aturSibuk(false);
      }
    }

    lokasi.addEventListener("click", () => {
      if (sibuk) return;
      if (!window.isSecureContext || !navigator.geolocation) {
        beriPesan("Lokasi perangkat tidak tersedia di halaman ini. Pilih titik awal di peta.", true);
        return;
      }

      aturSibuk(true);
      beriPesan("Mencari lokasi perangkat…");
      navigator.geolocation.getCurrentPosition(
        ({ coords }) => hitungRute(L.latLng(coords.latitude, coords.longitude), "Lokasi kamu"),
        (error) => {
          aturSibuk(false);
          beriPesan(error.code === 1
            ? "Izin lokasi ditolak. Kamu bisa pilih titik awal di peta."
            : "Lokasi perangkat belum ditemukan. Coba lagi atau pilih titik awal di peta.", true);
        },
        { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 },
      );
    });

    pilih.addEventListener("click", () => {
      if (sibuk) return;
      map.closePopup();
      aturPemilihan(true);
      beriPesan("Ketuk titik awal pada peta atau geser peta lalu gunakan titik tengah.");
      canvas.scrollIntoView({ block: "nearest" });
      canvas.focus({ preventScroll: true });
    });

    function mulaiDariPeta(titik) {
      aturPemilihan(false);
      pilih.focus({ preventScroll: true });
      hitungRute(titik.wrap(), "Titik pilihanmu");
    }

    map.on("click", (event) => {
      if (memilih) mulaiDariPeta(event.latlng);
    });

    tengah.addEventListener("click", () => {
      if (memilih) mulaiDariPeta(map.getCenter());
    });

    batal.addEventListener("click", () => {
      aturPemilihan(false);
      beriPesan("");
      pilih.focus();
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