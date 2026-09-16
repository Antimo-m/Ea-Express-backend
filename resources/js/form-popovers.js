let active;
export function attachFormPopovers(root = document) {
  const nodes = root.querySelectorAll('select:not([multiple]), input[type="date"]');
  const cleanups = [];
  nodes.forEach(control => {
    if (control.dataset.popoverReady) return;
    control.dataset.popoverReady = 'true';
    const open = event => {
      if (control.disabled || (event.type === 'keydown' && ![' ', 'Enter', 'ArrowDown'].includes(event.key))) return;
      event.preventDefault();
      active?.();
      const panel = document.createElement('div'); panel.className = 'form-popover'; panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', control.labels?.[0]?.textContent || 'Scegli un valore');
      document.body.append(panel);
      const rect = control.getBoundingClientRect(); panel.style.width = `${Math.min(control.tagName === 'SELECT' ? Math.max(rect.width, 220) : 300, innerWidth - 24)}px`;
      panel.style.left = `${Math.max(12, Math.min(rect.left, innerWidth - panel.offsetWidth - 12))}px`;
      panel.style.top = `${Math.max(12, Math.min(rect.bottom + 6, innerHeight - 350))}px`;
      const close = () => { panel.remove(); control.setAttribute('aria-expanded', 'false'); document.removeEventListener('pointerdown', outside); document.removeEventListener('keydown', keys); window.removeEventListener('resize', close); active = undefined; };
      const outside = event => { if (!panel.contains(event.target) && event.target !== control) close(); };
      const keys = event => {
        if (event.key === 'Escape') { event.preventDefault(); close(); control.focus(); }
        if (event.key === 'Tab') close();
        if (['ArrowDown','ArrowUp','ArrowLeft','ArrowRight','Home','End'].includes(event.key) && panel.contains(document.activeElement)) {
          event.preventDefault(); const buttons = [...panel.querySelectorAll('button:not(:disabled)')]; let index = buttons.indexOf(document.activeElement);
          if (event.key === 'Home') index = 0; else if (event.key === 'End') index = buttons.length - 1; else index = Math.max(0, Math.min(buttons.length - 1, index + (['ArrowDown','ArrowRight'].includes(event.key) ? 1 : -1)));
          buttons[index]?.focus();
        }
      };
      const select = value => {
        const setter = Object.getOwnPropertyDescriptor(control.tagName === 'SELECT' ? HTMLSelectElement.prototype : HTMLInputElement.prototype, 'value').set;
        setter.call(control, value);
        control.dispatchEvent(new Event('input', { bubbles: true })); control.dispatchEvent(new Event('change', { bubbles: true }));
        close(); control.focus();
      };
      const button = (text, callback, selected = false) => { const item = document.createElement('button'); item.type = 'button'; item.textContent = text; item.className = selected ? 'is-selected' : ''; item.addEventListener('click', callback); return item; };
      if (control.tagName === 'SELECT') {
        panel.classList.add('select-popover');
        [...control.options].forEach(option => { const item = button(option.textContent, () => select(option.value), option.selected); item.disabled = option.disabled; item.setAttribute('aria-pressed', String(option.selected)); panel.append(item); });
      } else {
        const initial = control.value ? new Date(`${control.value}T12:00:00`) : new Date();
        let month = new Date(initial.getFullYear(), initial.getMonth(), 1);
        const iso = date => `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
        const draw = () => {
          panel.replaceChildren(); const header = document.createElement('div');header.className='calendar-heading';
          const previous = button('‹', () => {month.setMonth(month.getMonth()-1);draw();}); previous.setAttribute('aria-label','Mese precedente');
          const next = button('›', () => {month.setMonth(month.getMonth()+1);draw();}); next.setAttribute('aria-label','Mese successivo');
          const title=document.createElement('strong');title.textContent=month.toLocaleDateString('it-IT',{month:'long',year:'numeric'});header.append(previous,title,next);panel.append(header);
          const grid=document.createElement('div');grid.className='calendar-grid';
          ['L','M','M','G','V','S','D'].forEach(day=>{const text=document.createElement('span');text.textContent=day;grid.append(text);});
          const offset=(month.getDay()+6)%7;
          for(let index=0;index<offset;index++)grid.append(document.createElement('span'));
          const last=new Date(month.getFullYear(),month.getMonth()+1,0).getDate();
          for(let day=1;day<=last;day++){const value=iso(new Date(month.getFullYear(),month.getMonth(),day));const item=button(String(day),()=>select(value),value===control.value);item.disabled=Boolean((control.min && value<control.min)||(control.max && value>control.max));item.setAttribute('aria-label',new Date(`${value}T12:00:00`).toLocaleDateString('it-IT',{day:'numeric',month:'long',year:'numeric'}));grid.append(item);}panel.append(grid);
          const today=button('Oggi',()=>select(iso(new Date())));today.disabled=Boolean((control.min && iso(new Date())<control.min)||(control.max && iso(new Date())>control.max));panel.append(today);
        };draw();
      }
      control.setAttribute('aria-expanded','true');control.setAttribute('aria-haspopup','dialog');
      panel.querySelector('.is-selected:not(:disabled), button:not(:disabled)')?.focus();
      document.addEventListener('pointerdown',outside);document.addEventListener('keydown',keys);window.addEventListener('resize',close);active=close;
    };
    control.addEventListener('pointerdown',open);control.addEventListener('keydown',open);
    cleanups.push(()=>{control.removeEventListener('pointerdown',open);control.removeEventListener('keydown',open);delete control.dataset.popoverReady;});
  });
  return () => {active?.();cleanups.forEach(cleanup=>cleanup());};
}
