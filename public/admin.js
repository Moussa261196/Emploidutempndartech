const form = document.querySelector('[data-edit-form]');
const status = document.querySelector('[data-save-status]');
let dirty = false;

function updateDay(box) {
  const rows = box.querySelectorAll('[data-course-row]');
  const count = document.querySelector('[data-count-for="' + box.dataset.d + '"]');
  count.textContent = rows.length === 0 ? 'Aucun cours' : rows.length + (rows.length === 1 ? ' cours' : ' cours');
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

function minutes(value) {
  const match = /^([01]\d|2[0-3]):([0-5]\d)$/.exec(value);
  return match ? Number(match[1]) * 60 + Number(match[2]) : null;
}

function validateOverlaps(box) {
  const warning = document.querySelector('[data-overlap-for="' + box.dataset.d + '"]');
  const courses = [...box.querySelectorAll('[data-course-row]')].map(row => {
    const inputs = row.querySelectorAll('input[type="time"]');
    return { row, start: minutes(inputs[0].value), end: minutes(inputs[1].value), inputs };
  });
  let message = '';
  courses.forEach(course => course.inputs.forEach(input => input.removeAttribute('aria-invalid')));

  for (let i = 0; i < courses.length; i++) {
    const first = courses[i];
    if (first.start === null || first.end === null || first.start >= first.end) continue;
    for (let j = i + 1; j < courses.length; j++) {
      const second = courses[j];
      if (second.start !== null && second.end !== null && first.start < second.end && second.start < first.end) {
        first.inputs.forEach(input => input.setAttribute('aria-invalid', 'true'));
        second.inputs.forEach(input => input.setAttribute('aria-invalid', 'true'));
        message = 'Attention : ces cours se chevauchent.';
      }
    }
  }
  warning.textContent = message;
  warning.hidden = !message;
  return !message;
}

function validateAllOverlaps() {
  return [...document.querySelectorAll('.rows')].every(validateOverlaps);
}

function markDirty() {
  dirty = true;
  status.textContent = 'Modifications non enregistrées';
  status.classList.add('is-dirty');
}

document.querySelectorAll('.rows').forEach(box => {
  updateDay(box);
  validateOverlaps(box);
});
form.addEventListener('input', event => {
  markDirty();
  const box = event.target.closest('.rows');
  if (box) validateOverlaps(box);
});

document.addEventListener('click', event => {
  const button = event.target.closest('[data-add], [data-add-slot], .x');
  if (!button) return;

  if (button.classList.contains('x')) {
    const box = button.closest('.rows');
    button.closest('[data-course-row]').remove();
    updateDay(box);
    validateOverlaps(box);
    markDirty();
    return;
  }

  const day = button.dataset.add || button.dataset.day;
  const box = document.querySelector('.rows[data-d="' + day + '"]');
  const template = document.createElement('div');
  template.innerHTML = document.getElementById('tpl').innerHTML.replaceAll('__D__', day);
  const row = template.firstElementChild;
  if (button.dataset.addSlot) {
    const [start, end] = button.dataset.addSlot.split('|');
    const times = row.querySelectorAll('input[type="time"]');
    times[0].value = start;
    times[1].value = end;
  }
  box.appendChild(row);
  updateDay(box);
  validateOverlaps(box);
  row.querySelector('.course-title input').focus();
  markDirty();
});

form.addEventListener('submit', event => {
  if (!validateAllOverlaps()) {
    event.preventDefault();
    status.textContent = 'Corrigez les horaires qui se chevauchent avant l’enregistrement.';
    status.classList.add('is-dirty');
    document.querySelector('.overlap-warning:not([hidden])').scrollIntoView({ behavior: 'smooth', block: 'center' });
    return;
  }
  dirty = false;
  status.textContent = 'Enregistrement…';
  status.classList.remove('is-dirty');
});

document.querySelector('[data-copy-form]').addEventListener('submit', event => {
  const copyForm = event.currentTarget;
  const previousCount = Number(copyForm.dataset.previousCount);
  const currentCount = Number(copyForm.dataset.currentCount);
  if (!window.confirm('Copier ' + previousCount + ' cours de la semaine précédente ? Cette action remplacera les ' + currentCount + ' cours de la semaine affichée.')) {
    event.preventDefault();
  }
});

document.querySelector('[data-generate-form]').addEventListener('submit', event => {
  const form = event.currentTarget;
  const existingCount = Number(form.dataset.existingCount);
  const replacing = form.querySelector('[name="replace_existing"]').checked;
  if (existingCount > 0 && !replacing) {
    event.preventDefault();
    window.alert('Cette période contient déjà des cours. Cochez leur remplacement pour continuer ; une sauvegarde sera créée.');
    return;
  }
  const action = existingCount > 0 ? 'Les cours existants seront sauvegardés puis remplacés.' : 'Le planning sera généré et sauvegardé.';
  if (!window.confirm(action + ' Générer neuf semaines à partir de la semaine affichée ?')) event.preventDefault();
});

document.querySelector('[data-restore-form]').addEventListener('submit', event => {
  if (!window.confirm('Restaurer cette sauvegarde ? Tous les cours et annonces actuellement enregistrés seront remplacés.')) {
    event.preventDefault();
  }
});

document.querySelectorAll('[data-snapshot-restore]').forEach(snapshotForm => {
  snapshotForm.addEventListener('submit', event => {
    if (!window.confirm('Restaurer cette sauvegarde automatique ? Tous les cours et annonces actuels seront remplacés.')) {
      event.preventDefault();
    }
  });
});

window.addEventListener('beforeunload', event => {
  if (!dirty) return;
  event.preventDefault();
  event.returnValue = '';
});
