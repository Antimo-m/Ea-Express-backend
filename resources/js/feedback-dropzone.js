import { compressImage } from './compress-image';

/**
 * Casella allegati della pagina Feedback: trascinamento, scelta dal dispositivo, anteprime e rimozione.
 * I file restano nell'input reale (tramite DataTransfer), quindi il modulo si invia in modo tradizionale.
 */
const units = bytes => bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1).replace('.', ',')} MB`;
const allowed = /\.(jpe?g|png|webp|gif|heic|pdf|txt)$/i;

function bindFeedbackForm(form) {
    if (form.dataset.feedbackBound) return;
    form.dataset.feedbackBound = 'true';
    const zone = form.querySelector('[data-feedback-dropzone]');
    const input = zone.querySelector('input[type="file"]');
    const list = form.querySelector('[data-feedback-files]');
    const error = form.querySelector('[data-feedback-files-error]');
    const maxFiles = Number(input.dataset.maxFiles), maxFile = Number(input.dataset.maxFileKb) * 1024, maxTotal = Number(input.dataset.maxTotalKb) * 1024;
    let files = [];
    const previews = new Map();
    const client = form.querySelector('[data-feedback-client]');
    if (client) client.value = `${navigator.userAgent} · schermo ${window.screen.width}×${window.screen.height} · finestra ${window.innerWidth}×${window.innerHeight}`.slice(0, 500);

    const showError = message => { error.textContent = message; error.hidden = !message; };
    const preview = zone.querySelector('[data-feedback-preview]');
    const title = zone.querySelector('[data-feedback-title]');
    const hint = zone.querySelector('[data-feedback-hint]');
    const iconName = file => /pdf$/i.test(file.name) ? 'file-earmark-pdf' : /\.txt$/i.test(file.name) ? 'file-earmark-text' : 'file-earmark-image';
    const thumb = (file, className) => {
        if (file.type.startsWith('image/') && !/heic/i.test(file.type)) {
            if (!previews.has(file)) previews.set(file, URL.createObjectURL(file));
            const image = document.createElement('img');
            image.src = previews.get(file); image.alt = ''; image.className = className;
            return image;
        }
        const icon = document.createElement('span');
        icon.className = `${className} feedback-file-icon`; icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = `<i class="bi bi-${iconName(file)}"></i>`;
        return icon;
    };
    const removeButton = file => {
        const remove = document.createElement('button');
        remove.type = 'button'; remove.className = 'icon-button action-delete feedback-file-remove';
        remove.setAttribute('aria-label', `Rimuovi ${file.name}`); remove.dataset.tooltip = 'Rimuovi';
        remove.innerHTML = '<span class="action-icon" aria-hidden="true"><i class="bi bi-x-lg"></i></span>';
        remove.addEventListener('click', event => {
            event.preventDefault(); event.stopPropagation();
            URL.revokeObjectURL(previews.get(file) || ''); previews.delete(file);
            files = files.filter(candidate => candidate !== file); showError(''); sync(); input.focus();
        });
        return remove;
    };
    const caption = file => {
        const text = document.createElement('span');
        text.className = 'feedback-file-text';
        const name = document.createElement('strong'); name.textContent = file.name; name.title = file.name;
        const size = document.createElement('small'); size.textContent = units(file.size);
        text.append(name, size);
        return text;
    };
    const sync = () => {
        const transfer = new DataTransfer();
        files.forEach(file => transfer.items.add(file));
        input.files = transfer.files;
        // Un solo allegato: anteprima al centro della casella. Due o più: miniature compatte in fila sotto la casella.
        const single = files.length === 1;
        zone.classList.toggle('has-single', single);
        preview.hidden = !single;
        preview.replaceChildren(...(single ? [thumb(files[0], 'feedback-preview-media'), caption(files[0]), removeButton(files[0])] : []));
        title.textContent = single ? 'Aggiungi altri file' : 'Trascina qui foto o file';
        hint.hidden = single;
        list.classList.toggle('is-strip', files.length > 1);
        list.replaceChildren(...(single ? [] : files.map((file, index) => {
            const item = document.createElement('li');
            item.className = 'feedback-file';
            item.style.setProperty('--file-index', index);
            item.append(thumb(file, 'feedback-file-thumb'), caption(file), removeButton(file));
            return item;
        })));
        list.setAttribute('aria-label', files.length > 1 ? `${files.length} allegati selezionati` : 'Allegati selezionati');
        zone.classList.toggle('is-full', files.length >= maxFiles);
    };
    const submit = form.querySelector('button[type="submit"]');
    const add = async incoming => {
        const problems = [];
        const accepted = incoming.filter(file => allowed.test(file.name) || !problems.push(`${file.name}: formato non supportato.`));
        zone.classList.add('is-processing'); title.textContent = 'Ottimizzazione delle immagini…'; if (submit) submit.disabled = true;
        const prepared = await Promise.all(accepted.map(compressImage));
        zone.classList.remove('is-processing'); if (submit) submit.disabled = false;
        for (const file of prepared) {
            if (files.length >= maxFiles) { problems.push(`Puoi allegare al massimo ${maxFiles} file.`); break; }
            if (file.size > maxFile) { problems.push(`${file.name}: supera ${units(maxFile)}.`); continue; }
            if (files.reduce((sum, current) => sum + current.size, 0) + file.size > maxTotal) { problems.push(`Gli allegati insieme possono pesare al massimo ${units(maxTotal)}.`); break; }
            if (!files.some(current => current.name === file.name && current.size === file.size && current.lastModified === file.lastModified)) files.push(file);
        }
        showError([...new Set(problems)].join(' '));
        sync();
    };

    input.addEventListener('change', () => { const chosen = [...input.files]; add(chosen); });
    ['dragenter', 'dragover'].forEach(type => zone.addEventListener(type, event => { event.preventDefault(); zone.classList.add('is-dragging'); }));
    ['dragleave', 'dragend'].forEach(type => zone.addEventListener(type, event => { if (!zone.contains(event.relatedTarget)) zone.classList.remove('is-dragging'); }));
    zone.addEventListener('drop', event => {
        event.preventDefault(); zone.classList.remove('is-dragging');
        add([...(event.dataTransfer?.files || [])]);
    });
    // Un file lasciato fuori dalla casella non deve aprire il file nel browser e far perdere il modulo.
    window.addEventListener('dragover', event => { if (event.dataTransfer?.types?.includes('Files')) event.preventDefault(); });
    window.addEventListener('drop', event => { if (event.dataTransfer?.types?.includes('Files') && !zone.contains(event.target)) event.preventDefault(); });
}

const bindAll = () => document.querySelectorAll('[data-feedback-form]').forEach(bindFeedbackForm);
bindAll();
document.addEventListener('ea:workspace-rendered', bindAll);
