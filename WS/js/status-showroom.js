(() => {
  const label = {
    buka: "Buka",
    "segera-tutup": "Segera tutup",
    tutup: "Tutup",
    "tidak-diketahui": "Jam belum pasti",
  };

  const kelompok = [...document.querySelectorAll("[data-filter-status-showroom]")].map((grid) => ({
    filter: grid.dataset.filterStatusShowroom,
    pesanKosong: grid.nextElementSibling?.matches("[data-status-showroom-kosong]")
      ? grid.nextElementSibling
      : null,
    kartu: [...grid.querySelectorAll("[data-jadwal-showroom]")].map((card) => ({
      card,
      badge: card.querySelector("[data-status-showroom]"),
      jadwal: Object.entries(JSON.parse(card.dataset.jadwalShowroom)).reverse(),
    })),
  }));
  if (!kelompok.length) return;

  const formatWib = new Intl.DateTimeFormat("id-ID", {
    timeZone: "Asia/Jakarta",
    hourCycle: "h23",
    hour: "2-digit",
    minute: "2-digit",
  });

  function menitWib(sekarang) {
    const bagian = formatWib.formatToParts(sekarang);
    const jam = Number(bagian.find(({ type }) => type === "hour").value);
    const menit = Number(bagian.find(({ type }) => type === "minute").value);
    return jam * 60 + menit;
  }

  let pengaturWaktu;
  function perbaruiStatus() {
    clearTimeout(pengaturWaktu);
    if (document.hidden) return;

    const sekarang = Date.now();
    const menit = menitWib(sekarang);
    kelompok.forEach(({ filter, pesanKosong, kartu }) => {
      let jumlahTampil = 0;
      kartu.forEach(({ card, badge, jadwal }) => {
        const [, status] = jadwal.find(([awal]) => Number(awal) <= menit);
        if (badge.dataset.statusShowroom !== status) {
          badge.dataset.statusShowroom = status;
          badge.textContent = label[status];
        }

        const tersembunyi = filter !== "" && status !== filter;
        card.hidden = tersembunyi;
        if (!tersembunyi) jumlahTampil++;
      });

      if (pesanKosong) pesanKosong.hidden = jumlahTampil > 0;
    });

    // Periksa lagi tepat setelah pergantian menit.
    pengaturWaktu = setTimeout(perbaruiStatus, 60_000 - (sekarang % 60_000) + 50);
  }

  document.addEventListener("visibilitychange", perbaruiStatus);
  window.addEventListener("pageshow", perbaruiStatus);
  perbaruiStatus();
})();
