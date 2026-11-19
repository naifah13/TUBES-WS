(() => {
  const canvas = document.querySelector("[data-detail-map]");
  if (!canvas) return;

  const placeholder = document.querySelector("[data-detail-map-placeholder]");
  const message = document.querySelector("[data-detail-map-message]");
  const lat = Number(canvas.dataset.lat);
  const long = Number(canvas.dataset.long);
  const nama = canvas.dataset.nama;
  const alamat = canvas.dataset.alamat;
  const kategori = ["mobil", "motor"].includes(canvas.dataset.kategori) ? canvas.dataset.kategori : "lain";

  function mulai() {
    const L = window.L;
    if (!L) {
      placeholder.textContent = "Peta belum bisa dimuat.";
      message.textContent = "Gunakan tombol Buka rute untuk melihat lokasinya.";
      message.hidden = false;
      return;
    }

    const map = L.map(canvas, { scrollWheelZoom: true, maxZoom: 19 }).setView([lat, long], 16);
    placeholder.hidden = true;

    let ubinTerlihat = false;
    L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    })
      .on("tileload", () => {
        ubinTerlihat = true;
        message.hidden = true;
      })
      .on("load", () => {
        if (ubinTerlihat) return;
        message.textContent = "Peta dasar belum tersedia. Kamu masih bisa menggunakan tombol Buka rute.";
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
