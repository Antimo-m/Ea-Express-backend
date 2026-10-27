@props(['name', 'label', 'options', 'values' => [], 'id'])
<div {{ $attributes->class(['filter-field', 'filter-account', 'account-multiselect']) }} data-account-multiselect>
    <label class="form-label" for="{{ $id }}">{{ $label }}</label>
    <select name="{{ $name }}[]" id="{{ $id }}" class="form-select" multiple data-account-source data-filter-label="{{ $label }}" aria-describedby="{{ $id }}-help">
        @foreach($options as $option)<option value="{{ $option->slug }}" data-account-name="{{ $option->name }}" @selected(in_array($option->slug, $values, true))>{{ $option->name }} · {{ $option->email }}</option>@endforeach
    </select>
    <span id="{{ $id }}-help" class="visually-hidden">Seleziona uno o più account. Nessuna selezione include tutti gli account.</span>
    <div class="account-multiselect-controls" data-account-controls hidden>
        <details class="account-multiselect-menu" data-account-menu>
            <summary class="form-select" aria-label="Seleziona account clienti"><span data-account-count>Tutti gli account</span></summary>
            <div class="account-multiselect-popup">
                <label class="visually-hidden" for="{{ $id }}-search">Cerca account per nome o email</label>
                <input type="search" id="{{ $id }}-search" class="form-control" placeholder="Cerca account…" autocomplete="off" data-account-search>
                <div class="account-multiselect-options" data-account-options>
                    @foreach($options as $option)<label class="account-multiselect-option"><input type="checkbox" value="{{ $option->slug }}" data-account-choice @checked(in_array($option->slug, $values, true))><span><strong>{{ $option->name }}</strong><small>{{ $option->email }}</small></span></label>@endforeach
                </div>
                <p class="register-note" data-account-empty hidden>Nessun account corrispondente.</p>
                <button class="btn btn-outline-secondary btn-sm" type="button" data-account-clear>Cancella selezione</button>
            </div>
        </details>
        <div class="account-selection" aria-label="Account selezionati" data-account-selected></div>
        <span class="visually-hidden" role="status" data-account-status></span>
    </div>
</div>
