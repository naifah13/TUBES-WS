(() => {
  const section = document.querySelector("[data-showroom-map]");
  if (!section) return;

  const canvas = section.querySelector("#peta-showroom");
  const status = section.querySelector("[data-map-status]");
  const placeholder = section.querySelector("[data-map-placeholder]");
  const tileStatus = section.querySelector("[data-map-tile-status]");
  const buttons = [...section.querySelectorAll("[data-map-category]")];
  const pusatSumut = [2.7, 98.8];

  function buatPopup(item) {
    const popup = document.createElement("div");
    popup.className = "vroom-map-popup";

    const label = document.createElement("span");
    label.className = "vroom-map-popup-category";
    label.textContent = [item.kategori, item.lokasi].filter(Boolean).join(" · ");
    if (label.textContent) popup.append(label);

    const nama = document.createElement("strong");
    nama.textContent = item.nama;
    popup.append(nama);

    if (item.alamat) {
      const alamat = document.createElement("p");
      alamat.textContent = item.alamat;
      popup.append(alamat);
    }

    const link = document.createElement("a");
    link.href = "detail.php?" + new URLSearchParams({ uri: item.uri });
    link.textContent = "Lihat detail showroom";
    popup.append(link);

    return popup;
  }

  function buatMarker(L, item) {
    const kategori = ["mobil", "motor"].includes(item.kategori) ? item.kategori : "lain";
    const icon = L.divIcon({
      className: "vroom-map-marker vroom-map-marker--" + kategori,
      html: '<span aria-hidden="true"></span>',
      iconSize: [22, 27],
      iconAnchor: [11, 24],
      popupAnchor: [0, -23],
    });

    const marker = L.marker([item.lat, item.long], {
      icon,
      title: item.nama,
      riseOnHover: true,
    });
    marker.on("add", () => marker.getElement()?.setAttribute("aria-label", item.nama));
    return marker.bindPopup(() => buatPopup(item), { maxWidth: 265 });
  }

  async function mulaiPeta() {
    const L = window.L;
    if (!L) {
      status.textContent = "Peta belum bisa dimuat. Periksa koneksi dan muat ulang halaman.";
      placeholder.textContent = "Peta tidak tersedia.";
      return;
    }

    const map = L.map(canvas, { scrollWheelZoom: true, maxZoom: 19 });
    placeholder.hidden = true;
    status.textContent = "Sedang memuat lokasi showroom…";

    const tiles = L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    });
    let tileTerlihat = false;
    tiles.on("tileload", () => {
      tileTerlihat = true;
      tileStatus.hidden = true;
    });
    tiles.on("load", () => {
      tileStatus.hidden = tileTerlihat;
    });
    tiles.addTo(map);

    let showrooms;
    try {
      const respons = await fetch(section.dataset.showroomMap);
      if (!respons.ok) throw new Error("Data peta gagal dimuat");
      const data = await respons.json();
      if (!Array.isArray(data.showrooms)) throw new Error("Format data peta keliru");
      showrooms = data.showrooms;
    } catch {
      map.setView(pusatSumut, 7);
      status.textContent = "Lokasi showroom belum bisa dimuat. Coba muat ulang halaman.";
      return;
    }

    const grup = L.featureGroup();
    grup.addTo(map);

    const pin = showrooms.map((item) => ({
      kategori: item.kategori,
      marker: buatMarker(L, item),
    }));

    function pilihKategori(kategori) {
      const tampil = kategori === "semua" ? pin : pin.filter((item) => item.kategori === kategori);

      buttons.forEach((button) => {
        button.setAttribute("aria-pressed", String(button.dataset.mapCategory === kategori));
      });

      map.closePopup();
      grup.clearLayers();
      tampil.forEach((item) => grup.addLayer(item.marker));

      if (tampil.length === 0) {
        map.setView(pusatSumut, 7);
        status.textContent = "Belum ada showroom dengan koordinat yang bisa ditampilkan.";
        return;
      }

      if (tampil.length === 1) {
        map.setView(tampil[0].marker.getLatLng(), 13);
      } else {
        map.fitBounds(grup.getBounds(), { padding: [48, 48], maxZoom: 14 });
      }
      status.textContent = tampil.length + " dari " + pin.length + " lokasi ditampilkan";
    }

    buttons.forEach((button) => {
      const kategori = button.dataset.mapCategory;
      const jumlah = kategori === "semua" ? pin.length : pin.filter((item) => item.kategori === kategori).length;
      button.querySelector("[data-map-count]").textContent = String(jumlah);
      button.disabled = jumlah === 0;
      button.addEventListener("click", () => pilihKategori(kategori));
    });
    pilihKategori("semua");
  }

  // Muat data dan ubin peta ketika bagian peta masuk ke layar.
  if ("IntersectionObserver" in window) {
    const observer = new IntersectionObserver((entries) => {
      if (!entries.some((entry) => entry.isIntersecting)) return;
      observer.disconnect();
      mulaiPeta();
    });
    observer.observe(section);
  } else {
    mulaiPeta();
  }
})();
