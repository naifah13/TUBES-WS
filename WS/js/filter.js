document.addEventListener('DOMContentLoaded', function () {
  const tombol = document.getElementById('filterToggleBtn');
  const panel = document.getElementById('filterPanel');

  if (!tombol || !panel) return;

  tombol.addEventListener('click', function (event) {
    event.stopPropagation();
    panel.classList.toggle('active');
  });

  document.addEventListener('click', function (event) {
    if (!panel.contains(event.target) && !tombol.contains(event.target)) {
      panel.classList.remove('active');
    }
  });
});