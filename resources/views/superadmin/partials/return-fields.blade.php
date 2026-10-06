{{-- Keeps the personnel list's search / filters / page after a form post. --}}
<input type="hidden" name="return_search" value="{{ $search }}">
<input type="hidden" name="return_academic_status" value="{{ $filterAcademic }}">
<input type="hidden" name="return_employment_type" value="{{ $filterEmployment }}">
<input type="hidden" name="return_page" value="{{ $page }}">
