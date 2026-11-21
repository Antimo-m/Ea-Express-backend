@props(['title', 'message' => null, 'tagline' => 'Restiamo pronti al prossimo pacco in arrivo.', 'heading' => 'h2'])
{{-- Stato vuoto illustrato e condiviso: furgone EA-Express fermo con le porte aperte e un Rider con il pacco in mano.
     Solo SVG e CSS, nessuna risorsa esterna; le animazioni si fermano con prefers-reduced-motion. --}}
<div {{ $attributes->class(['surface', 'empty-state', 'empty-scene']) }} role="status">
    <svg class="empty-scene-art" viewBox="0 0 320 170" aria-hidden="true" focusable="false">
        <g class="empty-scene-road"><line x1="8" y1="150" x2="312" y2="150" /><line class="empty-scene-dash" x1="20" y1="160" x2="300" y2="160" /></g>
        <g transform="translate(78 0)"><g class="empty-scene-truck">
            <rect class="empty-scene-body" x="40" y="58" width="132" height="76" rx="10" />
            <rect class="empty-scene-stripe" x="40" y="104" width="132" height="10" />
            <text class="empty-scene-brand" x="62" y="91">EA</text>
            <path class="empty-scene-cab" d="M172 78h34c6 0 10 3 13 8l14 22c2 3 3 6 3 10v16h-64z" />
            <path class="empty-scene-window" d="M180 85h24c3 0 5 1 7 4l10 16h-41z" />
            <g class="empty-scene-door empty-scene-door--top"><rect x="18" y="58" width="22" height="38" rx="4" /></g>
            <g class="empty-scene-door empty-scene-door--bottom"><rect x="18" y="96" width="22" height="38" rx="4" /></g>
            <rect class="empty-scene-hold" x="42" y="60" width="16" height="72" rx="3" />
            <rect class="empty-scene-box empty-scene-box--inside" x="44" y="112" width="12" height="18" rx="2" />
            <circle class="empty-scene-wheel" cx="78" cy="136" r="14" /><circle class="empty-scene-hub" cx="78" cy="136" r="5" />
            <circle class="empty-scene-wheel" cx="200" cy="136" r="14" /><circle class="empty-scene-hub" cx="200" cy="136" r="5" />
        </g></g>
        <g transform="translate(-218 0)"><g class="empty-scene-rider">
            <circle class="empty-scene-head" cx="268" cy="62" r="11" />
            <path class="empty-scene-cap" d="M257 59c1-8 6-12 11-12s10 4 11 12z" />
            <path class="empty-scene-torso" d="M255 78c0-4 6-6 13-6s13 2 13 6v36h-26z" />
            <path class="empty-scene-legs" d="M257 114h9v34h-9zM270 114h9v34h-9z" />
            <g class="empty-scene-parcel">
                <rect class="empty-scene-box" x="264" y="88" width="30" height="24" rx="3" />
                <path class="empty-scene-tape" d="M279 88v24M264 98h30" />
                <path class="empty-scene-arm" d="M257 84l9 12M281 84l2 12" />
            </g>
        </g>
        </g>
        <g class="empty-scene-sparks"><circle cx="30" cy="40" r="3" /><circle cx="78" cy="30" r="2.5" /><circle cx="60" cy="18" r="2" /></g>
    </svg>
    <{{ $heading }} class="empty-scene-title">{{ $title }}</{{ $heading }}>
    @if($tagline)<p class="empty-scene-tagline">{{ $tagline }}</p>@endif
    @if($message)<p class="empty-scene-message">{{ $message }}</p>@endif
    {{ $slot }}
</div>
