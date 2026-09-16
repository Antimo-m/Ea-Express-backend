const count = document.querySelector('input[name="parcel_count"]');
const container = document.querySelector('[data-package-fields]');
if (count && container) {
  const values = JSON.parse(container.dataset.packageFields || '[]');
  const fields = [['weight_kg', 'Peso (kg)', 0.01, 1000], ['length_cm', 'Lunghezza (cm)', 1, 500], ['width_cm', 'Larghezza (cm)', 1, 500], ['height_cm', 'Altezza (cm)', 1, 500]];
  const draw = () => {
    container.querySelectorAll('input').forEach(input => { const [index, key] = input.dataset.packageField.split(':'); values[index] ||= {}; values[index][key] = input.value; });
    container.replaceChildren();
    for (let index = 0; index < Math.max(1, Math.min(100, Number(count.value) || 1)); index++) {
      const group = document.createElement('fieldset'); group.className = 'package-measurements';
      const legend = document.createElement('legend'); legend.textContent = `Pacco ${index + 1}`; group.append(legend);
      const row = document.createElement('div'); row.className = 'field-grid';
      fields.forEach(([key, title, min, max]) => {
        const wrapper = document.createElement('div'); wrapper.className = 'field-quantity';
        const label = document.createElement('label'); label.className = 'form-label'; label.textContent = title; label.htmlFor = `package-${index}-${key}`;
        const input = document.createElement('input'); Object.assign(input, { id: label.htmlFor, type: 'number', name: `packages[${index}][${key}]`, min, max, step: '0.01', required: true, value: values[index]?.[key] || '', className: 'form-control' }); input.dataset.packageField = `${index}:${key}`;
        wrapper.append(label,input); row.append(wrapper);
      }); group.append(row);container.append(group);
    }
  };
  count.addEventListener('change', draw); draw();
}
