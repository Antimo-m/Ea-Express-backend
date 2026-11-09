let active;
let sequence = 0;
let touchGesture;
let suppressedClick;
const clearTouch = () => {
    if (touchGesture) clearTimeout(touchGesture.timer);
    touchGesture = undefined;
};
function closeTooltip() {
    if (!active) return;
    const { trigger, panel, description } = active;
    if (description) trigger.setAttribute('aria-describedby', description);
    else trigger.removeAttribute('aria-describedby');
    panel.remove(); active = undefined;
}
function positionTooltip() {
    if (!active) return;
    const { trigger, panel } = active;
    if (!trigger.isConnected || !trigger.getClientRects().length) { closeTooltip(); return; }
    const rect = trigger.getBoundingClientRect();
    const { width, height } = panel.getBoundingClientRect();
    panel.style.left = `${Math.max(12, Math.min(rect.left + (rect.width - width) / 2, innerWidth - width - 12))}px`;
    const top = rect.top - height - 8 >= 12 ? rect.top - height - 8 : rect.bottom + 8;
    panel.style.top = `${Math.max(12, Math.min(top, innerHeight - height - 12))}px`;
}
function showTooltip(trigger) {
    if (active?.trigger === trigger) return;
    closeTooltip();
    const panel = document.createElement('div');
    panel.className = 'ea-tooltip'; panel.id = `ea-tooltip-${++sequence}`;
    panel.setAttribute('role', 'tooltip'); panel.setAttribute('popover', 'manual');
    panel.textContent = trigger.dataset.tooltip;
    const description = trigger.getAttribute('aria-describedby');
    trigger.removeAttribute('title');
    trigger.setAttribute('aria-describedby', [description, panel.id].filter(Boolean).join(' '));
    (trigger.closest('dialog') || document.body).append(panel);
    active = { trigger, panel, description };
    panel.showPopover?.(); positionTooltip();
}
document.addEventListener('pointerover', event => {
    if (event.pointerType === 'touch') return;
    const trigger = event.target.closest('[data-tooltip]');
    if (trigger) showTooltip(trigger);
});
document.addEventListener('pointerout', event => {
    if (active && !active.trigger.contains(event.relatedTarget) && active.trigger.contains(event.target)) closeTooltip();
});
document.addEventListener('focusin', event => {
    const trigger = event.target.closest('[data-tooltip]');
    if (trigger) showTooltip(trigger);
});
document.addEventListener('focusout', event => { if (active?.trigger.contains(event.target)) closeTooltip(); });
document.addEventListener('pointerdown', event => {
    const trigger = event.target.closest('[data-tooltip]');
    clearTouch();
    closeTooltip();
    if (event.pointerType !== 'touch' || !trigger) return;
    const gesture = { trigger, x: event.clientX, y: event.clientY, shown: false };
    gesture.timer = setTimeout(() => {
        gesture.shown = true;
        showTooltip(trigger);
    }, 450);
    touchGesture = gesture;
}, true);
document.addEventListener('pointermove', event => {
    if (touchGesture && Math.hypot(event.clientX - touchGesture.x, event.clientY - touchGesture.y) > 10) {
        clearTouch(); closeTooltip();
    }
}, true);
document.addEventListener('pointerup', () => {
    if (touchGesture?.shown) suppressedClick = { trigger: touchGesture.trigger, until: performance.now() + 800 };
    clearTouch();
}, true);
document.addEventListener('pointercancel', () => { clearTouch(); closeTooltip(); }, true);
document.addEventListener('click', event => {
    if (!suppressedClick || performance.now() > suppressedClick.until) return;
    if (suppressedClick.trigger.contains(event.target)) {
        event.preventDefault(); event.stopImmediatePropagation(); suppressedClick = undefined;
    }
}, true);
document.addEventListener('contextmenu', event => { if (touchGesture?.shown) event.preventDefault(); });
document.addEventListener('keydown', event => { if (event.key === 'Escape') closeTooltip(); });
document.addEventListener('ea:workspace-rendered', closeTooltip);
document.addEventListener('toggle', event => { if (event.target.matches('dialog')) closeTooltip(); }, true);
window.addEventListener('scroll', () => { clearTouch(); closeTooltip(); }, true);
window.addEventListener('resize', positionTooltip);
