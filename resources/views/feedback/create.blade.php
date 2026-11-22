<x-app-layout title="Feedback e segnalazioni">
    <header class="page-heading feedback-heading"><div><span class="eyebrow">AIUTACI A MIGLIORARE</span><h1>Feedback e segnalazioni</h1><p>Hai trovato un problema o hai un’idea? Scrivici: la segnalazione arriva direttamente al team EA-Express.</p></div></header>
    <div class="feedback-page" data-workspace-region="feedback">
        @if(session('feedback-sent'))
            <section class="surface feedback-sent" role="status" aria-live="polite" data-feedback-sent>
                <span class="feedback-sent-icon" aria-hidden="true"><x-ui.icon name="send-check" /></span>
                <h2>Grazie, segnalazione inviata!</h2>
                <p>L’abbiamo ricevuta e la leggeremo al più presto. Se serve, ti risponderemo all’indirizzo email del tuo account.</p>
                <x-ui.icon-button icon="arrow-repeat" label="Invia un’altra segnalazione" :href="route('feedback.create')" text />
            </section>
        @else
            <form method="post" action="{{ route('feedback.store') }}" enctype="multipart/form-data" class="surface feedback-form" data-prevent-repeat data-feedback-form>
                @csrf
                @error('feedback')<p class="alert alert-danger" role="alert">{{ $message }}</p>@enderror
                <fieldset class="feedback-categories">
                    <legend class="form-label">Di cosa si tratta?<span class="required-marker" aria-hidden="true"> *</span></legend>
                    @foreach($categories as $value => $label)
                        <label class="feedback-category">
                            <input type="radio" name="category" value="{{ $value }}" @checked(old('category', 'problem') === $value) required>
                            <span><x-ui.icon :name="['problem' => 'bug', 'suggestion' => 'lightbulb', 'other' => 'chat-square-text'][$value]" />{{ $label }}</span>
                        </label>
                    @endforeach
                    @error('category')<div class="invalid-feedback d-block" role="alert">{{ $message }}</div>@enderror
                </fieldset>
                <x-ui.field name="subject" label="Oggetto" :value="old('subject')" maxlength="120" minlength="3" placeholder="Es. Il pulsante Consegna non risponde" required />
                <div class="field">
                    <label class="form-label" for="feedback-message">Descrizione<span class="required-marker" aria-hidden="true"> *</span></label>
                    <textarea id="feedback-message" name="message" rows="6" minlength="10" maxlength="5000" required @class(['form-control', 'is-invalid' => $errors->has('message')]) aria-describedby="feedback-message-help" placeholder="Cosa stavi facendo, cosa ti aspettavi e cosa è successo invece.">{{ old('message') }}</textarea>
                    <div id="feedback-message-help" class="form-text">Più dettagli ci dai, prima riusciamo a risolvere.</div>
                    @error('message')<div class="invalid-feedback d-block" role="alert">{{ $message }}</div>@enderror
                </div>
                <x-ui.field name="location" label="Pagina o sezione (facoltativo)" :value="old('location')" maxlength="200" placeholder="Es. La mia pagina operativa" />
                <div class="field">
                    <span class="form-label" id="feedback-files-label">Allegati (facoltativo)</span>
                    <label class="feedback-dropzone" data-feedback-dropzone aria-describedby="feedback-files-help">
                        <input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.webp,.gif,.heic,.pdf,.txt,image/*,application/pdf,text/plain" data-max-files="{{ \App\Http\Requests\FeedbackReportRequest::MaxFiles }}" data-max-file-kb="{{ \App\Http\Requests\FeedbackReportRequest::MaxFileKilobytes }}" data-max-total-kb="{{ \App\Http\Requests\FeedbackReportRequest::MaxTotalKilobytes }}" aria-labelledby="feedback-files-label">
                        <span class="feedback-dropzone-icon" aria-hidden="true"><x-ui.icon name="cloud-arrow-up" /></span>
                        <strong>Trascina qui foto o file</strong>
                        <span>oppure <u>scegli dal dispositivo</u></span>
                    </label>
                    <div id="feedback-files-help" class="form-text">Fino a {{ \App\Http\Requests\FeedbackReportRequest::MaxFiles }} file: immagini, PDF o TXT, massimo {{ \App\Http\Requests\FeedbackReportRequest::MaxFileKilobytes / 1024 }} MB ciascuno ({{ \App\Http\Requests\FeedbackReportRequest::MaxTotalKilobytes / 1024 }} MB in totale).@if($errors->any()) Se l’invio non è riuscito, seleziona di nuovo gli allegati.@endif</div>
                    <p class="invalid-feedback d-block" role="alert" data-feedback-files-error @if(! $errors->has('attachments') && ! $errors->has('attachments.*')) hidden @endif>{{ $errors->first('attachments') ?: $errors->first('attachments.*') }}</p>
                    <ul class="feedback-files" data-feedback-files aria-live="polite"></ul>
                </div>
                <input type="hidden" name="client" data-feedback-client>
                <div class="row-actions"><x-ui.icon-button action="send" label="Invia segnalazione" type="submit" text /></div>
            </form>
        @endif
    </div>
</x-app-layout>
