document.addEventListener('DOMContentLoaded', function () {
  const navbar = document.querySelector('.vroom-header');

  const beranda = document.querySelector(
    '.vroom-nav a[href="index.php"]'
  );

  const showroom = document.querySelector(
    '.vroom-nav a[href="#hasil"]'
  );

  const tim = document.querySelector(
    '.vroom-nav a[href="#tim"]'
  );

  const tentang = document.querySelector(
    '.vroom-nav a[href="#tentang"]'
  );

  const hasil = document.getElementById('hasil');
  const bagianTim = document.getElementById('tim');
  const bagianTentang = document.getElementById('tentang');

  if (!navbar || !beranda || !showroom || !hasil) return;

  function updateMenuAktif() {
    const batas = navbar.getBoundingClientRect().bottom + 30;
    const posisiHasil = hasil.getBoundingClientRect().top;

    const posisiTim = bagianTim
      ? bagianTim.getBoundingClientRect().top
      : Infinity;

    const posisiTentang = bagianTentang
      ? bagianTentang.getBoundingClientRect().top
      : Infinity;

    const diBawah =
      window.scrollY + window.innerHeight >=
      document.documentElement.scrollHeight - 5;

    let aktif = beranda;

    if (
      tentang &&
      bagianTentang &&
      (
        posisiTentang <= batas ||
        (diBawah && posisiTentang < window.innerHeight)
      )
    ) {
      aktif = tentang;
    } else if (tim && posisiTim <= batas) {
      aktif = tim;
    } else if (posisiHasil <= batas) {
      aktif = showroom;
    }

    [beranda, showroom, tim, tentang].forEach(function (menu) {
      if (menu) {
        menu.classList.toggle('vroom-nav-active', menu === aktif);
      }
    });
  }

  window.addEventListener('scroll', updateMenuAktif, { passive: true });
  window.addEventListener('resize', updateMenuAktif);
  window.addEventListener('load', updateMenuAktif);

  updateMenuAktif();
});