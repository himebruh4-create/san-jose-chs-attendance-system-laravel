{{-- The 3 security questions, each from the fixed list, with its answer
     (first-run setup and My Account). A question picked in one dropdown is
     disabled in the others; the server rejects repeats as well.
     $groupClass: the page's form-row class; $selected: preselected keys. --}}
@php $selected = array_values($selected ?? []); @endphp

<div class="security-question-fields" data-security-question-fields>
    @for ($i = 0; $i < \App\Support\SecurityQuestions::COUNT; $i++)
        <div class="{{ $groupClass }}">
            <label for="securityQuestion{{ $i }}">Question {{ $i + 1 }}</label>
            <select id="securityQuestion{{ $i }}" name="security_questions[]" required>
                <option value="">Choose a question</option>
                @foreach (\App\Support\SecurityQuestions::LIST as $key => $question)
                    <option value="{{ $key }}" @selected(($selected[$i] ?? null) === $key)>{{ $question }}</option>
                @endforeach
            </select>
        </div>
        <div class="{{ $groupClass }}">
            <label for="securityAnswer{{ $i }}">Answer {{ $i + 1 }}</label>
            <input type="text" id="securityAnswer{{ $i }}" name="security_answers[]" minlength="{{ \App\Support\SecurityQuestions::MIN_ANSWER_LENGTH }}" maxlength="255" required autocomplete="off" spellcheck="false">
        </div>
    @endfor
</div>
<script>
(function () {
    const selects = document.currentScript.previousElementSibling.querySelectorAll('select');

    function disablePicked() {
        const picked = Array.from(selects, select => select.value);
        selects.forEach(select => {
            Array.from(select.options).forEach(option => {
                option.disabled = option.value !== '' && option.value !== select.value && picked.includes(option.value);
            });
        });
    }

    selects.forEach(select => select.addEventListener('change', disablePicked));
    disablePicked();
})();
</script>
