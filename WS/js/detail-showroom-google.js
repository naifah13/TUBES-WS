(() => {
  const canvas = document.querySelector("[data-detail-map]");
  if (!canvas?.dataset.googleMapsKey) return;

  const apiKey = canvas.dataset.googleMapsKey;
  const tujuan = { lat: Number(canvas.dataset.lat), lng: Number(canvas.dataset.long) };
  const nama = canvas.dataset.nama;
  const alamat = canvas.dataset.alamat;
  const kategori = canvas.dataset.kategori;
  const placeholder = document.querySelector("[data-detail-map-placeholder]");
  const message = document.querySelector("[data-detail-map-message]");
  const panel = document.querySelector("[data-detail-route]");
  let autentikasiGagal = false;

  function siapkanRute(map, info, { LatLngBounds, Polyline, AdvancedMarkerElement, PinElement }) {
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
    const tautan = document.querySelector("[data-detail-google-directions]");
    const tautanAwal = tautan.href;
    let garisRute = [];
    let markerAsal;
    const formatKm = new Intl.NumberFormat("id-ID", { maximumFractionDigits: 1 });
    const formatMeter = new Intl.NumberFormat("id-ID", { maximumFractionDigits: 0 });
    let memilih = false;
    let sibuk = false;

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

    function formatEstimasiRute(detik) {
      if (detik < 60) return "< 1 menit";

      const menit = Math.round(detik / 60);
      const jam = Math.floor(menit / 60);
      const sisa = menit % 60;
      if (!jam) return menit + " menit";
      return jam + " jam" + (sisa ? " " + sisa + " menit" : "");
    }

    function bersihkanRute() {
      garisRute.forEach((garis) => garis.setMap(null));
      garisRute = [];
      if (markerAsal) {
        markerAsal.map = null;
        markerAsal = undefined;
      }
      tautan.href = tautanAwal;
    }

    async function hitungRute(asal, labelAsal) {
      aturSibuk(true);
      hasil.hidden = true;
      bersihkanRute();
      beriPesan("Menghitung rute ke showroom…");

      try {
        const { Route } = await google.maps.importLibrary("routes");
        const { routes } = await Route.computeRoutes({
          origin: asal,
          destination: tujuan,
          travelMode: "DRIVING",
          routingPreference: "TRAFFIC_AWARE_OPTIMAL",
          fields: ["path", "distanceMeters", "durationMillis", "warnings"],
          polylineQuality: "HIGH_QUALITY",
        });
        if (autentikasiGagal) return;

        const rute = routes?.[0];
        if (!rute) {
          beriPesan("Rute mobil belum ditemukan. Coba pilih titik awal yang lebih dekat ke jalan.", true);
          return;
        }

        const { path, distanceMeters, durationMillis } = rute;
        if (!path?.length || !Number.isFinite(distanceMeters) || distanceMeters < 0
          || !Number.isFinite(durationMillis) || durationMillis < 0) {
          throw new Error("Data rute Google Maps belum lengkap");
        }

        garisRute.push(new Polyline({
          map, path, strokeColor: "#fff", strokeOpacity: 0.9, strokeWeight: 9, clickable: false,
        }));
        garisRute.push(new Polyline({
          map, path, strokeColor: "#4f46c7", strokeWeight: 5, clickable: false,
        }));
        markerAsal = new AdvancedMarkerElement({ map, position: asal, title: labelAsal });
        markerAsal.append(new PinElement({ background: "#4f46c7", borderColor: "#fff", glyphColor: "#fff" }));

        const batas = new LatLngBounds();
        batas.extend(asal).extend(tujuan);
        path.forEach((titik) => batas.extend(titik));
        map.fitBounds(batas, 32);

        jarak.textContent = distanceMeters < 1000
          ? formatMeter.format(distanceMeters) + " m"
          : formatKm.format(distanceMeters / 1000) + " km";
        durasi.textContent = formatEstimasiRute(durationMillis / 1000);
        titikAwal.textContent = labelAsal;

        const pembanding = new URL("https://www.google.com/maps/dir/");
        pembanding.search = new URLSearchParams({
          api: "1",
          origin: asal.lat + "," + asal.lng,
          destination: tujuan.lat + "," + tujuan.lng,
          travelmode: "driving",
        });
        tautan.href = pembanding.href;
        hasil.hidden = false;
        beriPesan(rute.warnings?.join(" ") || "Rute Google Maps ditampilkan di peta.");
      } catch {
        bersihkanRute();
        beriPesan("Rute Google Maps belum bisa dimuat. Coba lagi atau gunakan tautan di bawah peta.", true);
      } finally {
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
        ({ coords }) => hitungRute({ lat: coords.latitude, lng: coords.longitude }, "Lokasi kamu"),
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
      info.close();
      aturPemilihan(true);
      beriPesan("Ketuk titik awal pada peta atau geser peta lalu gunakan titik tengah.");
      canvas.scrollIntoView({ block: "nearest" });
      canvas.focus({ preventScroll: true });
    });

    function mulaiDariPeta(titik) {
      aturPemilihan(false);
      pilih.focus({ preventScroll: true });
      hitungRute(titik.toJSON(), "Titik pilihanmu");
    }

    map.addListener("click", ({ latLng }) => {
      if (memilih && latLng) mulaiDariPeta(latLng);
    });

    tengah.addEventListener("click", () => {
      const titik = map.getCenter();
      if (memilih && titik) mulaiDariPeta(titik);
    });

    batal.addEventListener("click", () => {
      aturPemilihan(false);
      beriPesan("");
      pilih.focus();
    });

    panel.hidden = false;
  }

  function petaGagal(teks) {
    placeholder.textContent = "Peta belum bisa dimuat.";
    message.textContent = teks;
    message.hidden = false;
    panel.hidden = true;
  }

  function muatGoogleMaps() {
    if (window.google?.maps?.importLibrary) return Promise.resolve();

    return new Promise((resolve, reject) => {
      const script = document.createElement("script");
      const batasWaktu = setTimeout(() => selesai(new Error("Waktu tunggu Google Maps habis")), 15000);

      function selesai(error) {
        clearTimeout(batasWaktu);
        delete window.vroomGoogleMapsReady;
        script.onerror = null;
        if (error) {
          script.remove();
          reject(error);
        } else {
          resolve();
        }
      }

      window.vroomGoogleMapsReady = () => selesai();
      script.onerror = () => selesai(new Error("Google Maps gagal dimuat"));
      const params = new URLSearchParams({
        key: apiKey, v: "weekly", loading: "async",
        callback: "vroomGoogleMapsReady", language: "id", region: "ID",
      });
      script.src = "https://maps.googleapis.com/maps/api/js?" + params;
      script.async = true;
      document.head.append(script);
    });
  }

  async function mulai() {
    window.gm_authFailure = () => {
      autentikasiGagal = true;
      petaGagal("Google Maps belum bisa dibuka. Periksa API key, billing, dan izin domain.");
    };

    try {
      await muatGoogleMaps();
      const [{ Map, InfoWindow, Polyline }, { LatLngBounds }, { AdvancedMarkerElement, PinElement }] =
        await Promise.all([
          google.maps.importLibrary("maps"),
          google.maps.importLibrary("core"),
          google.maps.importLibrary("marker"),
        ]);
      if (autentikasiGagal) return;

      const map = new Map(canvas, {
        center: tujuan,
        zoom: 16,
        mapId: canvas.dataset.googleMapId || "DEMO_MAP_ID",
        disableDefaultUI: true,
        zoomControl: true,
        clickableIcons: false,
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

      const info = new InfoWindow({ content: popup, ariaLabel: nama });
      const marker = new AdvancedMarkerElement({
        map, position: tujuan, title: nama, gmpClickable: true,
      });
      const warna = kategori === "motor" ? "#146b81" : "#d90429";
      marker.append(new PinElement({ background: warna, borderColor: "#fff", glyphColor: "#fff" }));
      marker.addEventListener("gmp-click", () => info.open({ map, anchor: marker }));

      placeholder.hidden = true;
      siapkanRute(map, info, { LatLngBounds, Polyline, AdvancedMarkerElement, PinElement });
    } catch {
      petaGagal("Google Maps belum bisa dimuat. Lokasi showroom masih bisa dibuka lewat tautan di bawah peta.");
    }
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