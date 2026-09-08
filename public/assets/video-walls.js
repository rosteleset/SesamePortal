(() => {
  const editor = document.querySelector('[data-wall-editor]');
  if (editor) initEditor(editor);
  const screen = document.querySelector('[data-wall-view]');
  if (screen) window.SesameVideoWallPlayback.init(screen);

  function initEditor(form) {
    const catalog = JSON.parse(form.querySelector('[data-wall-catalog]').textContent);
    const input = form.querySelector('[data-wall-ids]');
    let ids = JSON.parse(input.value);
    input.disabled = false;
    const order = form.querySelector('[data-wall-order]');
    const rows = form.elements.rows;
    const columns = form.elements.columns;
    const checkboxes = [...form.querySelectorAll('input[name="cameraIds[]"]')];
    const labels = Object.fromEntries([...form.querySelectorAll('[data-wall-label]')].map((item) => [item.dataset.wallLabel, item.value]));
    const capacity = () => Number(rows.value) * Number(columns.value);
    let dragged = null;
    const button = (text, label, action, disabled = false) => {
      const element = document.createElement('button');
      element.type = 'button';
      element.textContent = text;
      element.title = label;
      element.setAttribute('aria-label', label);
      element.dataset.wallAction = action;
      element.disabled = disabled;
      return element;
    };
    const render = () => {
      input.value = JSON.stringify(ids);
      order.replaceChildren();
      ids.forEach((id, index) => {
        const camera = catalog[id];
        const li = document.createElement('li');
        li.dataset.cameraId = String(id);
        li.draggable = true;
        const number = document.createElement('span');
        number.className = 'vw-position';
        number.textContent = String(index + 1);
        const label = document.createElement('span');
        label.className = 'vw-selected-name';
        label.textContent = camera?.name || labels.unavailable;
        const actions = document.createElement('div');
        actions.className = 'vw-actions';
        actions.append(button('\u2191', labels.earlier, 'up', index === 0), button('\u2193', labels.later, 'down', index === ids.length - 1), button('\u00d7', labels.remove, 'remove'));
        li.append(number, label, actions);
        order.append(li);
      });
      checkboxes.forEach((checkbox) => { checkbox.checked = ids.includes(Number(checkbox.value)); });
      form.querySelector('[data-wall-count]').textContent = `${ids.length} / ${capacity()}`;
      const invalid = !ids.length || ids.length > capacity() || ids.some((id) => !catalog[id]);
      form.querySelector('[data-wall-limit]').hidden = !ids.length || !invalid;
      form.querySelector('[data-wall-save]').disabled = invalid;
    };
    checkboxes.forEach((checkbox) => checkbox.addEventListener('change', () => {
      const id = Number(checkbox.value);
      if (checkbox.checked && !ids.includes(id)) {
        if (ids.length >= capacity()) {
          checkbox.checked = false;
          form.querySelector('[data-wall-limit]').hidden = false;
          return;
        }
        ids.push(id);
      } else if (!checkbox.checked) ids = ids.filter((value) => value !== id);
      render();
    }));
    rows.addEventListener('change', render);
    columns.addEventListener('change', render);
    order.addEventListener('click', (event) => {
      const control = event.target.closest('[data-wall-action]');
      const item = control?.closest('[data-camera-id]');
      if (!item) return;
      const id = Number(item.dataset.cameraId);
      const index = ids.indexOf(id);
      if (control.dataset.wallAction === 'remove') ids.splice(index, 1);
      else {
        const next = index + (control.dataset.wallAction === 'up' ? -1 : 1);
        if (next < 0 || next >= ids.length) return;
        [ids[index], ids[next]] = [ids[next], ids[index]];
      }
      render();
      order.querySelector(`[data-camera-id="${id}"] [data-wall-action="${control.dataset.wallAction}"]`)?.focus();
    });
    order.addEventListener('dragstart', (event) => {
      dragged = Number(event.target.closest('[data-camera-id]')?.dataset.cameraId);
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', String(dragged));
    });
    order.addEventListener('dragover', (event) => { if (dragged) event.preventDefault(); });
    order.addEventListener('drop', (event) => {
      event.preventDefault();
      const target = Number(event.target.closest('[data-camera-id]')?.dataset.cameraId);
      if (ids.includes(dragged) && ids.includes(target) && target !== dragged) {
        const index = ids.indexOf(target);
        ids.splice(ids.indexOf(dragged), 1);
        ids.splice(index, 0, dragged);
        render();
      }
      dragged = null;
    });
    order.addEventListener('dragend', () => { dragged = null; });
    const search = form.querySelector('[data-wall-search]');
    let searching = false;
    search.addEventListener('input', () => {
      const query = search.value.trim().toLocaleLowerCase();
      const details = [...form.querySelectorAll('[data-wall-group]')];
      if (query && !searching) details.forEach((group) => { group.dataset.wasOpen = String(group.open); });
      form.querySelectorAll('[data-wall-camera-row]').forEach((row) => {
        const groupNames = [];
        let group = row.closest('[data-wall-group]');
        while (group) {
          groupNames.push(group.querySelector(':scope > summary').textContent);
          group = group.parentElement.closest('[data-wall-group]');
        }
        row.hidden = query !== '' && !`${row.textContent} ${groupNames.join(' ')}`.toLocaleLowerCase().includes(query);
      });
      details.reverse().forEach((group) => {
        group.hidden = query !== '' && !group.querySelector('[data-wall-camera-row]:not([hidden])');
        if (query) group.open = !group.hidden;
        else if (searching) group.open = group.dataset.wasOpen === 'true';
      });
      searching = query !== '';
      form.querySelector('[data-wall-no-results]').hidden = Boolean(form.querySelector('[data-wall-camera-row]:not([hidden])'));
    });
    render();
  }

})();
