document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('searchFilterForm');
  const input = form?.querySelector('input[name="keyword"]');
  const reset = document.getElementById('searchReset');

  if (!form || !input || !reset) return;

  function tampilkanReset() {
    reset.hidden = input.value.trim() === '';
  }

  tampilkanReset();
  input.addEventListener('input', tampilkanReset);

  reset.addEventListener('click', function (event) {
    event.preventDefault();

    input.value = '';

    form.querySelectorAll('select').forEach(function (select) {
      select.selectedIndex = 0;
    });

    form.submit();
  });
}); 