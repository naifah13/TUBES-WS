// Katalog kendaraan di detail showroom: tombol "Tampilkan semua" dan filter merek.
(function () {
  const bagian = document.querySelector("[data-kendaraan]");
  if (!bagian) return;

  const kartu = Array.from(bagian.querySelectorAll("[data-kendaraan-kartu]"));
  const tombol = bagian.querySelector("[data-kendaraan-tombol]");
  const teksTombol = bagian.querySelector("[data-kendaraan-tombol-teks]");
  const chip = Array.from(bagian.querySelectorAll("[data-kendaraan-merek]"));
  let terbuka = false;
  let merekAktif = "";

  function perbarui() {
    kartu.forEach(function (el) {
      const cocokMerek = merekAktif === "" || el.dataset.merek === merekAktif;
      // Saat satu merek dipilih, semua modelnya langsung tampil.
      const lolosBatas = terbuka || merekAktif !== "" || !el.hasAttribute("data-kendaraan-lebih");
      el.hidden = !(cocokMerek && lolosBatas);
    });
    if (tombol) {
      tombol.parentElement.hidden = merekAktif !== "";
      tombol.setAttribute("aria-expanded", String(terbuka));
      teksTombol.textContent = terbuka ? tombol.dataset.teksTutup : tombol.dataset.teksBuka;
    }
  }

  if (tombol) {
    tombol.addEventListener("click", function () {
      terbuka = !terbuka;
      perbarui();
      if (!terbuka) bagian.scrollIntoView({ behavior: "smooth", block: "start" });
    });
  }

  chip.forEach(function (btn) {
    btn.addEventListener("click", function () {
      merekAktif = btn.dataset.kendaraanMerek;
      chip.forEach(function (lain) {
        lain.setAttribute("aria-pressed", String(lain === btn));
      });
      perbarui();
    });
  });
})();
