<?php

namespace Tests\Feature;

use App\Models\KioskDevice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Settings -> School Name & Logo (App\Support\SchoolBranding): the Super
 * Admin renames the school and replaces its logo for the whole system. The
 * full name is on printouts, badges and the login page; the short name in
 * the sidebar and the browser tab titles. Uploads are checked on the server.
 */
class SchoolBrandingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function saveSchool(array $input = []): TestResponse
    {
        return $this->post(route('superadmin.settings.school'), $input + [
            'school_name' => 'Gen. Mariano Alvarez National High School',
            'school_short_name' => 'GMA NHS',
        ]);
    }

    private function savedSettings(): object
    {
        return DB::table('system_settings')->where('id', 1)->first(['school_name', 'school_short_name', 'school_logo_path']);
    }

    public function test_the_built_in_name_and_logo_show_until_the_super_admin_changes_them(): void
    {
        // Login opens once setup has made a Super Admin.
        $this->account('superadmin');

        $this->get('/login')
            ->assertSee('<title>Login - San Jose CHS</title>', false)
            ->assertSee('San Jose Community High School')
            ->assertSee(asset('img/logo.png'));

        $this->get(route('school-logo'))->assertRedirect(asset('img/logo.png'));
    }

    public function test_a_new_name_shows_on_the_login_page_sidebar_tab_titles_printouts_kiosk_and_badges(): void
    {
        $superAdmin = $this->account('superadmin');
        $this->teacher(['created_at' => '2026-01-01 00:00:00']);

        $this->actingAs($superAdmin);
        $this->saveSchool()->assertRedirect(route('superadmin.settings', ['tab' => 'school']))
            ->assertSessionHas('message', 'School name and logo saved.');

        $this->assertSame(['Gen. Mariano Alvarez National High School', 'GMA NHS'], [$this->savedSettings()->school_name, $this->savedSettings()->school_short_name]);
        $this->get(route('superadmin.dashboard'))
            ->assertSee('<title>Dashboard - Super Admin - GMA NHS</title>', false)
            ->assertSee('<h2>GMA NHS</h2>', false)
            ->assertDontSee('San Jose CHS');
        // Badges and the other browser-built printouts read window.SCHOOL.
        $this->get(route('superadmin.personnel'))
            ->assertSee('window.SCHOOL = {"name":"Gen. Mariano Alvarez National High School","short_name":"GMA NHS"', false);
        $this->get(route('reports.attendance-print', ['period' => 'daily', 'date' => '2026-10-05']))
            ->assertSee('<div class="school-name">Gen. Mariano Alvarez National High School</div>', false);

        $this->withCookie(KioskDevice::COOKIE, $this->kioskToken())->get(route('kiosk'))
            ->assertSee('<h2>GMA NHS</h2>', false);

        $this->post(route('logout'));
        $this->get('/login')
            ->assertSee('<title>Login - GMA NHS</title>', false)
            ->assertSee('Gen. Mariano Alvarez National High School');
    }

    public function test_an_uploaded_logo_is_re_encoded_and_replaces_the_logo_everywhere(): void
    {
        $this->actingAs($this->account('superadmin'));

        $this->saveSchool(['school_logo' => UploadedFile::fake()->image('new-logo.jpg', 1024, 768)])
            ->assertSessionHas('message', 'School name and logo saved.');

        $path = $this->savedSettings()->school_logo_path;
        Storage::disk('local')->assertExists($path);
        $this->assertStringEndsWith('.png', $path);
        $this->assertSame([512, 384], array_slice(getimagesizefromstring(Storage::disk('local')->get($path)), 0, 2));

        $logoUrl = route('school-logo', ['v' => pathinfo($path, PATHINFO_FILENAME)]);
        $this->get(route('superadmin.dashboard'))->assertSee('<img src="'.$logoUrl.'" alt="School Logo" class="sidebar-logo">', false);
        $this->get(route('reports.attendance-print', ['period' => 'daily', 'date' => '2026-10-05']))->assertSee($logoUrl, false);

        $this->post(route('logout'));
        $this->get('/login')->assertSee($logoUrl, false);
        $this->get($logoUrl)->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_the_names_are_saved_even_when_the_settings_row_is_missing(): void
    {
        DB::table('system_settings')->delete();
        $this->actingAs($this->account('superadmin'));

        $this->saveSchool()->assertSessionHas('message', 'School name and logo saved.');

        $this->assertSame('GMA NHS', $this->savedSettings()->school_short_name);
    }

    public function test_a_new_logo_removes_the_old_logo_file(): void
    {
        $this->actingAs($this->account('superadmin'));
        $this->saveSchool(['school_logo' => UploadedFile::fake()->image('first.png', 200, 200)]);
        $firstPath = $this->savedSettings()->school_logo_path;

        $this->saveSchool(['school_logo' => UploadedFile::fake()->image('second.webp', 200, 200)]);

        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($this->savedSettings()->school_logo_path);
    }

    #[TestWith(['logo.pdf', 'application/pdf'])]
    #[TestWith(['logo.gif', 'image/gif'])]
    #[TestWith(['logo.svg', 'image/svg+xml'])]
    public function test_a_logo_that_is_not_png_jpg_or_webp_is_rejected(string $filename, string $mimeType): void
    {
        $this->actingAs($this->account('superadmin'));

        $this->saveSchool(['school_logo' => UploadedFile::fake()->create($filename, 10, $mimeType)])
            ->assertSessionHas('message_type', 'error')
            ->assertSessionHas('message', 'The logo must be a PNG, JPG or WebP image.');

        $this->assertNull($this->savedSettings()->school_logo_path);
        $this->assertNull($this->savedSettings()->school_name);
    }

    public function test_a_file_named_like_an_image_that_is_not_one_is_rejected(): void
    {
        $this->actingAs($this->account('superadmin'));

        $this->saveSchool(['school_logo' => UploadedFile::fake()->createWithContent('logo.png', '<?php echo "not an image";')])
            ->assertSessionHas('message', 'The logo must be a PNG, JPG or WebP image.');

        $this->assertNull($this->savedSettings()->school_logo_path);
        $this->assertSame([], Storage::disk('local')->allFiles('school-logo'));
    }

    public function test_a_logo_larger_than_2_mb_is_rejected(): void
    {
        $this->actingAs($this->account('superadmin'));

        $this->saveSchool(['school_logo' => UploadedFile::fake()->image('big.png', 100, 100)->size(2049)])
            ->assertSessionHas('message', 'The logo must be 2 MB or smaller.');

        $this->assertNull($this->savedSettings()->school_logo_path);
    }

    #[TestWith(['school_name', '', 'The school name field is required.'])]
    #[TestWith(['school_short_name', '   ', 'The short name field is required.'])]
    #[TestWith(['school_short_name', 'A Short Name That Is Much Longer Than Forty', 'The short name field must not be greater than 40 characters.'])]
    public function test_the_names_are_required_and_limited_in_length(string $field, string $value, string $message): void
    {
        $this->actingAs($this->account('superadmin'));

        $this->saveSchool([$field => $value])->assertSessionHas('message', $message);

        $this->assertNull($this->savedSettings()->school_name);
    }

    public function test_only_the_super_admin_can_change_the_school_name_and_logo(): void
    {
        $this->actingAs($this->account('admin'));

        $this->saveSchool(['school_logo' => UploadedFile::fake()->image('logo.png', 100, 100)])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertNull($this->savedSettings()->school_name);
        $this->assertSame([], Storage::disk('local')->allFiles('school-logo'));
    }
}
