const form = document.querySelector('[data-edit-form]');
const status = document.querySelector('[data-save-status]');
let dirty = false;

function updateDay(box) {
  const rows = box.querySelectorAll('[data-course-row]');
  const count = document.querySelector('[data-count-for="' + box.dataset.d + '"]');
  count.textContent = rows.length + (rows.length === 1 ? ' cours' : ' cours');
  let empty = box.querySelector('.day-empty');
  if (!rows.length && !empty) {
    empty = document.createElement('p');
    empty.className = 'day-empty';
    empty.textContent = 'Aucun cours prévu';
    box.appendChild(empty);
  } else if (rows.length && empty) {
    empty.remove();
  }
}

function markDirty() {
  dirty = true;
  status.textContent = 'Modifications non enregistrées';
  status.classList.add('is-dirty');
}

document.querySelectorAll('.rows').forEach(updateDay);
form.addEventListener('input', markDirty);

document.addEventListener('click', event => {
  const button = event.target.closest('[data-add], .x');
  if (!button) return;

  if (button.classList.contains('x')) {
    const box = button.closest('.rows');
    button.closest('[data-course-row]').remove();
    updateDay(box);
    markDirty();
    return;
  }

  const day = button.dataset.add;
  const box = document.querySelector('.rows[data-d="' + day + '"]');
  const template = document.createElement('div');
  template.innerHTML = document.getElementById('tpl').innerHTML.replaceAll('__D__', day);
  const row = template.firstElementChild;
  box.appendChild(row);
  updateDay(box);
  row.querySelector('.course-title input').focus();
  markDirty();
});

form.addEventListener('submit', () => {
  dirty = false;
  status.textContent = 'Enregistrement…';
  status.classList.remove('is-dirty');
});

document.querySelector('[data-copy-form]').addEventListener('submit', event => {
  if (!window.confirm('Copier les cours de la semaine précédente ? Les cours déjà saisis pour cette semaine seront remplacés.')) {
    event.preventDefault();
  }
});

window.addEventListener('beforeunload', event => {
  if (!dirty) return;
  event.preventDefault();
  event.returnValue = '';
});
