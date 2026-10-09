<?php

use App\Enums\Office;
use App\Enums\Role;
use App\Models\AgeBracket;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\Rank;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevUserSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    config([
        'census.seed.admin_password' => 'test-admin-pass',
        'census.seed.sysadmin_password' => 'test-sysadmin-pass',
        'census.seed.encoder_password' => 'test-encoder-pass',
        'census.seed.viewer_password' => 'test-viewer-pass',
    ]);
});

it('creates the Admin and System Admin with passwords from config', function () {
    $this->seed(DatabaseSeeder::class);

    $admin = User::where('username', 'admin')->firstOrFail();
    $sysadmin = User::where('username', 'sysadmin')->firstOrFail();

    expect($admin->role)->toBe(Role::Admin)
        ->and($admin->office)->toBe(Office::Admin)
        ->and($admin->is_active)->toBeTrue()
        ->and(Hash::check('test-admin-pass', $admin->password))->toBeTrue()
        ->and($sysadmin->role)->toBe(Role::SystemAdmin)
        ->and($sysadmin->office)->toBe(Office::IT)
        ->and(Hash::check('test-sysadmin-pass', $sysadmin->password))->toBeTrue()
        ->and(User::count())->toBe(2);
});

it('refuses to seed accounts without a password', function () {
    config(['census.seed.admin_password' => '']);

    $this->seed(DatabaseSeeder::class);
})->throws(RuntimeException::class, 'SEED_ADMIN_PASSWORD');

it('seeds the five branches with their draft ranks', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Branch::ordered()->pluck('name')->all())->toBe(['Army', 'Navy', 'Air Force', 'Marines', 'Others'])
        ->and(Branch::where('name', 'Others')->value('is_afp'))->toBeFalse()
        ->and(Branch::where('is_afp', true)->count())->toBe(4);

    $counts = Branch::withCount('ranks')->pluck('ranks_count', 'name')->all();
    expect($counts)->toBe(['Army' => 19, 'Navy' => 20, 'Air Force' => 19, 'Marines' => 17, 'Others' => 5]);

    $navy = Branch::where('name', 'Navy')->firstOrFail();
    expect($navy->ranks->pluck('name'))->toContain('ENS')->toContain('ASN')
        ->and(Rank::where('is_other', true)->count())->toBe(1)
        ->and(Rank::where('is_other', true)->first()->branch->name)->toBe('Others');
});

it('seeds sample diagnoses with one fixed Other (specify) entry last', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Diagnosis::count())->toBe(13)
        ->and(Diagnosis::where('is_other', true)->pluck('name')->all())->toBe(['Other (specify)'])
        ->and(Diagnosis::ordered()->get()->last()->name)->toBe('Other (specify)');
});

it('seeds non-overlapping sample age brackets ending with "and above"', function () {
    $this->seed(DatabaseSeeder::class);

    $brackets = AgeBracket::ordered()->get();

    expect($brackets->pluck('label')->all())->toBe(['0–17', '18–25', '26–35', '36–45', '46–59', '60 & above']);

    $brackets->sliding(2)->each(function ($pair) {
        [$a, $b] = $pair->values()->all();
        expect($b->min_age)->toBe($a->max_age + 1);
    });
});

it('seeds the report settings', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Setting::valueOf('hospital_name'))->toBe('[Hospital Name]')
        ->and(Setting::valueOf('noted_by_title'))->toBe('Commanding Officer')
        ->and(Setting::valueOf('missing', 'fallback'))->toBe('fallback');
});

it('can run twice without duplicating rows or resetting passwords', function () {
    $this->seed(DatabaseSeeder::class);

    User::where('username', 'admin')->firstOrFail()->update(['password' => 'changed-by-admin']);
    Setting::whereKey('hospital_name')->update(['value' => 'AFP Medical Center']);

    $this->seed(DatabaseSeeder::class);

    expect(User::count())->toBe(2)
        ->and(Rank::count())->toBe(80)
        ->and(Diagnosis::count())->toBe(13)
        ->and(AgeBracket::count())->toBe(6)
        ->and(Hash::check('changed-by-admin', User::where('username', 'admin')->value('password')))->toBeTrue()
        ->and(Setting::valueOf('hospital_name'))->toBe('AFP Medical Center');
});

// --- Development test accounts (DevUserSeeder) ---------------------------

it('creates the encoder and viewer test accounts with passwords from config', function () {
    $this->seed(DevUserSeeder::class);

    $encoder = User::where('username', 'encoder')->firstOrFail();
    $viewer = User::where('username', 'viewer')->firstOrFail();

    expect($encoder->role)->toBe(Role::Encoder)
        ->and($encoder->office)->toBe(Office::ER)
        ->and(Hash::check('test-encoder-pass', $encoder->password))->toBeTrue()
        ->and($viewer->role)->toBe(Role::Viewer)
        ->and($viewer->office)->toBe(Office::Command)
        ->and(Hash::check('test-viewer-pass', $viewer->password))->toBeTrue();
});

it('skips a test account whose password is not set', function () {
    config(['census.seed.viewer_password' => '']);

    $this->seed(DevUserSeeder::class);

    expect(User::where('username', 'encoder')->exists())->toBeTrue()
        ->and(User::where('username', 'viewer')->exists())->toBeFalse();
});

it('adds the test accounts to the full seed only when APP_ENV is local', function () {
    $this->seed(DatabaseSeeder::class);
    expect(User::count())->toBe(2); // testing: admin + sysadmin only

    app()->detectEnvironment(fn () => 'local');
    $this->seed(DatabaseSeeder::class);

    expect(User::pluck('username')->sort()->values()->all())->toBe(['admin', 'encoder', 'sysadmin', 'viewer']);
});

it('never creates test accounts in production, even when called directly', function () {
    app()->detectEnvironment(fn () => 'production');

    (new DevUserSeeder)->run();

    expect(User::count())->toBe(0);
});
