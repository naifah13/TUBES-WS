document.addEventListener('DOMContentLoaded', function () {
  const cards = document.querySelectorAll('.showroom-card');

  if (!cards.length) return;

  // Jika pengguna memilih pengurangan gerakan, card langsung ditampilkan.
  const kurangiGerakan = window.matchMedia(
    '(prefers-reduced-motion: reduce)'
  );

  if (kurangiGerakan.matches || !('IntersectionObserver' in window)) {
    return;
  }

  const observer = new IntersectionObserver(
    function (entries, pengamat) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;

        const card = entry.target;

        card.classList.remove('card-reveal-pending');
        card.classList.add('card-reveal-in');

        // Setelah animasi selesai, hapus class animasi agar
        // efek hover dapat berjalan dengan leluasa.
        card.addEventListener(
          'animationend',
          function () {
            card.classList.remove('card-reveal-in');
          },
          { once: true }
        );

        // Setiap card dianimasikan sekali saja.
        pengamat.unobserve(card);
      });
    },
    {
      threshold: 0.1
    }
  );

  cards.forEach(function (card) {
    const posisi = card.getBoundingClientRect();

    // Card yang sudah terlihat saat halaman dibuka tetap terlihat.
    if (posisi.top >= window.innerHeight) {
      card.classList.add('card-reveal-pending');
      observer.observe(card);
    }
  });
});