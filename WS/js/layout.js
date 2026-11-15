document.addEventListener('DOMContentLoaded', function () {
  const navbar = document.querySelector('.vroom-header');

  if (!navbar) return;

  function updateTinggiNavbar() {
    document.documentElement.style.setProperty(
      '--tinggi-navbar',
      navbar.getBoundingClientRect().height + 'px'
    );
  }

  updateTinggiNavbar();

  window.addEventListener('resize', updateTinggiNavbar);

  if ('ResizeObserver' in window) {
    const observer = new ResizeObserver(updateTinggiNavbar);
    observer.observe(navbar);
  }
});